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
use Somethingwithproof\Smokeping\PeltChangepointDetector;

/**
 * Exact O(n^2) optimal partitioning reference implementation for verifying
 * PELT pruning exactness against brute-force dynamic programming.
 *
 * @param list<float> $values
 * @return list<int>
 */
function brute_force_optimal_partitioning(array $values, float $beta, int $minLen): array {
	$n = count($values);

	if ($n < 2 * $minLen) {
		return [];
	}

	$s1 = [0.0];
	$s2 = [0.0];

	foreach ($values as $v) {
		$s1[] = end($s1) + $v;
		$s2[] = end($s2) + ($v * $v);
	}

	$cost = static function (int $s, int $e) use ($s1, $s2): float {
		$len = $e - $s;
		$sum = $s1[$e] - $s1[$s];
		$sq  = $s2[$e] - $s2[$s];

		return max(0.0, $sq - (($sum * $sum) / $len));
	};

	$dp   = [0 => -$beta];
	$prev = [0 => -1];

	for ($t = $minLen; $t <= $n; $t++) {
		$best  = INF;
		$bestS = 0;

		for ($s = 0; $s <= $t - $minLen; $s++) {
			if (!isset($dp[$s])) {
				continue;
			}

			$candidate = $dp[$s] + $cost($s, $t) + $beta;

			if ($candidate < $best) {
				$best  = $candidate;
				$bestS = $s;
			}
		}

		$dp[$t]   = $best;
		$prev[$t] = $bestS;
	}

	$out = [];
	$cur = $prev[$n];

	while ($cur > 0) {
		$out[] = $cur;
		$cur   = $prev[$cur];
	}

	return array_reverse($out);
}

describe('DDSketch (Masson, Rim & Lee, PVLDB 2019)', function () {
	it('computes p50, p95, p99, and p99.9 within the relative-error alpha bound', function () {
		$alpha  = 0.01;
		$sketch = new DDSketch($alpha, 2048);
		$values = [];

		// Deterministic heavy-tailed latency distribution spanning 0.5 ms to 600 ms.
		for ($i = 1; $i <= 5000; $i++) {
			$v = 0.5 + (($i % 100) * 0.25) + ($i > 4750 ? ($i - 4750) * 2.1 : 0.0);
			$values[] = $v;
			$sketch->add($v);
		}

		sort($values, SORT_NUMERIC);
		$exact = static function (float $q) use ($values): float {
			$idx = max(0, min(count($values) - 1, (int) ceil($q * count($values)) - 1));

			return $values[$idx];
		};

		foreach ([0.50, 0.90, 0.95, 0.99, 0.999] as $q) {
			$truth    = $exact($q);
			$estimate = $sketch->quantile($q);
			$relError = abs($estimate - $truth) / $truth;

			expect($relError)->toBeLessThanOrEqual($alpha + 1e-6, "q=$q truth=$truth est=$estimate");
		}

		$summary = $sketch->summary();
		expect($summary['count'])->toBe(5000.0)
			->and($summary['min'])->toBe($values[0])
			->and($summary['max'])->toBe(end($values))
			->and($sketch->quantile(0.0))->toBe($values[0])
			->and($sketch->quantile(1.0))->toBe(end($values))
			->and($sketch->alpha())->toBe($alpha)
			->and($sketch->maxBins())->toBe(2048)
			->and($sketch->zeroThreshold())->toBe(DDSketch::DEFAULT_ZERO_THRESHOLD)
			->and($sketch->isEmpty())->toBeFalse();
	});

	it('merges sketches from remote pollers and matches a combined single sketch', function () {
		$pollerA  = new DDSketch(0.01, 2048);
		$pollerB  = new DDSketch(0.01, 2048);
		$combined = new DDSketch(0.01, 2048);

		for ($i = 1; $i <= 1000; $i++) {
			$a = 5.0 + ($i % 50) * 0.4;
			$b = 20.0 + ($i % 80) * 1.5;
			$pollerA->add($a);
			$pollerB->add($b);
			$combined->add($a);
			$combined->add($b);
		}

		// Merging an empty sketch is a safe no-op.
		$pollerA->merge(new DDSketch(0.01, 2048));
		$pollerA->merge($pollerB);

		expect($pollerA->count())->toBe($combined->count())
			->and($pollerA->min())->toBe($combined->min())
			->and($pollerA->max())->toBe($combined->max());

		foreach ([0.50, 0.95, 0.99, 0.999] as $q) {
			expect($pollerA->quantile($q))->toEqualWithDelta($combined->quantile($q), 1e-9);
		}
	});

	it('bounds bucket count via lowest-bin collapsing while preserving upper-tail accuracy', function () {
		$sketch = new DDSketch(0.01, 32);
		$values = [];

		for ($i = 1; $i <= 1000; $i++) {
			$v = 0.01 * (1.02 ** ($i % 400));
			$values[] = $v;
			$sketch->add($v);
		}

		sort($values, SORT_NUMERIC);
		$p99Truth = $values[(int) ceil(0.99 * count($values)) - 1];
		$p99Est   = $sketch->quantile(0.99);

		expect($sketch->binCount())->toBeLessThanOrEqual(32)
			->and(abs($p99Est - $p99Truth) / $p99Truth)->toBeLessThanOrEqual(0.01 + 1e-6);
	});

	it('handles zero/near-zero values, weighted samples, empty sketches, and array round-trip', function () {
		$empty = new DDSketch();
		expect($empty->isEmpty())->toBeTrue()
			->and($empty->quantile(0.5))->toBeNull()
			->and($empty->summary()['p99'])->toBeNull();

		$sketch = new DDSketch(0.02, 64, 1e-6);
		$sketch->add(0.0, 50.0);
		$sketch->add(1e-7, 10.0);
		$sketch->add(10.0, 40.0);

		expect($sketch->quantile(0.50))->toBe(0.0)
			->and($sketch->quantile(0.90))->toEqualWithDelta(10.0, 0.25);

		$payload  = $sketch->toArray();
		$restored = DDSketch::fromArray($payload);

		expect($restored->toArray())->toBe($payload)
			->and($restored->quantile(0.90))->toBe($sketch->quantile(0.90));

		// fromArray collapses bins if serialized payload exceeds max_bins.
		$wide = new DDSketch(0.01, 64);
		for ($i = 1; $i <= 40; $i++) {
			$wide->add(1.0 * (1.2 ** $i));
		}
		$shrunkPayload             = $wide->toArray();
		$shrunkPayload['max_bins'] = 8;
		$shrunk                    = DDSketch::fromArray($shrunkPayload);
		expect($shrunk->binCount())->toBeLessThanOrEqual(8);
	});

	it('rejects invalid parameters, non-finite inputs, incompatible merges, and malformed payloads', function () {
		expect(fn () => new DDSketch(0.0))->toThrow(InvalidArgumentException::class)
			->and(fn () => new DDSketch(1.0))->toThrow(InvalidArgumentException::class)
			->and(fn () => new DDSketch(NAN))->toThrow(InvalidArgumentException::class)
			->and(fn () => new DDSketch(0.01, 1))->toThrow(InvalidArgumentException::class)
			->and(fn () => new DDSketch(0.01, 16, -1.0))->toThrow(InvalidArgumentException::class);

		$sketch = new DDSketch(0.01, 16);
		expect(fn () => $sketch->add(-0.1))->toThrow(InvalidArgumentException::class)
			->and(fn () => $sketch->add(INF))->toThrow(InvalidArgumentException::class)
			->and(fn () => $sketch->add(1.0, 0.0))->toThrow(InvalidArgumentException::class)
			->and(fn () => $sketch->quantile(-0.01))->toThrow(InvalidArgumentException::class)
			->and(fn () => $sketch->quantile(1.01))->toThrow(InvalidArgumentException::class)
			->and(fn () => $sketch->merge(new DDSketch(0.05, 16)))->toThrow(InvalidArgumentException::class);

		expect(fn () => DDSketch::fromArray([]))->toThrow(InvalidArgumentException::class)
			->and(fn () => DDSketch::fromArray([
				'alpha' => 0.01, 'max_bins' => 16, 'zero_threshold' => 1e-9,
				'count' => 1.0, 'zero_count' => 2.0, 'min' => 0.0, 'max' => 1.0, 'bins' => [],
			]))->toThrow(InvalidArgumentException::class)
			->and(fn () => DDSketch::fromArray([
				'alpha' => 0.01, 'max_bins' => 16, 'zero_threshold' => 1e-9,
				'count' => 1.0, 'zero_count' => 0.0, 'min' => 5.0, 'max' => 2.0, 'bins' => [],
			]))->toThrow(InvalidArgumentException::class)
			->and(fn () => DDSketch::fromArray([
				'alpha' => 0.01, 'max_bins' => 16, 'zero_threshold' => 1e-9,
				'count' => 1.0, 'zero_count' => 0.0, 'min' => 1.0, 'max' => 1.0, 'bins' => 'bad',
			]))->toThrow(InvalidArgumentException::class)
			->and(fn () => DDSketch::fromArray([
				'alpha' => 0.01, 'max_bins' => 16, 'zero_threshold' => 1e-9,
				'count' => 1.0, 'zero_count' => 0.0, 'min' => 1.0, 'max' => 1.0, 'bins' => ['abc' => 1.0],
			]))->toThrow(InvalidArgumentException::class)
			->and(fn () => DDSketch::fromArray([
				'alpha' => 0.01, 'max_bins' => 16, 'zero_threshold' => 1e-9,
				'count' => 1.0, 'zero_count' => 0.0, 'min' => 1.0, 'max' => 1.0, 'bins' => [0 => -1.0],
			]))->toThrow(InvalidArgumentException::class)
			->and(fn () => DDSketch::fromArray([
				'alpha' => 0.01, 'max_bins' => 'bad', 'zero_threshold' => 1e-9,
				'count' => 0.0, 'zero_count' => 0.0, 'min' => null, 'max' => null, 'bins' => [],
			]))->toThrow(InvalidArgumentException::class)
			->and(fn () => DDSketch::fromArray([
				'alpha' => 0.01, 'max_bins' => 16, 'zero_threshold' => 1e-9,
				'count' => 0.0, 'zero_count' => 0.0, 'min' => -1.0, 'max' => null, 'bins' => [],
			]))->toThrow(InvalidArgumentException::class);
	});
});

describe('PeltChangepointDetector (Killick, Fearnhead & Eckley, JASA 2012)', function () {
	it('matches brute-force O(n^2) optimal partitioning exactly across minSegmentLength values', function () {
		// Multi-regime latency trace with deterministic jitter.
		$series = [];

		for ($i = 0; $i < 80; $i++) {
			$jitter = (($i * 37) % 11 - 5) * 0.15;
			$base   = $i < 25 ? 12.0 : ($i < 55 ? 42.0 : 21.0);
			$series[] = $base + $jitter;
		}

		foreach ([1, 3, 5] as $minLen) {
			foreach ([10.0, 40.0, 200.0] as $beta) {
				$detector = new PeltChangepointDetector($beta, $minLen);
				$expected = brute_force_optimal_partitioning($series, $beta, $minLen);

				expect($detector->detect($series))->toBe($expected, "minLen=$minLen beta=$beta");
			}
		}
	});

	it('separates sustained routing regime shifts from transient spikes', function () {
		// Baseline ~10ms with ~2ms jitter, a single-poll 26ms transient spike at
		// index 15, then a sustained routing shift to ~38ms starting at index 40.
		$series = [];

		for ($i = 0; $i < 80; $i++) {
			$noise    = (($i * 19) % 7 - 3) * 1.1;
			$base     = $i < 40 ? 10.0 : 38.0;
			$series[] = $base + $noise;
		}

		$series[15] = 26.0;

		$detector = new PeltChangepointDetector(null, 5);
		$points   = $detector->detect($series);

		expect($points)->toBe([40]);

		$shifts = $detector->detectRegimeShifts($series, 10.0);
		expect($shifts)->toHaveCount(1)
			->and($shifts[0]['index'])->toBe(40)
			->and($shifts[0]['before_mean'])->toBeLessThan(15.0)
			->and($shifts[0]['after_mean'])->toBeGreaterThan(35.0)
			->and($shifts[0]['delta'])->toBeGreaterThan(20.0);

		// Filtering with a high minDelta suppresses smaller shifts.
		expect($detector->detectRegimeShifts($series, 100.0))->toBe([]);
	});

	it('detects exact noiseless step changes, handles constant/short/empty series, and scales linearly', function () {
		$step = array_merge(array_fill(0, 20, 5.0), array_fill(0, 20, 25.0));
		$det  = new PeltChangepointDetector(null, 5);

		expect($det->detect($step))->toBe([20])
			->and($det->detect(array_fill(0, 30, 15.0)))->toBe([])
			->and($det->detect([1.0, 2.0, 3.0]))->toBe([])
			->and($det->segments([]))->toBe([])
			->and(PeltChangepointDetector::estimateNoiseVariance([1.0]))->toBe(0.0);

		$segments = $det->segments($step);
		expect($segments)->toHaveCount(2)
			->and($segments[0]['mean'])->toBe(5.0)
			->and($segments[0]['variance'])->toBe(0.0)
			->and($segments[1]['start'])->toBe(20)
			->and($segments[1]['mean'])->toBe(25.0);

		// Linear-time behavior over n = 2000 with multiple regimes.
		$large = [];

		for ($i = 0; $i < 2000; $i++) {
			$regime  = intdiv($i, 400);
			$large[] = (10.0 + ($regime * 25.0)) + ((($i * 13) % 5 - 2) * 0.25);
		}

		expect($det->detect($large))->toBe([400, 800, 1200, 1600]);
	});

	it('rejects invalid configuration and non-finite series inputs', function () {
		expect(fn () => new PeltChangepointDetector(-1.0))->toThrow(InvalidArgumentException::class)
			->and(fn () => new PeltChangepointDetector(INF))->toThrow(InvalidArgumentException::class)
			->and(fn () => new PeltChangepointDetector(null, 0))->toThrow(InvalidArgumentException::class);

		$det = new PeltChangepointDetector();
		expect(fn () => $det->detect([1.0, NAN, 2.0]))->toThrow(InvalidArgumentException::class)
			->and(fn () => $det->detectRegimeShifts([1.0, 2.0], -0.5))->toThrow(InvalidArgumentException::class);
	});
});
