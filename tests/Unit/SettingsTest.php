<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

use Somethingwithproof\Smokeping\Settings;

it('applies defaults for unset settings, with the mount under rra_path', function () {
	$settings = Settings::fromRow([], '/var/www/cacti/rra/');

	expect($settings->configFile)->toBe('/etc/smokeping/config')
		->and($settings->datadir)->toBe('/var/lib/smokeping')
		->and($settings->mountPath)->toBe('/var/www/cacti/rra/smokeping')
		->and($settings->pollerId)->toBe(1)
		->and($settings->readerBudget)->toBe(120)
		->and($settings->keepTablesOnUninstall)->toBeFalse();
});

it('falls back to defaults for malformed numbers', function () {
	$settings = Settings::fromRow(['smokeping_reader_budget' => '0', 'smokeping_poller_id' => 'two', 'smokeping_inventory_interval' => '-1'], '/r');

	expect($settings->readerBudget)->toBe(120)
		->and($settings->pollerId)->toBe(1)
		->and($settings->inventoryInterval)->toBe(300);
});

it('defines a form field for every setting it reads', function () {
	$form = Settings::form('/r');

	foreach (Settings::NAMES as $name) {
		expect($form)->toHaveKey($name);
	}

	expect($form['smokeping_mount_path']['default'])->toBe('/r/smokeping');
});
