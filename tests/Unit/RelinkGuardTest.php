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

use Cacti\Smokeping\RelinkGuard;

describe('RelinkGuard', function () {
	it('allows a free new target and one already on the old target\'s device', function (int $oldHost, int $newHost) {
		expect(RelinkGuard::refusal($oldHost, $newHost, 1, 1, 1))->toBeNull();
	})->with([
		'unmapped both' => [0, 0],
		'old mapped, new free' => [5, 0],
		'same device' => [5, 5],
	]);

	it('refuses a new target mapped to another device', function (int $oldHost, int $newHost) {
		expect(RelinkGuard::refusal($oldHost, $newHost, 1, 1, 1))->toContain("mapped to device $newHost");
	})->with([
		'old on A, new on B' => [5, 9],
		'old unmapped, new on B' => [0, 9],
	]);

	it('refuses a target on a poller other than the reader\'s', function (int $old, int $new) {
		expect(RelinkGuard::refusal(0, 0, $old, $new, 1))->toContain('poller that runs the reader');
	})->with([
		'old elsewhere' => [2, 1],
		'new elsewhere' => [1, 2],
		'both elsewhere' => [3, 3],
	]);
});
