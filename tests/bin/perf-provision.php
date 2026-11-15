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
 * Cacti objects for the performance runs, through the plugin's own
 * provisioner, the path an administrator's provision takes.
 *
 *   php perf-provision.php devices <count>  N targets (20 pings, 300 s step),
 *                                           each with its own device
 *   php perf-provision.php objects          the summary and smoke data source
 *                                           and graph for every mapped target
 *   php perf-provision.php stock <name>...  instead, one graph from each named
 *                                           stock Cacti graph template on every
 *                                           mapped device: the comparison for
 *                                           REQ-PERF-002
 *
 * Disposable databases only. Prints one line: the counts and the seconds.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/plugins/smokeping/setup.php');

if (getenv('SMOKEPING_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set SMOKEPING_TEST_DATABASE=disposable\n");
	exit(2);
}

smokeping_boot();

$settings    = smokeping_settings();
$targets     = new \Somethingwithproof\Smokeping\TargetRepository(false);
$provisioner = new \Somethingwithproof\Smokeping\SmokeProvisioner(
	$settings,
	$config['rra_path'],
	$config['library_path'],
	read_config_option('storage_location') != 0,
	new \Somethingwithproof\Smokeping\ObjectRepository(false),
	new \Somethingwithproof\Smokeping\CactiObjectRepository(false),
	new \Somethingwithproof\Smokeping\SystemClock(),
	smokeping_rrd_reader()
);

$start = microtime(true);
$done  = 0;
$fail  = 0;
$empty = array();

if (($argv[1] ?? '') === 'devices') {
	$count = (int) ($argv[2] ?? 0);

	for ($from = 0; $from < $count; $from += 500) {
		$rows   = array();
		$params = array();

		for ($i = $from; $i < min($count, $from + 500); $i++) {
			$rows[]   = "(?, ?, ?, 'FPing', ?, 300, 20, 1, 1, 1)";
			$params[] = $settings->pollerId;
			$params[] = sprintf('Perf/g%d/t%d', intdiv($i, 100), $i);
			$params[] = sprintf('t%d.perf.example', $i);
			$params[] = 'Target ' . $i;
		}

		db_execute_prepared('INSERT INTO plugin_smokeping_target (poller_id, path, host, probe, title, step, pings, present, first_seen, last_seen) VALUES ' . implode(', ', $rows), $params);
	}

	for ($i = 0; $i < $count; $i++) {
		$target = $targets->find($settings->pollerId, sprintf('Perf/g%d/t%d', intdiv($i, 100), $i));

		if ($target !== null && $provisioner->createDevice($target) > 0) {
			$done++;
		} else {
			$fail++;
		}
	}

	printf("devices=%d failed=%d seconds=%.1f\n", $done, $fail, microtime(true) - $start);
	exit($fail === 0 ? 0 : 1);
}

if (($argv[1] ?? '') === 'objects') {
	$mapped = db_fetch_assoc('SELECT t.path, m.host_id FROM plugin_smokeping_mapping AS m INNER JOIN plugin_smokeping_target AS t ON t.id = m.target_id ORDER BY t.id');

	foreach ($mapped as $row) {
		$target = $targets->find($settings->pollerId, $row['path']);

		if ($target === null) {
			$fail++;
			continue;
		}

		$summary = $provisioner->provisionSummary($target, (int) $row['host_id']);
		$smoke   = $provisioner->provision($target, (int) $row['host_id']);

		if ($summary->created && $smoke->created) {
			$done++;
		} else {
			$fail++;
		}
	}

	printf("targets=%d failed=%d seconds=%.1f\n", $done, $fail, microtime(true) - $start);
	exit($fail === 0 ? 0 : 1);
}

if (($argv[1] ?? '') === 'stock' && count($argv) > 2) {
	include_once($config['base_path'] . '/lib/template.php');
	include_once($config['base_path'] . '/lib/api_automation_tools.php');

	$templates = array();

	foreach (array_slice($argv, 2) as $name) {
		$id = (int) db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE name = ?', array($name));

		if ($id === 0) {
			fwrite(STDERR, 'No graph template ' . $name . PHP_EOL);
			exit(1);
		}

		$templates[] = $id;
	}

	foreach (db_fetch_assoc('SELECT host_id FROM plugin_smokeping_mapping ORDER BY host_id') as $row) {
		foreach ($templates as $id) {
			$graph = create_complete_graph_from_template($id, (int) $row['host_id'], array(), $empty);

			if (!empty($graph['local_graph_id'])) {
				$done++;
			} else {
				$fail++;
			}
		}
	}

	printf("graphs=%d failed=%d seconds=%.1f\n", $done, $fail, microtime(true) - $start);
	exit($fail === 0 ? 0 : 1);
}

fwrite(STDERR, "usage: perf-provision.php devices <count> | objects | stock <graph template>...\n");
exit(2);
