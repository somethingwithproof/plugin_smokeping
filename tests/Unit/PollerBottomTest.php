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

beforeEach(function () {
	$GLOBALS['__smokeping_exec']    = [];
	$GLOBALS['__smokeping_log']     = [];
	$GLOBALS['__smokeping_options'] = [];
	$GLOBALS['config']['poller_id'] = 1;
});

it('launches the reader with escaped array arguments when the PHP binary is valid', function () {
	$GLOBALS['__smokeping_options']['path_php_binary'] = PHP_BINARY;

	smokeping_poller_bottom();

	expect($GLOBALS['__smokeping_exec'])->toHaveCount(1)
		->and($GLOBALS['__smokeping_exec'][0]['filename'])->toBe(PHP_BINARY)
		->and($GLOBALS['__smokeping_exec'][0]['args'])->toBe(['-q', dirname(__DIR__, 2) . '/poller_smokeping.php', '--poller=1']);
});

it('logs an error and launches nothing when the PHP binary is not usable', function (string $binary) {
	$GLOBALS['__smokeping_options']['path_php_binary'] = $binary;

	smokeping_poller_bottom();

	expect($GLOBALS['__smokeping_exec'])->toBe([])
		->and($GLOBALS['__smokeping_log'])->toHaveCount(1)
		->and($GLOBALS['__smokeping_log'][0]['environ'])->toBe('SMOKEPING')
		->and($GLOBALS['__smokeping_log'][0]['message'])->toStartWith('ERROR: The SmokePing reader was not started');
})->with(['empty' => '', 'missing' => '/nonexistent/php', 'not executable' => __FILE__]);

it('does nothing on a poller that is not the SmokePing poller', function () {
	$GLOBALS['__smokeping_options']['path_php_binary']     = PHP_BINARY;
	$GLOBALS['__smokeping_options']['smokeping_poller_id'] = '2';

	smokeping_poller_bottom();

	expect($GLOBALS['__smokeping_exec'])->toBe([])
		->and($GLOBALS['__smokeping_log'])->toBe([]);
});

it('declares remote_poller so Cacti runs poller_bottom on a remote collector', function () {
	// Cacti 1.2.31 runs poller_bottom on a poller other than 1 only for
	// plugins whose capabilities include remote_poller:1 (#86).
	$info = parse_ini_file(dirname(__DIR__, 2) . '/INFO', true);

	expect($info['info']['capabilities'])->toContain('remote_collect:1')
		->and($info['info']['capabilities'])->toContain('remote_poller:1');
});
