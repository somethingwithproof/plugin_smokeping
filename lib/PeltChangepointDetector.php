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

namespace Somethingwithproof\Smokeping;

/**
 * Exact O(n) Pruned Exact Linear Time (PELT) changepoint detector for latency series.
 *
 * Implements the PELT dynamic-programming algorithm of Killick, Fearnhead, and
 * Eckley ("Optimal Detection of Changepoints with a Linear Computational Cost",
 * JASA 2012) under squared-error mean-shift segment cost. Combined with a
 * minimum segment length, PELT separates sustained routing or latency regime
 * shifts from transient single-poll spikes while guaranteeing global optimality.
 */
final readonly class PeltChangepointDetector {
	/** Default minimum number of observations per regime segment. */
	public const DEFAULT_MIN_SEGMENT_LENGTH = 5;

	/**
	 * Create a PELT detector with optional explicit penalty and minimum segment length.
	 *
	 * When $penalty is null, a data-adaptive BIC penalty (2 * sigma^2 * log(n))
	 * is computed from a robust MAD noise-variance estimate of first differences.
	 *
	 * @param float|null $penalty Non-negative changepoint penalty beta, or null for adaptive BIC.
	 * @param int $minSegmentLength Minimum number of points per segment (>= 1).
	 * @throws \InvalidArgumentException When $penalty is negative/non-finite or $minSegmentLength < 1.
	 */
	public function __construct(
		public ?float $penalty = null,
		public int $minSegmentLength = self::DEFAULT_MIN_SEGMENT_LENGTH,
	) {
		if ($this->penalty !== null && (!is_finite($this->penalty) || $this->penalty < 0.0)) {
			throw new \InvalidArgumentException('PELT penalty must be null or a non-negative finite number.');
		}

		if ($this->minSegmentLength < 1) {
			throw new \InvalidArgumentException('PELT minSegmentLength must be at least 1.');
		}
	}

	/**
	 * Detect optimal changepoint indices (0-based start of each new regime).
	 *
	 * Uses O(1) shifted prefix-sum segment cost evaluation and delayed Theorem 3.1
	 * pruning so exactness holds for any minSegmentLength >= 1.
	 *
	 * @param list<float|int> $series Ordered time-series observations.
	 * @return list<int> Strictly ascending changepoint start indices in (0, count($series)).
	 * @throws \InvalidArgumentException When any element of $series is non-finite.
	 */
	public function detect(array $series): array {
		return $this->detectNormalized(self::normalizeSeries($series));
	}

	/**
	 * Partition a series into optimal regime segments with summary statistics.
	 *
	 * @param list<float|int> $series Ordered time-series observations.
	 * @return list<array{start: int, end: int, length: int, mean: float, variance: float}>
	 * @throws \InvalidArgumentException When any element of $series is non-finite.
	 */
	public function segments(array $series): array {
		$values = self::normalizeSeries($series);
		$n      = count($values);

		if ($n === 0) {
			return [];
		}

		$boundaries = array_merge([0], $this->detectNormalized($values), [$n]);
		$segments   = [];

		for ($i = 0, $limit = count($boundaries) - 1; $i < $limit; $i++) {
			$start  = $boundaries[$i];
			$end    = $boundaries[$i + 1];
			$length = $end - $start;
			$sum    = 0.0;

			for ($j = $start; $j < $end; $j++) {
				$sum += $values[$j];
			}

			$mean = $sum / $length;
			$sse  = 0.0;

			for ($j = $start; $j < $end; $j++) {
				$diff = $values[$j] - $mean;
				$sse += $diff * $diff;
			}

			$segments[] = [
				'start'    => $start,
				'end'      => $end,
				'length'   => $length,
				'mean'     => $mean,
				'variance' => $sse / $length,
			];
		}

		return $segments;
	}

	/**
	 * Identify sustained regime shifts exceeding an optional minimum absolute mean delta.
	 *
	 * @param list<float|int> $series Ordered time-series observations.
	 * @param float $minDelta Minimum absolute change in segment mean required to report a shift.
	 * @return list<array{index: int, before_mean: float, after_mean: float, delta: float}>
	 * @throws \InvalidArgumentException When $minDelta is negative/non-finite or $series contains non-finite values.
	 */
	public function detectRegimeShifts(array $series, float $minDelta = 0.0): array {
		if (!is_finite($minDelta) || $minDelta < 0.0) {
			throw new \InvalidArgumentException('PELT minDelta must be finite and non-negative.');
		}

		$segments = $this->segments($series);
		$shifts   = [];

		for ($i = 1, $count = count($segments); $i < $count; $i++) {
			$before = $segments[$i - 1]['mean'];
			$after  = $segments[$i]['mean'];
			$delta  = $after - $before;

			if (abs($delta) >= $minDelta) {
				$shifts[] = [
					'index'       => $segments[$i]['start'],
					'before_mean' => $before,
					'after_mean'  => $after,
					'delta'       => $delta,
				];
			}
		}

		return $shifts;
	}

	/**
	 * Estimate per-observation noise variance robustly from first differences via MAD.
	 *
	 * Using first differences isolates short-range observation noise from
	 * step shifts in mean: sigma = 1.4826 * median(|y_t - y_{t-1}|) / sqrt(2).
	 *
	 * @param list<float|int> $series Ordered time-series observations.
	 * @return float Estimated non-negative noise variance.
	 * @throws \InvalidArgumentException When any element of $series is non-finite.
	 */
	public static function estimateNoiseVariance(array $series): float {
		return self::estimateNoiseVarianceNormalized(self::normalizeSeries($series));
	}

	/**
	 * Core PELT dynamic program over an already-validated float series.
	 *
	 * Inlines O(1) shifted prefix-sum SSE arithmetic inside the pruning and
	 * minimization loops to avoid per-candidate PHP call overhead, and stops
	 * pruning checks as soon as ascending candidates exceed $eligible - $minLen.
	 *
	 * @param list<float> $values Validated finite observations.
	 * @return list<int> Strictly ascending changepoint start indices.
	 */
	private function detectNormalized(array $values): array {
		$n      = count($values);
		$minLen = $this->minSegmentLength;

		if ($n < 2 * $minLen) {
			return [];
		}

		// Shift values by $values[0] before squaring so large-mean latency
		// series do not lose precision to catastrophic cancellation in SSE.
		$shift     = $values[0];
		$prefixSum = [0.0];
		$prefixSq  = [0.0];
		$a1        = 0.0;
		$a2        = 0.0;

		for ($i = 0; $i < $n; $i++) {
			$shifted     = $values[$i] - $shift;
			$a1         += $shifted;
			$a2         += $shifted * $shifted;
			$prefixSum[] = $a1;
			$prefixSq[]  = $a2;
		}

		$totalCost = $a2 - (($a1 * $a1) / $n);

		if ($totalCost <= 1e-12) {
			return [];
		}

		$beta = $this->resolvePenalty($values, $totalCost);

		/** @var array<int, float> $bestCost */
		$bestCost = [0 => -$beta];
		/** @var array<int, int> $previous */
		$previous = [0 => -1];
		/** @var list<int> $candidates */
		$candidates = [0];

		for ($t = $minLen; $t <= $n; $t++) {
			$eligible = $t - $minLen;

			if ($eligible >= $minLen) {
				// Delayed pruning: $eligible is now a valid predecessor for every
				// future T >= $t. By Killick et al. (2012) Theorem 3.1 with K = 0
				// (variance decomposition: C(s, eligible) + C(eligible, T) <= C(s, T)),
				// any earlier candidate s <= eligible - minLen with
				// F(s) + C(s, eligible) >= F(eligible) is strictly dominated forever.
				$eligibleCost = $bestCost[$eligible];
				$maxPrunable  = $eligible - $minLen;
				$psElig       = $prefixSum[$eligible];
				$sqElig       = $prefixSq[$eligible];
				$pruned       = [];

				foreach ($candidates as $s) {
					if ($s <= $maxPrunable) {
						$sum = $psElig - $prefixSum[$s];
						$sse = ($sqElig - $prefixSq[$s]) - (($sum * $sum) / ($eligible - $s));

						if ($bestCost[$s] + ($sse > 0.0 ? $sse : 0.0) >= $eligibleCost) {
							continue;
						}
					}

					$pruned[] = $s;
				}

				$pruned[]   = $eligible;
				$candidates = $pruned;
			}

			$psT     = $prefixSum[$t];
			$sqT     = $prefixSq[$t];
			$minCost = INF;
			$bestS   = 0;

			foreach ($candidates as $s) {
				$sum  = $psT - $prefixSum[$s];
				$sse  = ($sqT - $prefixSq[$s]) - (($sum * $sum) / ($t - $s));
				$cost = $bestCost[$s] + ($sse > 0.0 ? $sse : 0.0) + $beta;

				if ($cost < $minCost) {
					$minCost = $cost;
					$bestS   = $s;
				}
			}

			$bestCost[$t] = $minCost;
			$previous[$t] = $bestS;
		}

		$changepoints = [];
		$cursor       = $previous[$n];

		while ($cursor > 0) {
			$changepoints[] = $cursor;
			$cursor         = $previous[$cursor];
		}

		return array_reverse($changepoints);
	}

	/**
	 * Estimate noise variance from already-validated finite floats via first-difference MAD.
	 *
	 * @param list<float> $values Validated observation values.
	 * @return float Estimated non-negative noise variance.
	 */
	private static function estimateNoiseVarianceNormalized(array $values): float {
		$n = count($values);

		if ($n < 2) {
			return 0.0;
		}

		$diffs = [];

		for ($i = 1; $i < $n; $i++) {
			$diffs[] = abs($values[$i] - $values[$i - 1]);
		}

		sort($diffs, SORT_NUMERIC);
		$m   = count($diffs);
		$mid = intdiv($m, 2);
		$mad = ($m % 2 === 1) ? $diffs[$mid] : 0.5 * ($diffs[$mid - 1] + $diffs[$mid]);

		$sigma = (1.4826 * $mad) / M_SQRT2;

		return $sigma * $sigma;
	}

	/**
	 * Resolve the active changepoint penalty beta for a series.
	 *
	 * @param list<float> $values Validated observation values.
	 * @param float $totalCost Whole-series squared-error cost.
	 * @return float Non-negative changepoint penalty.
	 */
	private function resolvePenalty(array $values, float $totalCost): float {
		if ($this->penalty !== null) {
			return $this->penalty;
		}

		$n        = count($values);
		$variance = self::estimateNoiseVarianceNormalized($values);

		// For piecewise-constant (noiseless) series, first-difference MAD is zero;
		// scale a conservative penalty from overall series variance so exact step
		// changes are still separated cleanly from floating-point rounding noise.
		if ($variance <= 1e-12) {
			$variance = 0.01 * ($totalCost / $n);
		}

		return 2.0 * $variance * log($n);
	}

	/**
	 * Validate and convert input observations to finite floats.
	 *
	 * @param list<float|int> $series Input observations.
	 * @return list<float> Validated float list.
	 * @throws \InvalidArgumentException When any value is non-finite.
	 */
	private static function normalizeSeries(array $series): array {
		$values = [];

		foreach ($series as $value) {
			$float = (float) $value;

			if (!is_finite($float)) {
				throw new \InvalidArgumentException('PELT series values must be finite numbers.');
			}

			$values[] = $float;
		}

		return $values;
	}
}
