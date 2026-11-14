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
 * Small output helpers for the page classes. Every value passes through
 * html_escape() here, so page classes never print raw data.
 */
final class Html {
	/**
	 * @param list<string> $headers
	 * @param list<list<string|RawHtml>> $rows plain text, escaped here, or RawHtml from link()
	 */
	public static function table(string $title, array $headers, array $rows, string $empty): void {
		html_start_box(html_escape($title), '100%', '', '3', 'center', '');

		print '<tr class="tableHeader">';

		foreach ($headers as $header) {
			print '<th class="left">' . html_escape($header) . '</th>';
		}

		print '</tr>';

		if ($rows === []) {
			print '<tr><td colspan="' . count($headers) . '"><em>' . html_escape($empty) . '</em></td></tr>';
		}

		foreach ($rows as $row) {
			print '<tr class="selectable tableRow">';

			foreach ($row as $cell) {
				print '<td>' . ($cell instanceof RawHtml ? $cell->html : html_escape($cell)) . '</td>';
			}

			print '</tr>';
		}

		html_end_box(false);
	}

	/**
	 * A link to a page of this plugin; the text and every query value are escaped.
	 *
	 * @param array<string, int|string> $query
	 */
	public static function link(string $file, array $query, string $text): RawHtml {
		return new RawHtml('<a class="linkEditMain" href="' . html_escape($file . '?' . http_build_query($query)) . '">' . html_escape($text) . '</a>');
	}

	public static function level(HealthLevel $level): string {
		return match ($level) {
			HealthLevel::Ok      => __('OK', 'smokeping'),
			HealthLevel::Warning => __('Warning', 'smokeping'),
			HealthLevel::Error   => __('Error', 'smokeping'),
			HealthLevel::Unknown => __('Unknown', 'smokeping'),
		};
	}

	public static function age(?int $seconds): string {
		return $seconds === null ? __('never', 'smokeping') : __('%d s ago', $seconds, 'smokeping');
	}
}
