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
 * The three values of the "SmokePing Summary" data source, as Cacti stores
 * them: median in milliseconds, loss as a percentage of the round's pings, and
 * the seconds since the RRD's last update. Null is unknown and prints as U.
 */
final readonly class SummaryValues {
	/**
	 * Create a new SummaryValues instance.
	 *
	 * @param float|null $median Median.
	 * @param float|null $lossPct Loss pct.
	 * @param int|null $age Age.
	 */
	public function __construct(
		public ?float $median,
		public ?float $lossPct,
		public ?int $age,
	) {
	}

	/**
	 * Unknown.
	 *
	 * @return self
	 */
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

	/**
	 * Number.
	 *
	 * @param float|null $value Value.
	 * @return string
	 */
	private static function number(?float $value): string {
		if ($value === null) {
			return 'U';
		}

		// %F ignores the locale, so a decimal comma never reaches RRDtool.
		$text = rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');

		return $text === '' || $text === '-0' ? '0' : $text;
	}
}
