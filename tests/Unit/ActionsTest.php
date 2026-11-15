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

use Somethingwithproof\Smokeping\ActionOutcome;
use Somethingwithproof\Smokeping\DeviceActions;
use Somethingwithproof\Smokeping\GraphLinks;
use Somethingwithproof\Smokeping\HealthCheck;
use Somethingwithproof\Smokeping\HealthLevel;
use Somethingwithproof\Smokeping\Html;
use Somethingwithproof\Smokeping\RawHtml;

describe('ActionOutcome', function () {
	it('names the subject on every line and says what was skipped', function () {
		$outcome = new ActionOutcome('Europe/paris', 7, ['created device 7'], ['no smoke graph for master: only 10, 20, and 30 pings']);

		expect($outcome->succeeded())->toBeTrue()
			->and($outcome->lines())->toBe([
				'Europe/paris: created device 7',
				'Europe/paris: skipped, no smoke graph for master: only 10, 20, and 30 pings',
			]);
	});

	it('is not a success when nothing was done', function () {
		expect((new ActionOutcome('x', 0, [], ['reason']))->succeeded())->toBeFalse();
	});
});

describe('DeviceActions', function () {
	it('adds its action to the device list', function () {
		expect(DeviceActions::register(['1' => 'Delete']))->toHaveKey(DeviceActions::ACTION);
	});

	it('summarizes the objects created and the first skips, at most ten', function () {
		$outcomes = [];

		for ($i = 1; $i <= 15; $i++) {
			$outcomes[] = new ActionOutcome("dev$i", $i, ['created'], ["reason $i"]);
		}

		$lines = DeviceActions::summary($outcomes);

		expect($lines[0])->toBe('15 objects created or mapped, 15 skipped.')
			->and(count($lines))->toBe(11)
			->and($lines[1])->toBe('dev1: reason 1');
	});
});

describe('GraphLinks', function () {
	it('escapes the link and carries the device id', function () {
		$html = GraphLinks::html('/cacti/"><b>', 12);

		expect($html)->toContain('smokeping.php?host_id=12')
			->and($html)->not->toContain('<b>');
	});

	it('gives the link a focus ring of its own that shows on light and dark themes', function () {
		expect(GraphLinks::html('/cacti/', 12))->toContain('class="iconLink smokepingGraphLink"')
			->and(GraphLinks::style())->toContain('a.smokepingGraphLink:focus-visible{outline:2px solid #fff')
			->and(GraphLinks::style())->toContain('box-shadow:0 0 0 4px #000');
	});

	it('prints the focus rule once per request, before the first link', function () {
		(new ReflectionProperty(GraphLinks::class, 'styled'))->setValue(null, false);

		$first  = GraphLinks::forPage('/cacti/', 12);
		$second = GraphLinks::forPage('/cacti/', 13);

		expect($first)->toStartWith('<style>')->toContain('host_id=12')
			->and($second)->not->toContain('<style>')->toContain('host_id=13');
	});
});

describe('Html::link', function () {
	it('escapes the text and the query values', function () {
		$link = Html::link('smokeping_inventory.php', ['action' => 'add', 'id' => 5], '<script>');

		expect($link)->toBeInstanceOf(RawHtml::class)
			->and($link->html)->toContain('smokeping_inventory.php?action=add&amp;id=5')
			->and($link->html)->toContain('&lt;script&gt;')
			->and($link->html)->not->toContain('<script>');
	});
});

describe('device poller finding', function () {
	it('is an error naming the devices when summary data sources are on another poller', function () {
		$bad = HealthCheck::devicePollers(['edge1', 'edge2'], 3);

		expect($bad->level)->toBe(HealthLevel::Error)
			->and($bad->message)->toContain('edge1, edge2')
			->and($bad->message)->toContain('poller 3')
			->and(HealthCheck::devicePollers([], 3)->level)->toBe(HealthLevel::Ok);
	});
});
