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

use Somethingwithproof\Smokeping\Pages;

it('covers every shipped page script with exactly one realm', function () {
	$root    = dirname(__DIR__, 2);
	$scripts = array_map('basename', array_filter(glob($root . '/smokeping*.php'), fn ($f) => $f !== $root . '/setup.php'));
	$covered = array_merge(...array_map(fn ($realm) => $realm['files'], Pages::realms()));

	sort($scripts);
	sort($covered);

	expect($covered)->toBe($scripts)
		->and(array_unique($covered))->toBe($covered)
		->and(array_column(Pages::realms(), 'name'))->toBe(['SmokePing Operator', 'SmokePing View', 'SmokePing Admin']);
});
