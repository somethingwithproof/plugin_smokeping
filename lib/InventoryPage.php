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
 * Administrator view of targets, including unmapped ones, with SQL-level
 * filtering and pagination, plus open re-link proposals. Proposals are applied
 * only from a confirmation screen.
 */
final class InventoryPage {
	public function __construct(private readonly TargetRepository $targets) {
	}

	/**
	 * @param array<int, string> $devices device id => description the user may see; a
	 *                                    target mapped to any other device is left out
	 */
	public function render(int $pollerId, array $devices, ?TargetFilter $filter = null): void {
		$filter   = $filter ?? new TargetFilter();
		$mappings = $this->targets->mappings();

		if ($filter->isFiltered() || $filter->page > 1 || $filter->rows !== TargetFilter::DEFAULT_ROWS) {
			$pageData = $this->targets->filteredForPoller($pollerId, $filter, array_keys($devices));
			$targets  = $pageData['targets'];
			$total    = $pageData['total'];
			$probes   = $pageData['probes'];
		} else {
			$pageData = $this->targets->filteredForPoller($pollerId, $filter, array_keys($devices));
			$targets  = $pageData['targets'];
			$total    = $pageData['total'];
			$probes   = $pageData['probes'];
		}

		$this->renderFilterBar($filter, $probes, $total);

		$rows = [];

		foreach ($targets as $target) {
			$host = $mappings[(int) $target->id?->value] ?? 0;

			if ($host > 0 && !isset($devices[$host])) {
				continue;
			}

			$rows[] = [
				$target->path->value,
				$target->displayName(),
				$target->host,
				$target->probe,
				implode(' ', $target->slaves),
				sprintf('%d s / %d', $target->step, $target->pings),
				$target->present ? __('Present', 'smokeping') : __('Gone', 'smokeping'),
				$host > 0 ? $devices[$host] : __('Unmapped', 'smokeping'),
				$target->present && $target->id !== null && !isset($mappings[$target->id->value]) ? Html::link('smokeping_inventory.php', ['action' => 'add', 'id' => $target->id->value], __('Add to Cacti', 'smokeping')) : '',
			];
		}

		Html::table(
			__('SmokePing Inventory', 'smokeping'),
			[__('Path', 'smokeping'), __('Name', 'smokeping'), __('Host', 'smokeping'), __('Probe', 'smokeping'), __('Slaves', 'smokeping'), __('Step / Pings', 'smokeping'), __('Inventory', 'smokeping'), __('Mapping', 'smokeping'), __('Action', 'smokeping')],
			$rows,
			__('No targets have been imported on this poller.', 'smokeping')
		);

		$this->renderPagination($filter, $total);

		$visible   = array_filter($this->targets->openRelinkProposals(), fn (array $p) => !isset($mappings[$p['old_id']]) || isset($devices[$mappings[$p['old_id']]]));
		$proposals = array_map(fn (array $p) => [$p['old_path'], $p['new_path'], $p['host'], $p['probe'], Html::link('smokeping_inventory.php', ['action' => 'relink', 'id' => $p['id']], __('Review', 'smokeping'))], array_values($visible));

		Html::table(
			__('Re-link Proposals', 'smokeping'),
			[__('Disappeared Path', 'smokeping'), __('New Path', 'smokeping'), __('Host', 'smokeping'), __('Probe', 'smokeping'), __('Action', 'smokeping')],
			$proposals,
			__('No re-link proposals.', 'smokeping')
		);
	}

	/**
	 * @param list<string> $probes
	 */
	private function renderFilterBar(TargetFilter $filter, array $probes, int $total): void {
		print '<form method="get" action="smokeping_inventory.php" class="filterTable">';
		print '<table class="filterTable"><tr>';
		print '<td><label for="sp_filter">' . html_escape(__('Path', 'smokeping') . '/' . __('Host', 'smokeping')) . '</label> ';
		print '<input type="text" id="sp_filter" name="filter" size="24" value="' . html_escape($filter->search) . '"></td>';

		print '<td><label for="sp_probe">' . html_escape(__('Probe', 'smokeping')) . '</label> <select id="sp_probe" name="probe">';
		print '<option value="">' . html_escape('All') . '</option>';

		foreach ($probes as $probe) {
			$selected = $filter->probe === $probe ? ' selected' : '';
			print '<option value="' . html_escape($probe) . '"' . $selected . '>' . html_escape($probe) . '</option>';
		}

		print '</select></td>';

		print '<td><label for="sp_presence">' . html_escape(__('Inventory', 'smokeping')) . '</label> <select id="sp_presence" name="presence">';

		foreach (['all' => 'All', 'present' => __('Present', 'smokeping'), 'gone' => __('Gone', 'smokeping')] as $value => $label) {
			$selected = $filter->presence === $value ? ' selected' : '';
			print '<option value="' . html_escape($value) . '"' . $selected . '>' . html_escape($label) . '</option>';
		}

		print '</select></td>';

		print '<td><label for="sp_mapping">' . html_escape(__('Mapping', 'smokeping')) . '</label> <select id="sp_mapping" name="mapping">';

		foreach (['all' => 'All', 'mapped' => 'Mapped', 'unmapped' => __('Unmapped', 'smokeping')] as $value => $label) {
			$selected = $filter->mapping === $value ? ' selected' : '';
			print '<option value="' . html_escape($value) . '"' . $selected . '>' . html_escape($label) . '</option>';
		}

		print '</select></td>';
		print '<td><input type="submit" class="ui-button ui-corner-all ui-widget" value="Go"> ';
		print '<a class="ui-button ui-corner-all ui-widget" href="smokeping_inventory.php">Clear</a></td>';
		print '<td>' . html_escape(sprintf('%d targets', $total)) . '</td>';
		print '</tr></table></form>';
	}

	private function renderPagination(TargetFilter $filter, int $total): void {
		$pages = (int) max(1, (int) ceil($total / $filter->rows));

		if ($pages <= 1) {
			return;
		}

		$base = $filter->queryParams(false);
		print '<div class="navBarNavigation">';
		print '<span>' . html_escape(sprintf('Page %d of %d (%d total)', $filter->page, $pages, $total)) . '</span> ';

		if ($filter->page > 1) {
			print Html::link('smokeping_inventory.php', $base + ['page' => $filter->page - 1], 'Previous')->html . ' ';
		}

		if ($filter->page < $pages) {
			print Html::link('smokeping_inventory.php', $base + ['page' => $filter->page + 1], 'Next')->html;
		}

		print '</div>';
	}
}
