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
 * Log in as the Cacti admin user over HTTP and request a list of pages,
 * failing on any non-200 response, PHP error text, or missing Cacti footer.
 * Used to show that core pages keep loading with the plugin installed on an
 * unsupported PHP. Disposable databases only: it resets the admin password.
 * Must parse on PHP 8.0.
 *
 *   php plugins/smokeping/tests/bin/page-check.php http://127.0.0.1:8080/cacti/ index.php host.php ...
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');

if (getenv('SMOKEPING_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Refusing to reset the admin password: set SMOKEPING_TEST_DATABASE=disposable\n");
	exit(2);
}

$base     = rtrim($argv[1], '/') . '/';
$pages    = array_slice($argv, 2);
$password = 'Smoke-' . bin2hex(random_bytes(6));

db_execute_prepared("UPDATE user_auth SET password = ?, must_change_password = '' WHERE id = 1", array(password_hash($password, PASSWORD_DEFAULT)));

$jar = tempnam(sys_get_temp_dir(), 'page');

function page_get($url, $jar, $post = null) {
	$ch = curl_init($url);

	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60));

	if ($post !== null) {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	}

	$body = (string) curl_exec($ch);

	return array((int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $body);
}

list(, $login) = page_get($base . 'index.php', $jar);
preg_match('/csrfMagicToken\s*=\s*"([^"]+)"/', $login, $match);
page_get($base . 'index.php', $jar, array('__csrf_magic' => isset($match[1]) ? $match[1] : '', 'action' => 'login', 'login_username' => 'admin', 'login_password' => $password));

$failures = 0;

foreach ($pages as $page) {
	list($code, $body) = page_get($base . $page, $jar);

	$error = preg_match('/(Fatal error|Parse error|Warning:|Uncaught)/', $body, $m) ? $m[1] : '';
	$ok    = $code === 200 && $error === '' && strpos($body, 'logout.php') !== false;

	printf("%s %-40s HTTP %d %s\n", $ok ? 'PASS' : 'FAIL', $page, $code, $error);

	if (strpos($page, 'smokeping') !== false && preg_match('/requires PHP [0-9.]+ or later/', $body, $r)) {
		print "     page shows: The SmokePing plugin " . $r[0] . PHP_EOL;
	}

	$failures += $ok ? 0 : 1;
}

unlink($jar);

exit($failures > 0 ? 1 : 0);
