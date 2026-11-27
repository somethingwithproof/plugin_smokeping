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
 * RFC 4180 CSV encoder with spreadsheet formula neutralization.
 *
 * Cells whose first character is =, +, -, @, tab, or carriage return are
 * prefixed with a single quote so spreadsheet applications open them as text
 * instead of evaluating formulas.
 */
final class CsvExporter {
	public const TARGET_HEADERS = [
		'path',
		'name',
		'host',
		'probe',
		'device',
		'location',
		'median_ms',
		'loss_pct',
		'age_seconds',
		'freshness',
		'condition',
	];

	/**
	 * Neutralize spreadsheet formula triggers on untrusted text fields.
	 */
	public static function neutralize(string $value): string {
		if ($value === '') {
			return '';
		}

		$first = $value[0];

		if ($first === '=' || $first === '+' || $first === '-' || $first === '@' || $first === "\t" || $first === "\r") {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Encode one cell per RFC 4180, applying formula neutralization first.
	 */
	public static function encodeCell(string $value, bool $neutralize = true): string {
		$cell = $neutralize ? self::neutralize($value) : $value;

		if (strpbrk($cell, ",\"\r\n") !== false) {
			return '"' . str_replace('"', '""', $cell) . '"';
		}

		return $cell;
	}

	/**
	 * @param list<string> $cells
	 */
	public static function formatRow(array $cells, bool $neutralize = true): string {
		return implode(',', array_map(static fn (string $c): string => self::encodeCell($c, $neutralize), $cells)) . "\r\n";
	}

	/**
	 * @param list<string> $headers
	 * @param list<list<string>> $rows
	 */
	public static function export(array $headers, array $rows): string {
		$out = self::formatRow($headers, false);

		foreach ($rows as $row) {
			$out .= self::formatRow($row, true);
		}

		return $out;
	}

	/**
	 * Export view-page target state rows using SummaryValues fields.
	 *
	 * @param list<array{
	 *     path: string,
	 *     name: string,
	 *     host: string,
	 *     probe: string,
	 *     device: string,
	 *     location: string,
	 *     median_ms: ?float,
	 *     loss_pct: ?float,
	 *     age_seconds: ?int,
	 *     freshness: string,
	 *     condition: string
	 * }> $rows
	 */
	public static function exportTargets(array $rows): string {
		$csvRows = [];

		foreach ($rows as $row) {
			$csvRows[] = [
				$row['path'],
				$row['name'],
				$row['host'],
				$row['probe'],
				$row['device'],
				$row['location'] === '' ? 'master' : $row['location'],
				$row['median_ms'] === null ? '' : self::formatFloat($row['median_ms']),
				$row['loss_pct'] === null ? '' : self::formatFloat($row['loss_pct']),
				$row['age_seconds'] === null ? '' : (string) $row['age_seconds'],
				$row['freshness'],
				$row['condition'],
			];
		}

		return self::export(self::TARGET_HEADERS, $csvRows);
	}

	private static function formatFloat(float $value): string {
		$text = rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');

		return $text === '' || $text === '-0' ? '0' : $text;
	}
}
