<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

use Somethingwithproof\Smokeping\Pages;

it('covers every shipped page script with exactly one realm', function () {
	$root    = dirname(__DIR__, 2);
	$scripts = array_map('basename', array_filter(glob($root . '/smokeping*.php'), fn ($f) => $f !== $root . '/setup.php'));
	$covered = array_merge(...array_map(fn ($realm) => $realm['files'], Pages::realms()));

	sort($scripts);
	sort($covered);

	expect($covered)->toBe($scripts)
		->and(array_unique($covered))->toBe($covered)
		->and(array_column(Pages::realms(), 'name'))->toBe(['SmokePing View', 'SmokePing Admin']);
});
