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

require_once __DIR__ . '/../Support/OperatorStubs.php';

// Cacti's get_allowed_devices() leaves disabled devices out while the user's
// hide_disabled setting is on, which is its default, and reads the setting
// from the session. Devices 1 and 2 are enabled; device 3 is disabled.
function smokeping_stub_devices(array $permitted): void {
	$GLOBALS['__smokeping_db_assoc'] = [['id' => '1'], ['id' => '2'], ['id' => '3']];
	$GLOBALS['__operator_devices']   = function () use ($permitted): array {
		$hide = ($_SESSION['sess_user_config_array']['hide_disabled'] ?? 'on') === 'on';

		return array_map(fn (int $id) => ['id' => (string) $id], array_values(array_filter($permitted, fn (int $id) => !($hide && $id === 3))));
	};
}

function smokeping_sees_every_device(): bool {
	return smokeping_user_sees_every_device(new Somethingwithproof\Smokeping\ConfigRepository(false, '/var/www/cacti/rra'));
}

beforeEach(function () {
	$_SESSION = ['sess_user_id' => 2];
});

afterEach(function () {
	$_SESSION                        = [];
	$GLOBALS['__smokeping_db_assoc'] = [];
	$GLOBALS['__operator_devices']   = [];
});

it('treats an unrestricted user with Hide Disabled on as seeing every device', function () {
	smokeping_stub_devices([1, 2, 3]);
	$_SESSION['sess_user_config_array'] = ['hide_disabled' => 'on'];

	expect(smokeping_sees_every_device())->toBeTrue();
});

it('treats an unrestricted user with Hide Disabled off as seeing every device', function () {
	smokeping_stub_devices([1, 2, 3]);
	$_SESSION['sess_user_config_array'] = ['hide_disabled' => ''];

	expect(smokeping_sees_every_device())->toBeTrue();
});

it('refuses a user denied only a disabled device, whatever Hide Disabled says', function (string $hideDisabled) {
	smokeping_stub_devices([1, 2]);
	$_SESSION['sess_user_config_array'] = ['hide_disabled' => $hideDisabled];

	expect(smokeping_sees_every_device())->toBeFalse();
})->with(['on', '']);

it('refuses a user limited to some enabled devices', function () {
	smokeping_stub_devices([1, 3]);
	$_SESSION['sess_user_config_array'] = ['hide_disabled' => 'on'];

	expect(smokeping_sees_every_device())->toBeFalse();
});

it('restores the session settings after the check', function () {
	smokeping_stub_devices([1, 2, 3]);
	$_SESSION['sess_user_config_array'] = ['hide_disabled' => 'on', 'other' => 'x'];
	smokeping_sees_every_device();

	expect($_SESSION['sess_user_config_array'])->toBe(['hide_disabled' => 'on', 'other' => 'x']);

	unset($_SESSION['sess_user_config_array']);
	smokeping_sees_every_device();

	expect($_SESSION)->not->toHaveKey('sess_user_config_array');
});
