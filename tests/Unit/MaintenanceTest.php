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

use Somethingwithproof\Smokeping\DeviceMaintenance;
use Somethingwithproof\Smokeping\FreshnessEvaluator;
use Somethingwithproof\Smokeping\MaintMaintenance;
use Somethingwithproof\Smokeping\ObservationRepository;
use Somethingwithproof\Smokeping\SystemClock;
use Somethingwithproof\Smokeping\TargetRepository;
use Somethingwithproof\Smokeping\ViewPage;

// Cacti's hook dispatch hands back its argument when no plugin answers, as it
// does without Maint. $GLOBALS['__mt_hook'] stands in for Maint's answer.
if (!function_exists('api_plugin_hook_function')) {
	function api_plugin_hook_function($name, $parm = null) {
		$GLOBALS['__mt_calls'][] = [$name, $parm];

		return array_key_exists('__mt_hook', $GLOBALS) ? ($GLOBALS['__mt_hook'])($parm) : $parm;
	}
}

if (!function_exists('html_start_box')) {
	function html_start_box($title, $width, $div, $cell_padding, $align, $add_text) {
		print '<table>';
	}
}

if (!function_exists('html_end_box')) {
	function html_end_box($trailing_br = true) {
		print '</table>';
	}
}

beforeEach(function () {
	unset($GLOBALS['__mt_hook']);
	$GLOBALS['__mt_calls'] = [];
});

describe('MaintMaintenance', function () {
	it('asks Maint through is_device_in_maintenance and believes only true', function () {
		$GLOBALS['__mt_hook'] = fn ($host) => $host === 7;

		$maintenance = new MaintMaintenance();

		expect($maintenance->inMaintenance(7))->toBeTrue()
			->and($maintenance->inMaintenance(8))->toBeFalse()
			->and($GLOBALS['__mt_calls'])->toBe([['is_device_in_maintenance', 7], ['is_device_in_maintenance', 8]]);
	});

	it('reads the host id that comes back without Maint as not in maintenance', function () {
		expect((new MaintMaintenance())->inMaintenance(7))->toBeFalse();
	});

	it('asks once per device and never for an id below 1', function () {
		$GLOBALS['__mt_hook'] = fn ($host) => true;
		$maintenance          = new MaintMaintenance();

		$maintenance->inMaintenance(7);
		$maintenance->inMaintenance(7);

		expect($maintenance->inMaintenance(0))->toBeFalse()
			->and($GLOBALS['__mt_calls'])->toBe([['is_device_in_maintenance', 7]]);
	});
});

describe('ViewPage maintenance column', function () {
	it('shows which devices are in maintenance', function () {
		$GLOBALS['__smokeping_db_assoc'] = fn (string $sql) => str_contains($sql, 'plugin_smokeping_mapping')
			? [
				['id' => 1, 'poller_id' => 1, 'path' => 'Europe/paris', 'host' => 'h1', 'probe' => 'FPing', 'pings' => 20, 'step' => 300, 'present' => 1, 'mapped_host_id' => 7],
				['id' => 2, 'poller_id' => 1, 'path' => 'Europe/rome', 'host' => 'h2', 'probe' => 'FPing', 'pings' => 20, 'step' => 300, 'present' => 1, 'mapped_host_id' => 8],
			]
			: [];

		$maintenance = new class implements DeviceMaintenance {
			public function inMaintenance(int $hostId): bool {
				return $hostId === 7;
			}
		};

		ob_start();
		(new ViewPage(new TargetRepository(false), new ObservationRepository(false), new FreshnessEvaluator(new SystemClock(), 60), $maintenance))
			->render([7 => 'paris-gw', 8 => 'rome-gw']);
		$html = (string) ob_get_clean();

		expect($html)->toContain('<th class="left">Maintenance</th>')
			->and($html)->toMatch('#paris-gw</td>.*?<td>In maintenance</td>#')
			->and($html)->toMatch('#rome-gw</td>.*?<td>No</td>#');

		$GLOBALS['__smokeping_db_assoc'] = [];
	});
});
