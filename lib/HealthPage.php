<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

final class HealthPage {
	public function __construct(
		private readonly HealthCheck $checks,
		private readonly MountCheck $mount,
		private readonly HealthRepository $health,
		private readonly TargetRepository $targets,
	) {
	}

	public function render(Settings $settings, string $rraPath, string $phpBinary): void {
		$health   = $this->health->forPoller($settings->pollerId);
		$expected = [];

		foreach ($this->targets->readable($settings->pollerId) as $target) {
			foreach ($target->locations() as $location) {
				$expected[] = $target->path->rrdFile($settings->mountPath, $location);
			}
		}

		$findings = array_merge(
			$this->checks->check($health, $phpBinary),
			$this->mount->check($settings->mountPath, $rraPath, $expected)
		);

		Html::table(
			sprintf(__('SmokePing Health for Poller %d', 'smokeping'), $settings->pollerId),
			[__('Check', 'smokeping'), __('Status', 'smokeping'), __('Detail', 'smokeping')],
			array_map(fn (Finding $f) => [$f->check, Html::level($f->level), $f->message], $findings),
			''
		);
	}
}
