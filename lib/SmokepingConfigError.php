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
 * A SmokePing configuration file, or one of its includes, could not be read
 * or is not valid. $source and $sourceLine name the place the way SmokePing's
 * own parser does; $sourceLine is 0 when the problem has no single line,
 * such as a mandatory variable that is missing.
 */
final class SmokepingConfigError extends \RuntimeException {
	public function __construct(public readonly string $reason, public readonly string $source = '', public readonly int $sourceLine = 0, public readonly string $section = '') {
		parent::__construct(self::format($reason, $source, $sourceLine, $section));
	}

	private static function format(string $reason, string $file, int $line, string $section): string {
		if ($file === '') {
			return $reason;
		}

		if ($line > 0) {
			return sprintf('%s, line %d: %s', $file, $line, $reason);
		}

		return $section === '' ? sprintf('%s: %s', $file, $reason) : sprintf('%s (%s): %s', $file, $section, $reason);
	}
}
