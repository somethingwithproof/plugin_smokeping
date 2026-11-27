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

use Somethingwithproof\Smokeping\DDSketch;
use Somethingwithproof\Smokeping\ExtendedSummaryValues;
use Somethingwithproof\Smokeping\FixedClock;
use Somethingwithproof\Smokeping\FreshnessEvaluator;
use Somethingwithproof\Smokeping\Observation;
use Somethingwithproof\Smokeping\ObservationRepository;
use Somethingwithproof\Smokeping\RrdLastUpdate;
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

describe('ExtendedSummaryValues and SummaryCalculator::computeExtended', function () {
	it('computes exact IQR jitter, RFC 3550 jitter, quantiles, and loss burst streak from ping samples', function () {
		$clock = new FixedClock(SUMMARY_OBSERVED + 30);
		$calc  = new SummaryCalculator(new FreshnessEvaluator($clock, 60), $clock);
		$obs   = summaryObservation(1.0, 0.015, true, SUMMARY_OBSERVED, 20);

		// 10 ordered ping samples in seconds -> 10, 12, 14, 16, 18, 20, 22, 24, 26, 28 ms.
		// Sorted: Q1 (pos=2.25) = 14 + 0.25*2 = 14.5, Q3 (pos=6.75) = 22 + 0.75*2 = 23.5 -> IQR = 9.0.
		// p95 (pos=8.55) = 26 + 0.55*2 = 27.1; p99 (pos=8.91) = 26 + 0.91*2 = 27.82.
		$pingsSeconds = [0.010, 0.012, 0.014, 0.016, 0.018, 0.020, 0.022, 0.024, 0.026, 0.028];
		$recentLosses = [0.0, 5.0, 10.0, 15.0, 0.0, 5.0, 5.0, null, 20.0];

		$ext = $calc->computeExtended($obs, SUMMARY_OBSERVED, $pingsSeconds, $recentLosses);

		// RFC 3550 with constant consecutive |D_i| = 2.0 ms over 9 transitions:
		// J_9 = 2.0 * (1 - (15/16)^9).
		$expectedRfc = 2.0 * (1.0 - ((15.0 / 16.0) ** 9));

		expect($ext->base->format())->toBe('median:15 loss_pct:5 age:30')
			->and($ext->jitterIqrMs)->toEqualWithDelta(9.0, 1e-9)
			->and($ext->jitterRfc3550Ms)->toEqualWithDelta($expectedRfc, 1e-9)
			->and($ext->minMs)->toEqualWithDelta(10.0, 1e-9)
			->and($ext->p95Ms)->toEqualWithDelta(27.1, 1e-9)
			->and($ext->p99Ms)->toEqualWithDelta(27.82, 1e-9)
			->and($ext->maxMs)->toEqualWithDelta(28.0, 1e-9)
			->and($ext->lossBurstMax)->toBe(3);

		$formatted = $ext->format();
		expect($formatted)->toStartWith('median:15 loss_pct:5 age:30 ')
			->and($formatted)->toContain('jitter_iqr_ms:9')
			->and($formatted)->toContain('min_ms:10')
			->and($formatted)->toContain('p95_ms:27.1')
			->and($formatted)->toContain('p99_ms:27.82')
			->and($formatted)->toContain('max_ms:28')
			->and($formatted)->toContain('loss_burst_max:3');

		$arr = $ext->toArray();
		expect($arr['median'])->toBe(15.0)
			->and($arr['loss_burst_max'])->toBe(3)
			->and($arr['p99_ms'])->toEqualWithDelta(27.82, 1e-9);
	});

	it('verifies exact RFC 3550 smoothed jitter recurrence on alternating RTT samples', function () {
		// Alternating 10ms / 26ms -> every |D_i| = 16ms:
		// J1 = 1.0, J2 = 1.9375, J3 = 2.81640625.
		expect(ExtendedSummaryValues::jitterRfc3550([10.0, 26.0, 10.0, 26.0]))->toEqualWithDelta(2.81640625, 1e-9)
			->and(ExtendedSummaryValues::jitterRfc3550([10.0]))->toBe(0.0)
			->and(ExtendedSummaryValues::jitterRfc3550([]))->toBeNull()
			->and(ExtendedSummaryValues::jitterIqr([]))->toBeNull()
			->and(ExtendedSummaryValues::jitterIqr([10.0]))->toBe(0.0)
			->and(ExtendedSummaryValues::percentile([], 0.95))->toBeNull()
			->and(ExtendedSummaryValues::percentile([42.0], 0.95))->toBe(42.0)
			->and(ExtendedSummaryValues::percentile([10.0, 20.0, 30.0], 0.0))->toBe(10.0)
			->and(ExtendedSummaryValues::percentile([10.0, 20.0, 30.0], 1.0))->toBe(30.0);
	});

	it('computes quantiles from DDSketch when supplied and falls back cleanly on single-round loss', function () {
		$clock  = new FixedClock(SUMMARY_OBSERVED + 10);
		$calc   = new SummaryCalculator(new FreshnessEvaluator($clock, 60), $clock);
		$obs    = summaryObservation(0.0, 0.02, true, SUMMARY_OBSERVED, 20);
		$sketch = new DDSketch(0.01, 2048);

		for ($i = 1; $i <= 1000; $i++) {
			$sketch->add(10.0 + ($i * 0.1));
		}

		$ext = $calc->computeExtended($obs, SUMMARY_OBSERVED, [], [], $sketch);

		expect($ext->minMs)->toEqualWithDelta(10.1, 1e-9)
			->and($ext->maxMs)->toEqualWithDelta(110.0, 1e-9)
			->and($ext->p95Ms)->toEqualWithDelta(105.0, 1.5)
			->and($ext->p99Ms)->toEqualWithDelta(109.0, 1.5)
			->and($ext->jitterIqrMs)->toBeGreaterThan(45.0)
			->and($ext->jitterRfc3550Ms)->toBeNull()
			->and($ext->lossBurstMax)->toBe(0);
	});

	it('never fabricates zero extended metrics when stale, missing, or unknown', function () {
		$clock = new FixedClock(SUMMARY_OBSERVED + 900);
		$calc  = new SummaryCalculator(new FreshnessEvaluator($clock, 60), $clock);
		$obs   = summaryObservation(0.0, 0.02, true, SUMMARY_OBSERVED, 20);

		$stale = $calc->computeExtended($obs, SUMMARY_OBSERVED, [0.01, 0.02], [5.0, 5.0]);
		expect($stale->format())->toBe('median:U loss_pct:U age:900 jitter_iqr_ms:U jitter_rfc3550_ms:U min_ms:U p95_ms:U p99_ms:U max_ms:U loss_burst_max:U');

		$missing = $calc->computeExtended(null, 0, [0.01, 0.02], [5.0]);
		expect($missing->format())->toBe(ExtendedSummaryValues::unknown()->format())
			->and($missing->format())->toBe('median:U loss_pct:U age:U jitter_iqr_ms:U jitter_rfc3550_ms:U min_ms:U p95_ms:U p99_ms:U max_ms:U loss_burst_max:U');

		expect(ExtendedSummaryValues::lossBurstMax([null, null]))->toBeNull()
			->and(ExtendedSummaryValues::lossBurstMax([], null))->toBeNull()
			->and(ExtendedSummaryValues::lossBurstMax([], 5.0))->toBe(1)
			->and(ExtendedSummaryValues::lossBurstMax([], 0.0))->toBe(0);
	});

	it('extracts ordered ping1..ping20 samples from RrdLastUpdate output', function () {
		$raw = "uptime loss median ping1 ping2 ping10 ping3 ping20\n\n1700000000: U 1.0000000000e+00 1.2000000000e-02 1.0000000000e-02 1.1000000000e-02 1.9000000000e-02 U 2.5000000000e-02\n";
		$lu  = RrdLastUpdate::parse($raw);

		expect($lu->pingSamples())->toBe([0.01, 0.011, 0.019, 0.025]);
	});

	it('preserves SummaryScript default 3-field output and supports optional extended mode', function () {
		$clock  = new FixedClock(SUMMARY_OBSERVED + 15);
		$calc   = new SummaryCalculator(new FreshnessEvaluator($clock, 60), $clock);
		$script = new SummaryScript(new ObservationRepository(false), $calc);

		expect($script->run('invalid'))->toBe('median:U loss_pct:U age:U')
			->and($script->run('invalid', true))->toBe('median:U loss_pct:U age:U jitter_iqr_ms:U jitter_rfc3550_ms:U min_ms:U p95_ms:U p99_ms:U max_ms:U loss_burst_max:U');
	});
});
