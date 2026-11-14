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

use Somethingwithproof\Smokeping\RelinkGuard;

describe('RelinkGuard', function () {
	it('allows a free new target and one already on the old target\'s device', function (int $oldHost, int $newHost) {
		expect(RelinkGuard::refusal($oldHost, $newHost, 1, 1, 1))->toBeNull();
	})->with([
		'unmapped both' => [0, 0],
		'old mapped, new free' => [5, 0],
		'same device' => [5, 5],
	]);

	it('refuses a new target mapped to another device', function (int $oldHost, int $newHost) {
		expect(RelinkGuard::refusal($oldHost, $newHost, 1, 1, 1))->toContain("mapped to device $newHost");
	})->with([
		'old on A, new on B' => [5, 9],
		'old unmapped, new on B' => [0, 9],
	]);

	it('refuses a target on a poller other than the reader\'s', function (int $old, int $new) {
		expect(RelinkGuard::refusal(0, 0, $old, $new, 1))->toContain('poller that runs the reader');
	})->with([
		'old elsewhere' => [2, 1],
		'new elsewhere' => [1, 2],
		'both elsewhere' => [3, 3],
	]);

	it('refuses when the old target has data sources on a device it is not mapped to', function (int $oldHost, array $objectHosts) {
		expect(RelinkGuard::refusal($oldHost, 0, 1, 1, 1, $objectHosts))->toContain('not mapped to');
	})->with([
		'unmapped, objects on B' => [0, [9]],
		'mapped to A, objects on A and B' => [5, [5, 9]],
	]);

	it('allows data sources on the mapped device only', function () {
		expect(RelinkGuard::refusal(5, 0, 1, 1, 1, [5]))->toBeNull()
			->and(RelinkGuard::refusal(0, 0, 1, 1, 1, []))->toBeNull();
	});
});
