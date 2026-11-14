<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

use Cacti\Smokeping\AdminState;
use Cacti\Smokeping\LoadEstimate;
use Cacti\Smokeping\Target;
use Cacti\Smokeping\TargetMode;
use Cacti\Smokeping\TargetPath;

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
		->and($estimate->graphItemRows)->toBe(250000);
});

it('counts every location and leaves the smoke rows out when the pings are not supported', function () {
	$estimate = LoadEstimate::forTargets([smokeping_load_target('A/b', 60, 15, ['x', 'y']), smokeping_load_target('A/c', 300, 10, [])]);

	expect($estimate->series)->toBe(4)
		->and($estimate->dataSourceRows)->toBe(3 + (10 + 3) * 1 + 3)
		->and($estimate->lines()[0])->toContain('4 series');
	expect(LoadEstimate::forTargets([])->series)->toBe(0);
});
