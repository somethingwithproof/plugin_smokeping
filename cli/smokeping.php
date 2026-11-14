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
 * Administrator command line for SmokePing targets and Cacti objects.
 *
 *   cli/smokeping.php provision <target path> [--device=ID] [--location=NAME]
 *   cli/smokeping.php relink <old target path> <new target path> [--location=NAME]
 *   cli/smokeping.php export [--format=yaml|xml] [--file=PATH]
 *   cli/smokeping.php import --file=PATH [--format=yaml|xml] [--apply --confirm=PLAN]
 *
 * export writes the plugin's settings, observed targets, and device mappings
 * (by host name and description) as YAML, the default, or XML, to standard
 * output or to a new file. import checks a file and prints every change it
 * would make with a plan id; it writes only with --apply and the id of the
 * plan that was shown, so the confirmation names exactly those changes.
 * Neither command creates an RRD file or touches SmokePing's data directory.
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

$smokeping_usage = 'usage: cli/smokeping.php provision <target path> [--device=ID] [--location=NAME]' . PHP_EOL
	. '       cli/smokeping.php relink <old target path> <new target path> [--location=NAME]' . PHP_EOL
	. '       cli/smokeping.php export [--format=yaml|xml] [--file=PATH]' . PHP_EOL
	. '       cli/smokeping.php import --file=PATH [--format=yaml|xml] [--apply --confirm=PLAN]' . PHP_EOL;

/**
 * The export and import commands. Returns the exit status: 0 done, 1 refused
 * or failed, 2 the file was read but the check found errors.
 *
 * @param array<int, string> $paths positional arguments, which these commands do not take
 */
function smokeping_cli_config($command, $format, $file, $apply, $confirm, $paths, $usage) {
	if ($paths !== array() || !\Cacti\Smokeping\ConfigCodec::isFormat($format)) {
		fwrite(STDERR, $usage);

		return 1;
	}

	$service = smokeping_config_service();

	try {
		if ($command === 'export') {
			$text = $service->export($format);

			if ($file === '' || $file === '-') {
				fwrite(STDOUT, $text);

				return 0;
			}

			// Never overwrite: an export is a backup, and a wrong path must not destroy one.
			$handle = @fopen($file, 'x');

			if ($handle === false || fwrite($handle, $text) === false || !fclose($handle) || !chmod($file, 0600)) {
				fwrite(STDERR, 'ERROR: Cannot create ' . $file . ' (it must not exist yet)' . PHP_EOL);

				return 1;
			}

			print 'Exported to ' . $file . PHP_EOL;

			return 0;
		}

		if ($file === '') {
			fwrite(STDERR, 'ERROR: import needs --file=PATH' . PHP_EOL . $usage);

			return 1;
		}

		$text     = $file === '-' ? \Cacti\Smokeping\ConfigCodec::readStream(STDIN) : \Cacti\Smokeping\ConfigCodec::readFile($file);
		$document = \Cacti\Smokeping\ConfigCodec::parse($format, $text);
		$plan     = $service->plan($document);

		if ($apply && $confirm === '') {
			fwrite(STDERR, 'ERROR: --apply needs --confirm=PLAN, the plan id printed by the check without --apply' . PHP_EOL);

			return 1;
		}

		if (!$apply) {
			foreach ($plan->lines() as $line) {
				print $line . PHP_EOL;
			}

			print 'Plan ' . $plan->id() . ($plan->hasErrors() ? ': not importable' : ($plan->isEmpty() ? ': nothing to do' : ': check only; nothing was written. To apply: --apply --confirm=' . $plan->id())) . PHP_EOL;

			return $plan->hasErrors() ? 2 : 0;
		}

		$refusals = $service->apply($document, $confirm, 0, time());

		foreach ($refusals as $refusal) {
			fwrite(STDERR, 'ERROR: ' . $refusal . PHP_EOL);
		}

		if ($refusals === array()) {
			print 'Applied plan ' . $plan->id() . PHP_EOL;
		}

		return $refusals === array() ? 0 : 1;
	} catch (\Cacti\Smokeping\ConfigException $e) {
		foreach ($e->lines() as $line) {
			fwrite(STDERR, 'ERROR: ' . $line . PHP_EOL);
		}

		return 2;
	}
}

$smokeping_args     = array_slice($_SERVER['argv'], 1);
$smokeping_command  = array_shift($smokeping_args);
$smokeping_paths    = [];
$smokeping_device   = 0;
$smokeping_location = '';
$smokeping_format   = 'yaml';
$smokeping_file     = '';
$smokeping_apply    = false;
$smokeping_confirm  = '';

foreach ($smokeping_args as $smokeping_arg) {
	if (strpos($smokeping_arg, '--device=') === 0) {
		$smokeping_device = (int) substr($smokeping_arg, 9);
	} elseif (strpos($smokeping_arg, '--location=') === 0) {
		$smokeping_location = substr($smokeping_arg, 11);
	} elseif (strpos($smokeping_arg, '--format=') === 0) {
		$smokeping_format = substr($smokeping_arg, 9);
	} elseif (strpos($smokeping_arg, '--file=') === 0) {
		$smokeping_file = substr($smokeping_arg, 7);
	} elseif (strpos($smokeping_arg, '--confirm=') === 0) {
		$smokeping_confirm = substr($smokeping_arg, 10);
	} elseif ($smokeping_arg === '--apply') {
		$smokeping_apply = true;
	} elseif (strpos($smokeping_arg, '--') === 0) {
		fwrite(STDERR, 'ERROR: Unknown argument ' . $smokeping_arg . PHP_EOL . $smokeping_usage);
		exit(1);
	} else {
		$smokeping_paths[] = $smokeping_arg;
	}
}

if (in_array($smokeping_command, ['export', 'import'], true)) {
	exit(smokeping_cli_config($smokeping_command, $smokeping_format, $smokeping_file, $smokeping_apply, $smokeping_confirm, $smokeping_paths, $smokeping_usage));
}

if (!in_array($smokeping_command, ['provision', 'relink'], true) || count($smokeping_paths) !== ($smokeping_command === 'relink' ? 2 : 1)) {
	fwrite(STDERR, $smokeping_usage);
	exit(1);
}

$smokeping_settings = smokeping_settings();
$smokeping_targets  = new \Cacti\Smokeping\TargetRepository(false);

$smokeping_provisioner = new \Cacti\Smokeping\SmokeProvisioner(
	$smokeping_settings,
	$config['rra_path'],
	$config['library_path'],
	read_config_option('storage_location') != 0,
	new \Cacti\Smokeping\ObjectRepository(false),
	new \Cacti\Smokeping\CactiObjectRepository(false),
	new \Cacti\Smokeping\SystemClock()
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
