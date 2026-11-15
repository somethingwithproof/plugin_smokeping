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
use Somethingwithproof\Smokeping\LoadEstimate;
use Somethingwithproof\Smokeping\Target;
use Somethingwithproof\Smokeping\TargetMode;
use Somethingwithproof\Smokeping\TargetPath;

function smokeping_load_target(string $path, int $step, int $pings, array $slaves = []): Target {
	return new Target(null, 1, TargetPath::fromString($path), 'h', 'FPing', '', '', $slaves, false, $step, $pings, TargetMode::Observed, AdminState::Enabled, true, 0, 0);
}

it('estimates packets, RRD updates, and Cacti rows from the targets', function () {
	// The plan's arithmetic: 10,000 series of 20 pings at 300 s is about 667 packets and 33 updates per second.
	$targets = [];

	for ($i = 0; $i < 10000; $i++) {
		$targets[] = smokeping_load_target('A/t' . $i, 300, 20);
	}

	$estimate = LoadEstimate::forTargets($targets);

	expect($estimate->series)->toBe(10000)
		->and(round($estimate->packetsPerSecond))->toBe(667.0)
		->and(round($estimate->rrdUpdatesPerSecond))->toBe(33.0)
		->and($estimate->dataSourceRows)->toBe(10000 * 23 + 10000 * 3)
		->and($estimate->graphItemRows)->toBe(10000 * 47 + 10000 * 5);
});

it('counts the graph items the shipped templates create', function () {
	// Graph templates are the hash_0001 nodes. The provisioned objects measured on the release runner (docs/performance.md)
	// have exactly the items of the template they come from.
	$xml   = simplexml_load_file(dirname(__DIR__, 2) . '/templates/smokeping-templates.xml');
	$items = [];

	foreach ($xml->children() as $node) {
		if (str_starts_with($node->getName(), 'hash_0001') && $node->items->children()->count() > 0) {
			$items[(string) $node->name] = $node->items->children()->count();
		}
	}

	foreach ([10, 20, 30] as $pings) {
		expect($items['SmokePing Smoke (' . $pings . ' pings)'])->toBe($pings + LoadEstimate::SMOKE_ITEMS_BEYOND_PINGS)
			->and(LoadEstimate::forTargets([smokeping_load_target('A/t', 300, $pings)])->graphItemRows)->toBe($pings + LoadEstimate::SMOKE_ITEMS_BEYOND_PINGS + LoadEstimate::SUMMARY_ITEMS);
	}

	expect($items['SmokePing Summary'])->toBe(LoadEstimate::SUMMARY_ITEMS);
});

it('counts every location and leaves the smoke rows out when the pings are not supported', function () {
	$estimate = LoadEstimate::forTargets([smokeping_load_target('A/b', 60, 15, ['x', 'y']), smokeping_load_target('A/c', 300, 10, [])]);

	expect($estimate->series)->toBe(4)
		->and($estimate->dataSourceRows)->toBe(3 + (10 + 3) * 1 + 3)
		->and($estimate->lines()[0])->toContain('4 series');
	expect(LoadEstimate::forTargets([])->series)->toBe(0);
});
