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

use Somethingwithproof\Smokeping\AdminState;
use Somethingwithproof\Smokeping\Inventory;
use Somethingwithproof\Smokeping\InventoryError;
use Somethingwithproof\Smokeping\InventoryImporter;
use Somethingwithproof\Smokeping\Target;
use Somethingwithproof\Smokeping\TargetId;
use Somethingwithproof\Smokeping\TargetMode;
use Somethingwithproof\Smokeping\TargetPath;

/**
 * tests/Fixtures/inventory.json is the inventory of tests/Fixtures/inventory.conf,
 * identical on SmokePing 2.7.3, 2.8.2 and 2.9.0 apart from the version
 * (tests/oracle/run.sh checks all three).
 */
function fixture_inventory(string $version = '2.009000'): Inventory {
	$json = str_replace(['@DIR@', '@VERSION@'], ['/var/lib/smokeping', $version], (string) file_get_contents(dirname(__DIR__) . '/Fixtures/inventory.json'));

	return Inventory::fromJson($json, 1);
}

function stored(Target $target, int $id, bool $present = true): Target {
	return new Target(TargetId::fromInt($id), $target->pollerId, $target->path, $target->host, $target->probe, $target->menu, $target->title, $target->slaves, $target->noMasterPoll, $target->step, $target->pings, TargetMode::Observed, $target->adminState, $present, 100, 200, $target->probeDesc, $target->alerts);
}

function with_path(Target $target, string $path): Target {
	return new Target(null, $target->pollerId, TargetPath::fromString($path), $target->host, $target->probe, $target->menu, $target->title, $target->slaves, $target->noMasterPoll, $target->step, $target->pings, $target->mode, $target->adminState, true, 0, 0, $target->probeDesc, $target->alerts);
}

describe('Inventory::fromJson', function () {
	it('reads every field of the fixture for both SmokePing lines', function (string $version) {
		$inventory = fixture_inventory($version);
		$paths     = array_map(fn (Target $t) => $t->path->value, $inventory->targets);

		expect($inventory->smokepingVersion)->toBe($version)
			->and($paths)->toBe(['DNS/resolver', 'Europe/berlin', 'Europe/berlin/core', 'Europe/paris', 'Slaves/remote'])
			->and($inventory->warnings)->toBe([]);

		[$dns, $berlin, , $paris, $remote] = $inventory->targets;

		expect($paris->slaves)->toBe(['boston', 'sydney.au'])
			->and($paris->title)->toBe('Paris gateway')
			->and($paris->locations())->toBe(['', 'boston', 'sydney.au'])
			->and([$paris->step, $paris->pings])->toBe([300, 20])
			->and([$berlin->probe, $berlin->step, $berlin->pings])->toBe(['FPingFast', 60, 10])
			->and([$dns->probe, $dns->pings])->toBe(['DNS', 5])
			->and($remote->noMasterPoll)->toBeTrue()
			->and($remote->locations())->toBe(['boston']);
	})->with(['2.8.2' => '2.008002', '2.9.0' => '2.009000']);

	it('rejects output that is not the expected document', function (string $json) {
		expect(fn () => Inventory::fromJson($json, 1))->toThrow(InventoryError::class);
	})->with(['', 'ERROR: parse failed', '[]', '{"format":2,"targets":[]}', '{"format":1}']);

	it('skips malformed entries with a warning and keeps the rest', function () {
		$inventory = Inventory::fromJson(json_encode(['format' => 1, 'targets' => [
			['path' => '../etc/passwd', 'step' => 300, 'pings' => 20],
			['path' => 'A/ok', 'host' => 'h', 'step' => 300, 'pings' => 20],
			['path' => 'A/ok', 'host' => 'h', 'step' => 300, 'pings' => 20],
			['path' => 'A/nostep', 'host' => 'h', 'pings' => 20],
			'not an object',
		]]), 1);

		expect(array_map(fn (Target $t) => $t->path->value, $inventory->targets))->toBe(['A/ok'])
			->and($inventory->warnings)->toHaveCount(4);
	});
});

describe('InventoryImporter', function () {
	it('adds every target on the first import', function () {
		$plan = (new InventoryImporter())->plan(fixture_inventory(), []);

		expect($plan->added)->toHaveCount(5)
			->and($plan->changed)->toBe([])
			->and($plan->disappeared)->toBe([])
			->and($plan->proposals)->toBe([]);
	});

	it('changes nothing when the inventory is unchanged', function () {
		$inventory = fixture_inventory();
		$existing  = array_map(fn (Target $t) => stored($t, 10 + crc32($t->path->value) % 1000), $inventory->targets);

		expect((new InventoryImporter())->plan($inventory, $existing)->isEmpty())->toBeTrue();
	});

	it('proposes a re-link for a renamed target and never applies it', function () {
		$inventory = fixture_inventory();
		$existing  = [];

		foreach ($inventory->targets as $i => $target) {
			$existing[] = stored($target->path->value === 'Europe/paris' ? with_path($target, 'Europe/paris_old') : $target, $i + 1);
		}

		$plan = (new InventoryImporter())->plan($inventory, $existing);

		expect(array_map(fn (Target $t) => $t->path->value, $plan->added))->toBe(['Europe/paris'])
			->and(array_map(fn (Target $t) => $t->path->value, $plan->disappeared))->toBe(['Europe/paris_old'])
			->and($plan->proposals)->toBe([['old_id' => 4, 'new_path' => 'Europe/paris']])
			->and($plan->changed)->toBe([]);
	});

	it('does not propose a re-link when host or probe differ', function () {
		$inventory = fixture_inventory();
		$existing  = [];

		foreach ($inventory->targets as $i => $target) {
			if ($target->path->value === 'Europe/paris') {
				$target = new Target(null, 1, TargetPath::fromString('Europe/old'), '198.51.100.1', $target->probe, '', '', [], false, 300, 20, TargetMode::Observed, AdminState::Enabled, true, 0, 0);
			}

			$existing[] = stored($target, $i + 1);
		}

		expect((new InventoryImporter())->plan($inventory, $existing)->proposals)->toBe([]);
	});

	it('updates changed attributes in place and warns when step or pings change', function () {
		$inventory = fixture_inventory();
		$existing  = [];

		foreach ($inventory->targets as $i => $target) {
			if ($target->path->value === 'Europe/paris') {
				$target = new Target(null, 1, $target->path, $target->host, $target->probe, 'Old menu', $target->title, [], false, 300, 10, TargetMode::Observed, AdminState::Disabled, true, 0, 0);
			}

			$existing[] = stored($target, $i + 1);
		}

		$plan = (new InventoryImporter())->plan($inventory, $existing);

		expect($plan->changed)->toHaveCount(1)
			->and($plan->changed[0]->id?->value)->toBe(4)
			->and($plan->changed[0]->menu)->toBe('Paris')
			->and($plan->changed[0]->slaves)->toBe(['boston', 'sydney.au'])
			->and($plan->changed[0]->adminState)->toBe(AdminState::Disabled)
			->and($plan->warnings)->toHaveCount(1)
			->and($plan->warnings[0])->toContain('Europe/paris changed step or pings');
	});

	it('updates a target whose SmokePing alerts changed, for the health page', function () {
		$inventory = fixture_inventory();
		$existing  = [];

		foreach ($inventory->targets as $i => $target) {
			$stored = stored($target, $i + 1);

			if ($target->path->value === 'Europe/paris') {
				$stored = new Target($stored->id, 1, $target->path, $target->host, $target->probe, $target->menu, $target->title, $target->slaves, $target->noMasterPoll, $target->step, $target->pings, TargetMode::Observed, $target->adminState, true, 100, 200, $target->probeDesc, '');
			}

			$existing[] = $stored;
		}

		$plan = (new InventoryImporter())->plan($inventory, $existing);

		expect(array_map(fn (Target $t) => $t->path->value, $plan->changed))->toBe(['Europe/paris'])
			->and($plan->changed[0]->alerts)->toBe('someloss');
	});

	it('updates a target whose probe description changed, for its probe line', function () {
		$inventory = fixture_inventory();
		$existing  = [];

		foreach ($inventory->targets as $i => $target) {
			$stored = stored($target, $i + 1);

			if ($target->path->value === 'DNS/resolver') {
				$stored = new Target($stored->id, 1, $target->path, $target->host, $target->probe, $target->menu, $target->title, $target->slaves, $target->noMasterPoll, $target->step, $target->pings, TargetMode::Observed, $target->adminState, true, 100, 200, '', $target->alerts);
			}

			$existing[] = $stored;
		}

		$plan = (new InventoryImporter())->plan($inventory, $existing);

		expect(array_map(fn (Target $t) => $t->path->value, $plan->changed))->toBe(['DNS/resolver'])
			->and($plan->changed[0]->probeDesc)->toBe('DNS requests')
			->and($plan->warnings)->toBe([]);
	});

	it('marks a returning target present again', function () {
		$inventory = fixture_inventory();
		$existing  = array_map(fn (Target $t) => stored($t, 1 + crc32($t->path->value) % 1000, $t->path->value !== 'DNS/resolver'), $inventory->targets);

		$plan = (new InventoryImporter())->plan($inventory, $existing);

		expect(array_map(fn (Target $t) => $t->path->value, $plan->changed))->toBe(['DNS/resolver'])
			->and($plan->changed[0]->present)->toBeTrue();
	});
});
