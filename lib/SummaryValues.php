<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

namespace Cacti\Smokeping;

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
