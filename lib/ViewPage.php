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
 * Latest measurement state of the mapped targets on devices the user may see.
 */
final class ViewPage {
	public function __construct(
		private readonly TargetRepository $targets,
		private readonly ObservationRepository $observations,
		private readonly FreshnessEvaluator $evaluator,
		private readonly DeviceMaintenance $maintenance,
	) {
	}

	/**
	 * @param array<int, string> $devices allowed host id => description
	 */
	public function render(array $devices): void {
		$mapped       = $this->targets->mappedTo(array_keys($devices));
		$observations = $this->observations->forTargets(array_map(fn (array $m) => (int) $m['target']->id?->value, $mapped));
		$rows         = [];

		foreach ($mapped as $entry) {
			$target = $entry['target'];

			foreach ($target->locations() as $location) {
				$observation = null;

				foreach ($observations[$target->id?->value] ?? [] as $candidate) {
					if ($candidate->location === $location) {
						$observation = $candidate;
					}
				}

				$rows[] = $this->row($devices[$entry['host_id']] ?? '', $this->maintenance->inMaintenance($entry['host_id']), $target, $location, $observation);
			}
		}

		Html::table(
			__('SmokePing Targets', 'smokeping'),
			[__('Device', 'smokeping'), __('Target', 'smokeping'), __('Location', 'smokeping'), __('Freshness', 'smokeping'), __('Condition', 'smokeping'), __('Maintenance', 'smokeping'), __('Loss', 'smokeping'), __('Median', 'smokeping'), __('Last Update', 'smokeping')],
			$rows,
			__('No SmokePing targets are mapped to devices you can view.', 'smokeping')
		);
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
