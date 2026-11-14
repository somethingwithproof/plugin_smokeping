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

use Somethingwithproof\Smokeping\Condition;
use Somethingwithproof\Smokeping\ExecutionState;
use Somethingwithproof\Smokeping\FixedClock;
use Somethingwithproof\Smokeping\Freshness;
use Somethingwithproof\Smokeping\FreshnessEvaluator;
use Somethingwithproof\Smokeping\Observation;
use Somethingwithproof\Smokeping\RrdLastUpdate;
use Somethingwithproof\Smokeping\TargetId;

const OBSERVED = 1700000000;

function observation(?float $loss = 0.0, ?float $median = 0.012, int $observedAt = OBSERVED, bool $present = true, int $step = 300, int $pings = 20, int $ingestedAt = OBSERVED): Observation {
	return new Observation(TargetId::fromInt(1), '', $observedAt, $ingestedAt, $step, $pings, $loss, $median, $present, '');
}

describe('freshness boundaries', function () {
	// step 300, allowance 60: current to 360 s, delayed to 600 s (heartbeat), stale after.
	it('classifies each side of every boundary', function (int $age, Freshness $expected) {
		$evaluator = new FreshnessEvaluator(new FixedClock(OBSERVED + $age), 60);

		expect($evaluator->freshness(observation()))->toBe($expected);
	})->with([
		'just written' => [0, Freshness::Current],
		'clock behind the RRD' => [-30, Freshness::Current],
		'one step' => [300, Freshness::Current],
		'step plus allowance' => [360, Freshness::Current],
		'one second past allowance' => [361, Freshness::Delayed],
		'heartbeat' => [600, Freshness::Delayed],
		'one second past heartbeat' => [601, Freshness::Stale],
		'a day' => [86400, Freshness::Stale],
	]);

	it('is never observed without a file, without an update, or without a step', function () {
		$evaluator = new FreshnessEvaluator(new FixedClock(OBSERVED), 60);

		expect($evaluator->freshness(observation(present: false)))->toBe(Freshness::Never)
			->and($evaluator->freshness(observation(observedAt: 0)))->toBe(Freshness::Never)
			->and($evaluator->freshness(observation(step: 0)))->toBe(Freshness::Never);
	});

	it('ignores when the reader or a page last looked', function () {
		$clock     = new FixedClock(OBSERVED + 3600);
		$evaluator = new FreshnessEvaluator($clock, 60);

		expect($evaluator->freshness(observation(ingestedAt: OBSERVED + 3600)))->toBe(Freshness::Stale);
	});

	it('moves with the injected clock', function () {
		$clock     = new FixedClock(OBSERVED);
		$evaluator = new FreshnessEvaluator($clock, 60);
		$seen      = [];

		foreach ([0, 361, 240] as $step) {
			$clock->advance($step);
			$seen[] = $evaluator->freshness(observation());
		}

		expect($seen)->toBe([Freshness::Current, Freshness::Delayed, Freshness::Stale]);
	});
});

describe('execution and condition', function () {
	$now = new FixedClock(OBSERVED + 10);

	it('reports complete loss as loss = pings with median unknown', function () use ($now) {
		$evaluator = new FreshnessEvaluator($now, 60);

		expect($evaluator->execution(observation(loss: 20.0, median: null)))->toBe(ExecutionState::Completed)
			->and($evaluator->condition(observation(loss: 20.0, median: null)))->toBe(Condition::CompleteLoss);
	});

	it('distinguishes a stopped collector from complete loss', function () {
		$evaluator = new FreshnessEvaluator(new FixedClock(OBSERVED + 7200), 60);
		$stopped   = observation(loss: 20.0, median: null);

		expect($evaluator->freshness($stopped))->toBe(Freshness::Stale)
			->and($evaluator->execution($stopped))->toBe(ExecutionState::Unknown)
			->and($evaluator->condition($stopped))->toBe(Condition::Unknown);
	});

	it('grades partial loss as degraded and no loss as within policy', function () use ($now) {
		$evaluator = new FreshnessEvaluator($now, 60);

		expect($evaluator->condition(observation(loss: 5.0, median: 0.02)))->toBe(Condition::Degraded)
			->and($evaluator->condition(observation(loss: 0.0)))->toBe(Condition::WithinPolicy)
			->and($evaluator->condition(observation(loss: 0.0, median: null)))->toBe(Condition::WithinPolicy);
	});

	it('treats a round without a loss value as unknown, not as a probe error', function () use ($now) {
		$evaluator = new FreshnessEvaluator($now, 60);

		expect($evaluator->execution(observation(loss: null, median: null)))->toBe(ExecutionState::Unknown)
			->and($evaluator->condition(observation(loss: null, median: null)))->toBe(Condition::Unknown);
	});

	it('reports delayed data from its last round', function () {
		$evaluator = new FreshnessEvaluator(new FixedClock(OBSERVED + 500), 60);

		expect($evaluator->execution(observation(loss: 3.0)))->toBe(ExecutionState::Completed)
			->and($evaluator->condition(observation(loss: 3.0)))->toBe(Condition::Degraded);
	});
});

describe('RrdLastUpdate::parse', function () {
	// Output of rrdtool 1.8 lastupdate on SmokePing-layout files from
	// tests/bin/make-fixture-rrds.php, trimmed to 3 pings.
	it('parses values and unknowns', function () {
		$normal = RrdLastUpdate::parse(" uptime loss median ping1 ping2 ping3\n\n1794613041: 3600 0 1.1000000000e-02 0.010100 0.010200 0.010300\n");


		expect($normal->time)->toBe(1794613041)
			->and($normal->value('loss'))->toBe(0.0)
			->and($normal->value('median'))->toBe(0.011)
			->and($normal->allUnknown())->toBeFalse();
	});

	it('recognizes a never-updated file and complete loss', function () {
		$never = RrdLastUpdate::parse(" uptime loss median ping1\n\n1794607051: U U U U\n");
		$loss  = RrdLastUpdate::parse(" uptime loss median ping1\n\n1794613041: 3600 1 U U\n");

		expect($never->allUnknown())->toBeTrue()
			->and($loss->allUnknown())->toBeFalse()
			->and($loss->value('loss'))->toBe(1.0)
			->and($loss->value('median'))->toBeNull();
	});

	it('rejects output it does not understand', function (string $output) {
		expect(fn () => RrdLastUpdate::parse($output))->toThrow(UnexpectedValueException::class);
	})->with(['', "ERROR: opening 'x.rrd': No such file or directory\n", " loss median\n\n1: 0\n"]);
});
