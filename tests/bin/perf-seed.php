<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

/*
 * Seed N SmokePing targets (20 pings, 300 s step) in groups of 100 sections,
 * all present and all mapped to one device, for the performance runs.
 *
 *   php perf-seed.php <count>
 *
 * Disposable databases only. Prints the device id.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/api_device.php');

if (getenv('SMOKEPING_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set SMOKEPING_TEST_DATABASE=disposable\n");
	exit(2);
}

$count = (int) $argv[1];
$host  = (int) api_device_save(0, 0, 'perf device', 'perf.example', '', 0, '', '', 161, 500, '', AVAIL_NONE, 0, 23, 400, 1, '', 'MD5', '', 'DES', '', '', 5, 1, 1);

for ($from = 0; $from < $count; $from += 500) {
	$rows   = array();
	$params = array();

	for ($i = $from; $i < min($count, $from + 500); $i++) {
		$rows[]   = "(1, ?, 'perf.example', 'FPing', ?, 300, 20, 1, 1, 1)";
		$params[] = sprintf('Perf/g%d/t%d', intdiv($i, 100), $i);
		$params[] = 'Target ' . $i;
	}

	db_execute_prepared('INSERT INTO plugin_smokeping_target (poller_id, path, host, probe, title, step, pings, present, first_seen, last_seen) VALUES ' . implode(', ', $rows), $params);
}

db_execute_prepared('INSERT INTO plugin_smokeping_mapping (target_id, host_id, mapped_by, mapped_at) SELECT id, ?, 0, 1 FROM plugin_smokeping_target', array($host));

print $host . PHP_EOL;
