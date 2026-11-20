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

// A small model of Cacti 1.2's device permissions. Devices 1 and 2 are
// enabled; device 3 is disabled. A user either has simple device permissions
// (every device) or a policy that allows $GLOBALS['__dv_permitted'].
//
// Hide Disabled is read the way Cacti reads it for a logged-in user: from
// settings_user by user id, defaulting to 'on'. read_user_setting() with
// $user > 0 never consults $_SESSION, so a session value changes nothing.
$GLOBALS['__dv_settings_user'] = [];
$GLOBALS['__dv_simple']        = false;
$GLOBALS['__dv_permitted']     = [];
$GLOBALS['__dv_valid_user']    = true;
$GLOBALS['__dv_policies']      = null;
$GLOBALS['__dv_rows']          = null;
$GLOBALS['__dv_all_rows']      = null;
$GLOBALS['__dv_hosts']         = [1, 2, 3];

const SMOKEPING_DV_DISABLED = [3];

if (!function_exists('read_user_setting')) {
	function read_user_setting($config_name, $default = false, $force = false, $user = 0) {
		return $GLOBALS['__dv_settings_user'][$user][$config_name] ?? 'on';
	}
}

if (!function_exists('auth_valid_user')) {
	function auth_valid_user($user_id) {
		return $GLOBALS['__dv_valid_user'];
	}
}

if (!function_exists('get_simple_device_perms')) {
	function get_simple_device_perms($user) {
		return $GLOBALS['__dv_simple'];
	}
}

if (!function_exists('get_policies')) {
	function get_policies($user_id) {
		return $GLOBALS['__dv_policies'] ?? [['id' => $user_id, 'type' => 'user', 'name' => 'user', 'policy_graphs' => 2, 'policy_hosts' => 2, 'policy_graph_templates' => 2, 'policy_trees' => 1]];
	}
}

if (!function_exists('get_policy_where')) {
	function get_policy_where($graph_auth_method, $policies, $sql_where) {
		return $sql_where . ' AND (POLICY)';
	}
}

// The device query: every host, narrowed to the permitted ones when a policy
// condition was added, and without disabled hosts when the SQL filters on
// h.disabled as get_allowed_devices() does under Hide Disabled. Any other
// query is ConfigRepository::deviceIds(), every non-deleted host.
function smokeping_dv_query(string $sql): mixed {
	$ids = $GLOBALS['__dv_hosts'];

	if (!str_contains($sql, 'DISTINCT h.id') && $GLOBALS['__dv_all_rows'] !== null) {
		return $GLOBALS['__dv_all_rows'];
	}

	if (str_contains($sql, 'DISTINCT h.id')) {
		if ($GLOBALS['__dv_rows'] !== null) {
			return $GLOBALS['__dv_rows'];
		}

		if (str_contains($sql, 'POLICY')) {
			$ids = array_values(array_intersect($ids, $GLOBALS['__dv_permitted']));
		}

		if (str_contains($sql, 'h.disabled')) {
			$ids = array_values(array_diff($ids, SMOKEPING_DV_DISABLED));
		}
	}

	return array_map(fn (int $id): array => ['id' => (string) $id], $ids);
}

// Cacti's get_allowed_devices() over the same model, Hide Disabled included.
function smokeping_dv_cacti_allowed(): array {
	$user  = (int) ($_SESSION['sess_user_id'] ?? 0);
	$ids   = $GLOBALS['__dv_simple'] ? [1, 2, 3] : $GLOBALS['__dv_permitted'];
	$hide  = read_user_setting('hide_disabled', false, false, $user) == 'on';

	return array_map(fn (int $id): array => ['id' => (string) $id], array_values(array_filter($ids, fn (int $id): bool => !($hide && in_array($id, SMOKEPING_DV_DISABLED, true)))));
}

function smokeping_dv_user(bool $simple, array $permitted = [], string $hideDisabled = 'on'): void {
	$GLOBALS['__dv_simple']                         = $simple;
	$GLOBALS['__dv_permitted']                      = $permitted;
	$GLOBALS['__dv_settings_user'][2]['hide_disabled'] = $hideDisabled;
}

function smokeping_sees_every_device(): bool {
	return smokeping_user_sees_every_device(new Somethingwithproof\Smokeping\ConfigRepository(false, '/var/www/cacti/rra'));
}

beforeEach(function () {
	$_SESSION = ['sess_user_id' => 2];
	$GLOBALS['__smokeping_options']   = ['auth_method' => '1', 'graph_auth_method' => '1'];
	$GLOBALS['__smokeping_db_assoc']  = fn (string $sql): mixed => smokeping_dv_query($sql);
	$GLOBALS['__operator_devices']    = fn (): array => smokeping_dv_cacti_allowed();
	$GLOBALS['__dv_settings_user']    = [];
	$GLOBALS['__dv_valid_user']       = true;
	$GLOBALS['__dv_policies']         = null;
	$GLOBALS['__dv_rows']             = null;
	$GLOBALS['__dv_all_rows']         = null;
	$GLOBALS['__dv_hosts']            = [1, 2, 3];
});

afterEach(function () {
	$_SESSION                         = [];
	$GLOBALS['__smokeping_options']   = [];
	$GLOBALS['__smokeping_db_assoc']  = [];
	$GLOBALS['__operator_devices']    = [];
});

it('models Cacti: get_allowed_devices() leaves the disabled device out under the stored Hide Disabled, whatever the session says', function () {
	smokeping_dv_user(true);
	$_SESSION['sess_user_config_array'] = ['hide_disabled' => ''];

	expect(array_column(get_allowed_devices(), 'id'))->toBe(['1', '2']);
});

it('treats an unrestricted user with Hide Disabled on as seeing every device', function (bool $simple) {
	smokeping_dv_user($simple, [1, 2, 3], 'on');

	expect(smokeping_sees_every_device())->toBeTrue();
})->with(['simple permissions' => true, 'policy allowing every device' => false]);

it('treats an unrestricted user with Hide Disabled off as seeing every device', function () {
	smokeping_dv_user(true, [], '');

	expect(smokeping_sees_every_device())->toBeTrue();
});

it('refuses a user denied only a disabled device, whatever Hide Disabled says', function (string $hideDisabled) {
	smokeping_dv_user(false, [1, 2], $hideDisabled);

	expect(smokeping_sees_every_device())->toBeFalse();
})->with(['on', '']);

it('refuses a user limited to some enabled devices', function () {
	smokeping_dv_user(false, [1, 3]);

	expect(smokeping_sees_every_device())->toBeFalse();
});

it('leaves the session alone', function () {
	smokeping_dv_user(true);
	$_SESSION['sess_user_config_array'] = ['hide_disabled' => 'on', 'other' => 'x'];
	smokeping_sees_every_device();

	expect($_SESSION)->toBe(['sess_user_id' => 2, 'sess_user_config_array' => ['hide_disabled' => 'on', 'other' => 'x']]);
});

it('treats every user as unrestricted when Cacti authentication is off, as Cacti does', function () {
	smokeping_dv_user(false, [1]);
	$GLOBALS['__smokeping_options']['auth_method'] = '0';

	expect(smokeping_sees_every_device())->toBeTrue();
});

it('refuses when the allowed devices cannot be worked out', function (Closure $break) {
	smokeping_dv_user(false, [1, 2, 3]);
	$break();

	expect(smokeping_sees_every_device())->toBeFalse();
})->with([
	'no session user'             => [fn () => $_SESSION = []],
	'a user Cacti does not know'  => [fn () => $GLOBALS['__dv_valid_user'] = false],
	'no authentication setting'   => [fn () => $GLOBALS['__smokeping_options']['auth_method'] = ''],
	'an unknown permission method' => [fn () => $GLOBALS['__smokeping_options']['graph_auth_method'] = '0'],
	'no policies'                 => [fn () => $GLOBALS['__dv_policies'] = []],
	'a policy without its columns' => [fn () => $GLOBALS['__dv_policies'] = [['id' => 2, 'type' => 'user']]],
	'a failed device query'       => [fn () => $GLOBALS['__dv_rows'] = false],
	'a device row without an id'  => [fn () => $GLOBALS['__dv_rows'] = [['id' => '1'], ['id' => '2'], ['name' => 'x']]],
	'a failed full device query'  => [fn () => $GLOBALS['__dv_all_rows'] = false],
	'a full device row without an id' => [fn () => $GLOBALS['__dv_all_rows'] = [['id' => '1'], ['name' => 'x']]],
]);

it('refuses a user allowed no devices when the full device query fails', function () {
	smokeping_dv_user(false, []);
	$GLOBALS['__dv_all_rows'] = false;

	expect(smokeping_allowed_device_ids(new Somethingwithproof\Smokeping\ConfigRepository(false, '/var/www/cacti/rra')))->toBe([])
		->and(smokeping_sees_every_device())->toBeFalse();
});

it('lets a user with the realm through when Cacti has no devices at all', function () {
	smokeping_dv_user(false, []);
	$GLOBALS['__dv_hosts'] = [];

	expect(smokeping_sees_every_device())->toBeTrue();
});
