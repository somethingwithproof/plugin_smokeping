<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

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
