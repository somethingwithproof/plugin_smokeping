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
 * Builds previews and creates equivalent Thold thresholds on a target's
 * summary data sources from SmokePing alert definitions. Never modifies
 * SmokePing's own configuration files.
 */
final class TholdGenerator {
	public function __construct(
		private readonly TargetRepository $targets,
		private readonly ObjectRepository $objects,
		private readonly CactiObjectRepository $cacti,
	) {
	}

	/**
	 * Preview translating the target's SmokePing alert(s) to Thold thresholds.
	 *
	 * @param array<string, array{name: string, type: string, pattern: string, comment: string, edgetrigger: bool}> $alertDefinitions
	 * @return array{
	 *     target: string,
	 *     pings: int,
	 *     summary_sources: list<array{local_data_id: int, host_id: int, location: string}>,
	 *     translations: list<array{
	 *         supported: bool,
	 *         alert_name: string,
	 *         smokeping_type: string,
	 *         smokeping_pattern: string,
	 *         reason: string,
	 *         data_source_name?: string,
	 *         thold_hi?: float,
	 *         thold_fail_trigger?: int,
	 *         thold_type?: int,
	 *         comparator?: string
	 *     }>
	 * }
	 */
	public function preview(Target $target, array $alertDefinitions, ?string $onlyAlert = null): array {
		$names = array_values(array_filter(array_map('trim', explode(',', $target->alerts)), static fn (string $n): bool => $n !== ''));

		if ($onlyAlert !== null && $onlyAlert !== '') {
			$names = in_array($onlyAlert, $names, true) ? [$onlyAlert] : [$onlyAlert];
		}

		$translations = [];

		foreach ($names as $name) {
			if (!isset($alertDefinitions[$name])) {
				$translations[] = [
					'supported'         => false,
					'alert_name'        => $name,
					'smokeping_type'    => '',
					'smokeping_pattern' => '',
					'reason'            => sprintf('Alert "%s" is not defined in the SmokePing Alerts section.', $name),
				];

				continue;
			}

			$translations[] = TholdTranslator::translate($alertDefinitions[$name], $target->pings);
		}

		$summaries = $target->id !== null ? $this->objects->summaryDataSourcesForTarget($target->id->value) : [];

		return [
			'target'          => $target->path->value,
			'pings'           => $target->pings,
			'summary_sources' => $summaries,
			'translations'    => $translations,
		];
	}

	/**
	 * Create Thold threshold records for every supported translation on the
	 * target's summary data sources. Refuses unsupported patterns without
	 * writing anything.
	 *
	 * @param array<string, array{name: string, type: string, pattern: string, comment: string, edgetrigger: bool}> $alertDefinitions
	 * @return array{created: list<array{thold_id: int, local_data_id: int, alert_name: string, name: string}>, skipped: list<string>}
	 */
	public function apply(Target $target, array $alertDefinitions, ?string $onlyAlert = null): array {
		if (!$this->cacti->tholdInstalled()) {
			return ['created' => [], 'skipped' => ['Thold plugin is not installed (thold_data table absent).']];
		}

		$plan    = $this->preview($target, $alertDefinitions, $onlyAlert);
		$created = [];
		$skipped = [];

		if ($plan['summary_sources'] === []) {
			return ['created' => [], 'skipped' => [sprintf('Target %s has no SmokePing Summary data source.', $target->path->value)]];
		}

		foreach ($plan['translations'] as $item) {
			if (!$item['supported'] || !isset($item['thold_hi'], $item['thold_fail_trigger'])) {
				$skipped[] = sprintf('%s: %s', $item['alert_name'], $item['reason']);

				continue;
			}

			foreach ($plan['summary_sources'] as $source) {
				$locLabel = $source['location'] === '' ? 'master' : $source['location'];
				$name     = sprintf('SmokePing %s (%s) [%s]', $target->path->value, $locLabel, $item['alert_name']);
				$id       = $this->cacti->createTholdThreshold(
					$source['host_id'],
					$source['local_data_id'],
					$name,
					$item['thold_hi'],
					$item['thold_fail_trigger']
				);

				if ($id !== null && $id > 0) {
					$created[] = [
						'thold_id'      => $id,
						'local_data_id' => $source['local_data_id'],
						'alert_name'    => $item['alert_name'],
						'name'          => $name,
					];
				} else {
					$skipped[] = sprintf('%s on data source %d: already exists or could not be created.', $item['alert_name'], $source['local_data_id']);
				}
			}
		}

		return ['created' => $created, 'skipped' => $skipped];
	}

	/**
	 * Load a target by path on the given poller.
	 */
	public function findTarget(int $pollerId, string $path): ?Target {
		return $this->targets->find($pollerId, $path);
	}
}
