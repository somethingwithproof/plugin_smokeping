<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

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
	public function __construct(private readonly ObservationRepository $observations, private readonly SummaryCalculator $calculator) {
	}

	public function run(string $series): string {
		return $this->values($series)->format();
	}

	public function values(string $series): SummaryValues {
		$key = self::parse($series);

		if ($key === null) {
			return SummaryValues::unknown();
		}

		$row = $this->observations->latest($key['target'], $key['location']);

		return $row === null ? SummaryValues::unknown() : $this->calculator->compute($row['observation'], $row['firstSeen']);
	}

	/**
	 * @return array{target: int, location: string}|null
	 */
	public static function parse(string $series): ?array {
		if (preg_match('/^([1-9][0-9]{0,9})~([-_.0-9a-zA-Z]{0,64})$/', $series, $match) !== 1) {
			return null;
		}

		return ['target' => (int) $match[1], 'location' => $match[2]];
	}

	public static function series(int $targetId, string $location): string {
		return $targetId . '~' . $location;
	}
}
