<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

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
