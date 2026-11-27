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

final class HealthPage {
	private readonly OrphanRrdScanner $orphanScanner;

	public function __construct(
		private readonly HealthCheck $checks,
		private readonly MountCheck $mount,
		private readonly HealthRepository $health,
		private readonly TargetRepository $targets,
		private readonly TemplateInstaller $templates,
		private readonly CactiObjectRepository $cacti,
		?OrphanRrdScanner $orphanScanner = null,
	) {
		$this->orphanScanner = $orphanScanner ?? new OrphanRrdScanner();
	}

	/**
	 * The loss_colors row, with the smoke graphs still on the other colors.
	 */
	private function lossColors(string $smokepingVersion): Finding {
		$setting = (string) read_config_option(LossColors::SETTING);
		$left    = $this->cacti->graphsOnOtherLossColors(LossColors::resolve($setting, $smokepingVersion));

		return HealthCheck::lossColors($setting, $smokepingVersion, $left['pending'], $left['failed']);
	}

	public function render(Settings $settings, string $rraPath, string $phpBinary, bool $readerHost): void {
		$health   = $this->health->forPoller($settings->pollerId);
		$expected = [];
		$readable = $this->targets->readable($settings->pollerId);

		foreach ($readable as $target) {
			foreach ($target->locations() as $location) {
				$expected[] = $target->path->rrdFile($settings->mountPath, $location);
			}
		}

		$orphanScan = null;

		if ($readerHost && read_config_option('smokeping_check_orphan_rrds') !== 'off' && MountCheck::withinRra($settings->mountPath, $rraPath) === null && is_dir($settings->mountPath)) {
			$orphanScan = $this->orphanScanner->scan($settings->mountPath, $expected);
		}

		$dualPaths = $this->cacti->dualAlerting($settings->pollerId);
		$proposals = $this->targets->openRelinkProposals();

		$findings = array_merge(
			$this->checks->check($health, $phpBinary),
			[HealthCheck::configFile($settings->configFile, $readerHost, $settings->pollerId)],
			[HealthCheck::templates($this->templates->present(), $this->templates->failure())],
			[HealthCheck::devicePollers($this->cacti->summaryDevicesOffPoller($settings->pollerId), $settings->pollerId)],
			[HealthCheck::dualAlerting($dualPaths)],
			[$this->lossColors($health->smokepingVersion)],
			$this->mount->check($settings->mountPath, $rraPath, $expected),
			[HealthCheck::orphanRrds($orphanScan, $proposals)]
		);

		Html::table(
			__('SmokePing Health for Poller %d', $settings->pollerId, 'smokeping'),
			[__('Check', 'smokeping'), __('Status', 'smokeping'), __('Detail', 'smokeping')],
			array_map(fn (Finding $f) => [$f->check, Html::level($f->level), $f->message], $findings),
			''
		);

		$alertTargets = array_values(array_filter($readable, static fn (Target $t): bool => trim($t->alerts) !== ''));

		if ($alertTargets !== []) {
			$alertRows = [];

			foreach (array_slice($alertTargets, 0, 50) as $target) {
				$alertRows[] = [
					$target->path->value,
					$target->alerts,
					Html::link('smokeping_health.php', ['action' => 'thold_preview', 'target' => $target->path->value], 'Convert to Thold'),
				];
			}

			Html::table(
				'SmokePing Alerts -> Thold Threshold Conversion',
				['Path', 'SmokePing Alerts', 'Action'],
				$alertRows,
				''
			);
		}

		print '<form method="post" action="smokeping_health.php"><input type="hidden" name="action" value="retry_templates"><input type="submit" class="ui-button ui-corner-all ui-widget" value="' . html_escape(__('Retry Template Import', 'smokeping')) . '"></form>';
	}

	/**
	 * Side-by-side confirmation screen comparing SmokePing alert pattern(s)
	 * against the equivalent Thold threshold before any write occurs.
	 *
	 * @param array{
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
	 * } $plan
	 */
	public function renderTholdPreview(array $plan): void {
		$rows       = [];
		$anySupport = false;

		foreach ($plan['translations'] as $item) {
			if ($item['supported'] && isset($item['thold_hi'], $item['thold_fail_trigger'])) {
				$anySupport = true;
				$tholdDesc  = sprintf('loss_pct > %g%% for %d consecutive poll(s)', $item['thold_hi'], $item['thold_fail_trigger']);
			} else {
				$tholdDesc = 'Unsupported: ' . $item['reason'];
			}

			$rows[] = [
				$item['alert_name'],
				sprintf('type=%s pattern=%s', $item['smokeping_type'] !== '' ? $item['smokeping_type'] : 'unknown', $item['smokeping_pattern']),
				$tholdDesc,
				$item['supported'] ? 'Supported' : 'Skipped',
			];
		}

		Html::table(
			'Convert SmokePing Alerts to Thold: ' . $plan['target'],
			['Alert', 'SmokePing Condition', 'Equivalent Thold Threshold', __('Status', 'smokeping')],
			$rows,
			'No SmokePing alerts configured for this target.'
		);

		print '<p>' . html_escape('SmokePing configuration files are never modified (observe mode). Retire the SmokePing alert manually after confirming the Thold threshold.') . '</p>';

		if ($anySupport && $plan['summary_sources'] !== []) {
			form_start('smokeping_health.php', 'smokeping_thold_create');
			print '<input type="hidden" name="action" value="thold_create">';
			print '<input type="hidden" name="target" value="' . html_escape($plan['target']) . '">';
			print '<input type="button" class="ui-button ui-corner-all ui-widget cactiReturnTo" value="Cancel"> ';
			print '<input type="submit" class="ui-button ui-corner-all ui-widget" value="Create Thold Threshold(s)">';
			form_end();
		} else {
			print '<p><a class="ui-button ui-corner-all ui-widget" href="smokeping_health.php">Back</a></p>';
		}
	}
}
