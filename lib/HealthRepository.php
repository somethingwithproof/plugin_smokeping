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

/** Health repository component. */
final class HealthRepository {
	/**
	 * Create a new HealthRepository instance.
	 *
	 * @param \PDO|false $connection Connection.
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * For poller.
	 *
	 * @param int $pollerId Poller id.
	 * @return Health
	 */
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

	/**
	 * Record run.
	 *
	 * @param int $pollerId Poller id.
	 * @param int $started Started.
	 * @param int $finished Finished.
	 * @param float $duration Duration.
	 * @param int $total Total.
	 * @param int $read Read.
	 * @param bool $overrun Overrun.
	 */
	public function recordRun(int $pollerId, int $started, int $finished, float $duration, int $total, int $read, bool $overrun): void {
		$this->ensureRow($pollerId);

		db_execute_prepared('UPDATE plugin_smokeping_health
			SET run_started = ?, run_finished = ?, run_duration = ?, series_total = ?, series_read = ?,
				budget_overruns = budget_overruns + ?, last_overrun = IF(? = 1, ?, last_overrun)
			WHERE poller_id = ?',
			[$started, $finished, $duration, $total, $read, (int) $overrun, (int) $overrun, $finished, $pollerId], true, $this->connection);
	}

	/**
	 * Record inventory.
	 *
	 * @param int $pollerId Poller id.
	 * @param int $at At.
	 * @param InventoryStatus $status Status.
	 * @param string $error Error.
	 * @param int $targets Targets.
	 * @param string $smokepingVersion Smokeping version.
	 */
	public function recordInventory(int $pollerId, int $at, InventoryStatus $status, string $error, int $targets, string $smokepingVersion = ''): void {
		$this->ensureRow($pollerId);

		// A failed run keeps the previous target count and SmokePing version:
		// the stored inventory is still the one from the last successful run.
		$ok = (int) ($status === InventoryStatus::Ok);

		db_execute_prepared('UPDATE plugin_smokeping_health
			SET inventory_at = ?, inventory_status = ?, inventory_error = ?,
				inventory_targets = IF(? = 1, ?, inventory_targets),
				smokeping_version = IF(? = 1, ?, smokeping_version)
			WHERE poller_id = ?',
			[$at, $status->value, mb_substr($error, 0, 1024), $ok, $targets, $ok, mb_substr($smokepingVersion, 0, 16), $pollerId], true, $this->connection);
	}

	/**
	 * Ensure row.
	 *
	 * @param int $pollerId Poller id.
	 */
	private function ensureRow(int $pollerId): void {
		db_execute_prepared('INSERT IGNORE INTO plugin_smokeping_health (poller_id) VALUES (?)', [$pollerId], true, $this->connection);
	}
}
