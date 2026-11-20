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
use Somethingwithproof\Smokeping\Condition;
use Somethingwithproof\Smokeping\ExecutionState;
use Somethingwithproof\Smokeping\Freshness;
use Somethingwithproof\Smokeping\InventoryStatus;
use Somethingwithproof\Smokeping\Observation;
use Somethingwithproof\Smokeping\Target;
use Somethingwithproof\Smokeping\TargetId;
use Somethingwithproof\Smokeping\TargetMode;
use Somethingwithproof\Smokeping\TargetPath;

function target_row(array $override = []): array {
	return array_merge([
		'id'           => '12',
		'poller_id'    => '1',
		'path'         => 'Europe/Paris/gw1',
		'host'         => 'gw1.example.net',
		'probe'        => 'FPing',
		'menu'         => 'Gateway',
		'title'        => 'Paris gateway',
		'slaves'       => 'boston sydney.au',
		'nomasterpoll' => '0',
		'step'         => '300',
		'pings'        => '20',
		'mode'         => 'observed',
		'admin_state'  => 'enabled',
		'present'      => '1',
		'first_seen'   => '1700000000',
		'last_seen'    => '1700000300',
	], $override);
}

describe('TargetPath', function () {
	it('accepts SmokePing section names joined by slashes', function () {
		expect(TargetPath::fromString('Europe/Paris/gw-1_a')->value)->toBe('Europe/Paris/gw-1_a');
	});

	it('rejects paths that could escape the data directory or are not section names', function (string $path) {
		expect(fn () => TargetPath::fromString($path))->toThrow(InvalidArgumentException::class);
	})->with(['', '/a', 'a/', 'a//b', '../a', 'a/../b', 'a.b', 'a b', 'a;b', "gw1\n", "Europe/gw1\n", "Europe\n/gw1", str_repeat('a', 256)]);

	it('maps the master and slave locations to SmokePing file names', function () {
		$path = TargetPath::fromString('Europe/gw1');

		expect($path->rrdFile('/var/lib/cacti/rra/smokeping/'))->toBe('/var/lib/cacti/rra/smokeping/Europe/gw1.rrd')
			->and($path->rrdFile('/m', 'sydney.au'))->toBe('/m/Europe/gw1~sydney.au.rrd');
	});

	it('refuses location names that are not slave names', function (string $location) {
		expect(TargetPath::isLocation($location))->toBeFalse()
			->and(fn () => TargetPath::fromString('a')->rrdFile('/m', $location))->toThrow(InvalidArgumentException::class);
	})->with(['.', '..', '.hidden', 'a/b', 'a~b', 'a b', "sydney\n"]);
});

describe('TargetId', function () {
	it('rejects zero and negative ids', function () {
		expect(TargetId::fromInt(3)->value)->toBe(3)
			->and(fn () => TargetId::fromInt(0))->toThrow(InvalidArgumentException::class);
	});
});

describe('Target::fromRow', function () {
	it('normalizes a complete row', function () {
		$target = Target::fromRow(target_row());

		expect($target->id?->value)->toBe(12)
			->and($target->path->value)->toBe('Europe/Paris/gw1')
			->and($target->slaves)->toBe(['boston', 'sydney.au'])
			->and($target->noMasterPoll)->toBeFalse()
			->and($target->step)->toBe(300)
			->and($target->pings)->toBe(20)
			->and($target->mode)->toBe(TargetMode::Observed)
			->and($target->adminState)->toBe(AdminState::Enabled)
			->and($target->present)->toBeTrue()
			->and($target->locations())->toBe(['', 'boston', 'sydney.au']);
	});

	it('maps empty strings and NULL to column defaults', function (mixed $empty) {
		$target = Target::fromRow(target_row([
			'id' => $empty, 'poller_id' => $empty, 'host' => $empty, 'slaves' => $empty,
			'nomasterpoll' => $empty, 'step' => $empty, 'pings' => $empty,
			'mode' => $empty, 'admin_state' => $empty, 'present' => $empty,
		]));

		expect($target->id)->toBeNull()
			->and($target->pollerId)->toBe(1)
			->and($target->host)->toBe('')
			->and($target->slaves)->toBe([])
			->and($target->noMasterPoll)->toBeFalse()
			->and($target->step)->toBe(0)
			->and($target->pings)->toBe(0)
			->and($target->mode)->toBe(TargetMode::Observed)
			->and($target->adminState)->toBe(AdminState::Enabled)
			->and($target->present)->toBeFalse();
	})->with(['empty string' => '', 'NULL' => null]);

	it('fails closed on unknown mode and state values', function () {
		$target = Target::fromRow(target_row(['mode' => 'Managed', 'admin_state' => 'on']));

		expect($target->mode)->toBe(TargetMode::Observed)
			->and($target->adminState)->toBe(AdminState::Disabled);
	});

	it('drops malformed numbers and slave names instead of coercing them', function () {
		$target = Target::fromRow(target_row(['step' => '300s', 'pings' => '-20', 'poller_id' => 'x', 'slaves' => 'ok ../etc a~b ok']));

		expect($target->step)->toBe(0)
			->and($target->pings)->toBe(0)
			->and($target->pollerId)->toBe(1)
			->and($target->slaves)->toBe(['ok']);
	});

	it('throws on a missing or malformed path rather than guessing an identity', function (mixed $path) {
		expect(fn () => Target::fromRow(target_row(['path' => $path])))->toThrow(InvalidArgumentException::class);
	})->with(['empty' => '', 'NULL' => null, 'traversal' => '../x']);

	it('polls only slaves when nomasterpoll is set', function () {
		expect(Target::fromRow(target_row(['nomasterpoll' => '1']))->locations())->toBe(['boston', 'sydney.au']);
	});
});

describe('Observation::fromRow', function () {
	it('keeps unknown loss and median distinct from zero', function () {
		$unknown = Observation::fromRow(['target_id' => '4', 'location' => null, 'loss' => null, 'median' => '', 'file_present' => '1']);
		$zero    = Observation::fromRow(['target_id' => '4', 'location' => 'boston', 'loss' => '0', 'median' => '0.0']);

		expect($unknown->loss)->toBeNull()
			->and($unknown->median)->toBeNull()
			->and($unknown->location)->toBe('')
			->and($unknown->filePresent)->toBeTrue()
			->and($zero->loss)->toBe(0.0)
			->and($zero->median)->toBe(0.0)
			->and($zero->filePresent)->toBeFalse();
	});

	it('treats malformed measurements as unknown and malformed times as zero', function () {
		$observation = Observation::fromRow(['target_id' => '4', 'loss' => 'U', 'median' => 'n/a', 'observed_at' => '17e8', 'ingested_at' => '-5']);

		expect($observation->loss)->toBeNull()
			->and($observation->median)->toBeNull()
			->and($observation->observedAt)->toBe(0)
			->and($observation->ingestedAt)->toBe(0);
	});

	it('requires a target id', function (mixed $id) {
		expect(fn () => Observation::fromRow(['target_id' => $id]))->toThrow(InvalidArgumentException::class);
	})->with(['', null, '0', 'abc']);
});

describe('state enums', function () {
	// Stored values are a contract with existing rows. Pin each one so that
	// reordering or renaming a case cannot change what is written.
	it('keeps stored values independent of case order', function (string $enum, array $values) {
		$cases = $enum::cases();

		expect(array_map(fn ($case) => $case->value, $cases))->toEqualCanonicalizing($values);

		foreach (array_reverse($cases) as $case) {
			expect($enum::from($case->value))->toBe($case);
		}
	})->with([
		'Freshness' => [Freshness::class, ['current', 'delayed', 'stale', 'never']],
		'ExecutionState' => [ExecutionState::class, ['completed', 'probe_error', 'unknown']],
		'Condition' => [Condition::class, ['within_policy', 'degraded', 'complete_loss', 'unknown']],
		'TargetMode' => [TargetMode::class, ['observed', 'managed']],
		'AdminState' => [AdminState::class, ['enabled', 'disabled', 'archived']],
		'InventoryStatus' => [InventoryStatus::class, ['ok', 'error', 'never']],
	]);
});
