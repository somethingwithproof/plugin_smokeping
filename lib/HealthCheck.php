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
 * Health findings for the reader and inventory of one poller.
 */
final class HealthCheck {
	public function __construct(private readonly Clock $clock, private readonly int $pollerInterval) {
	}

	/**
	 * @return list<Finding>
	 */
	public function check(Health $health, string $phpBinary): array {
		return [
			self::phpBinary($phpBinary),
			$this->reader($health),
			$this->inventory($health),
		];
	}

	/**
	 * Which loss colors the Loss Colors setting selects and why (LossColors),
	 * then how many smoke graphs still draw the other colors: those the main
	 * poller has yet to move, and those whose move failed, which wait for
	 * upgrade-graphs (SmokeProvisioner::restyleDue()).
	 *
	 * @param string $setting the Loss Colors setting
	 * @param string $version the SmokePing version the reader found, '' for none
	 * @param int    $pending graphs on the other colors' templates, not failed
	 * @param int    $failed  graphs on the other colors' templates whose move failed
	 */
	public static function lossColors(string $setting, string $version, int $pending = 0, int $failed = 0): Finding {
		$selected = self::lossColorsSelected($setting, $version);
		$message  = $selected->message;
		$level    = $selected->level;

		if ($pending > 0) {
			$message .= ' ' . __('%d smoke graphs still draw the other colors; the main poller moves up to 200 per cycle.', $pending, 'smokeping');
		}

		if ($failed > 0) {
			$message .= ' ' . __('%d smoke graphs could not be moved; run upgrade-graphs to try them again, and see the Cacti log for the cause.', $failed, 'smokeping');
			$level    = $level === HealthLevel::Ok || $level === HealthLevel::Unknown ? HealthLevel::Warning : $level;
		}

		return new Finding('loss_colors', $level, $message);
	}

	private static function lossColorsSelected(string $setting, string $version): Finding {
		$colors   = LossColors::resolve($setting, $version);
		$detected = LossColors::ofVersion($version);
		$shown    = LossColors::displayVersion($version);

		// The setting, not the graphs: after a change the main poller moves
		// graphs to these colors over the next cycles (restyleDue()).
		if (LossColors::tryFrom($setting) !== null) {
			if ($detected !== null && $detected !== $colors) {
				return new Finding('loss_colors', HealthLevel::Warning, __(
					'The Loss Colors setting asks for SmokePing %s loss colors, but the reader found SmokePing %s. Set it to Automatic unless the graphs should differ from SmokePing\'s.',
					$colors->value,
					$shown,
					'smokeping'
				));
			}

			return new Finding('loss_colors', HealthLevel::Ok, __('The Loss Colors setting selects SmokePing %s loss colors for smoke graphs.', $colors->value, 'smokeping'));
		}

		if ($detected === null) {
			return new Finding('loss_colors', HealthLevel::Unknown, __(
				'The reader found no SmokePing version, so the Loss Colors setting selects the loss colors of SmokePing 2.8 and later. If SmokePing is 2.7, set Loss Colors to SmokePing 2.7.',
				'smokeping'
			));
		}

		return new Finding('loss_colors', HealthLevel::Ok, __('The reader found SmokePing %s, so the Loss Colors setting selects its loss colors.', $shown, 'smokeping'));
	}

	/**
	 * Targets that alert in SmokePing and in Thold may notify twice for one
	 * problem, when both alerts' conditions hold (docs/alerting.md).
	 *
	 * @param list<string>|null $paths from CactiObjectRepository::dualAlerting(); null without Thold
	 */
	public static function dualAlerting(?array $paths): Finding {
		if ($paths === null) {
			return new Finding('dual_alerting', HealthLevel::Ok, __('Thold is not installed, so no target alerts in both SmokePing and Thold.', 'smokeping'));
		}

		if ($paths === []) {
			return new Finding('dual_alerting', HealthLevel::Ok, __('No target has both a SmokePing alert and an enabled Thold threshold.', 'smokeping'));
		}

		return new Finding('dual_alerting', HealthLevel::Warning, __('%d targets have both a SmokePing alert and an enabled Thold threshold, so one problem may notify twice. Compare their alert conditions before removing either: %s', count($paths), implode(', ', array_slice($paths, 0, 10)) . (count($paths) > 10 ? ', ...' : ''), 'smokeping'));
	}

	/**
	 * Orphan RRD files found under the SmokePing mount that no target owns.
	 * Read-only report; files are never deleted or modified.
	 *
	 * @param array{orphans: list<array{relative: string, mtime: int}>, truncated: bool, scanned: int}|null $scan
	 * @param list<array{id: int, old_id: int, old_path: string, new_path: string, host: string, probe: string, proposed_at: int}> $proposals
	 */
	public static function orphanRrds(?array $scan, array $proposals = []): Finding {
		if ($scan === null) {
			return new Finding('orphan_rrds', HealthLevel::Unknown, 'Orphan RRD scan runs only on the SmokePing reader poller.');
		}

		$orphans = $scan['orphans'];

		if ($orphans === []) {
			$note = $scan['truncated'] ? ' (scan budget reached)' : '';

			return new Finding('orphan_rrds', HealthLevel::Ok, sprintf('No unowned RRD files found (%d RRD files scanned%s).', $scan['scanned'], $note));
		}

		$proposalByRrd = [];

		foreach ($proposals as $proposal) {
			$proposalByRrd[$proposal['old_path'] . '.rrd'] = $proposal['id'];
		}

		$listed = [];

		foreach (array_slice($orphans, 0, 10) as $orphan) {
			$updated = $orphan['mtime'] > 0 ? gmdate('Y-m-d H:i:s', $orphan['mtime']) . ' UTC' : 'unknown';
			$suffix  = isset($proposalByRrd[$orphan['relative']]) ? sprintf(' [re-link proposal #%d]', $proposalByRrd[$orphan['relative']]) : '';
			$listed[] = sprintf('%s (updated %s)%s', $orphan['relative'], $updated, $suffix);
		}

		$more  = count($orphans) > 10 ? ', ...' : '';
		$trunc = $scan['truncated'] ? ' (scan budget reached)' : '';

		return new Finding(
			'orphan_rrds',
			HealthLevel::Warning,
			sprintf('%d unowned RRD file(s) under the mount%s: %s%s', count($orphans), $trunc, implode(', ', $listed), $more)
		);
	}

	public static function templates(bool $present, ?TemplateFailure $failure): Finding {
		if ($failure !== null) {
			return new Finding('templates', HealthLevel::Error, __('The SmokePing templates failed to import and are not retried automatically: %s Use Retry Template Import after fixing the cause.', $failure->message, 'smokeping'));
		}

		if (!$present) {
			return new Finding('templates', HealthLevel::Warning, __('The SmokePing templates are not imported. Use Retry Template Import.', 'smokeping'));
		}

		return new Finding('templates', HealthLevel::Ok, __('The SmokePing templates are imported.', 'smokeping'));
	}

	/**
	 * @param list<string> $devices devices with summary data sources polled by another poller
	 */
	public static function devicePollers(array $devices, int $readerPollerId): Finding {
		if ($devices === []) {
			return new Finding('device_pollers', HealthLevel::Ok, __('Every device with summary data sources is polled by the reader\'s poller.', 'smokeping'));
		}

		return new Finding('device_pollers', HealthLevel::Error, __('%d devices have summary data sources but are not polled by poller %d, which runs the reader, so their summary values and alerts have no data: %s', count($devices), $readerPollerId, implode(', ', array_slice($devices, 0, 5)), 'smokeping'));
	}

	/**
	 * Read the SmokePing configuration with the plugin's parser, as the reader
	 * will. Only meaningful on the host of the reader's poller, which is the
	 * one that can see the file.
	 */
	public static function configFile(string $file, bool $readerHost, int $readerPollerId): Finding {
		if (!$readerHost) {
			return new Finding('config_file', HealthLevel::Unknown, __('The SmokePing configuration is read on poller %d, not on this server; the inventory row shows the result of its last read.', $readerPollerId, 'smokeping'));
		}

		try {
			$inventory = (new SmokepingConfigReader($file))->read($readerPollerId);
		} catch (InventoryError $e) {
			return new Finding('config_file', HealthLevel::Error, __('The SmokePing configuration %s cannot be read: %s', $file, $e->getMessage(), 'smokeping'));
		}

		if ($inventory->warnings !== []) {
			return new Finding('config_file', HealthLevel::Warning, __('The SmokePing configuration %s lists %d targets; %d entries are skipped: %s', $file, count($inventory->targets), count($inventory->warnings), implode('; ', array_slice($inventory->warnings, 0, 5)), 'smokeping'));
		}

		return new Finding('config_file', HealthLevel::Ok, __('The SmokePing configuration %s lists %d targets.', $file, count($inventory->targets), 'smokeping'));
	}

	public static function phpBinary(string $phpBinary): Finding {
		if ($phpBinary === '' || !is_file($phpBinary) || !is_executable($phpBinary)) {
			return new Finding('php_binary', HealthLevel::Error, __('The PHP binary path setting is empty or not an executable file, so the reader cannot start.', 'smokeping'));
		}

		return new Finding('php_binary', HealthLevel::Ok, __('The reader starts with %s.', $phpBinary, 'smokeping'));
	}

	private function reader(Health $health): Finding {
		if ($health->runFinished === 0) {
			return new Finding('reader', HealthLevel::Unknown, __('The reader has not completed a run on this poller.', 'smokeping'));
		}

		$age = $this->clock->now() - $health->runFinished;

		if ($age > 3 * $this->pollerInterval) {
			return new Finding('reader', HealthLevel::Warning, __('The last reader run finished %d seconds ago, more than three poller intervals.', $age, 'smokeping'));
		}

		if ($health->budgetOverruns > 0) {
			return new Finding('reader', HealthLevel::Warning, __('The reader read %d of %d series in %.1f seconds and has exceeded its time budget %d times.', $health->seriesRead, $health->seriesTotal, $health->runDuration, $health->budgetOverruns, 'smokeping'));
		}

		return new Finding('reader', HealthLevel::Ok, __('The reader read %d of %d series in %.1f seconds, %d seconds ago.', $health->seriesRead, $health->seriesTotal, $health->runDuration, $age, 'smokeping'));
	}

	private function inventory(Health $health): Finding {
		return match ($health->inventoryStatus) {
			InventoryStatus::Never => new Finding('inventory', HealthLevel::Unknown, __('No inventory has been read on this poller.', 'smokeping')),
			InventoryStatus::Error => new Finding('inventory', HealthLevel::Error, __('The last inventory failed %d seconds ago and the previous one is still in use: %s', $this->clock->now() - $health->inventoryAt, $health->inventoryError, 'smokeping')),
			InventoryStatus::Ok    => new Finding('inventory', HealthLevel::Ok, __('The inventory listed %d targets %d seconds ago.', $health->inventoryTargets, $this->clock->now() - $health->inventoryAt, 'smokeping')),
		};
	}
}
