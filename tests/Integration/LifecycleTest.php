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

const SMOKEPING_TABLES = [
	'plugin_smokeping_health',
	'plugin_smokeping_mapping',
	'plugin_smokeping_object',
	'plugin_smokeping_observation',
	'plugin_smokeping_relink',
	'plugin_smokeping_target',
];

const SMOKEPING_HOOKS = ['api_device_save', 'config_arrays', 'config_settings', 'data_source_remove', 'device_action_array', 'device_action_execute', 'device_action_prepare', 'device_remove', 'draw_navigation_text', 'graph_buttons', 'graph_buttons_thumbnails', 'poller_bottom', 'poller_finishing', 'poller_top'];

beforeEach(function () {
	lifecycle('reset');
});

afterEach(function () {
	lifecycle('show_templates');
});

afterAll(function () {
	lifecycle('reset');
});

it('installs fresh with tracked tables, hooks, and both realms, leaving the plugin disabled', function () {
	$state = lifecycle('install');

	expect($state['plugin']['status'])->toEqual('4')
		->and($state['plugin']['version'])->toEqual('0.1')
		->and($state['schema'])->toEqual('2')
		->and(array_keys($state['tables']))->toBe(SMOKEPING_TABLES)
		->and($state['tracked'])->toBe(SMOKEPING_TABLES)
		->and(array_keys($state['hooks']))->toBe(SMOKEPING_HOOKS)
		->and($state['realms'])->toEqual([
			'smokeping.php'                                => 'SmokePing View',
			'smokeping_inventory.php,smokeping_health.php' => 'SmokePing Admin',
		])
		->and($state['admin'])->toBe(2);

	// Only Cacti's configuration hooks are live before the plugin is enabled.
	expect($state['hooks']['poller_bottom'])->toEqual('0')
		->and($state['hooks']['draw_navigation_text'])->toEqual('0');
});

it('enables and disables through core, stopping the poller hook on disable', function () {
	lifecycle('install');

	$enabled = lifecycle('enable');
	expect($enabled['plugin']['status'])->toEqual('1')
		->and(array_values(array_unique(array_map('strval', $enabled['hooks']))))->toBe(['1']);

	$disabled = lifecycle('disable');
	expect($disabled['plugin']['status'])->toEqual('4')
		->and($disabled['hooks']['poller_bottom'])->toEqual('4');
});

it('upgrades from an older recorded version and is idempotent on repeated runs', function () {
	lifecycle('install');
	lifecycle('seed');
	lifecycle('set_version', '0.0');

	$first = lifecycle('upgrade');
	expect($first['result'])->toBeTrue()
		->and($first['plugin']['version'])->toEqual('0.1');

	$second = lifecycle('upgrade');
	$third  = lifecycle('upgrade');

	foreach ([$second, $third] as $run) {
		expect($run['result'])->toBeTrue()
			->and($run['tables'])->toBe($first['tables'])
			->and($run['hooks'])->toBe($first['hooks'])
			->and($run['realms'])->toBe($first['realms'])
			->and($run['tracked'])->toBe(SMOKEPING_TABLES);
	}

	expect($first['tables']['plugin_smokeping_target'])->toBe(1);
});

it('imports the templates once, and install, upgrade, and re-import leave one copy of each', function () {
	lifecycle('remove_templates');

	$first = lifecycle('install');

	// One device template; a graph and a data template for each of 10, 20, and 30 pings.
	expect($first['templates']['host'])->toBe(1)
		->and($first['templates']['graph'])->toBe(3)
		->and($first['templates']['data'])->toBe(3)
		->and($first['templates']['linked'])->toBe(4)
		->and($first['templates']['version'])->toEqual('2');

	$upgraded = lifecycle('forget_templates');
	$again    = lifecycle('upgrade');

	expect($again['result'])->toBeTrue()
		->and($again['templates'])->toBe($first['templates']);

	// Re-importing with nothing forgotten changes nothing either.
	$third = lifecycle('upgrade');
	expect($third['templates'])->toBe($first['templates']);
});

it('records a failed template import once instead of retrying it on every request', function () {
	lifecycle('remove_templates');
	lifecycle('hide_templates');

	$first = lifecycle('install');
	$errors = $first['templates']['import_errors'];

	expect($first['templates']['host'])->toBe(0)
		->and(json_decode($first['templates']['failure'], true)['message'])->toContain('missing or empty')
		->and($first['plugin']['version'])->toEqual('0.1');

	// Many later requests neither retry nor log again.
	for ($i = 0; $i < 5; $i++) {
		$run = lifecycle('upgrade');
	}

	expect($run['result'])->toBeTrue()
		->and($run['templates']['import_errors'])->toBe($errors);

	// The file is back, but the same plugin version does not retry by itself.
	lifecycle('show_templates');
	$still = lifecycle('upgrade');
	expect($still['templates']['host'])->toBe(0);

	// A different plugin version retries.
	$failure = json_decode($still['templates']['failure'], true);
	$failure['plugin'] = '0.0';
	lifecycle('set_setting', 'smokeping_templates_failure=' . json_encode($failure));
	$upgraded = lifecycle('upgrade');
	expect($upgraded['templates']['host'])->toBe(1)
		->and($upgraded['templates']['failure'])->toBe('');
});

it('retries a failed template import on an explicit request and clears the record', function () {
	lifecycle('remove_templates');
	lifecycle('hide_templates');

	lifecycle('install');
	lifecycle('show_templates');

	$retried = lifecycle('retry_templates');

	expect($retried['templates']['host'])->toBe(1)
		->and($retried['templates']['failure'])->toBe('');
});

it('keeps the imported templates through uninstall and restores them when an operator removed them', function () {
	$installed = lifecycle('install');
	$after     = lifecycle('uninstall');

	expect($after['templates']['graph'])->toBe(3);

	lifecycle('remove_templates');
	$state = lifecycle('install');

	expect($state['templates'])->toBe($installed['templates']);
});

it('finishes an upgrade that was interrupted after creating only some tables', function () {
	lifecycle('install');
	lifecycle('seed');
	lifecycle('drop_table', 'plugin_smokeping_health');
	lifecycle('drop_table', 'plugin_smokeping_relink');
	lifecycle('set_schema', '0');
	lifecycle('set_version', '0.0');

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeTrue()
		->and(array_keys($state['tables']))->toBe(SMOKEPING_TABLES)
		->and($state['tables']['plugin_smokeping_target'])->toBe(1)
		->and($state['schema'])->toEqual('2')
		->and($state['plugin']['version'])->toEqual('0.1');
});

it('refuses a schema newer than the code and leaves the recorded version alone', function () {
	lifecycle('install');
	lifecycle('set_schema', '99');
	lifecycle('set_version', '0.0');

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeFalse()
		->and($state['plugin']['version'])->toEqual('0.0')
		->and($state['schema'])->toEqual('99');
});

it('drops tracked tables on uninstall by default, and keeps them when configured', function () {
	lifecycle('install');
	lifecycle('seed');

	$dropped = lifecycle('uninstall');
	expect($dropped['plugin'])->toBeEmpty()
		->and($dropped['tables'])->toBe([])
		->and($dropped['hooks'])->toBe([])
		->and($dropped['realms'])->toBe([])
		->and($dropped['schema'])->toBeEmpty();

	lifecycle('install');
	lifecycle('seed');
	lifecycle('set_setting', 'smokeping_uninstall_keep_tables=on');

	$kept = lifecycle('uninstall');
	expect(array_keys($kept['tables']))->toBe(SMOKEPING_TABLES)
		->and($kept['tables']['plugin_smokeping_target'])->toBe(1)
		->and($kept['tracked'])->toBe([])
		->and($kept['realms'])->toBe([]);

	// A reinstall reuses the kept tables and their rows.
	$reinstalled = lifecycle('install');
	expect($reinstalled['plugin']['status'])->toEqual('4')
		->and($reinstalled['tables']['plugin_smokeping_target'])->toBe(1);
});

it('leaves SmokePing RRD files byte-identical across install, uninstall, and reinstall', function () {
	$cacti = rtrim((string) getenv('CACTI_PATH'), '/');
	$dir   = $cacti . '/rra/smokeping-lifecycle-' . getmypid();
	$rrd   = $dir . '/Fixture/target.rrd';

	mkdir(dirname($rrd), 0755, true);
	exec('rrdtool create ' . escapeshellarg($rrd) . ' --step 300 --start 1700000000 DS:loss:GAUGE:600:0:20 DS:median:GAUGE:600:0:180 RRA:AVERAGE:0.5:1:10', $out, $rc);
	expect($rc)->toBe(0);

	$before = hash_file('sha256', $rrd);

	lifecycle('install');
	lifecycle('set_setting', 'smokeping_mount_path=' . $dir);
	lifecycle('enable');
	lifecycle('disable');
	lifecycle('uninstall');
	lifecycle('install');
	lifecycle('uninstall');

	expect(hash_file('sha256', $rrd))->toBe($before);

	unlink($rrd);
	rmdir(dirname($rrd));
	rmdir($dir);
});
