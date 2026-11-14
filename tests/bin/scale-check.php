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
 * Checks each smoke graph's upper limit against rrdtool's own reading of its
 * file: 1.2 times the largest median of the last day, or the template's 0.05
 * when the day has no median. Run from the Cacti root.
 *
 *   php plugins/smokeping/tests/bin/scale-check.php [graph id ...]
 *
 * Prints "<graph> <limit> <expected>" per graph and a last line
 * "sized N kept N wrong N". The data moves while a suite runs, so a limit
 * within 5 percent of the expected value counts as right.
 */

chdir(getcwd());
include('./include/cli_check.php');

$ids  = array_map('intval', array_slice($argv, 1));
$rows = db_fetch_assoc("SELECT o.local_graph_id, o.rrd_path, g.upper_limit
	FROM plugin_smokeping_object o
	JOIN graph_templates_graph g ON g.local_graph_id = o.local_graph_id
	WHERE o.state IN ('active', 'archived')" . ($ids ? ' AND o.local_graph_id IN (' . implode(',', $ids) . ')' : '') . '
	ORDER BY o.local_graph_id');

$sized = $kept = $wrong = 0;

foreach ($rows as $row) {
	$file = rtrim($config['rra_path'], '/') . '/' . $row['rrd_path'];
	$out  = shell_exec('rrdtool graph /dev/null --start -86400 --end now ' . escapeshellarg('DEF:m=' . $file . ':median:AVERAGE') . ' VDEF:x=m,MAXIMUM PRINT:x:%le 2>&1');
	$last = trim((string) preg_replace('/^.*\n/s', '', trim((string) $out)));
	$max  = is_numeric($last) ? (float) $last : NAN;
	$have = (float) $row['upper_limit'];

	if (!is_finite($max) || $max <= 0) {
		$ok = $row['upper_limit'] === '0.05';
		$ok ? $kept++ : $wrong++;
		echo $row['local_graph_id'], ' ', $row['upper_limit'], " 0.05\n";
	} else {
		$want = 1.2 * $max;
		$ok   = abs($have - $want) <= 0.05 * $want;
		$ok ? $sized++ : $wrong++;
		echo $row['local_graph_id'], ' ', $row['upper_limit'], ' ', sprintf('%.9F', $want), "\n";
	}
}

echo "sized $sized kept $kept wrong $wrong\n";
