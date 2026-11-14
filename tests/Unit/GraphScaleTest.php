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

use Somethingwithproof\Smokeping\GraphScale;

it('reads the maximum from the PRINT line of rrdtool graph output', function () {
	// rrdtool graph prints the image size first, then each PRINT.
	expect(GraphScale::parseMaximum("0x0\n1.430000e-01\n"))->toBe(0.143)
		->and(GraphScale::parseMaximum("0x0\n4.700000e-05"))->toBe(0.000047);
});

it('reads no maximum when the range has no median', function () {
	expect(GraphScale::parseMaximum("0x0\nnan\n"))->toBeNull()
		->and(GraphScale::parseMaximum("0x0\n-nan\n"))->toBeNull()
		->and(GraphScale::parseMaximum("0x0\n"))->toBeNull()
		->and(GraphScale::parseMaximum(''))->toBeNull()
		->and(GraphScale::parseMaximum("0x0\n0.000000e+00\n"))->toBeNull();
});

it('puts the limit 20 percent above the largest median, as SmokePing does', function () {
	expect(GraphScale::upperLimit(0.143))->toBe('0.1716')
		->and(GraphScale::upperLimit(0.229))->toBe('0.2748');
});

it('writes small limits in fixed-point notation', function () {
	// 47 microseconds: rrdtool's RPN and Cacti's limit field read fixed point.
	expect(GraphScale::upperLimit(0.000047))->toBe('0.0000564');
});

it('gives no limit without a positive median', function () {
	expect(GraphScale::upperLimit(null))->toBeNull()
		->and(GraphScale::upperLimit(0.0))->toBeNull()
		->and(GraphScale::upperLimit(-1.0))->toBeNull()
		->and(GraphScale::upperLimit(NAN))->toBeNull()
		->and(GraphScale::upperLimit(1e-12))->toBeNull();
});
