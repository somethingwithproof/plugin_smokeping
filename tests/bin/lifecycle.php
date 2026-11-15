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

$GLOBALS['lifecycle_hook_ran'] = false;

function lifecycle_hook_probe($args) {
	$GLOBALS['lifecycle_hook_ran'] = true;

	return $args;
}

ob_start();

switch ($action) {
	case 'reset':
		api_plugin_uninstall('smokeping');

		foreach (db_fetch_assoc("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'plugin\\_smokeping\\_%'") as $row) {
			db_execute('DROP TABLE `' . $row['t'] . '`');
		}

		db_execute("DELETE FROM settings WHERE name LIKE 'smokeping\\_%'");
		db_execute('DELETE FROM graph_templates_graph WHERE local_graph_id = 990001');

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
	case 'remote_hooks':
		// poller id: which poller hooks core's capability gate lets run for
		// this plugin on that poller, judged from the plugin's INFO. A probe
		// stands in for the hook functions, so nothing is launched.
		$main_poller         = $config['poller_id'];
		$config['poller_id'] = (int) $argument;
		$result              = array();

		foreach (array('poller_top', 'poller_bottom', 'poller_finishing') as $hook) {
			api_plugin_run_plugin_hook($hook, 'smokeping', 'lifecycle_hook_probe', array());

			$result[$hook] = $GLOBALS['lifecycle_hook_ran'] === true;

			$GLOBALS['lifecycle_hook_ran'] = false;
		}

		$config['poller_id'] = $main_poller;

		break;
	case 'upgrade':
		include_once($config['base_path'] . '/plugins/smokeping/setup.php');
		$result = plugin_smokeping_upgrade();

		break;
	case 'add_target':
		// path:host:pings:step
		list($path, $host, $pings, $step) = explode(':', $argument);
		db_execute_prepared("INSERT INTO plugin_smokeping_target (poller_id, path, host, probe, step, pings, present, first_seen, last_seen) VALUES (1, ?, ?, 'FPing', ?, ?, 1, 1, 1) ON DUPLICATE KEY UPDATE pings = VALUES(pings), step = VALUES(step)", array($path, $host, (int) $step, (int) $pings));
		$result = (int) db_fetch_cell_prepared('SELECT id FROM plugin_smokeping_target WHERE path = ?', array($path));

		break;
	case 'make_devices':
		// count:prefix. Plain devices (no template) with host names <prefix><n>.example, for bulk checks.
		list($count, $prefix) = explode(':', $argument);
		include_once($config['base_path'] . '/lib/api_device.php');
		$ids = array();

		for ($n = 1; $n <= (int) $count; $n++) {
			$ids[] = (int) api_device_save(0, 0, $prefix . $n, $prefix . $n . '.example', '', 0, '', '', 161, 500, '', AVAIL_NONE, 0, 23, 400, 1, '', 'MD5', '', 'DES', '', '', 5, 1, 1);
		}

		$result = $ids;

		break;
	case 'make_device':
		// host name|description: a plain device with exactly this identity, as a restore recreates one.
		list($hostname, $description) = explode('|', $argument, 2);
		include_once($config['base_path'] . '/lib/api_device.php');
		$result = (int) api_device_save(0, 0, $description, $hostname, '', 0, '', '', 161, 500, '', AVAIL_NONE, 0, 23, 400, 1, '', 'MD5', '', 'DES', '', '', 5, 1, 1);

		break;
	case 'update_target':
		// path|column|value for the few text columns a test needs to make hostile.
		list($path, $column, $value) = explode('|', $argument, 3);

		if (!in_array($column, array('title', 'menu', 'host', 'admin_state', 'slaves'), true)) {
			fwrite(STDERR, "Unsupported column\n");
			exit(2);
		}

		db_execute_prepared("UPDATE plugin_smokeping_target SET `$column` = ? WHERE path = ?", array($value, $path));

		break;
	case 'propose_relink':
		// old path|new path, as an inventory import proposes after a rename.
		list($old, $new) = explode('|', $argument);
		db_execute_prepared("INSERT IGNORE INTO plugin_smokeping_relink (old_target_id, new_target_id, status, proposed_at) SELECT o.id, n.id, 'proposed', 1 FROM plugin_smokeping_target o, plugin_smokeping_target n WHERE o.path = ? AND n.path = ?", array($old, $new));
		db_execute_prepared('UPDATE plugin_smokeping_target SET present = 0 WHERE path = ?', array($old));
		$result = (int) db_fetch_cell("SELECT MAX(id) FROM plugin_smokeping_relink");

		break;
	case 'map_target':
		// path|device
		list($path, $device) = explode('|', $argument);
		db_execute_prepared('REPLACE INTO plugin_smokeping_mapping (target_id, host_id, mapped_by, mapped_at) SELECT id, ?, 0, 1 FROM plugin_smokeping_target WHERE path = ?', array((int) $device, $path));

		break;
	case 'revoke_device':
		// user|device: what an administrator does in User Management.
		list($user, $device) = explode('|', $argument);
		db_execute_prepared('DELETE p FROM user_auth_perms p INNER JOIN user_auth u ON u.id = p.user_id WHERE u.username = ? AND p.type = 3 AND p.item_id = ?', array($user, (int) $device));

		break;
	case 'remove_devices':
		// The Delete action of the device list with "delete all graphs and data sources" chosen.
		include_once($config['base_path'] . '/lib/api_device.php');
		include_once($config['base_path'] . '/lib/api_graph.php');
		include_once($config['base_path'] . '/lib/api_data_source.php');
		include_once($config['base_path'] . '/lib/utility.php');
		api_device_remove_multi(array_map('intval', explode(',', $argument)), 2);

		break;
	case 'fail_mapping_inserts':
		// A trigger that refuses every mapping insert, to prove an import rolls back whole.
		db_execute('DROP TRIGGER IF EXISTS smokeping_test_refuse_mapping');
		db_execute("CREATE TRIGGER smokeping_test_refuse_mapping BEFORE INSERT ON plugin_smokeping_mapping FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by test'");

		break;
	case 'allow_mapping_inserts':
		db_execute('DROP TRIGGER IF EXISTS smokeping_test_refuse_mapping');

		break;
	case 'fail_observation_writes':
		// A trigger that refuses every write to the observation cache, as a full disk would.
		db_execute('DROP TRIGGER IF EXISTS smokeping_test_refuse_observation');
		db_execute("CREATE TRIGGER smokeping_test_refuse_observation BEFORE INSERT ON plugin_smokeping_observation FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by test'");

		break;
	case 'allow_observation_writes':
		db_execute('DROP TRIGGER IF EXISTS smokeping_test_refuse_observation');

		break;
	case 'queue_purge':
		// What rrdcleaner.php inserts when an administrator selects an unused file: path relative to rra_path.
		list($file, $action) = explode('|', $argument);
		db_execute_prepared('INSERT INTO data_source_purge_action (local_data_id, name, action) VALUES (0, ?, ?) ON DUPLICATE KEY UPDATE action = VALUES(action)', array($file, (int) $action));

		break;
	case 'render':
		include_once($config['base_path'] . '/lib/rrd.php');
		$image  = rrdtool_function_graph((int) $argument, 0, array('graph_start' => time() - 7200, 'graph_end' => time(), 'output_flag' => RRDTOOL_OUTPUT_STDOUT));
		// Cacti renders PNG or SVG according to the graph's image format; either is an image.
		$result = is_string($image) && (strncmp($image, "\x89PNG", 4) === 0 || strpos($image, '<svg') !== false) ? 'image' : 'error';

		break;
	case 'hide_templates':
	case 'show_templates':
		$from = $config['base_path'] . '/plugins/smokeping/templates/smokeping-templates.xml';
		$to   = $from . '.hidden';

		if ($action === 'hide_templates') {
			rename($from, $to);
		} elseif (is_file($to)) {
			rename($to, $from);
		}

		break;
	case 'retry_templates':
		include_once($config['base_path'] . '/plugins/smokeping/setup.php');
		$result = plugin_smokeping_upgrade(true);

		break;
	case 'forget_templates':
		db_execute("DELETE FROM settings WHERE name = 'smokeping_templates_version'");

		break;
	case 'link_templates':
		// What an earlier template file left: the device template listing every SmokePing graph template.
		db_execute("INSERT IGNORE INTO host_template_graph (host_template_id, graph_template_id)
			SELECT ht.id, gt.id FROM host_template ht, graph_templates gt
			WHERE ht.name = 'SmokePing Target' AND gt.name LIKE 'SmokePing %'");

		break;
	case 'remove_templates':
		// What an operator would do in the UI before a reinstall.
		db_execute("DELETE FROM host_template_graph WHERE host_template_id IN (SELECT id FROM host_template WHERE name = 'SmokePing Target')");
		db_execute("DELETE FROM host_template WHERE name = 'SmokePing Target'");
		db_execute("DELETE FROM graph_templates WHERE name LIKE 'SmokePing Smoke%'");
		db_execute("DELETE FROM settings WHERE name IN ('smokeping_templates_version', 'smokeping_templates_failure')");

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
	case 'set_sizing':
		// graph|graph limit|recorded limit|sized at: a graph's limit and the
		// plugin's record of it, as a hand edit or an older sizing leaves them.
		list($graph, $limit, $recorded, $at) = explode('|', $argument);
		db_execute_prepared('UPDATE graph_templates_graph SET upper_limit = ? WHERE local_graph_id = ?', array($limit, (int) $graph));
		db_execute_prepared('UPDATE plugin_smokeping_object SET sized_limit = ?, sized_at = ? WHERE local_graph_id = ?', array($recorded, (int) $at, (int) $graph));

		break;
	case 'resize_due':
		// poller:batch, the reader's daily sizing step on its own.
		list($poller, $batch) = explode(':', $argument);
		include_once($config['base_path'] . '/plugins/smokeping/setup.php');
		smokeping_boot();
		$sizer  = new \Somethingwithproof\Smokeping\SmokeProvisioner(smokeping_settings(), $config['rra_path'], $config['library_path'], false, new \Somethingwithproof\Smokeping\ObjectRepository(false), new \Somethingwithproof\Smokeping\CactiObjectRepository(false), new \Somethingwithproof\Smokeping\SystemClock(), smokeping_rrd_reader());
		$result = $sizer->resizeDue((int) $poller, (int) $batch);

		break;
	case 'seed_sized_graph':
		// An active and a summary object on a graph whose limit is 0.25, as
		// schema 3 left them, without the sizing columns.
		db_execute('DELETE FROM graph_templates_graph WHERE local_graph_id = 990001');
		db_execute("INSERT INTO graph_templates_graph (local_graph_id, upper_limit, title) VALUES (990001, '0.25', 'seeded')");
		db_execute("INSERT INTO plugin_smokeping_object (target_id, location, host_id, local_data_id, local_graph_id, rrd_path, state) SELECT id, '', 0, 990001, 990001, 'smokeping/Seed/target.rrd', 'active' FROM plugin_smokeping_target WHERE path = 'Seed/target'");
		db_execute("INSERT INTO plugin_smokeping_object (target_id, location, host_id, local_data_id, local_graph_id, rrd_path, state) SELECT id, '', 0, 990002, 990001, '', 'summary' FROM plugin_smokeping_target WHERE path = 'Seed/target'");
		db_execute('ALTER TABLE plugin_smokeping_object DROP COLUMN sized_limit, DROP COLUMN sized_at');

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
	'templates' => array(
		'host'  => (int) db_fetch_cell("SELECT COUNT(*) FROM host_template WHERE name = 'SmokePing Target'"),
		'graph' => (int) db_fetch_cell("SELECT COUNT(*) FROM graph_templates WHERE name LIKE 'SmokePing Smoke (%'"),
		'data'  => (int) db_fetch_cell("SELECT COUNT(*) FROM data_template WHERE name LIKE 'SmokePing Smoke (%'"),
		'cdef'  => (int) db_fetch_cell("SELECT COUNT(*) FROM cdef WHERE name LIKE 'SmokePing %'"),
		'items' => (int) db_fetch_cell("SELECT COUNT(*) FROM graph_templates_item gti INNER JOIN graph_templates gt ON gt.id = gti.graph_template_id WHERE gt.name LIKE 'SmokePing Smoke (%' AND gti.local_graph_id = 0"),
		'linked' => (int) db_fetch_cell("SELECT COUNT(*) FROM host_template_graph htg INNER JOIN host_template ht ON ht.id = htg.host_template_id WHERE ht.name = 'SmokePing Target'"),
		'version' => db_fetch_cell("SELECT value FROM settings WHERE name = 'smokeping_templates_version'"),
		'failure' => db_fetch_cell("SELECT value FROM settings WHERE name = 'smokeping_templates_failure'"),
		'import_errors' => is_file($config['base_path'] . '/log/cacti.log') ? substr_count((string) file_get_contents($config['base_path'] . '/log/cacti.log'), 'SmokePing templates') + substr_count((string) file_get_contents($config['base_path'] . '/log/cacti.log'), 'The template file') : 0,
	),
), JSON_PRETTY_PRINT) . PHP_EOL;
