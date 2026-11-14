<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

/*
 * Drive the plugin lifecycle through Cacti's own plugin API in a fresh PHP
 * process, the way plugins.php and cli/plugin_manage.php do, and print the
 * resulting state as JSON. Used by tests/Integration. Disposable databases
 * only.
 *
 *   php plugins/smokeping/tests/bin/lifecycle.php <action> [argument]
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/plugins.php');
require_once($config['base_path'] . '/lib/poller.php');

if (getenv('SMOKEPING_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set SMOKEPING_TEST_DATABASE=disposable\n");
	exit(2);
}

$action   = isset($argv[1]) ? $argv[1] : 'state';
$argument = isset($argv[2]) ? $argv[2] : '';
$result   = null;

ob_start();

switch ($action) {
	case 'reset':
		api_plugin_uninstall('smokeping');

		foreach (db_fetch_assoc("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'plugin\\_smokeping\\_%'") as $row) {
			db_execute('DROP TABLE `' . $row['t'] . '`');
		}

		db_execute("DELETE FROM settings WHERE name LIKE 'smokeping\\_%'");

		break;
	case 'install':
		api_plugin_install('smokeping');

		break;
	case 'enable':
		api_plugin_enable('smokeping');

		break;
	case 'disable':
		api_plugin_disable('smokeping');

		break;
	case 'uninstall':
		api_plugin_uninstall('smokeping');

		break;
	case 'upgrade':
		include_once($config['base_path'] . '/plugins/smokeping/setup.php');
		$result = plugin_smokeping_upgrade();

		break;
	case 'set_version':
		db_execute_prepared("UPDATE plugin_config SET version = ? WHERE directory = 'smokeping'", array($argument));

		break;
	case 'set_schema':
		db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('smokeping_schema_version', ?)", array($argument));

		break;
	case 'set_setting':
		list($name, $value) = explode('=', $argument, 2);
		db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array($name, $value));

		break;
	case 'drop_table':
		db_execute('DROP TABLE IF EXISTS `' . preg_replace('/[^a-z_]/', '', $argument) . '`');

		break;
	case 'seed':
		// A target with an observation, so table drops and keeps are visible.
		db_execute("INSERT INTO plugin_smokeping_target (poller_id, path, host, probe, step, pings) VALUES (1, 'Seed/target', 'seed.example', 'FPing', 300, 20)");
		db_execute("INSERT INTO plugin_smokeping_observation (target_id, location, observed_at) SELECT id, '', 1 FROM plugin_smokeping_target WHERE path = 'Seed/target'");

		break;
	case 'query':
		// Read-only inspection for the shell-driven checks.
		if (stripos(ltrim($argument), 'SELECT') !== 0) {
			fwrite(STDERR, "Only SELECT is allowed\n");
			exit(2);
		}

		ob_end_clean();
		print json_encode(db_fetch_assoc($argument)) . PHP_EOL;
		exit(0);
	case 'state':
		break;
	default:
		fwrite(STDERR, "Unknown action $action\n");
		exit(2);
}

$output = ob_get_clean();

$tables = array();

foreach (db_fetch_assoc("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'plugin\\_smokeping\\_%' ORDER BY TABLE_NAME") as $row) {
	$tables[$row['t']] = (int) db_fetch_cell('SELECT COUNT(*) FROM `' . $row['t'] . '`');
}

print json_encode(array(
	'result'   => $result,
	'output'   => $output,
	'plugin'   => db_fetch_row("SELECT status, version FROM plugin_config WHERE directory = 'smokeping'"),
	'schema'   => db_fetch_cell("SELECT value FROM settings WHERE name = 'smokeping_schema_version'"),
	'hooks'    => array_column(db_fetch_assoc("SELECT hook, status FROM plugin_hooks WHERE name = 'smokeping' ORDER BY hook"), 'status', 'hook'),
	'realms'   => array_column(db_fetch_assoc("SELECT file, display FROM plugin_realms WHERE plugin = 'smokeping' ORDER BY file"), 'display', 'file'),
	'admin'    => (int) db_fetch_cell("SELECT COUNT(*) FROM user_auth_realm AS uar INNER JOIN plugin_realms AS pr ON pr.id + 100 = uar.realm_id WHERE pr.plugin = 'smokeping' AND uar.user_id = 1"),
	'tracked'  => array_column(db_fetch_assoc("SELECT `table` FROM plugin_db_changes WHERE plugin = 'smokeping' AND method = 'create' ORDER BY `table`"), 'table'),
	'tables'   => $tables,
), JSON_PRETTY_PRINT) . PHP_EOL;
