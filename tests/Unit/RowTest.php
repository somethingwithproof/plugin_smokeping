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

use Somethingwithproof\Smokeping\Row;

describe('Row::int', function () {
	it('reads whole decimal numbers from strings and ints', function () {
		expect(Row::int(['v' => '42'], 'v'))->toBe(42)
			->and(Row::int(['v' => ' 7 '], 'v'))->toBe(7)
			->and(Row::int(['v' => '-3'], 'v'))->toBe(-3)
			->and(Row::int(['v' => 9], 'v'))->toBe(9);
	});

	it('returns the default for missing, NULL, and empty values', function () {
		expect(Row::int([], 'v', 5))->toBe(5)
			->and(Row::int(['v' => null], 'v', 5))->toBe(5)
			->and(Row::int(['v' => ''], 'v', 5))->toBe(5);
	});

	it('returns the default for malformed values instead of truncating them', function (mixed $value) {
		expect(Row::int(['v' => $value], 'v', 5))->toBe(5);
	})->with([
		'decimal' => '1.5',
		'exponent' => '1e3',
		'trailing text' => '12abc',
		'leading text' => 'abc12',
		'float' => 3.0,
		'bool' => true,
		'array' => [[1]],
		'too long' => '1234567890123456789012',
	]);

	it('returns the default below the minimum', function () {
		expect(Row::int(['v' => '0'], 'v', 1, 1))->toBe(1)
			->and(Row::int(['v' => '-1'], 'v', 0, 0))->toBe(0)
			->and(Row::int(['v' => '1'], 'v', 9, 1))->toBe(1);
	});
});

describe('Row::float', function () {
	it('keeps zero distinct from unknown', function () {
		expect(Row::float(['v' => '0'], 'v'))->toBe(0.0)
			->and(Row::float(['v' => 0], 'v'))->toBe(0.0)
			->and(Row::float(['v' => null], 'v'))->toBeNull()
			->and(Row::float(['v' => ''], 'v'))->toBeNull()
			->and(Row::float([], 'v'))->toBeNull();
	});

	it('reads numeric strings including rrdtool exponent notation', function () {
		expect(Row::float(['v' => '1.2345000000e-02'], 'v'))->toBe(0.012345)
			->and(Row::float(['v' => ' 20 '], 'v'))->toBe(20.0);
	});

	it('treats rrdtool unknowns and malformed text as unknown', function (mixed $value) {
		expect(Row::float(['v' => $value], 'v'))->toBeNull();
	})->with(['U', 'nan', 'NaN', 'inf', '-', '1,5', 'abc', [[1]], true]);

	it('treats non-finite floats as unknown', function () {
		expect(Row::float(['v' => NAN], 'v'))->toBeNull()
			->and(Row::float(['v' => INF], 'v'))->toBeNull();
	});
});

describe('Row::string', function () {
	it('casts scalars, trims, and maps missing or NULL to empty', function () {
		expect(Row::string(['v' => ' a '], 'v'))->toBe('a')
			->and(Row::string(['v' => 12], 'v'))->toBe('12')
			->and(Row::string(['v' => null], 'v'))->toBe('')
			->and(Row::string([], 'v'))->toBe('')
			->and(Row::string(['v' => ['x']], 'v'))->toBe('');
	});
});

describe('Row::bool', function () {
	it('accepts only explicit true spellings', function (mixed $value, bool $expected) {
		expect(Row::bool(['v' => $value], 'v'))->toBe($expected);
	})->with([
		'checkbox on' => ['on', true],
		'yes' => ['yes', true],
		'YES' => [' YES ', true],
		'one string' => ['1', true],
		'one int' => [1, true],
		'true' => [true, true],
		'empty' => ['', false],
		'null' => [null, false],
		'zero' => ['0', false],
		'off' => ['off', false],
		'two' => [2, false],
		'garbage' => ['enabled', false],
	]);
});
