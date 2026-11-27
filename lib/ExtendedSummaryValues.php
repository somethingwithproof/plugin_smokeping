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
 * Extended summary metrics built on top of SummaryValues without altering the
 * default "median:... loss_pct:... age:..." script-server contract.
 *
 * Adds jitter (interquartile range and RFC 3550 smoothed mean deviation),
 * round latency distribution quantiles (min, p95, p99, max in milliseconds),
 * and maximum consecutive packet-loss burst length across rounds. Null is
 * unknown and prints as U.
 */
final readonly class ExtendedSummaryValues {
	/**
	 * Create an ExtendedSummaryValues instance.
	 *
	 * @param SummaryValues $base Base median, loss_pct, and age values.
	 * @param float|null $jitterIqrMs Interquartile range (Q3 - Q1) in milliseconds.
	 * @param float|null $jitterRfc3550Ms RFC 3550 section 6.4.1 smoothed interarrival jitter in milliseconds.
	 * @param float|null $minMs Minimum sample latency in milliseconds.
	 * @param float|null $p95Ms 95th percentile sample latency in milliseconds.
	 * @param float|null $p99Ms 99th percentile sample latency in milliseconds.
	 * @param float|null $maxMs Maximum sample latency in milliseconds.
	 * @param int|null $lossBurstMax Maximum consecutive lost rounds.
	 */
	public function __construct(
		public SummaryValues $base,
		public ?float $jitterIqrMs,
		public ?float $jitterRfc3550Ms,
		public ?float $minMs,
		public ?float $p95Ms,
		public ?float $p99Ms,
		public ?float $maxMs,
		public ?int $lossBurstMax,
	) {
	}

	/**
	 * Return an all-unknown extended summary (all fields print as U).
	 *
	 * @return self
	 */
	public static function unknown(): self {
		return new self(SummaryValues::unknown(), null, null, null, null, null, null, null);
	}

	/**
	 * Return an extended summary keeping only the base values (for stale or never-updated series).
	 *
	 * @param SummaryValues $base Base summary values.
	 * @return self
	 */
	public static function baseOnly(SummaryValues $base): self {
		return new self($base, null, null, null, null, null, null, null);
	}

	/**
	 * Build extended summary metrics from raw ping samples (in milliseconds),
	 * recent round loss values, and/or an accumulated DDSketch.
	 *
	 * When both raw samples and a non-empty DDSketch are supplied, jitter is
	 * derived from the ordered raw ping samples while quantiles come from the
	 * sketch (which can aggregate across multiple rounds or remote pollers).
	 *
	 * @param SummaryValues $base Base summary values.
	 * @param list<float|int> $samplesMs Ordered round ping latencies in milliseconds.
	 * @param list<float|null> $recentLosses Ordered round loss counts or percentages (null = unknown round).
	 * @param DDSketch|null $sketch Optional latency sketch in milliseconds for multi-round quantiles.
	 * @param float|null $currentLoss Optional current round loss fallback when $recentLosses is empty.
	 * @return self
	 */
	public static function fromSamples(
		SummaryValues $base,
		array $samplesMs,
		array $recentLosses = [],
		?DDSketch $sketch = null,
		?float $currentLoss = null,
	): self {
		$clean = [];

		foreach ($samplesMs as $sample) {
			$val = (float) $sample;

			if (is_finite($val) && $val >= 0.0) {
				$clean[] = $val;
			}
		}

		$jitterIqr     = null;
		$jitterRfc3550 = null;
		$minMs         = null;
		$p95Ms         = null;
		$p99Ms         = null;
		$maxMs         = null;

		if ($clean !== []) {
			$jitterRfc3550 = self::jitterRfc3550($clean);
			$sorted        = $clean;
			sort($sorted, SORT_NUMERIC);
			$jitterIqr = self::jitterIqr($sorted);
			$minMs     = $sorted[0];
			$maxMs     = $sorted[count($sorted) - 1];
			$p95Ms     = self::percentile($sorted, 0.95);
			$p99Ms     = self::percentile($sorted, 0.99);
		}

		if ($sketch !== null && !$sketch->isEmpty()) {
			$summary = $sketch->summary();
			$minMs   = $summary['min'];
			$p95Ms   = $summary['p95'];
			$p99Ms   = $summary['p99'];
			$maxMs   = $summary['max'];

			if ($jitterIqr === null) {
				$q25 = $sketch->quantile(0.25);
				$q75 = $sketch->quantile(0.75);

				if ($q25 !== null && $q75 !== null) {
					$jitterIqr = max(0.0, $q75 - $q25);
				}
			}
		}

		$lossBurstMax = self::lossBurstMax($recentLosses, $currentLoss);

		return new self(
			$base,
			$jitterIqr,
			$jitterRfc3550,
			$minMs,
			$p95Ms,
			$p99Ms,
			$maxMs,
			$lossBurstMax,
		);
	}

	/**
	 * Compute interquartile range (Q3 - Q1) from an ascending sorted list of samples.
	 *
	 * @param list<float> $sortedSamples Ascending finite latency samples in milliseconds.
	 * @return float|null Non-negative IQR in milliseconds, or null when empty.
	 */
	public static function jitterIqr(array $sortedSamples): ?float {
		if ($sortedSamples === []) {
			return null;
		}

		$q1 = self::percentile($sortedSamples, 0.25);
		$q3 = self::percentile($sortedSamples, 0.75);

		if ($q1 === null || $q3 === null) {
			return null;
		}

		return max(0.0, $q3 - $q1);
	}

	/**
	 * Compute RFC 3550 section 6.4.1 / Appendix A.8 smoothed interarrival jitter.
	 *
	 * Updates J_i = J_{i-1} + (|x_i - x_{i-1}| - J_{i-1}) / 16 across
	 * consecutive ping samples in arrival order.
	 *
	 * @param list<float> $samples Ordered finite latency samples in milliseconds.
	 * @return float|null Smoothed mean deviation in milliseconds, or null when empty.
	 */
	public static function jitterRfc3550(array $samples): ?float {
		$count = count($samples);

		if ($count === 0) {
			return null;
		}

		if ($count === 1) {
			return 0.0;
		}

		$jitter = 0.0;

		for ($i = 1; $i < $count; $i++) {
			$diff    = abs($samples[$i] - $samples[$i - 1]);
			$jitter += ($diff - $jitter) / 16.0;
		}

		return $jitter;
	}

	/**
	 * Compute an exact linear-interpolated (Type 7 / R-7) quantile from an ascending list.
	 *
	 * @param list<float> $sortedSamples Ascending finite latency samples.
	 * @param float $q Quantile rank in [0.0, 1.0].
	 * @return float|null Interpolated quantile value, or null when empty.
	 */
	public static function percentile(array $sortedSamples, float $q): ?float {
		$count = count($sortedSamples);

		if ($count === 0) {
			return null;
		}

		if ($count === 1 || $q <= 0.0) {
			return $sortedSamples[0];
		}

		if ($q >= 1.0) {
			return $sortedSamples[$count - 1];
		}

		$pos   = $q * ($count - 1);
		$lower = (int) floor($pos);
		$upper = (int) ceil($pos);

		if ($lower === $upper) {
			return $sortedSamples[$lower];
		}

		$fraction = $pos - $lower;

		return $sortedSamples[$lower] + ($fraction * ($sortedSamples[$upper] - $sortedSamples[$lower]));
	}

	/**
	 * Compute the maximum consecutive streak of rounds experiencing packet loss (> 0).
	 *
	 * Unknown (null) rounds break an active streak without fabricating zero loss.
	 * Returns null when no numeric round loss observation is present.
	 *
	 * @param list<float|null> $roundLosses Ordered round loss counts or percentages.
	 * @param float|null $currentLoss Optional current round loss used when $roundLosses is empty.
	 * @return int|null Longest consecutive lost-round burst, or null when unknown.
	 */
	public static function lossBurstMax(array $roundLosses, ?float $currentLoss = null): ?int {
		if ($roundLosses === []) {
			if ($currentLoss === null || !is_finite($currentLoss)) {
				return null;
			}

			return $currentLoss > 0.0 ? 1 : 0;
		}

		$seenNumeric = false;
		$current     = 0;
		$maxStreak   = 0;

		foreach ($roundLosses as $loss) {
			if ($loss === null || !is_finite($loss)) {
				$current = 0;

				continue;
			}

			$seenNumeric = true;

			if ($loss > 0.0) {
				$current++;

				if ($current > $maxStreak) {
					$maxStreak = $current;
				}
			} else {
				$current = 0;
			}
		}

		return $seenNumeric ? $maxStreak : null;
	}

	/**
	 * Format the extended script-server output line, keeping the three base fields first.
	 *
	 * @return string Space-separated field:value pairs with U for unknown values.
	 */
	public function format(): string {
		return sprintf(
			'%s jitter_iqr_ms:%s jitter_rfc3550_ms:%s min_ms:%s p95_ms:%s p99_ms:%s max_ms:%s loss_burst_max:%s',
			$this->base->format(),
			self::number($this->jitterIqrMs),
			self::number($this->jitterRfc3550Ms),
			self::number($this->minMs),
			self::number($this->p95Ms),
			self::number($this->p99Ms),
			self::number($this->maxMs),
			$this->lossBurstMax === null ? 'U' : (string) $this->lossBurstMax,
		);
	}

	/**
	 * Return all base and extended metrics as an associative array.
	 *
	 * @return array{
	 *     median: ?float,
	 *     loss_pct: ?float,
	 *     age: ?int,
	 *     jitter_iqr_ms: ?float,
	 *     jitter_rfc3550_ms: ?float,
	 *     min_ms: ?float,
	 *     p95_ms: ?float,
	 *     p99_ms: ?float,
	 *     max_ms: ?float,
	 *     loss_burst_max: ?int
	 * }
	 */
	public function toArray(): array {
		return [
			'median'            => $this->base->median,
			'loss_pct'          => $this->base->lossPct,
			'age'               => $this->base->age,
			'jitter_iqr_ms'     => $this->jitterIqrMs,
			'jitter_rfc3550_ms' => $this->jitterRfc3550Ms,
			'min_ms'            => $this->minMs,
			'p95_ms'            => $this->p95Ms,
			'p99_ms'            => $this->p99Ms,
			'max_ms'            => $this->maxMs,
			'loss_burst_max'    => $this->lossBurstMax,
		];
	}

	/**
	 * Format a nullable float using locale-independent decimal points with U for null.
	 *
	 * @param float|null $value Value to format.
	 * @return string Formatted numeric string or U.
	 */
	private static function number(?float $value): string {
		if ($value === null) {
			return 'U';
		}

		$text = rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');

		return $text === '' || $text === '-0' ? '0' : $text;
	}
}
