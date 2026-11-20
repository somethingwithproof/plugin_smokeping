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
use Somethingwithproof\Smokeping\FreshnessEvaluator;
use Somethingwithproof\Smokeping\Observation;
use Somethingwithproof\Smokeping\SummaryCalculator;
use Somethingwithproof\Smokeping\SummaryScript;
use Somethingwithproof\Smokeping\SummaryValues;
use Somethingwithproof\Smokeping\TargetId;

const SUMMARY_OBSERVED = 1700000000;

function summaryFor(?Observation $observation, int $age, int $firstSeen = 0): string {
	$clock = new FixedClock(SUMMARY_OBSERVED + $age);

	return (new SummaryCalculator(new FreshnessEvaluator($clock, 60), $clock))->compute($observation, $firstSeen)->format();
}

function summaryObservation(?float $loss, ?float $median, bool $present = true, int $observedAt = SUMMARY_OBSERVED, int $pings = 20): Observation {
	return new Observation(TargetId::fromInt(7), '', $observedAt, $observedAt, 300, $pings, $loss, $median, $present, '');
}

describe('SummaryCalculator', function () {
	it('reports a current round as milliseconds, percent of pings, and age', function () {
		expect(summaryFor(summaryObservation(1.0, 0.0125), 30))->toBe('median:12.5 loss_pct:5 age:30');
	});

	it('reports zero loss as zero, not unknown', function () {
		expect(summaryFor(summaryObservation(0.0, 0.01), 0))->toBe('median:10 loss_pct:0 age:0');
	});

	it('reports complete loss as 100 percent with the median unknown', function () {
		expect(summaryFor(summaryObservation(20.0, null), 10))->toBe('median:U loss_pct:100 age:10');
	});

	it('keeps the last values while a round is only delayed', function () {
		expect(summaryFor(summaryObservation(0.0, 0.02), 500))->toBe('median:20 loss_pct:0 age:500');
	});

	it('makes median and loss unknown when stale and still counts the age', function () {
		expect(summaryFor(summaryObservation(0.0, 0.02), 601))->toBe('median:U loss_pct:U age:601')
			->and(summaryFor(summaryObservation(0.0, 0.02), 86400))->toBe('median:U loss_pct:U age:86400');
	});

	it('is all unknown without a cache row or without the file', function () {
		expect(summaryFor(null, 0))->toBe('median:U loss_pct:U age:U')
			->and(summaryFor(summaryObservation(0.0, 0.01, present: false), 10))->toBe('median:U loss_pct:U age:U');
	});

	it('ages a file that was never updated from when the target was first seen', function () {
		expect(summaryFor(summaryObservation(null, null, observedAt: 0), 1000, SUMMARY_OBSERVED))->toBe('median:U loss_pct:U age:1000')
			->and(summaryFor(summaryObservation(null, null, observedAt: 0), 1000, 0))->toBe('median:U loss_pct:U age:U');
	});

	it('does not read a missing loss as zero', function () {
		expect(summaryFor(summaryObservation(null, 0.01), 10))->toBe('median:10 loss_pct:U age:10');
	});

	it('never lets a clock behind the RRD make a negative age', function () {
		expect(summaryFor(summaryObservation(0.0, 0.01), -30))->toBe('median:10 loss_pct:0 age:0');
	});
});

describe('SummaryValues', function () {
	it('prints numbers without a locale decimal comma or trailing zeros', function () {
		expect((new SummaryValues(0.1 + 0.2, 33.333333, 5))->format())->toBe('median:0.3 loss_pct:33.333 age:5');
	});
});

describe('SummaryScript series', function () {
	it('accepts a target id with a location and rejects anything else', function (string $series, ?array $expected) {
		expect(SummaryScript::parse($series))->toBe($expected);
	})->with([
		'master' => ['12~', ['target' => 12, 'location' => '']],
		'slave' => ['12~berlin.1', ['target' => 12, 'location' => 'berlin.1']],
		'no separator' => ['12', null],
		'zero id' => ['0~', null],
		'negative' => ['-1~', null],
		'path in location' => ['12~../x', null],
		'space' => ['12~a b', null],
		'shell' => ['12~$(id)', null],
		'empty' => ['', null],
		'trailing newline' => ["12~berlin\n", null],
		'newline after separator' => ["12~\n", null],
	]);

	it('builds the key the data source stores', function () {
		expect(SummaryScript::series(12, 'berlin'))->toBe('12~berlin');
	});
});
