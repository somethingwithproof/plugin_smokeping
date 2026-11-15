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
 *   cli/smokeping.php size-graphs
 *   cli/smokeping.php upgrade-graphs
 *   cli/smokeping.php export [--format=yaml|xml] [--file=PATH]
 *   cli/smokeping.php import --file=PATH [--format=yaml|xml] [--apply --confirm=PLAN]
 *   cli/smokeping.php import-smokeping [--dry-run | --apply --confirm=PLAN] [--file=PATH]
 *       [--sections=A,B/C] [--device-per=host|target] [--device-template=ID] [--poller=ID]
 *       [--site=ID] [--tree=ID | --tree-name=NAME]
 *
 * export writes the plugin's settings, observed targets, and device mappings
 * (by host name and description) as YAML, the default, or XML, to standard
 * output or to a new file. import checks a file and prints every change it
 * would make with a plan id; it writes only with --apply and the id of the
 * plan that was shown, so the confirmation names exactly those changes.
 * Neither command creates an RRD file or touches SmokePing's data directory.
 *
 * import-smokeping imports an existing SmokePing configuration into Cacti:
 * the configured file with its includes, or with --file another file read
 * on its own (no @include). It prints the devices, graphs, and tree entries
 * it would create with a plan id, and creates them only with --apply and
 * that id. Running it again creates nothing new.
 *
 * provision creates a "SmokePing Target" device when --device is not given,
 * maps the target to it, and creates the summary data source and graph, and
 * the smoke data source and graph when the target can have them. relink
 * moves the smoke data source to the new target and keeps the old file's
 * history in an archived data source and graph.
 *
 * size-graphs sets the upper limit of every smoke graph from its file, as
 * SmokePing does: the largest median of the last day, times 1.2. Graphs are
 * sized when they are created and again about once a day by the reader; run
 * it to size them all now. A graph with no median in the last day, or with a
 * limit set by hand, keeps its limit.
 *
 * upgrade-graphs brings every graph the plugin made from older templates to
 * the installed ones: the items the templates gained and their legend. The
 * main poller does the same a batch per cycle; run it to do them all now.
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
	. '       cli/smokeping.php size-graphs' . PHP_EOL
	. '       cli/smokeping.php upgrade-graphs' . PHP_EOL
	. '       cli/smokeping.php export [--format=yaml|xml] [--file=PATH]' . PHP_EOL
	. '       cli/smokeping.php import --file=PATH [--format=yaml|xml] [--apply --confirm=PLAN]' . PHP_EOL
	. '       cli/smokeping.php import-smokeping [--dry-run | --apply --confirm=PLAN] [--file=PATH] [--sections=A,B/C]' . PHP_EOL
	. '           [--device-per=host|target] [--device-template=ID] [--poller=ID] [--site=ID] [--tree=ID | --tree-name=NAME]' . PHP_EOL;

/**
 * The import-smokeping command. Returns the exit status: 0 done, 1 refused
 * or failed, 2 the configuration was read but the plan has errors.
 *
 * @param array<string, string> $options
 */
function smokeping_cli_import_smokeping(array $options, $file, $apply, $confirm, $usage) {
	$service = smokeping_import_service();

	foreach (array('device-template', 'poller', 'site', 'tree') as $name) {
		if (isset($options[$name]) && !preg_match('/^[0-9]{1,9}$/', $options[$name])) {
			fwrite(STDERR, 'ERROR: --' . $name . ' must be a number' . PHP_EOL . $usage);

			return 1;
		}
	}

	$sections = isset($options['sections']) ? array_values(array_filter(array_map('trim', explode(',', $options['sections'])), 'strlen')) : array();
	$devicePer = $options['device-per'] ?? \Somethingwithproof\Smokeping\SmokepingImportOptions::PER_HOST;

	$importOptions = new \Somethingwithproof\Smokeping\SmokepingImportOptions(
		$devicePer,
		isset($options['device-template']) ? (int) $options['device-template'] : $service->defaultTemplateId(),
		isset($options['poller']) ? (int) $options['poller'] : $service->readerPollerId(),
		isset($options['site']) ? (int) $options['site'] : 0,
		$sections,
		isset($options['tree']) ? (int) $options['tree'] : 0,
		isset($options['tree']) ? '' : trim($options['tree-name'] ?? '')
	);

	if ($apply && $confirm === '') {
		fwrite(STDERR, 'ERROR: --apply needs --confirm=PLAN, the plan id printed by the dry run' . PHP_EOL);

		return 1;
	}

	try {
		if ($file !== '') {
			$text = @file_get_contents($file, false, null, 0, \Somethingwithproof\Smokeping\SmokepingConfigParser::MAX_FILE_BYTES + 1);

			if (!is_string($text)) {
				fwrite(STDERR, 'ERROR: Cannot read ' . $file . PHP_EOL);

				return 1;
			}

			list($inventory, $digest) = $service->read($text, $file);
		} else {
			list($inventory, $digest) = $service->read(null);
		}
	} catch (\Somethingwithproof\Smokeping\InventoryError $e) {
		fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);

		return 2;
	}

	if (!$apply) {
		$plan = $service->plan($inventory, $digest, $importOptions, $file !== '');

		foreach ($plan->lines() as $line) {
			print $line . PHP_EOL;
		}

		print 'Plan ' . $plan->id() . ($plan->hasErrors() ? ': not importable' : ($plan->isEmpty() ? ': nothing to do' : ': dry run; nothing was written. To apply: --apply --confirm=' . $plan->id())) . PHP_EOL;

		return $plan->hasErrors() ? 2 : 0;
	}

	list($done, $refusals) = $service->apply($inventory, $digest, $importOptions, $file !== '', $confirm, 0, true);

	foreach ($done as $line) {
		print $line . PHP_EOL;
	}

	foreach ($refusals as $refusal) {
		fwrite(STDERR, 'ERROR: ' . $refusal . PHP_EOL);
	}

	if ($refusals === array()) {
		print 'Applied plan ' . $confirm . PHP_EOL;
	}

	return $refusals === array() ? 0 : 1;
}

/**
 * The export and import commands. Returns the exit status: 0 done, 1 refused
 * or failed, 2 the file was read but the check found errors.
 *
 * @param array<int, string> $paths positional arguments, which these commands do not take
 */
function smokeping_cli_config($command, $format, $file, $apply, $confirm, $paths, $usage) {
	if ($paths !== array() || !\Somethingwithproof\Smokeping\ConfigCodec::isFormat($format)) {
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

		$text     = $file === '-' ? \Somethingwithproof\Smokeping\ConfigCodec::readStream(STDIN) : \Somethingwithproof\Smokeping\ConfigCodec::readFile($file);
		$document = \Somethingwithproof\Smokeping\ConfigCodec::parse($format, $text);
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
	} catch (\Somethingwithproof\Smokeping\ConfigException $e) {
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
$smokeping_import   = array();

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
	} elseif ($smokeping_arg === '--dry-run') {
		$smokeping_apply = false;
	} elseif ($smokeping_command === 'import-smokeping' && preg_match('/^--(sections|device-per|device-template|poller|site|tree|tree-name)=(.*)$/s', $smokeping_arg, $smokeping_match)) {
		$smokeping_import[$smokeping_match[1]] = $smokeping_match[2];
	} elseif (strpos($smokeping_arg, '--') === 0) {
		fwrite(STDERR, 'ERROR: Unknown argument ' . $smokeping_arg . PHP_EOL . $smokeping_usage);
		exit(1);
	} else {
		$smokeping_paths[] = $smokeping_arg;
	}
}

if ($smokeping_command === 'import-smokeping') {
	if ($smokeping_paths !== array() || (isset($smokeping_import['device-per']) && !in_array($smokeping_import['device-per'], array('host', 'target'), true)) || (isset($smokeping_import['tree']) && isset($smokeping_import['tree-name']))) {
		fwrite(STDERR, $smokeping_usage);
		exit(1);
	}

	exit(smokeping_cli_import_smokeping($smokeping_import, $smokeping_file, $smokeping_apply, $smokeping_confirm, $smokeping_usage));
}

if (in_array($smokeping_command, ['export', 'import'], true)) {
	exit(smokeping_cli_config($smokeping_command, $smokeping_format, $smokeping_file, $smokeping_apply, $smokeping_confirm, $smokeping_paths, $smokeping_usage));
}

if ($smokeping_command === 'size-graphs' && $smokeping_paths === []) {
	$smokeping_scaler = new \Somethingwithproof\Smokeping\SmokeProvisioner(smokeping_settings(), $config['rra_path'], $config['library_path'], read_config_option('storage_location') != 0, new \Somethingwithproof\Smokeping\ObjectRepository(false), new \Somethingwithproof\Smokeping\CactiObjectRepository(false), new \Somethingwithproof\Smokeping\SystemClock(), smokeping_rrd_reader());

	foreach ($smokeping_scaler->resizeAll() as $smokeping_graph => $smokeping_sizing) {
		print 'Graph ' . $smokeping_graph . ': ' . match ($smokeping_sizing->status) {
			\Somethingwithproof\Smokeping\GraphSizing::SIZED  => 'upper limit ' . $smokeping_sizing->limit,
			\Somethingwithproof\Smokeping\GraphSizing::MANUAL => 'kept the limit set by hand' . ($smokeping_sizing->limit === null ? '' : '; the plugin last set ' . $smokeping_sizing->limit),
			default                                            => 'kept its limit',
		} . PHP_EOL;
	}

	exit(0);
}

if ($smokeping_command === 'upgrade-graphs' && $smokeping_paths === []) {
	$smokeping_retemplater = new \Somethingwithproof\Smokeping\SmokeProvisioner(smokeping_settings(), $config['rra_path'], $config['library_path'], read_config_option('storage_location') != 0, new \Somethingwithproof\Smokeping\ObjectRepository(false), new \Somethingwithproof\Smokeping\CactiObjectRepository(false), new \Somethingwithproof\Smokeping\SystemClock(), smokeping_rrd_reader());
	$smokeping_version     = (int) read_config_option(\Somethingwithproof\Smokeping\TemplateInstaller::SETTING);
	$smokeping_totals      = ['upgraded' => 0, 'failed' => 0];

	// Each batch records the version of the graphs it handled, failed or not.
	// A batch that upgrades nothing ends the run, so graphs whose version
	// cannot be recorded do not keep it going.
	do {
		$smokeping_batch = $smokeping_retemplater->retemplateDue($smokeping_version, \Somethingwithproof\Smokeping\SmokeProvisioner::RETEMPLATE_BATCH);
		$smokeping_totals['upgraded'] += $smokeping_batch['upgraded'];
		$smokeping_totals['failed']   += $smokeping_batch['failed'];
	} while ($smokeping_batch['upgraded'] > 0);

	print sprintf('Upgraded %d graphs to templates version %d; %d failed (see the Cacti log)', $smokeping_totals['upgraded'], $smokeping_version, $smokeping_totals['failed']) . PHP_EOL;

	exit($smokeping_totals['failed'] > 0 ? 1 : 0);
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
	new \Somethingwithproof\Smokeping\SystemClock(),
	smokeping_rrd_reader()
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
