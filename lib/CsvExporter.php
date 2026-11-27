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
	/** Target headers constant. */
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
		$encoded = [];

		foreach ($cells as $cell) {
			$encoded[] = self::encodeCell($cell, $neutralize);
		}

		return implode(',', $encoded) . "\r\n";
	}

	/**
	 * @param list<string> $headers
	 * @param list<list<string>> $rows
	 */
	public static function export(array $headers, array $rows): string {
		$lines = [self::encodeRowCells($headers, false)];

		foreach ($rows as $row) {
			$lines[] = self::encodeRowCells($row, true);
		}

		return implode("\r\n", $lines) . "\r\n";
	}

	/**
	 * Export view-page target state rows using SummaryValues fields.
	 *
	 * Encodes each row directly into the output line buffer without allocating
	 * an intermediate matrix of string rows.
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
		$lines = [self::encodeRowCells(self::TARGET_HEADERS, false)];

		foreach ($rows as $row) {
			$median = $row['median_ms'] === null ? '' : self::formatFloat($row['median_ms']);
			$loss   = $row['loss_pct'] === null ? '' : self::formatFloat($row['loss_pct']);
			$age    = $row['age_seconds'] === null ? '' : (string) $row['age_seconds'];

			$lines[] = self::encodeCell($row['path'])
				. ',' . self::encodeCell($row['name'])
				. ',' . self::encodeCell($row['host'])
				. ',' . self::encodeCell($row['probe'])
				. ',' . self::encodeCell($row['device'])
				. ',' . self::encodeCell($row['location'] === '' ? 'master' : $row['location'])
				. ',' . ($median !== '' && $median[0] === '-' ? "'" . $median : $median)
				. ',' . ($loss !== '' && $loss[0] === '-' ? "'" . $loss : $loss)
				. ',' . ($age !== '' && $age[0] === '-' ? "'" . $age : $age)
				. ',' . self::encodeCell($row['freshness'])
				. ',' . self::encodeCell($row['condition']);
		}

		return implode("\r\n", $lines) . "\r\n";
	}

	/**
	 * Encode a list of cells as a single comma-delimited line without trailing CRLF.
	 *
	 * @param list<string> $cells Row cells.
	 * @param bool $neutralize Whether to neutralize spreadsheet formula prefixes.
	 * @return string Encoded CSV line.
	 */
	private static function encodeRowCells(array $cells, bool $neutralize): string {
		$encoded = [];

		foreach ($cells as $cell) {
			$encoded[] = self::encodeCell($cell, $neutralize);
		}

		return implode(',', $encoded);
	}

	/**
	 * Format float.
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private static function formatFloat(float $value): string {
		$text = rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');

		return $text === '' || $text === '-0' ? '0' : $text;
	}
}
