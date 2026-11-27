<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_smokeping, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_smokeping
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

namespace Somethingwithproof\Smokeping;

/**
 * Latest measurement state of the mapped targets on devices the user may see,
 * with filter bar, pagination, and shared structured rows for CSV export.
 */
final class ViewPage {
	/** Clock property. */
	private readonly Clock $clock;

	/**
	 * Create a new ViewPage instance.
	 *
	 * @param TargetRepository $targets Targets.
	 * @param ObservationRepository $observations Observations.
	 * @param FreshnessEvaluator $evaluator Evaluator.
	 * @param DeviceMaintenance $maintenance Maintenance.
	 * @param Clock|null $clock Clock.
	 */
	public function __construct(
		private readonly TargetRepository $targets,
		private readonly ObservationRepository $observations,
		private readonly FreshnessEvaluator $evaluator,
		private readonly DeviceMaintenance $maintenance,
		?Clock $clock = null,
	) {
		$this->clock = $clock ?? new SystemClock();
	}

	/**
	 * Build filtered, permission-checked target state rows shared between the
	 * HTML table and CSV export. Uses SummaryValues so exported numbers match
	 * polled summary data sources.
	 *
	 * @param array<int, string> $devices allowed host id => description
	 * @return list<array{
	 *     path: string,
	 *     name: string,
	 *     host: string,
	 *     probe: string,
	 *     device: string,
	 *     host_id: int,
	 *     location: string,
	 *     median_ms: ?float,
	 *     loss_pct: ?float,
	 *     age_seconds: ?int,
	 *     freshness: string,
	 *     condition: string,
	 *     in_maintenance: bool,
	 *     observation: ?Observation,
	 *     target: Target
	 * }>
	 */
	public function rows(array $devices, ?TargetFilter $filter = null): array {
		$filter       = $filter ?? new TargetFilter();
		$mapped       = $this->targets->mappedTo(array_keys($devices));
		$observations = $this->observations->forTargets(array_map(fn (array $m) => (int) $m['target']->id?->value, $mapped));
		$calculator   = new SummaryCalculator($this->evaluator, $this->clock);
		$searchLower  = strtolower($filter->search);
		$result       = [];

		foreach ($mapped as $entry) {
			$target = $entry['target'];
			$device = $devices[$entry['host_id']] ?? '';

			if ($filter->probe !== '' && $target->probe !== $filter->probe) {
				continue;
			}

			if ($searchLower !== '') {
				$haystack = strtolower($target->path->value . ' ' . $target->displayName() . ' ' . $target->host . ' ' . $device);

				if (!str_contains($haystack, $searchLower)) {
					continue;
				}
			}

			$inMaintenance = $this->maintenance->inMaintenance($entry['host_id']);

			foreach ($target->locations() as $location) {
				$observation = null;

				foreach ($observations[$target->id?->value] ?? [] as $candidate) {
					if ($candidate->location === $location) {
						$observation = $candidate;
					}
				}

				$freshness = $observation === null ? Freshness::Never : $this->evaluator->freshness($observation);

				if ($filter->state !== 'all' && $freshness->value !== $filter->state) {
					continue;
				}

				$condition = $observation === null ? Condition::Unknown : $this->evaluator->condition($observation);
				$summary   = $calculator->compute($observation, $target->firstSeen);

				$result[] = [
					'path'           => $target->path->value,
					'name'           => $target->displayName(),
					'host'           => $target->host,
					'probe'          => $target->probe,
					'device'         => $device,
					'host_id'        => $entry['host_id'],
					'location'       => $location,
					'median_ms'      => $summary->median,
					'loss_pct'       => $summary->lossPct,
					'age_seconds'    => $summary->age,
					'freshness'      => $freshness->value,
					'condition'      => $condition->value,
					'in_maintenance' => $inMaintenance,
					'observation'    => $observation,
					'target'         => $target,
				];
			}
		}

		return $result;
	}

	/**
	 * @param array<int, string> $devices allowed host id => description
	 */
	public function render(array $devices, ?TargetFilter $filter = null, int $hostId = 0): void {
		$filter  = $filter ?? new TargetFilter();
		$allRows = $this->rows($devices, $filter);
		$total   = count($allRows);
		$paged   = $filter->rows > 0 ? array_slice($allRows, $filter->offset(), $filter->rows) : $allRows;

		$probes = [];

		foreach ($allRows as $entry) {
			if ($entry['probe'] !== '') {
				$probes[$entry['probe']] = true;
			}
		}

		$probeList = array_keys($probes);
		sort($probeList);

		$this->renderFilterBar($filter, $probeList, $total, $hostId);

		$rows = [];

		foreach ($paged as $entry) {
			$rows[] = $this->row($entry['device'], $entry['in_maintenance'], $entry['target'], $entry['location'], $entry['observation']);
		}

		Html::table(
			__('SmokePing Targets', 'smokeping'),
			[__('Device', 'smokeping'), __('Target', 'smokeping'), __('Location', 'smokeping'), __('Freshness', 'smokeping'), __('Condition', 'smokeping'), __('Maintenance', 'smokeping'), __('Loss', 'smokeping'), __('Median', 'smokeping'), __('Last Update', 'smokeping')],
			$rows,
			__('No SmokePing targets are mapped to devices you can view.', 'smokeping')
		);

		$this->renderPagination($filter, $total, $hostId);
	}

	/**
	 * @param list<string> $probes
	 */
	private function renderFilterBar(TargetFilter $filter, array $probes, int $total, int $hostId): void {
		$exportParams = $filter->queryParams(false) + ['action' => 'export_csv'];

		if ($hostId > 0) {
			$exportParams['host_id'] = $hostId;
		}

		print '<form method="get" action="smokeping.php" class="filterTable">';

		if ($hostId > 0) {
			print '<input type="hidden" name="host_id" value="' . $hostId . '">';
		}

		print '<table class="filterTable"><tr>';
		print '<td><label for="sp_view_filter">' . html_escape(__('Target', 'smokeping')) . '</label> ';
		print '<input type="text" id="sp_view_filter" name="filter" size="24" value="' . html_escape($filter->search) . '"></td>';

		print '<td><label for="sp_view_probe">Probe</label> <select id="sp_view_probe" name="probe">';
		print '<option value="">' . html_escape('All') . '</option>';

		foreach ($probes as $probe) {
			$selected = $filter->probe === $probe ? ' selected' : '';
			print '<option value="' . html_escape($probe) . '"' . $selected . '>' . html_escape($probe) . '</option>';
		}

		print '</select></td>';

		print '<td><label for="sp_view_state">' . html_escape(__('Freshness', 'smokeping')) . '</label> <select id="sp_view_state" name="state">';

		foreach (['all' => 'All', 'current' => 'current', 'delayed' => 'delayed', 'stale' => 'stale', 'never' => 'never'] as $value => $label) {
			$selected = $filter->state === $value ? ' selected' : '';
			print '<option value="' . html_escape($value) . '"' . $selected . '>' . html_escape($label) . '</option>';
		}

		print '</select></td>';
		print '<td><label for="sp_view_rows">Rows</label> <select id="sp_view_rows" name="rows">';

		foreach (TargetFilter::PAGE_SIZE_LABELS as $value => $label) {
			$selected = $filter->rows === $value ? ' selected' : '';
			print '<option value="' . $value . '"' . $selected . '>' . html_escape($label) . '</option>';
		}

		print '</select></td>';
		print '<td><input type="submit" class="ui-button ui-corner-all ui-widget" value="Go"> ';
		print '<a class="ui-button ui-corner-all ui-widget" href="smokeping.php">Clear</a> ';
		print Html::link('smokeping.php', $exportParams, 'Export CSV')->html . '</td>';
		print '<td>' . html_escape(sprintf('%d rows', $total)) . '</td>';
		print '</tr></table></form>';
	}

	/**
	 * Render pagination.
	 *
	 * @param TargetFilter $filter Filter.
	 * @param int $total Total.
	 * @param int $hostId Host id.
	 */
	private function renderPagination(TargetFilter $filter, int $total, int $hostId): void {
		if ($filter->rows <= 0) {
			return;
		}

		$pages = (int) max(1, (int) ceil($total / $filter->rows));

		if ($pages <= 1) {
			return;
		}

		$base = $filter->queryParams(false);

		if ($hostId > 0) {
			$base['host_id'] = $hostId;
		}

		print '<div class="navBarNavigation">';
		print '<span>' . html_escape(sprintf('Page %d of %d (%d total)', $filter->page, $pages, $total)) . '</span> ';

		if ($filter->page > 1) {
			print Html::link('smokeping.php', $base + ['page' => $filter->page - 1], 'Previous')->html . ' ';
		}

		if ($filter->page < $pages) {
			print Html::link('smokeping.php', $base + ['page' => $filter->page + 1], 'Next')->html;
		}

		print '</div>';
	}

	/**
	 * @return list<string>
	 */
	private function row(string $device, bool $inMaintenance, Target $target, string $location, ?Observation $observation): array {
		$name        = $location === '' ? __('Master', 'smokeping') : $location;
		$maintenance = $inMaintenance ? __('In maintenance', 'smokeping') : __('No', 'smokeping');

		if ($observation === null) {
			return [$device, $target->displayName(), $name, __('Never observed', 'smokeping'), __('Unknown', 'smokeping'), $maintenance, '', '', __('never', 'smokeping')];
		}

		return [
			$device,
			$target->displayName(),
			$name,
			$this->evaluator->freshness($observation)->value,
			$this->evaluator->condition($observation)->value,
			$maintenance,
			$observation->loss === null ? 'U' : sprintf('%d/%d', (int) $observation->loss, $observation->pings),
			$observation->median === null ? 'U' : sprintf('%.1f ms', $observation->median * 1000),
			Html::age($this->evaluator->age($observation)),
		];
	}
}
