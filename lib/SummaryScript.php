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
 * The script server input of the "SmokePing Summary" data source.
 *
 * The one argument names the series as "<target id>~<location>", the key of
 * the reader's cache. The answer comes from that one cache row and nothing
 * else: no RRD file, no SmokePing, no inventory. Anything malformed or absent
 * is unknown, never zero.
 */
final class SummaryScript {
	/**
	 * Create a new SummaryScript instance.
	 *
	 * @param ObservationRepository $observations Observations.
	 * @param SummaryCalculator $calculator Calculator.
	 */
	public function __construct(private readonly ObservationRepository $observations, private readonly SummaryCalculator $calculator) {
	}

	/**
	 * Run the script-server query for one series.
	 *
	 * Defaults to the backwards-compatible "median:.. loss_pct:.. age:.." output.
	 * Passing $extended = true appends jitter, quantile, and loss-burst fields.
	 *
	 * @param string $series Series key "<target id>~<location>".
	 * @param bool $extended Whether to emit extended summary fields.
	 * @return string
	 */
	public function run(string $series, bool $extended = false): string {
		if ($extended) {
			return $this->extendedValues($series)->format();
		}

		return $this->values($series)->format();
	}

	/**
	 * Values.
	 *
	 * @param string $series Series.
	 * @return SummaryValues
	 */
	public function values(string $series): SummaryValues {
		$key = self::parse($series);

		if ($key === null) {
			return SummaryValues::unknown();
		}

		$row = $this->observations->latest($key['target'], $key['location']);

		return $row === null ? SummaryValues::unknown() : $this->calculator->compute($row['observation'], $row['firstSeen']);
	}

	/**
	 * Extended summary values for one series, optionally enriched with round
	 * ping samples, recent round loss readings, or an aggregated DDSketch.
	 *
	 * @param string $series Series key "<target id>~<location>".
	 * @param list<float|int> $pingSamplesSeconds Optional round ping samples in seconds.
	 * @param list<float|null> $recentLosses Optional recent round loss values.
	 * @param DDSketch|null $sketch Optional latency DDSketch in milliseconds.
	 * @return ExtendedSummaryValues
	 */
	public function extendedValues(
		string $series,
		array $pingSamplesSeconds = [],
		array $recentLosses = [],
		?DDSketch $sketch = null,
	): ExtendedSummaryValues {
		$key = self::parse($series);

		if ($key === null) {
			return ExtendedSummaryValues::unknown();
		}

		$row = $this->observations->latest($key['target'], $key['location']);

		return $row === null
			? ExtendedSummaryValues::unknown()
			: $this->calculator->computeExtended($row['observation'], $row['firstSeen'], $pingSamplesSeconds, $recentLosses, $sketch);
	}

	/**
	 * @return array{target: int, location: string}|null
	 */
	public static function parse(string $series): ?array {
		if (preg_match('/^([1-9][0-9]{0,9})~([-_.0-9a-zA-Z]{0,64})$/D', $series, $match) !== 1) {
			return null;
		}

		return ['target' => (int) $match[1], 'location' => $match[2]];
	}

	/**
	 * Series.
	 *
	 * @param int $targetId Target id.
	 * @param string $location Location.
	 * @return string
	 */
	public static function series(int $targetId, string $location): string {
		return $targetId . '~' . $location;
	}
}
