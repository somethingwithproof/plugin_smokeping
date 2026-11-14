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
 * SmokePing reader, started by the poller_bottom hook on the poller next to
 * SmokePing. One instance per poller, enforced through Cacti's processes
 * table.
 *
 * usage: poller_smokeping.php [--poller=N] [--force] [--inventory] [--debug]
 */

$dir = __DIR__;
chdir($dir . '/../..');

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/plugins/smokeping/setup.php');

ini_set('max_execution_time', '0');

$smokeping_poller    = (int) $config['poller_id'];
$smokeping_force     = false;
$smokeping_inventory = false;

foreach (array_slice($_SERVER['argv'], 1) as $smokeping_arg) {
	if (strpos($smokeping_arg, '--poller=') === 0) {
		$smokeping_poller = (int) substr($smokeping_arg, 9);
	} elseif ($smokeping_arg === '--force') {
		$smokeping_force = true;
	} elseif ($smokeping_arg === '--inventory') {
		$smokeping_inventory = true;
	} elseif ($smokeping_arg === '--debug') {
		$debug = true;
	} else {
		fwrite(STDERR, 'ERROR: Unknown argument ' . $smokeping_arg . PHP_EOL . 'usage: poller_smokeping.php [--poller=N] [--force] [--inventory] [--debug]' . PHP_EOL);
		exit(1);
	}
}

if (smokeping_refuse_unsupported_php()) {
	exit(1);
}

smokeping_boot();

if (!$smokeping_force && !api_plugin_is_enabled('smokeping')) {
	exit(0);
}

$smokeping_settings = smokeping_settings();

if ($smokeping_poller < 1 || $smokeping_poller !== $smokeping_settings->pollerId) {
	exit(0);
}

if (!(new \Somethingwithproof\Smokeping\Schema(false))->isCurrent()) {
	cacti_log('ERROR: The SmokePing reader did not run because the plugin schema is not current; open the Plugins page to finish the upgrade', false, 'SMOKEPING');
	exit(1);
}

// The timeout covers a run that overruns its budget by a full inventory.
if (!register_process_start('smokeping', 'reader', $smokeping_poller, $smokeping_settings->readerBudget + 300)) {
	cacti_log('NOTE: A SmokePing reader is already running on poller ' . $smokeping_poller, false, 'SMOKEPING', POLLER_VERBOSITY_MEDIUM);
	exit(0);
}

$smokeping_job = new \Somethingwithproof\Smokeping\ReaderJob(
	$smokeping_poller,
	$smokeping_settings,
	new \Somethingwithproof\Smokeping\TargetRepository(false),
	new \Somethingwithproof\Smokeping\ObservationRepository(false),
	new \Somethingwithproof\Smokeping\HealthRepository(false),
	new \Somethingwithproof\Smokeping\ScriptInventoryReader(
		$smokeping_settings->perlBinary,
		$config['base_path'] . '/plugins/smokeping/bin/smokeping-inventory.pl',
		$smokeping_settings->configFile,
		$smokeping_settings->libDir
	),
	new \Somethingwithproof\Smokeping\RrdtoolProcess((string) read_config_option('path_rrdtool')),
	new \Somethingwithproof\Smokeping\SystemClock()
);

try {
	$smokeping_result = $smokeping_job->run($smokeping_inventory);
} finally {
	unregister_process('smokeping', 'reader', $smokeping_poller);
}

cacti_log(sprintf('SMOKEPING STATS: Poller:%d Series:%d Read:%d Failed:%d Overrun:%s', $smokeping_poller, $smokeping_result->seriesTotal, $smokeping_result->seriesRead, $smokeping_result->seriesFailed, $smokeping_result->overrun ? 'yes' : 'no'), false, 'SMOKEPING', POLLER_VERBOSITY_MEDIUM);

exit(0);
