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
// hide_disabled setting is on, which is its default. The fake host table has
// two enabled devices and one disabled device.
function smokeping_fake_host_table(): void {
	$GLOBALS['__smokeping_db_cell'] = fn (string $sql, array $params) => str_contains($sql, 'disabled') ? 2 : 3;
}

function smokeping_sees_every_device(): bool {
	return smokeping_user_sees_every_device(new Somethingwithproof\Smokeping\ConfigRepository(false, '/var/www/cacti/rra'));
}

afterEach(function () {
	$GLOBALS['__smokeping_user_settings'] = [];
	$GLOBALS['__smokeping_db_cell']       = 0;
	$GLOBALS['__operator_devices']        = [];
});

it('treats an unrestricted user with Hide Disabled on as seeing every device', function () {
	smokeping_fake_host_table();
	$GLOBALS['__smokeping_user_settings']['hide_disabled'] = 'on';
	$GLOBALS['__operator_devices']                         = [['id' => '1'], ['id' => '2']];

	expect(smokeping_sees_every_device())->toBeTrue();
});

it('treats an unrestricted user with Hide Disabled off as seeing every device', function () {
	smokeping_fake_host_table();
	$GLOBALS['__smokeping_user_settings']['hide_disabled'] = '';
	$GLOBALS['__operator_devices']                         = [['id' => '1'], ['id' => '2'], ['id' => '3']];

	expect(smokeping_sees_every_device())->toBeTrue();
});

it('refuses a user limited to some devices whatever Hide Disabled says', function (string $hideDisabled, array $devices) {
	smokeping_fake_host_table();
	$GLOBALS['__smokeping_user_settings']['hide_disabled'] = $hideDisabled;
	$GLOBALS['__operator_devices']                         = $devices;

	expect(smokeping_sees_every_device())->toBeFalse();
})->with([
	'on'  => ['on', [['id' => '1']]],
	'off' => ['', [['id' => '1'], ['id' => '2']]],
]);

it('counts disabled devices only when asked to leave them out', function () {
	smokeping_fake_host_table();
	$repository = new Somethingwithproof\Smokeping\ConfigRepository(false, '/var/www/cacti/rra');

	expect($repository->deviceCount())->toBe(3)
		->and($repository->deviceCount(true))->toBe(2);
});
