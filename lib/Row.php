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
 * Normalization rules for loose values read from Cacti's database.
 *
 * Only fromRow() constructors call these. Cacti stores most values as strings
 * and NULL, '' and malformed text all appear in real rows, so each rule names
 * what it does with them. tests/Unit/RowTest.php pins these choices; changing
 * one changes comparisons everywhere a value object is built.
 */
final class Row {
	/**
	 * Integer column. Missing, NULL, '', and anything that is not a whole
	 * decimal number (including '1.5', '1e3', and '12abc') yield $default, as
	 * does a value below $min.
	 *
	 * @param array<array-key, mixed> $row
	 */
	public static function int(array $row, string $key, int $default = 0, int $min = PHP_INT_MIN): int {
		$value = $row[$key] ?? null;

		if (is_int($value)) {
			$int = $value;
		} elseif (is_string($value) && preg_match('/^\s*-?\d{1,18}\s*$/', $value) === 1) {
			$int = (int) $value;
		} else {
			return $default;
		}

		return $int < $min ? $default : $int;
	}

	/**
	 * Measured value. NULL, '', rrdtool's 'U', 'nan', and other non-numeric
	 * text yield null (unknown). Zero stays zero: unknown and zero are
	 * different measurements.
	 *
	 * @param array<array-key, mixed> $row
	 */
	public static function float(array $row, string $key): ?float {
		$value = $row[$key] ?? null;

		if (is_int($value) || is_float($value)) {
			$float = (float) $value;
		} elseif (is_string($value) && is_numeric(trim($value))) {
			$float = (float) trim($value);
		} else {
			return null;
		}

		return is_finite($float) ? $float : null;
	}

	/**
	 * Text column. Missing and NULL yield ''; scalars are cast and trimmed;
	 * arrays and objects yield ''.
	 *
	 * @param array<array-key, mixed> $row
	 */
	public static function string(array $row, string $key): string {
		$value = $row[$key] ?? null;

		return is_scalar($value) ? trim((string) $value) : '';
	}

	/**
	 * Result of a db_fetch_assoc*() call, which returns false on error. An
	 * error reads as no rows, and non-array rows are dropped.
	 *
	 * @return list<array<array-key, mixed>>
	 */
	public static function rows(mixed $rows): array {
		return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
	}

	/**
	 * Flag column, including Cacti checkbox settings ('on' or ''). Only 'on',
	 * 'yes', 'true', '1', 1 and true are true; everything else is false.
	 *
	 * @param array<array-key, mixed> $row
	 */
	public static function bool(array $row, string $key): bool {
		$value = $row[$key] ?? null;

		if (is_bool($value)) {
			return $value;
		}

		if (is_int($value)) {
			return $value === 1;
		}

		return is_string($value) && in_array(strtolower(trim($value)), ['on', 'yes', 'true', '1'], true);
	}
}
