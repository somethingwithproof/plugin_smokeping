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
 * Integration bootstrap. The tests drive Cacti in separate PHP processes, as
 * plugins.php and the CLI tools do, so nothing from Cacti is loaded here.
 */

$cacti = rtrim((string) getenv('CACTI_PATH'), '/');

if ($cacti === '' || !is_file($cacti . '/include/config.php') || !is_dir($cacti . '/plugins/smokeping')) {
	fwrite(STDERR, "Set CACTI_PATH to an installed Cacti with this plugin at plugins/smokeping.\n");
	exit(2);
}

if (getenv('SMOKEPING_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set SMOKEPING_TEST_DATABASE=disposable: these tests install and uninstall the plugin.\n");
	exit(2);
}

/**
 * Run tests/bin/lifecycle.php in a fresh PHP process and decode its state.
 *
 * @return array<string, mixed>
 */
function lifecycle(string $action, string $argument = ''): array {
	$cacti   = rtrim((string) getenv('CACTI_PATH'), '/');
	$command = [PHP_BINARY, $cacti . '/plugins/smokeping/tests/bin/lifecycle.php', $action];

	if ($argument !== '') {
		$command[] = $argument;
	}

	$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cacti);
	$stdout  = stream_get_contents($pipes[1]);
	$stderr  = stream_get_contents($pipes[2]);

	fclose($pipes[1]);
	fclose($pipes[2]);

	$status = proc_close($process);
	$state  = json_decode((string) $stdout, true);

	if ($status !== 0 || !is_array($state)) {
		throw new RuntimeException("lifecycle.php $action failed ($status): $stderr $stdout");
	}

	$state['stderr'] = $stderr;

	return $state;
}
