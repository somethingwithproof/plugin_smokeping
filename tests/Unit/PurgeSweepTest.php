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

use Somethingwithproof\Smokeping\Schema;

beforeEach(function () {
	$GLOBALS['__smokeping_exec']        = [];
	$GLOBALS['__smokeping_log']         = [];
	$GLOBALS['__smokeping_options']     = ['path_php_binary' => PHP_BINARY];
	$GLOBALS['__smokeping_db_exec']     = [];
	$GLOBALS['config']['poller_id']     = 1;
	$GLOBALS['__smokeping_purge_queue'] = [
		['id' => 7, 'name' => '/smokeping/Europe/paris.rrd'],
		['id' => 8, 'name' => 'localhost_load_1.rrd'],
	];
});

/**
 * @return list<int> ids of the purge rows the code deleted
 */
function smokeping_dropped_purges(): array {
	$ids = [];

	foreach ($GLOBALS['__smokeping_db_exec'] as $statement) {
		if (str_starts_with($statement['sql'], 'DELETE FROM data_source_purge_action')) {
			$ids[] = $statement['params'][0];
		}
	}

	return $ids;
}

it('sweeps on hooks that core runs before it starts poller_maintenance.php', function () {
	// poller.php starts the purge in the background before the poller_bottom
	// hooks, so a sweep there would race it.
	expect(Schema::HOOKS['poller_top'])->toBe('smokeping_purge_sweep')
		->and(Schema::HOOKS['poller_finishing'])->toBe('smokeping_purge_sweep')
		->and(array_keys(Schema::HOOKS, 'smokeping_purge_sweep', true))->toBe(['poller_top', 'poller_finishing']);
});

it('removes the queued purge rows under the mount and keeps the rest', function () {
	smokeping_purge_sweep();

	expect(smokeping_dropped_purges())->toBe([7]);
});

it('leaves the queue alone on a remote poller, which does not own it', function () {
	$GLOBALS['config']['poller_id'] = 2;

	smokeping_purge_sweep();

	expect(smokeping_dropped_purges())->toBe([]);
});

it('does not sweep from poller_bottom, after the purge has started', function () {
	smokeping_poller_bottom();

	expect(smokeping_dropped_purges())->toBe([])
		->and($GLOBALS['__smokeping_exec'])->toHaveCount(1);
});
