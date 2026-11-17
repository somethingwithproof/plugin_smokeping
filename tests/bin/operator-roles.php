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

// Executed only by lifecycle.php in a disposable Cacti database.
if (PHP_SAPI !== 'cli') {
	exit(1);
}

if (getenv('SMOKEPING_TEST_DATABASE') !== 'disposable') {
	exit(2);
}
require_once $config['library_path'] . '/auth.php';
require_once $config['base_path'] . '/plugins/smokeping/setup.php';
smokeping_boot();
$view     = \Somethingwithproof\Smokeping\Pages::VIEW;
$admin    = \Somethingwithproof\Smokeping\Pages::ADMIN[0];
$operator = 'smokeping_operator.php';
api_plugin_load_realms();
$old = [$view => $user_auth_realm_filenames[$view], $admin => $user_auth_realm_filenames[$admin]];
// Emulate an installed two-realm release without modifying the existing IDs.
$operatorId = $user_auth_realm_filenames[$operator];
db_execute_prepared('DELETE FROM user_auth_realm WHERE realm_id = ?', [$operatorId]);
db_execute_prepared('DELETE FROM plugin_realms WHERE id = ?', [$operatorId - 100]);
db_execute("DELETE FROM plugin_hooks WHERE name = 'smokeping' AND hook = 'auth_alternate_realms'");
db_execute("INSERT INTO user_auth (username, password, realm, full_name, enabled) VALUES ('smokeping_role_test', '', 0, 'Role test', 'on')");
$user = (int) db_fetch_insert_id();
db_execute_prepared('INSERT INTO user_auth_realm (user_id, realm_id) VALUES (?, ?)', [$user, $old[$view]]);
$_SESSION = ['sess_user_id' => $user];
plugin_smokeping_upgrade();
api_plugin_load_realms();
$result = [
	'ids_preserved' => $old === [$view => $user_auth_realm_filenames[$view], $admin => $user_auth_realm_filenames[$admin]],
	'viewer_grants' => array_map('intval', array_column(db_fetch_assoc_prepared('SELECT realm_id FROM user_auth_realm WHERE user_id = ?', [$user]), 'realm_id')),
	'expected_view' => $old[$view],
	'hook_enabled'  => (int) db_fetch_cell("SELECT status FROM plugin_hooks WHERE name = 'smokeping' AND hook = 'auth_alternate_realms'"),
];
$realms = [$view => $user_auth_realm_filenames[$view], $operator => $user_auth_realm_filenames[$operator], $admin => $user_auth_realm_filenames[$admin]];
$check  = static function () use ($user, $view, $operator, $admin): array {
	$_SESSION = ['sess_user_id' => $user];
	api_plugin_load_realms();
	smokeping_auth_alternate_realms();

	return [api_plugin_user_realm_auth($view), api_plugin_user_realm_auth($operator), api_plugin_user_realm_auth($admin)];
};

foreach (['viewer' => $view, 'operator' => $operator, 'admin' => $admin] as $role => $file) {
	db_execute_prepared('DELETE FROM user_auth_realm WHERE user_id = ?', [$user]);
	db_execute_prepared('INSERT INTO user_auth_realm (user_id, realm_id) VALUES (?, ?)', [$user, $realms[$file]]);
	$result[$role] = $check();

	foreach ([$view, $operator, $admin] as $page) {
		$_SERVER['SCRIPT_NAME'] = '/plugins/smokeping/' . $page;
		$_SESSION = [];
		api_plugin_load_realms();
		smokeping_auth_alternate_realms();
		$deferred = $user_auth_realm_filenames[$page] === -1;
		// Both cookie restore and Basic auth establish the user after the hook.
		$_SESSION = ['sess_user_id' => $user];
		$current_user = db_fetch_row_prepared('SELECT * FROM user_auth WHERE id = ?', [$user]);
		$allowed = smokeping_page_allowed();
		$result['first_request'][$role][] = [$deferred, $allowed, $user_auth_realm_filenames[$page] > 0];
	}
}
// A valid realm never enables a disabled account after authentication restore.
db_execute_prepared("UPDATE user_auth SET enabled = '' WHERE id = ?", [$user]);
foreach ([$view, $operator, $admin] as $page) {
	$_SERVER['SCRIPT_NAME'] = '/plugins/smokeping/' . $page;
	$_SESSION = [];
	api_plugin_load_realms();
	smokeping_auth_alternate_realms();
	$_SESSION = ['sess_user_id' => $user];
	$current_user = db_fetch_row_prepared('SELECT * FROM user_auth WHERE id = ?', [$user]);
	$result['disabled_user'][] = smokeping_page_allowed();
}
db_execute_prepared("UPDATE user_auth SET enabled = 'on' WHERE id = ?", [$user]);
db_execute_prepared('DELETE FROM user_auth_realm WHERE user_id = ?', [$user]);
$result['revoked'] = $check();
foreach ([$view, $operator, $admin] as $page) {
	$_SERVER['SCRIPT_NAME'] = '/plugins/smokeping/' . $page;
	$_SESSION = [];
	api_plugin_load_realms();
	smokeping_auth_alternate_realms();
	$_SESSION = ['sess_user_id' => $user];
	$current_user = db_fetch_row_prepared('SELECT * FROM user_auth WHERE id = ?', [$user]);
	$result['first_request']['revoked'][] = smokeping_page_allowed();
}
db_execute("INSERT INTO user_auth_group (name, enabled) VALUES ('smokeping_role_test', 'on')");
$group = (int) db_fetch_insert_id();
db_execute_prepared('INSERT INTO user_auth_group_members (group_id, user_id) VALUES (?, ?)', [$group, $user]);
db_execute_prepared('INSERT INTO user_auth_group_realm (group_id, realm_id) VALUES (?, ?)', [$group, $realms[$operator]]);
$result['group_operator'] = $check();
db_execute_prepared("UPDATE user_auth_group SET enabled = '' WHERE id = ?", [$group]);
$result['disabled_group'] = $check();
db_execute_prepared('DELETE FROM user_auth_group_realm WHERE group_id = ?', [$group]);
db_execute_prepared('DELETE FROM user_auth_group_members WHERE group_id = ?', [$group]);
db_execute_prepared('DELETE FROM user_auth_group WHERE id = ?', [$group]);
db_execute_prepared('DELETE FROM user_auth WHERE id = ?', [$user]);
$_SESSION = [];
