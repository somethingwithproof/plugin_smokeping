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
 * Administrator command line for SmokePing targets and Cacti objects.
 *
 *   cli/smokeping.php provision <target path> [--device=ID] [--location=NAME]
 *   cli/smokeping.php relink <old target path> <new target path> [--location=NAME]
 *
 * provision creates a "SmokePing Target" device when --device is not given,
 * maps the target to it, and creates the summary data source and graph, and
 * the smoke data source and graph when the target can have them. relink
 * moves the smoke data source to the new target and keeps the old file's
 * history in an archived data source and graph.
 */

$dir = __DIR__;
chdir($dir . '/../../..');

if (PHP_SAPI !== 'cli') {
	exit(1);
}

require('./include/cli_check.php');
require_once($config['base_path'] . '/plugins/smokeping/setup.php');

if (smokeping_refuse_unsupported_php()) {
	exit(1);
}

smokeping_boot();

$smokeping_usage = 'usage: cli/smokeping.php provision <target path> [--device=ID] [--location=NAME]' . PHP_EOL . '       cli/smokeping.php relink <old target path> <new target path> [--location=NAME]' . PHP_EOL;

$smokeping_args     = array_slice($_SERVER['argv'], 1);
$smokeping_command  = array_shift($smokeping_args);
$smokeping_paths    = [];
$smokeping_device   = 0;
$smokeping_location = '';

foreach ($smokeping_args as $smokeping_arg) {
	if (strpos($smokeping_arg, '--device=') === 0) {
		$smokeping_device = (int) substr($smokeping_arg, 9);
	} elseif (strpos($smokeping_arg, '--location=') === 0) {
		$smokeping_location = substr($smokeping_arg, 11);
	} elseif (strpos($smokeping_arg, '--') === 0) {
		fwrite(STDERR, 'ERROR: Unknown argument ' . $smokeping_arg . PHP_EOL . $smokeping_usage);
		exit(1);
	} else {
		$smokeping_paths[] = $smokeping_arg;
	}
}

if (!in_array($smokeping_command, ['provision', 'relink'], true) || count($smokeping_paths) !== ($smokeping_command === 'relink' ? 2 : 1)) {
	fwrite(STDERR, $smokeping_usage);
	exit(1);
}

$smokeping_settings = smokeping_settings();
$smokeping_targets  = new \Somethingwithproof\Smokeping\TargetRepository(false);

$smokeping_provisioner = new \Somethingwithproof\Smokeping\SmokeProvisioner(
	$smokeping_settings,
	$config['rra_path'],
	$config['library_path'],
	read_config_option('storage_location') != 0,
	new \Somethingwithproof\Smokeping\ObjectRepository(false),
	new \Somethingwithproof\Smokeping\CactiObjectRepository(false),
	new \Somethingwithproof\Smokeping\SystemClock()
);

$smokeping_found = [];

foreach ($smokeping_paths as $smokeping_path) {
	$smokeping_target = $smokeping_targets->find($smokeping_settings->pollerId, $smokeping_path);

	if ($smokeping_target === null) {
		fwrite(STDERR, 'ERROR: No target ' . $smokeping_path . ' on poller ' . $smokeping_settings->pollerId . PHP_EOL);
		exit(1);
	}

	$smokeping_found[] = $smokeping_target;
}

if ($smokeping_command === 'relink') {
	$smokeping_result = $smokeping_provisioner->relink($smokeping_found[0], $smokeping_found[1], $smokeping_location);
} else {
	$smokeping_smoke_reason = $smokeping_provisioner->refusal($smokeping_found[0], $smokeping_location);

	if ($smokeping_device === 0) {
		$smokeping_device = $smokeping_provisioner->createDevice($smokeping_found[0]);

		if ($smokeping_device === 0) {
			fwrite(STDERR, 'ERROR: Cacti did not create the device' . PHP_EOL);
			exit(1);
		}

		print 'Created device ' . $smokeping_device . PHP_EOL;
	}

	$smokeping_summary = $smokeping_provisioner->provisionSummary($smokeping_found[0], $smokeping_device, $smokeping_location);

	if ($smokeping_summary->created) {
		print 'Summary data source ' . $smokeping_summary->localDataId . ' graph ' . $smokeping_summary->localGraphId . PHP_EOL;
	} else {
		fwrite(STDERR, 'SKIPPED summary: ' . $smokeping_summary->reason . PHP_EOL);
	}

	if ($smokeping_smoke_reason !== null) {
		// The device and summary stay: a target that cannot have a smoke graph
		// still has summary data.
		fwrite(STDERR, 'SKIPPED: ' . $smokeping_smoke_reason . PHP_EOL);
		exit($smokeping_summary->created ? 0 : 1);
	}

	$smokeping_result = $smokeping_provisioner->provision($smokeping_found[0], $smokeping_device, $smokeping_location);
}

if (!$smokeping_result->created) {
	fwrite(STDERR, 'SKIPPED: ' . $smokeping_result->reason . PHP_EOL);
	exit(1);
}

print 'Data source ' . $smokeping_result->localDataId . ' graph ' . $smokeping_result->localGraphId . PHP_EOL;
exit(0);
