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
 * Test setup for tests/permissions-e2e.sh: the users the permission suite logs
 * in as. Disposable databases only. Passwords come from the environment
 * (SP_USER_PASSWORD), never from the command line.
 *
 *   SP_USER_PASSWORD=... php plugins/smokeping/tests/bin/permission-setup.php <device a> <device b>
 *
 * scoped    View Graphs and SmokePing View; devices, graphs, and graph templates
 *           denied by policy except device A (Cacti's default permission method is
 *           permissive, so a user is scoped only when every policy denies)
 * revoked   the same as scoped; the suite removes the device permission later
 * restricted the SmokePing admin realm with the device policy of scoped, so it
 *           sees unmapped targets but only the mapped ones of device A
 * broad     View Graphs and SmokePing View, everything allowed by policy
 * norealm   View Graphs only, every device allowed by policy
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');

if (getenv('SMOKEPING_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set SMOKEPING_TEST_DATABASE=disposable\n");
	exit(2);
}

$password = (string) getenv('SP_USER_PASSWORD');

if ($password === '') {
	fwrite(STDERR, "Set SP_USER_PASSWORD\n");
	exit(2);
}

list(, $deviceA) = $argv;

$viewRealm = (int) db_fetch_cell_prepared("SELECT id + 100 FROM plugin_realms WHERE plugin = 'smokeping' AND file = ?", array('smokeping.php'));

if ($viewRealm < 101) {
	fwrite(STDERR, "The SmokePing view realm is not registered\n");
	exit(1);
}

// The suite also logs in as the administrator.
db_execute_prepared("UPDATE user_auth SET password = ?, must_change_password = '', password_change = '', enabled = 'on', locked = '', failed_attempts = 0 WHERE id = 1", array(password_hash($password, PASSWORD_DEFAULT)));

$adminRealm = (int) db_fetch_cell_prepared("SELECT id + 100 FROM plugin_realms WHERE plugin = 'smokeping' AND file LIKE ?", array('smokeping_inventory.php%'));

$users = array(
	'restricted' => array('policy' => 2, 'realms' => array(7, $viewRealm, $adminRealm), 'devices' => array((int) $deviceA)),
	'scoped'  => array('policy' => 2, 'realms' => array(7, $viewRealm), 'devices' => array((int) $deviceA)),
	'revoked' => array('policy' => 2, 'realms' => array(7, $viewRealm), 'devices' => array((int) $deviceA)),
	'broad'   => array('policy' => 1, 'realms' => array(7, $viewRealm), 'devices' => array()),
	'norealm' => array('policy' => 1, 'realms' => array(7), 'devices' => array()),
);

foreach ($users as $name => $spec) {
	$id = (int) db_fetch_cell_prepared('SELECT id FROM user_auth WHERE username = ?', array($name));

	if ($id > 0) {
		db_execute_prepared('DELETE FROM user_auth_realm WHERE user_id = ?', array($id));
		db_execute_prepared('DELETE FROM user_auth_perms WHERE user_id = ?', array($id));
		db_execute_prepared('DELETE FROM user_auth WHERE id = ?', array($id));
	}

	db_execute_prepared("INSERT INTO user_auth (username, password, realm, full_name, must_change_password, password_change, policy_graphs, policy_trees, policy_hosts, policy_graph_templates, enabled)
		VALUES (?, ?, 0, ?, '', '', ?, 1, ?, ?, 'on')", array($name, password_hash($password, PASSWORD_DEFAULT), $name, $spec['policy'], $spec['policy'], $spec['policy']));

	$id = (int) db_fetch_cell_prepared('SELECT id FROM user_auth WHERE username = ?', array($name));

	foreach ($spec['realms'] as $realm) {
		db_execute_prepared('INSERT INTO user_auth_realm (realm_id, user_id) VALUES (?, ?)', array($realm, $id));
	}

	foreach ($spec['devices'] as $device) {
		db_execute_prepared('INSERT INTO user_auth_perms (user_id, item_id, type) VALUES (?, ?, 3)', array($id, $device));
	}

	print "$name $id\n";
}
