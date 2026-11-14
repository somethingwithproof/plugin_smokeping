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
 * Output of `rrdtool lastupdate` for one file.
 */
final readonly class RrdLastUpdate {
	/**
	 * @param array<string, ?float> $values data source => value, null when U
	 */
	public function __construct(public int $time, public array $values) {
	}

	/**
	 * Parse rrdtool's text output: a header line of data source names, a blank
	 * line, then "<time>: <value> ...".
	 */
	public static function parse(string $output): self {
		$lines = array_values(array_filter(array_map('trim', explode("\n", $output)), fn (string $line) => $line !== ''));

		if (count($lines) < 2 || preg_match('/^(\d+):\s*(.*)$/', $lines[1], $match) !== 1) {
			throw new \UnexpectedValueException('Unrecognized rrdtool lastupdate output');
		}

		$names  = preg_split('/\s+/', $lines[0]) ?: [];
		$values = preg_split('/\s+/', trim($match[2])) ?: [];

		if (count($names) !== count($values)) {
			throw new \UnexpectedValueException('rrdtool lastupdate returned mismatched columns');
		}

		$parsed = [];

		foreach ($names as $i => $name) {
			$parsed[$name] = Row::float($values, (string) $i);
		}

		return new self((int) $match[1], $parsed);
	}

	public function value(string $name): ?float {
		return $this->values[$name] ?? null;
	}

	/**
	 * True when every data source is unknown, which is what a freshly created
	 * SmokePing RRD reports before its first update.
	 */
	public function allUnknown(): bool {
		return array_filter($this->values, fn (?float $value) => $value !== null) === [];
	}
}
