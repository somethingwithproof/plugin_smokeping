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

use Somethingwithproof\Smokeping\FixedClock;
use Somethingwithproof\Smokeping\Health;
use Somethingwithproof\Smokeping\HealthCheck;
use Somethingwithproof\Smokeping\HealthLevel;
use Somethingwithproof\Smokeping\InventoryStatus;
use Somethingwithproof\Smokeping\MountCheck;

// A read-only bind mount of SmokePing's datadir under rra_path, as
// /proc/self/mountinfo shows it in a container started with
// -v /var/lib/smokeping:/var/www/cacti/rra/smokeping:ro.
const MOUNTINFO = <<<'TXT'
612 523 0:52 / / rw,relatime master:301 - overlay overlay rw,lowerdir=/l,upperdir=/u,workdir=/w
631 612 254:1 /var/lib/smokeping @ROOT@/rra/smokeping ro,relatime - ext4 /dev/vda1 rw,discard
632 612 254:1 /srv/other\040dir @ROOT@/rra/other rw,relatime - ext4 /dev/vda1 rw
TXT;

beforeEach(function () {
	$this->root = sys_get_temp_dir() . '/smokeping-health-' . getmypid() . '-' . random_int(0, PHP_INT_MAX);
	mkdir($this->root . '/rra/smokeping/Europe', 0755, true);
	mkdir($this->root . '/rra/other', 0755, true);
	$this->root = realpath($this->root);
	$this->mountinfo = str_replace('@ROOT@', $this->root, MOUNTINFO);
});

afterEach(function () {
	exec('rm -rf ' . escapeshellarg($this->root));
});

function levels(array $findings): array {
	$levels = [];

	foreach ($findings as $finding) {
		$levels[$finding->check] = $finding->level;
	}

	return $levels;
}

describe('MountCheck', function () {
	it('finds the mount containing a path by longest prefix and reads ro', function () {
		expect(MountCheck::containingMount($this->mountinfo, $this->root . '/rra/smokeping/Europe'))->toBe(['mountpoint' => $this->root . '/rra/smokeping', 'readonly' => true])
			->and(MountCheck::containingMount($this->mountinfo, $this->root . '/rra/smokepingx'))->toBe(['mountpoint' => '/', 'readonly' => false])
			->and(MountCheck::containingMount($this->mountinfo, $this->root . '/rra/other'))->toBe(['mountpoint' => $this->root . '/rra/other', 'readonly' => false])
			->and(MountCheck::containingMount('', '/x'))->toBeNull();
	});

	it('decodes octal escapes in mount points', function () {
		$info = '1 0 0:1 / /mnt/a\\040b ro - ext4 /dev/x rw';

		expect(MountCheck::containingMount($info, '/mnt/a b/c'))->toBe(['mountpoint' => '/mnt/a b', 'readonly' => true]);
	});

	it('accepts a read-only mount inside rra_path', function () {
		$rrd = $this->root . '/rra/smokeping/Europe/paris.rrd';
		touch($rrd);
		chmod($this->root . '/rra/smokeping', 0555);

		$levels = levels((new MountCheck($this->mountinfo))->check($this->root . '/rra/smokeping', $this->root . '/rra', [$rrd]));

		chmod($this->root . '/rra/smokeping', 0755);

		expect($levels['mount'])->toBe(HealthLevel::Ok)
			->and($levels['readonly'])->toBe(HealthLevel::Ok)
			->and($levels['visible'])->toBe(HealthLevel::Ok)
			->and($levels)->not->toHaveKey('writable');
	})->skip(fn () => posix_getuid() === 0, 'root ignores directory modes');

	it('rejects a mount outside rra_path', function () {
		$findings = (new MountCheck($this->mountinfo))->check($this->root . '/elsewhere', $this->root . '/rra', []);

		expect($findings)->toHaveCount(1)
			->and($findings[0]->level)->toBe(HealthLevel::Error)
			->and($findings[0]->message)->toContain('not inside the Cacti RRA path');
	});

	it('rejects a symlink anywhere below rra_path', function () {
		symlink($this->root . '/rra/other', $this->root . '/rra/link');
		mkdir($this->root . '/rra/other/deeper');

		foreach (['/rra/link', '/rra/link/deeper'] as $path) {
			$findings = (new MountCheck($this->mountinfo))->check($this->root . $path, $this->root . '/rra', []);

			expect($findings[0]->level)->toBe(HealthLevel::Error)
				->and($findings[0]->message)->toContain('symlink');
		}
	});

	it('rejects "." and ".." segments', function () {
		expect(MountCheck::withinRra($this->root . '/rra/../rra/smokeping', $this->root . '/rra'))->toContain('segment')
			->and(MountCheck::withinRra($this->root . '/rra/smokeping', $this->root . '/rra'))->toBeNull();
	});

	it('reports a writable mount and a writable directory as errors', function () {
		$levels = levels((new MountCheck($this->mountinfo))->check($this->root . '/rra/other', $this->root . '/rra', []));

		expect($levels['readonly'])->toBe(HealthLevel::Error)
			->and($levels['writable'])->toBe(HealthLevel::Error)
			->and($levels['visible'])->toBe(HealthLevel::Unknown);
	});

	it('reports read-only state as unknown without a mount table', function () {
		$levels = levels((new MountCheck(''))->check($this->root . '/rra/smokeping', $this->root . '/rra', []));

		expect($levels['readonly'])->toBe(HealthLevel::Unknown);
	});

	it('warns about expected RRD files that are not visible', function () {
		touch($this->root . '/rra/smokeping/Europe/paris.rrd');

		$findings = (new MountCheck($this->mountinfo))->check($this->root . '/rra/smokeping', $this->root . '/rra', [
			$this->root . '/rra/smokeping/Europe/paris.rrd',
			$this->root . '/rra/smokeping/Europe/berlin.rrd',
		]);

		expect(levels($findings)['visible'])->toBe(HealthLevel::Warning)
			->and(end($findings)->message)->toContain('1 of 2 expected RRD files');
	});

	it('reports a missing mount directory', function () {
		$findings = (new MountCheck($this->mountinfo))->check($this->root . '/rra/missing', $this->root . '/rra', []);

		expect($findings[0]->level)->toBe(HealthLevel::Error)
			->and($findings[0]->message)->toContain('does not exist');
	});
});

describe('HealthCheck', function () {
	$health = fn (array $row) => Health::fromRow($row + ['poller_id' => 1]);

	it('shows missing instrumentation as unknown, never as healthy', function () use ($health) {
		$levels = levels((new HealthCheck(new FixedClock(1700000000), 300))->check(Health::never(1), PHP_BINARY));

		expect($levels['reader'])->toBe(HealthLevel::Unknown)
			->and($levels['inventory'])->toBe(HealthLevel::Unknown)
			->and($levels['dual_alerting'])->toBe(HealthLevel::Unknown)
			->and($levels['php_binary'])->toBe(HealthLevel::Ok);

		expect(Health::fromRow(['poller_id' => 1, 'inventory_status' => 'bogus'])->inventoryStatus)->toBe(InventoryStatus::Never);
	});

	it('reports a reader that stopped, a budget overrun, and an inventory error', function () use ($health) {
		$check = new HealthCheck(new FixedClock(1700003600), 300);

		$stopped = levels($check->check($health(['run_finished' => 1700000000, 'inventory_status' => 'ok', 'inventory_at' => 1700000000]), PHP_BINARY));
		$overrun = levels($check->check($health(['run_finished' => 1700003500, 'budget_overruns' => 2]), PHP_BINARY));
		$broken  = $check->check($health(['run_finished' => 1700003500, 'inventory_status' => 'error', 'inventory_at' => 1700003000, 'inventory_error' => 'unknown section']), PHP_BINARY);

		expect($stopped['reader'])->toBe(HealthLevel::Warning)
			->and($stopped['inventory'])->toBe(HealthLevel::Ok)
			->and($overrun['reader'])->toBe(HealthLevel::Warning)
			->and(levels($broken)['inventory'])->toBe(HealthLevel::Error)
			->and($broken[2]->message)->toContain('unknown section');
	});

	it('reports an unusable PHP binary', function (string $binary) {
		expect(HealthCheck::phpBinary($binary)->level)->toBe(HealthLevel::Error);
	})->with(['', '/nonexistent/php', __FILE__]);
});

describe('Template import failure', function () {
	it('round-trips through its setting and blocks only the same plugin and template version', function () {
		$failure = new Somethingwithproof\Smokeping\TemplateFailure('0.1', 1, 1700000000, 'broken');
		$again   = Somethingwithproof\Smokeping\TemplateFailure::fromSetting($failure->toSetting());

		expect($again)->toEqual($failure)
			->and($again->blocks('0.1', 1))->toBeTrue()
			->and($again->blocks('0.2', 1))->toBeFalse()
			->and($again->blocks('0.1', 2))->toBeFalse()
			->and(Somethingwithproof\Smokeping\TemplateFailure::fromSetting(''))->toBeNull()
			->and(Somethingwithproof\Smokeping\TemplateFailure::fromSetting('not json'))->toBeNull();
	});

	it('reports a failure as an error with its reason, and a missing template as a warning', function () {
		$failed = HealthCheck::templates(false, new Somethingwithproof\Smokeping\TemplateFailure('0.1', 1, 1, 'file missing'));

		expect($failed->level)->toBe(HealthLevel::Error)
			->and($failed->message)->toContain('file missing')
			->and(HealthCheck::templates(false, null)->level)->toBe(HealthLevel::Warning)
			->and(HealthCheck::templates(true, null)->level)->toBe(HealthLevel::Ok);
	});
});
