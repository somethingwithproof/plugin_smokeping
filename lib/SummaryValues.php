<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * The three values of the "SmokePing Summary" data source, as Cacti stores
 * them: median in milliseconds, loss as a percentage of the round's pings, and
 * the seconds since the RRD's last update. Null is unknown and prints as U.
 */
final readonly class SummaryValues {
	public function __construct(
		public ?float $median,
		public ?float $lossPct,
		public ?int $age,
	) {
	}

	public static function unknown(): self {
		return new self(null, null, null);
	}

	/**
	 * The script server answer: output field names with values, which Cacti
	 * maps to the data template's data sources.
	 */
	public function format(): string {
		return sprintf('median:%s loss_pct:%s age:%s', self::number($this->median), self::number($this->lossPct), $this->age === null ? 'U' : (string) $this->age);
	}

	private static function number(?float $value): string {
		if ($value === null) {
			return 'U';
		}

		// %F ignores the locale, so a decimal comma never reaches RRDtool.
		$text = rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');

		return $text === '' || $text === '-0' ? '0' : $text;
	}
}
