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
 * Small output helpers for the page classes. Every value passes through
 * html_escape() here, so page classes never print raw data.
 */
final class Html {
	/**
	 * @param list<string> $headers
	 * @param list<list<string>> $rows already plain text; escaped here
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
				print '<td>' . html_escape($cell) . '</td>';
			}

			print '</tr>';
		}

		html_end_box(false);
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
		return $seconds === null ? __('never', 'smokeping') : sprintf(__('%d s ago', 'smokeping'), $seconds);
	}
}
