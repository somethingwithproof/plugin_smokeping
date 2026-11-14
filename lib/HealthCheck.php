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
			// Needs SmokePing alert data and Thold thresholds on plugin-created data
			// sources, which do not exist before milestone 3.
			new Finding('dual_alerting', HealthLevel::Unknown, __('Targets alerting in both SmokePing and Thold cannot be listed until SmokePing data sources exist.', 'smokeping')),
		];
	}

	public static function phpBinary(string $phpBinary): Finding {
		if ($phpBinary === '' || !is_file($phpBinary) || !is_executable($phpBinary)) {
			return new Finding('php_binary', HealthLevel::Error, __('The PHP binary path setting is empty or not an executable file, so the reader cannot start.', 'smokeping'));
		}

		return new Finding('php_binary', HealthLevel::Ok, sprintf(__('The reader starts with %s.', 'smokeping'), $phpBinary));
	}

	private function reader(Health $health): Finding {
		if ($health->runFinished === 0) {
			return new Finding('reader', HealthLevel::Unknown, __('The reader has not completed a run on this poller.', 'smokeping'));
		}

		$age = $this->clock->now() - $health->runFinished;

		if ($age > 3 * $this->pollerInterval) {
			return new Finding('reader', HealthLevel::Warning, sprintf(__('The last reader run finished %d seconds ago, more than three poller intervals.', 'smokeping'), $age));
		}

		if ($health->budgetOverruns > 0) {
			return new Finding('reader', HealthLevel::Warning, sprintf(__('The reader read %1$d of %2$d series in %3$.1f seconds and has exceeded its time budget %4$d times.', 'smokeping'), $health->seriesRead, $health->seriesTotal, $health->runDuration, $health->budgetOverruns));
		}

		return new Finding('reader', HealthLevel::Ok, sprintf(__('The reader read %1$d of %2$d series in %3$.1f seconds, %4$d seconds ago.', 'smokeping'), $health->seriesRead, $health->seriesTotal, $health->runDuration, $age));
	}

	private function inventory(Health $health): Finding {
		return match ($health->inventoryStatus) {
			InventoryStatus::Never => new Finding('inventory', HealthLevel::Unknown, __('No inventory has been read on this poller.', 'smokeping')),
			InventoryStatus::Error => new Finding('inventory', HealthLevel::Error, sprintf(__('The last inventory failed %1$d seconds ago and the previous one is still in use: %2$s', 'smokeping'), $this->clock->now() - $health->inventoryAt, $health->inventoryError)),
			InventoryStatus::Ok    => new Finding('inventory', HealthLevel::Ok, sprintf(__('The inventory listed %1$d targets %2$d seconds ago.', 'smokeping'), $health->inventoryTargets, $this->clock->now() - $health->inventoryAt)),
		};
	}
}
