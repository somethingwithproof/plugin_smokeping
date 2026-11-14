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

/*
 * Create RRDs with SmokePing's layout and known values for the graph fidelity
 * comparison. One file per ping count (10, 20, 30), step 300, four consecutive
 * segments of 36 rounds each:
 *
 *   0: normal            no loss, spread varies with the round number
 *   1: partial loss      1 to pings/2 pings lost per round, lost pings stored as U
 *   2: complete loss     every ping lost, median U, loss = pings
 *   3: missing data      no updates, so every data source is unknown
 *
 * The data are a deterministic function of the round and ping numbers.
 *
 *   php make-fixtures.php <directory>
 */

const T0      = 1700000100;
const STEP    = 300;
const SEGMENT = 36;

$dir = rtrim($argv[1] ?? '', '/');

if ($dir === '') {
	fwrite(STDERR, "usage: make-fixtures.php <directory>\n");
	exit(2);
}

if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
	fwrite(STDERR, "cannot create $dir\n");
	exit(1);
}

$rrd = popen('rrdtool - > /dev/null', 'w');

foreach (array(10, 20, 30) as $pings) {
	$file = "$dir/p$pings.rrd";
	$hb   = 2 * STEP;
	$ds   = array("DS:uptime:GAUGE:$hb:0:U", "DS:loss:GAUGE:$hb:0:$pings", "DS:median:GAUGE:$hb:0:U");

	for ($i = 1; $i <= $pings; $i++) {
		$ds[] = "DS:ping$i:GAUGE:$hb:0:U";
	}

	// The default [Database] table in SmokePing's config.
	$rra = 'RRA:AVERAGE:0.5:1:1008 RRA:AVERAGE:0.5:12:4320 RRA:MIN:0.5:12:4320 RRA:MAX:0.5:12:4320 RRA:AVERAGE:0.5:144:720 RRA:MAX:0.5:144:720 RRA:MIN:0.5:144:720';

	fwrite($rrd, 'create ' . $file . ' --step ' . STEP . ' --start ' . (T0 - STEP) . ' ' . implode(' ', $ds) . " $rra\n");

	// Segments 0 to 2 carry data; segment 3 is left without updates.
	for ($round = 0; $round < SEGMENT * 3; $round++) {
		$segment = intdiv($round, SEGMENT);
		$lost    = 0;

		if ($segment === 1) {
			$lost = 1 + ($round % intdiv($pings, 2));
		} elseif ($segment === 2) {
			$lost = $pings;
		}

		$received = $pings - $lost;
		$base     = 0.010 + 0.0002 * ($round % 9);
		$spread   = 0.0001 * (1 + ($round * 7) % 5);
		$values   = array();

		for ($i = 1; $i <= $pings; $i++) {
			// Sorted ascending in i, as SmokePing stores them; lost pings are U.
			$values[] = $i <= $received ? sprintf('%.7f', $base + $spread * $i + (($round * $i) % 3) * 0.00001) : 'U';
		}

		$median = $received > 0 ? $values[intdiv($received, 2)] : 'U';

		fwrite($rrd, 'update ' . $file . ' ' . (T0 + $round * STEP) . ':' . (3600 + $round * STEP) . ":$lost:$median:" . implode(':', $values) . "\n");
	}
}

pclose($rrd);
