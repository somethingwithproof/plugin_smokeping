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
 * Reader and inventory status of one poller.
 */
final readonly class Health {
	/**
	 * Create a new Health instance.
	 *
	 * @param int $pollerId Poller id.
	 * @param int $runStarted Run started.
	 * @param int $runFinished Run finished.
	 * @param float $runDuration Run duration.
	 * @param int $seriesTotal Series total.
	 * @param int $seriesRead Series read.
	 * @param int $budgetOverruns Budget overruns.
	 * @param int $lastOverrun Last overrun.
	 * @param int $inventoryAt Inventory at.
	 * @param InventoryStatus $inventoryStatus Inventory status.
	 * @param string $inventoryError Inventory error.
	 * @param int $inventoryTargets Inventory targets.
	 * @param string $smokepingVersion Smokeping version.
	 */
	public function __construct(
		public int $pollerId,
		public int $runStarted,
		public int $runFinished,
		public float $runDuration,
		public int $seriesTotal,
		public int $seriesRead,
		public int $budgetOverruns,
		public int $lastOverrun,
		public int $inventoryAt,
		public InventoryStatus $inventoryStatus,
		public string $inventoryError,
		public int $inventoryTargets,
		// The SmokePing version the reader found with the last inventory, such
		// as 2.007003; '' when it found none.
		public string $smokepingVersion = '',
	) {
	}

	/**
	 * Never.
	 *
	 * @param int $pollerId Poller id.
	 * @return self
	 */
	public static function never(int $pollerId): self {
		return new self($pollerId, 0, 0, 0.0, 0, 0, 0, 0, 0, InventoryStatus::Never, '', 0);
	}

	/**
	 * An unknown inventory status reads as never, not ok, so missing
	 * instrumentation is never shown as healthy.
	 *
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): self {
		return new self(
			pollerId: Row::int($row, 'poller_id', 0, 0),
			runStarted: Row::int($row, 'run_started', 0, 0),
			runFinished: Row::int($row, 'run_finished', 0, 0),
			runDuration: Row::float($row, 'run_duration') ?? 0.0,
			seriesTotal: Row::int($row, 'series_total', 0, 0),
			seriesRead: Row::int($row, 'series_read', 0, 0),
			budgetOverruns: Row::int($row, 'budget_overruns', 0, 0),
			lastOverrun: Row::int($row, 'last_overrun', 0, 0),
			inventoryAt: Row::int($row, 'inventory_at', 0, 0),
			inventoryStatus: InventoryStatus::tryFrom(Row::string($row, 'inventory_status')) ?? InventoryStatus::Never,
			inventoryError: Row::string($row, 'inventory_error'),
			inventoryTargets: Row::int($row, 'inventory_targets', 0, 0),
			smokepingVersion: Row::string($row, 'smokeping_version'),
		);
	}
}
