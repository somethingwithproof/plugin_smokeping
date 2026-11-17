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
 * Proves the PHP 8.2 guard against a real Cacti install, on whatever PHP runs
 * this script. Run from the Cacti root as the poller user:
 *
 *   php plugins/smokeping/tests/bin/php-guard-check.php
 *
 * Below 8.2 it expects install and enable to be refused with a message, no
 * tables, hooks, or realms, no plugin class loaded, and every hook shim to
 * return quietly even when hooks were registered by an earlier, supported
 * PHP. On 8.2 and later it expects install and enable to succeed.
 *
 * This file must itself parse on PHP 8.0. Exits non-zero on any failure.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/plugins.php');
require_once($config['base_path'] . '/lib/poller.php');

$failures = 0;

function guard_expect($condition, $message) {
	global $failures;

	print ($condition ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;

	if (!$condition) {
		$failures++;
	}
}

function guard_state() {
	return array(
		'status' => db_fetch_cell("SELECT status FROM plugin_config WHERE directory = 'smokeping'"),
		'hooks'  => (int) db_fetch_cell("SELECT COUNT(*) FROM plugin_hooks WHERE name = 'smokeping'"),
		'realms' => (int) db_fetch_cell("SELECT COUNT(*) FROM plugin_realms WHERE plugin = 'smokeping'"),
		'tables' => (int) db_fetch_cell("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'plugin\\_smokeping\\_%'"),
	);
}

$supported   = version_compare(PHP_VERSION, '8.2.0', '>=');
$preexisting = guard_state();

print 'PHP ' . PHP_VERSION . ', Cacti ' . CACTI_VERSION . ', hooks already registered: ' . $preexisting['hooks'] . PHP_EOL;

if (!$supported && $preexisting['hooks'] > 0) {
	// Hooks registered under a supported PHP, then PHP was downgraded. Cacti
	// still calls the shims; each must return before loading any class.
	include_once($config['base_path'] . '/plugins/smokeping/setup.php');

	$nav = array('index.php:' => array('title' => 'Console'));

	smokeping_config_arrays();
	smokeping_config_settings();
	guard_expect(smokeping_draw_navigation_text($nav) === $nav, 'draw_navigation_text returns the navigation unchanged');
	smokeping_poller_bottom();
	guard_expect(plugin_smokeping_check_config() === false, 'check_config refuses');

	// Core's own dispatch, as every page load does.
	api_plugin_hook('config_arrays');
	api_plugin_hook('config_settings');
	api_plugin_hook('poller_bottom');
	api_plugin_hook_function('draw_navigation_text', $nav);

	guard_expect(!class_exists('Somethingwithproof\\Smokeping\\Schema', false), 'no plugin class was loaded');
	guard_expect(guard_state()['tables'] === $preexisting['tables'], 'plugin tables were not touched');
} else {
	ob_start();
	api_plugin_install('smokeping');
	api_plugin_enable('smokeping');
	ob_end_clean();

	$state = guard_state();

	if ($supported) {
		guard_expect($state['status'] == 1, 'install and enable succeed (status ' . $state['status'] . ')');
		guard_expect($state['tables'] === 6, 'all six plugin tables exist (' . $state['tables'] . ')');
		guard_expect($state['hooks'] === 17 && $state['realms'] === 3, 'hooks and realms are registered');
	} else {
		guard_expect($state['status'] == 2, 'plugin is left as needing configuration, not enabled (status ' . $state['status'] . ')');
		guard_expect($state['tables'] === 0 && $state['hooks'] === 0 && $state['realms'] === 0, 'no tables, hooks, or realms were created');
		guard_expect(!class_exists('Somethingwithproof\\Smokeping\\Schema', false), 'no plugin class was loaded');

		$logged = (int) shell_exec('grep -c ' . escapeshellarg('SMOKEPING ERROR: The SmokePing plugin requires PHP 8.2.0 or later') . ' ' . escapeshellarg(cacti_log_file()));
		guard_expect($logged > 0, 'the refusal naming PHP 8.2.0 was logged');
	}
}

exit($failures > 0 ? 1 : 0);
