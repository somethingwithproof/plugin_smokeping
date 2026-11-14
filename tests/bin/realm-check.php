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
 * Proves that Cacti's own auth.php gates every plugin page by realm, over
 * real HTTP. Run from the Cacti root against a disposable, installed Cacti
 * with the plugin enabled and Cacti served at $argv[1]:
 *
 *   php -S 127.0.0.1:8080 -t / &
 *   php plugins/smokeping/tests/bin/realm-check.php http://127.0.0.1:8080/cacti/
 *
 * It creates three local users (no plugin realm, SmokePing View, and
 * SmokePing Admin), logs each in through the login form, and requests every
 * plugin page. Exits non-zero on any unexpected result.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/auth.php');
require_once($config['base_path'] . '/plugins/smokeping/setup.php');

if (getenv('SMOKEPING_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Refusing to create users: set SMOKEPING_TEST_DATABASE=disposable\n");
	exit(2);
}

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080/cacti/', '/') . '/';

smokeping_boot();

$realm_ids = array();

foreach (\Cacti\Smokeping\Pages::realms() as $realm) {
	$realm_ids[$realm['files'][0]] = db_fetch_cell_prepared('SELECT id + 100 FROM plugin_realms WHERE plugin = ? AND file = ?', array('smokeping', implode(',', $realm['files'])));
}

$view  = $realm_ids[\Cacti\Smokeping\Pages::VIEW];
$admin = $realm_ids[\Cacti\Smokeping\Pages::ADMIN[0]];

if (!$view || !$admin) {
	fwrite(STDERR, "Plugin realms are not registered\n");
	exit(2);
}

// Realm 8 is Console Access and 7 View Graphs; neither grants a plugin page.
$users = array(
	'sp_none'  => array(7, 8),
	'sp_view'  => array(7, 8, $view),
	'sp_admin' => array(7, 8, $view, $admin),
);

$password = 'Smoke-' . bin2hex(random_bytes(6));

foreach ($users as $username => $realms) {
	db_execute_prepared('DELETE FROM user_auth WHERE username = ?', array($username));
	db_execute_prepared("INSERT INTO user_auth (username, password, realm, full_name, must_change_password, show_tree, show_list, show_preview, graph_settings, login_opts, policy_graphs, policy_trees, policy_hosts, policy_graph_templates, enabled)
		VALUES (?, ?, 0, ?, '', 'on', 'on', 'on', 'on', 1, 1, 1, 1, 1, 'on')",
		array($username, password_hash($password, PASSWORD_DEFAULT), $username));

	$id = db_fetch_cell_prepared('SELECT id FROM user_auth WHERE username = ?', array($username));

	foreach ($realms as $realm) {
		db_execute_prepared('INSERT INTO user_auth_realm (realm_id, user_id) VALUES (?, ?)', array($realm, $id));
	}
}

function realm_request($url, $jar, $post = null) {
	$ch = curl_init($url);

	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_COOKIEJAR      => $jar,
		CURLOPT_COOKIEFILE     => $jar,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_TIMEOUT        => 30,
	));

	if ($post !== null) {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	}

	$body = (string) curl_exec($ch);
	$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

	return array($code, $body);
}

$pages = array_merge(array(\Cacti\Smokeping\Pages::VIEW), \Cacti\Smokeping\Pages::ADMIN);

$expected = array(
	'sp_none'  => array(),
	'sp_view'  => array(\Cacti\Smokeping\Pages::VIEW),
	'sp_admin' => $pages,
);

$failures = 0;

foreach ($expected as $username => $allowed) {
	$jar = tempnam(sys_get_temp_dir(), 'realm');

	list(, $login) = realm_request($base . 'index.php', $jar);

	if (!preg_match('/csrfMagicToken\s*=\s*"([^"]+)"/', $login, $match)) {
		fwrite(STDERR, "No CSRF token on the login page\n");
		exit(2);
	}

	list(, $after) = realm_request($base . 'index.php', $jar, array(
		'__csrf_magic'   => $match[1],
		'action'         => 'login',
		'login_username' => $username,
		'login_password' => $password,
	));

	if (strpos($after, 'logout.php') === false) {
		print "FAIL $username could not log in\n";
		$failures++;

		continue;
	}

	foreach ($pages as $page) {
		list($code, $body) = realm_request($base . 'plugins/smokeping/' . $page, $jar);

		$denied = strpos($body, 'You are not permitted to access this section of Cacti.') !== false;
		$should = in_array($page, $allowed, true);
		$ok     = $should ? (!$denied && $code === 200 && strpos($body, 'SmokePing') !== false) : $denied;

		printf("%s %-9s %-24s HTTP %d %s\n", $ok ? 'PASS' : 'FAIL', $username, $page, $code, $denied ? 'denied' : 'allowed');

		$failures += $ok ? 0 : 1;
	}

	unlink($jar);
}

exit($failures > 0 ? 1 : 0);
