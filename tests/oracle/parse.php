<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

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

use Somethingwithproof\Smokeping\SmokepingConfigError;
use Somethingwithproof\Smokeping\SmokepingConfigParser;
use Somethingwithproof\Smokeping\SmokepingInventory;

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
