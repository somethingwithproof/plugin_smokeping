<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * Latest measurement state of the mapped targets on devices the user may see.
 */
final class ViewPage {
	public function __construct(
		private readonly TargetRepository $targets,
		private readonly ObservationRepository $observations,
		private readonly FreshnessEvaluator $evaluator,
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

				$rows[] = $this->row($devices[$entry['host_id']] ?? '', $target, $location, $observation);
			}
		}

		Html::table(
			__('SmokePing Targets', 'smokeping'),
			[__('Device', 'smokeping'), __('Target', 'smokeping'), __('Location', 'smokeping'), __('Freshness', 'smokeping'), __('Condition', 'smokeping'), __('Loss', 'smokeping'), __('Median', 'smokeping'), __('Last Update', 'smokeping')],
			$rows,
			__('No SmokePing targets are mapped to devices you can view.', 'smokeping')
		);
	}

	/**
	 * @return list<string>
	 */
	private function row(string $device, Target $target, string $location, ?Observation $observation): array {
		$name = $location === '' ? __('Master', 'smokeping') : $location;

		if ($observation === null) {
			return [$device, $target->displayName(), $name, __('Never observed', 'smokeping'), __('Unknown', 'smokeping'), '', '', __('never', 'smokeping')];
		}

		return [
			$device,
			$target->displayName(),
			$name,
			$this->evaluator->freshness($observation)->value,
			$this->evaluator->condition($observation)->value,
			$observation->loss === null ? 'U' : sprintf('%d/%d', (int) $observation->loss, $observation->pings),
			$observation->median === null ? 'U' : sprintf('%.1f ms', $observation->median * 1000),
			Html::age($this->evaluator->age($observation)),
		];
	}
}
