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
		private readonly TemplateInstaller $templates,
		private readonly CactiObjectRepository $cacti,
	) {
	}

	public function render(Settings $settings, string $rraPath, string $phpBinary, bool $readerHost): void {
		$health   = $this->health->forPoller($settings->pollerId);
		$expected = [];

		foreach ($this->targets->readable($settings->pollerId) as $target) {
			foreach ($target->locations() as $location) {
				$expected[] = $target->path->rrdFile($settings->mountPath, $location);
			}
		}

		$findings = array_merge(
			$this->checks->check($health, $phpBinary),
			[HealthCheck::configFile($settings->configFile, $readerHost, $settings->pollerId)],
			[HealthCheck::templates($this->templates->present(), $this->templates->failure())],
			[HealthCheck::devicePollers($this->cacti->summaryDevicesOffPoller($settings->pollerId), $settings->pollerId)],
			$this->mount->check($settings->mountPath, $rraPath, $expected)
		);

		Html::table(
			__('SmokePing Health for Poller %d', $settings->pollerId, 'smokeping'),
			[__('Check', 'smokeping'), __('Status', 'smokeping'), __('Detail', 'smokeping')],
			array_map(fn (Finding $f) => [$f->check, Html::level($f->level), $f->message], $findings),
			''
		);

		print '<form method="post" action="smokeping_health.php"><input type="hidden" name="action" value="retry_templates"><input type="submit" class="ui-button ui-corner-all ui-widget" value="' . html_escape(__('Retry Template Import', 'smokeping')) . '"></form>';
	}
}
