<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

/*
 * Save the SmokePing settings tab through Cacti's settings.php over HTTP and
 * confirm every value reaches the settings table and is shown again on the
 * tab. Disposable databases only: it resets the admin password.
 *
 *   php plugins/smokeping/tests/bin/settings-check.php http://127.0.0.1:8080/cacti/
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');

if (getenv('SMOKEPING_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set SMOKEPING_TEST_DATABASE=disposable\n");
	exit(2);
}

$base     = rtrim($argv[1], '/') . '/';
$password = 'Smoke-' . bin2hex(random_bytes(6));
$jar      = tempnam(sys_get_temp_dir(), 'settings');

db_execute_prepared("UPDATE user_auth SET password = ?, must_change_password = '' WHERE id = 1", array(password_hash($password, PASSWORD_DEFAULT)));

function settings_request($url, $jar, $post = null) {
	$ch = curl_init($url);

	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60));

	if ($post !== null) {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	}

	return (string) curl_exec($ch);
}

function settings_token($body) {
	return preg_match('/csrfMagicToken\s*=\s*"([^"]+)"/', $body, $m) ? $m[1] : '';
}

$login = settings_request($base . 'index.php', $jar);
settings_request($base . 'index.php', $jar, array('__csrf_magic' => settings_token($login), 'action' => 'login', 'login_username' => 'admin', 'login_password' => $password));

$values = array(
	'smokeping_poller_id'             => '1',
	'smokeping_config_file'           => '/etc/smokeping/config-test',
	'smokeping_perl_binary'           => '/usr/bin/perl',
	'smokeping_lib_dir'               => '/usr/share/smokeping',
	'smokeping_datadir'               => '/srv/smokeping/data',
	'smokeping_mount_path'            => $config['rra_path'] . '/smokeping',
	'smokeping_reader_budget'         => '45',
	'smokeping_inventory_interval'    => '600',
	'smokeping_freshness_allowance'   => '30',
	'smokeping_uninstall_keep_tables' => 'on',
);

$tab = settings_request($base . 'settings.php?tab=smokeping', $jar);

settings_request($base . 'settings.php', $jar, array_merge($values, array('__csrf_magic' => settings_token($tab), 'action' => 'save', 'tab' => 'smokeping')));

$failures = 0;
$shown    = settings_request($base . 'settings.php?tab=smokeping', $jar);

foreach ($values as $name => $value) {
	$stored = db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', array($name));
	$ok     = $stored === $value && strpos($shown, "id='$name'") !== false;

	printf("%s %-32s stored=%s\n", $ok ? 'PASS' : 'FAIL', $name, var_export($stored, true));

	$failures += $ok ? 0 : 1;
}

unlink($jar);

exit($failures > 0 ? 1 : 0);
