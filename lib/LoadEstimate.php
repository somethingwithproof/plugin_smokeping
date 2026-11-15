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
 * What adding a set of SmokePing targets to Cacti costs, from the targets'
 * own settings, for the confirm screen of a bulk add. It is arithmetic on
 * values already in the database: it reads no RRD file and runs no probe.
 *
 * Per series (one target at one location): pings / step packets per second
 * leave SmokePing, one RRD update per step lands in SmokePing's file, and
 * the smoke data source has one RRD data source per ping plus uptime, loss,
 * and median, and its graph has pings + 30 items (the shipped templates: one
 * per ping, the smoke and loss bands, the median, the legend and the probe
 * line; 50 for 20 pings). Each target also gets a summary data source of three values and a
 * summary graph of five items. LoadEstimateTest counts the items in
 * templates/smokeping-templates.xml, so a template change fails it.
 */
final readonly class LoadEstimate {
	/** Graph items in a smoke graph beyond one per ping. */
	public const SMOKE_ITEMS_BEYOND_PINGS = 30;

	/** Graph items in a summary graph. */
	public const SUMMARY_ITEMS = 5;

	public function __construct(
		public int $series,
		public float $packetsPerSecond,
		public float $rrdUpdatesPerSecond,
		public int $dataSourceRows,
		public int $graphItemRows,
	) {
	}

	/**
	 * @param list<Target> $targets
	 */
	public static function forTargets(array $targets): self {
		$series  = 0;
		$packets = 0.0;
		$updates = 0.0;
		$rows    = 0;
		$items   = 0;

		foreach ($targets as $target) {
			$locations = count($target->locations());
			$series   += $locations;

			if ($target->step > 0) {
				$packets += $locations * $target->pings / $target->step;
				$updates += $locations / $target->step;
			}

			// The smoke data source and graph exist for 10, 20, and 30 pings only.
			if (in_array($target->pings, [10, 20, 30], true)) {
				$rows  += $locations * ($target->pings + 3);
				$items += $locations * ($target->pings + self::SMOKE_ITEMS_BEYOND_PINGS);
			}

			$rows  += 3;
			$items += self::SUMMARY_ITEMS;
		}

		return new self($series, $packets, $updates, $rows, $items);
	}

	/**
	 * @return list<string>
	 */
	public function lines(): array {
		return [
			__('%d series: about %.1f probe packets per second and %.2f RRD updates per second in SmokePing.', $this->series, $this->packetsPerSecond, $this->rrdUpdatesPerSecond, 'smokeping'),
			__('Cacti gets about %d data source rows and %d graph item rows.', $this->dataSourceRows, $this->graphItemRows, 'smokeping'),
		];
	}
}
