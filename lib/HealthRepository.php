<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

final class HealthRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	public function forPoller(int $pollerId): Health {
		$row = db_fetch_row_prepared('SELECT * FROM plugin_smokeping_health WHERE poller_id = ?', [$pollerId], true, $this->connection);

		return is_array($row) && $row !== [] ? Health::fromRow($row) : Health::never($pollerId);
	}

	/**
	 * @return list<Health>
	 */
	public function all(): array {
		$rows = Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_smokeping_health ORDER BY poller_id', [], true, $this->connection));

		return array_map([Health::class, 'fromRow'], $rows);
	}

	public function recordRun(int $pollerId, int $started, int $finished, float $duration, int $total, int $read, bool $overrun): void {
		$this->ensureRow($pollerId);

		db_execute_prepared('UPDATE plugin_smokeping_health
			SET run_started = ?, run_finished = ?, run_duration = ?, series_total = ?, series_read = ?,
				budget_overruns = budget_overruns + ?, last_overrun = IF(? = 1, ?, last_overrun)
			WHERE poller_id = ?',
			[$started, $finished, $duration, $total, $read, (int) $overrun, (int) $overrun, $finished, $pollerId], true, $this->connection);
	}

	public function recordInventory(int $pollerId, int $at, InventoryStatus $status, string $error, int $targets): void {
		$this->ensureRow($pollerId);

		// A failed run keeps the previous target count: the stored inventory is
		// still the one from the last successful run.
		db_execute_prepared('UPDATE plugin_smokeping_health
			SET inventory_at = ?, inventory_status = ?, inventory_error = ?,
				inventory_targets = IF(? = 1, ?, inventory_targets)
			WHERE poller_id = ?',
			[$at, $status->value, mb_substr($error, 0, 1024), (int) ($status === InventoryStatus::Ok), $targets, $pollerId], true, $this->connection);
	}

	private function ensureRow(int $pollerId): void {
		db_execute_prepared('INSERT IGNORE INTO plugin_smokeping_health (poller_id) VALUES (?)', [$pollerId], true, $this->connection);
	}
}
