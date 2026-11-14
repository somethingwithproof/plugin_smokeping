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

/*
 * The PHP side of the differential parser tests: parse each configuration
 * named in a list with the plugin's parser and print one JSON object per
 * line, in the oracle's format (see tests/oracle/smokeping-inventory.pl).
 *
 *   php tests/oracle/parse.php <list> [--time]
 *
 * --time adds the parse time in seconds to each line.
 */

require dirname(__DIR__, 2) . '/include/autoload.php';

use Cacti\Smokeping\SmokepingConfigError;
use Cacti\Smokeping\SmokepingConfigParser;
use Cacti\Smokeping\SmokepingInventory;

$list = $argv[1] ?? null;
$time = in_array('--time', $argv, true);

if ($list === null || !is_file($list)) {
	fwrite(STDERR, "usage: parse.php <list> [--time]\n");
	exit(2);
}

foreach (file($list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $file) {
	$start = hrtime(true);

	try {
		$root = (new SmokepingConfigParser())->parseFile($file);
		$line = ['config' => $file, 'inventory' => SmokepingInventory::document($root)];
	} catch (SmokepingConfigError $e) {
		$line = ['config' => $file, 'error' => 'ERROR: ' . $e->getMessage()];
	}

	if ($time) {
		$line['seconds'] = round((hrtime(true) - $start) / 1e9, 4);
	}

	echo json_encode($line, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
}
