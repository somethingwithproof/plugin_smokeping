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
 * Time the summary script server input against a large reader cache.
 * Disposable databases only; it replaces the plugin's targets and cache.
 *
 *   php plugins/smokeping/tests/bin/summary-bench.php [rows] [calls]
 *
 * Fills the cache with <rows> series (default 10,000) in a mix of current,
 * delayed, stale, complete-loss, and never-observed states, then calls
 * ss_smokeping_summary() <calls> times (default 5,000) on random series in
 * one process, as the script server does, and prints the mean, 99th
 * percentile, and maximum in milliseconds.
 */

chdir(dirname(__DIR__, 4));

$called_by_script_server = true;

require('./include/cli_check.php');
require_once($config['base_path'] . '/plugins/smokeping/setup.php');
require_once($config['base_path'] . '/plugins/smokeping/scripts/ss_smokeping_summary.php');

if (getenv('SMOKEPING_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set SMOKEPING_TEST_DATABASE=disposable\n");
	exit(2);
}

$rows  = isset($argv[1]) ? (int) $argv[1] : 10000;
$calls = isset($argv[2]) ? (int) $argv[2] : 5000;
$now   = time();

db_execute('DELETE FROM plugin_smokeping_observation');
db_execute('DELETE FROM plugin_smokeping_target');

for ($i = 1; $i <= $rows; $i += 500) {
	$targets = array();
	$cache   = array();

	for ($id = $i; $id < min($i + 500, $rows + 1); $id++) {
		$targets[] = "($id, 1, 'Bench/t$id', 'host$id.example', 'FPing', 300, 20, 1, $now, $now)";

		switch ($id % 5) {
			case 0:
				$cache[] = "($id, '', $now - 20, $now, 300, 20, 20, NULL, 1)";

				break;
			case 1:
				$cache[] = "($id, '', $now - 450, $now, 300, 20, 2, 0.0123, 1)";

				break;
			case 2:
				$cache[] = "($id, '', $now - 4000, $now, 300, 20, 0, 0.01, 1)";

				break;
			case 3:
				$cache[] = "($id, '', 0, $now, 300, 20, NULL, NULL, 1)";

				break;
			default:
				$cache[] = "($id, '', $now - 5, $now, 300, 20, 0, 0.0111, 1)";
		}
	}

	db_execute('INSERT INTO plugin_smokeping_target (id, poller_id, path, host, probe, step, pings, present, first_seen, last_seen) VALUES ' . implode(', ', $targets));
	db_execute('INSERT INTO plugin_smokeping_observation (target_id, location, observed_at, ingested_at, expected_interval, pings, loss, median, file_present) VALUES ' . implode(', ', $cache));
}

$count = (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_smokeping_observation');
$times = array();

// One warm-up call loads the classes and the settings, as the first poll does.
ss_smokeping_summary('1~');

mt_srand(7);

for ($n = 0; $n < $calls; $n++) {
	$series = mt_rand(1, $rows) . '~';
	$start  = hrtime(true);
	$answer = ss_smokeping_summary($series);
	$times[] = (hrtime(true) - $start) / 1e6;
}

sort($times);

printf("cache rows %d, calls %d, mean %.3f ms, p99 %.3f ms, max %.3f ms\n", $count, $calls, array_sum($times) / count($times), $times[(int) (0.99 * (count($times) - 1))], end($times));
echo 'last answer: ' . $answer . PHP_EOL;
