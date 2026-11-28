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
 * Mergeable relative-error quantile sketch for latency distributions.
 *
 * Implements the collapsing-lowest-bin logarithmic DDSketch algorithm from
 * Masson, Rim, and Lee ("DDSketch: A Fast and Fully-Mergeable Quantile Sketch
 * with Relative-Error Guarantees", PVLDB 2019). Positive observations are
 * mapped to logarithmic buckets spaced by gamma = (1 + alpha) / (1 - alpha),
 * bounding relative quantile error by alpha while preserving tail accuracy
 * (p95, p99, p99.9) across remote pollers via associative merge().
 */
final class DDSketch {
	/** Default relative-error guarantee (1%). */
	public const DEFAULT_ALPHA = 0.01;

	/** Default upper bound on distinct logarithmic buckets retained in memory. */
	public const DEFAULT_MAX_BINS = 2048;

	/** Default threshold at or below which values map to the zero bucket. */
	public const DEFAULT_ZERO_THRESHOLD = 1e-9;

	/** Relative-error parameter alpha in (0, 1). */
	private float $alpha;

	/** Logarithmic bucket base (1 + alpha) / (1 - alpha). */
	private float $gamma;

	/** Natural logarithm of gamma for O(1) bucket indexing. */
	private float $logGamma;

	/** Reciprocal of logGamma so indexFor() multiplies instead of dividing. */
	private float $invLogGamma;

	/** Midpoint scale factor 2 / (gamma + 1) for O(1) value reconstruction. */
	private float $valueScale;

	/** Maximum number of positive buckets retained before collapsing lowest bins. */
	private int $maxBins;

	/** Non-negative threshold collapsing near-zero observations into zeroCount. */
	private float $zeroThreshold;

	/** Total weight of all recorded observations. */
	private float $count = 0.0;

	/** Total weight of observations at or below zeroThreshold. */
	private float $zeroCount = 0.0;

	/** Exact minimum observed value, or null when empty. */
	private ?float $min = null;

	/** Exact maximum observed value, or null when empty. */
	private ?float $max = null;

	/** Whether $bins is currently ordered by ascending bucket index. */
	private bool $binsSorted = true;

	/** Lowest bucket index currently present in $bins, or null when empty. */
	private ?int $minBinIndex = null;

	/** Highest bucket index currently present in $bins, or null when empty. */
	private ?int $maxBinIndex = null;

	/**
	 * Map of logarithmic bucket index to accumulated observation weight.
	 *
	 * @var array<int, float>
	 */
	private array $bins = [];

	/**
	 * Create a new DDSketch instance with bounded memory and relative-error guarantees.
	 *
	 * @param float $alpha Relative accuracy parameter strictly between 0 and 1.
	 * @param int $maxBins Maximum number of positive buckets (>= 2).
	 * @param float $zeroThreshold Threshold at or below which non-negative values map to the zero bucket.
	 * @throws \InvalidArgumentException When any parameter is non-finite or outside its valid domain.
	 */
	public function __construct(
		float $alpha = self::DEFAULT_ALPHA,
		int $maxBins = self::DEFAULT_MAX_BINS,
		float $zeroThreshold = self::DEFAULT_ZERO_THRESHOLD,
	) {
		if (!is_finite($alpha) || $alpha <= 0.0 || $alpha >= 1.0) {
			throw new \InvalidArgumentException('DDSketch alpha must be finite and strictly between 0 and 1.');
		}

		if ($maxBins < 2) {
			throw new \InvalidArgumentException('DDSketch maxBins must be at least 2.');
		}

		if (!is_finite($zeroThreshold) || $zeroThreshold < 0.0) {
			throw new \InvalidArgumentException('DDSketch zeroThreshold must be finite and non-negative.');
		}

		$this->alpha         = $alpha;
		$this->gamma         = (1.0 + $alpha) / (1.0 - $alpha);
		$this->logGamma      = log($this->gamma);
		$this->invLogGamma   = 1.0 / $this->logGamma;
		$this->valueScale    = 2.0 / ($this->gamma + 1.0);
		$this->maxBins       = $maxBins;
		$this->zeroThreshold = $zeroThreshold;
	}

	/**
	 * Record a non-negative latency observation with a positive weight.
	 *
	 * @param float $value Non-negative finite observation value.
	 * @param float $weight Positive finite observation weight.
	 * @throws \InvalidArgumentException When $value is negative/non-finite or $weight is non-positive/non-finite.
	 */
	public function add(float $value, float $weight = 1.0): void {
		if (!is_finite($value) || $value < 0.0) {
			throw new \InvalidArgumentException('DDSketch value must be finite and non-negative.');
		}

		if (!is_finite($weight) || $weight <= 0.0) {
			throw new \InvalidArgumentException('DDSketch weight must be finite and strictly positive.');
		}

		$this->count += $weight;
		$this->min    = $this->min === null || $value < $this->min ? $value : $this->min;
		$this->max    = $this->max === null || $value > $this->max ? $value : $this->max;

		if ($value <= $this->zeroThreshold) {
			$this->zeroCount += $weight;

			return;
		}

		$index = (int) ceil(log($value) * $this->invLogGamma);

		if (isset($this->bins[$index])) {
			$this->bins[$index] += $weight;

			return;
		}

		// When already at maxBins capacity, any sample below the current lowest
		// bucket would immediately collapse into minBinIndex. Fold it directly
		// in O(1) without inserting, sorting, or scanning.
		if ($this->minBinIndex !== null && $index < $this->minBinIndex && count($this->bins) >= $this->maxBins) {
			$this->bins[$this->minBinIndex] += $weight;

			return;
		}

		if ($this->minBinIndex === null || $index < $this->minBinIndex) {
			$this->minBinIndex = $index;
		}

		if ($this->maxBinIndex === null || $index > $this->maxBinIndex) {
			$this->maxBinIndex = $index;
		} else {
			$this->binsSorted = false;
		}

		$this->bins[$index] = $weight;

		if (count($this->bins) > $this->maxBins) {
			$this->collapseExcess();
		}
	}

	/**
	 * Estimate the q-quantile in [0, 1], returning null for an empty sketch.
	 *
	 * @param float $q Quantile rank in [0.0, 1.0].
	 * @return float|null Estimated quantile within alpha relative error, or null when empty.
	 * @throws \InvalidArgumentException When $q is outside [0.0, 1.0] or non-finite.
	 */
	public function quantile(float $q): ?float {
		if (!is_finite($q) || $q < 0.0 || $q > 1.0) {
			throw new \InvalidArgumentException('DDSketch quantile rank must be finite and in [0.0, 1.0].');
		}

		if ($this->count <= 0.0 || $this->min === null || $this->max === null) {
			return null;
		}

		if ($q === 0.0) {
			return $this->min;
		}

		if ($q === 1.0) {
			return $this->max;
		}

		$targetRank = $q * $this->count;

		if ($targetRank <= $this->zeroCount) {
			return 0.0;
		}

		$this->ensureSorted();
		$running = $this->zeroCount;

		foreach ($this->bins as $index => $binWeight) {
			$running += $binWeight;

			if ($running >= $targetRank) {
				$estimate = $this->valueFor($index);

				return max($this->min, min($this->max, $estimate));
			}
		}

		return $this->max;
	}

	/**
	 * Compute standard latency summary quantiles (p50, p95, p99, p99.9) alongside count/min/max.
	 *
	 * Resolves all four quantiles in a single ascending scan over the sorted
	 * positive buckets instead of sorting and scanning once per quantile.
	 *
	 * @return array{count: float, min: ?float, max: ?float, p50: ?float, p95: ?float, p99: ?float, p999: ?float}
	 */
	public function summary(): array {
		if ($this->count <= 0.0 || $this->min === null || $this->max === null) {
			return [
				'count' => $this->count,
				'min'   => $this->min,
				'max'   => $this->max,
				'p50'   => null,
				'p95'   => null,
				'p99'   => null,
				'p999'  => null,
			];
		}

		$ranks = [
			'p50'  => 0.50 * $this->count,
			'p95'  => 0.95 * $this->count,
			'p99'  => 0.99 * $this->count,
			'p999' => 0.999 * $this->count,
		];
		$out     = ['p50' => null, 'p95' => null, 'p99' => null, 'p999' => null];
		$pending = [];

		foreach ($ranks as $label => $targetRank) {
			if ($targetRank <= $this->zeroCount) {
				$out[$label] = 0.0;
			} else {
				$pending[$label] = $targetRank;
			}
		}

		if ($pending !== []) {
			$this->ensureSorted();
			$running = $this->zeroCount;

			foreach ($this->bins as $index => $binWeight) {
				$running += $binWeight;

				foreach ($pending as $label => $targetRank) {
					if ($running >= $targetRank) {
						$estimate    = $this->valueFor($index);
						$out[$label] = $estimate < $this->min ? $this->min : ($estimate > $this->max ? $this->max : $estimate);
						unset($pending[$label]);
					}
				}

				if ($pending === []) {
					break;
				}
			}

			foreach ($pending as $label => $_targetRank) {
				$out[$label] = $this->max;
			}
		}

		return [
			'count' => $this->count,
			'min'   => $this->min,
			'max'   => $this->max,
			'p50'   => $out['p50'],
			'p95'   => $out['p95'],
			'p99'   => $out['p99'],
			'p999'  => $out['p999'],
		];
	}

	/**
	 * Merge another compatible DDSketch into this sketch in place.
	 *
	 * Associative and commutative up to bucket-collapsing order, allowing
	 * remote pollers to transmit compact sketches and combine them centrally.
	 *
	 * @param self $other Sketch with matching alpha and zeroThreshold.
	 * @throws \InvalidArgumentException When $other has incompatible alpha or zeroThreshold parameters.
	 */
	public function merge(self $other): void {
		if (abs($this->alpha - $other->alpha) > 1e-12 || abs($this->zeroThreshold - $other->zeroThreshold) > 1e-12) {
			throw new \InvalidArgumentException('Cannot merge DDSketch instances with different alpha or zeroThreshold parameters.');
		}

		if ($other->isEmpty()) {
			return;
		}

		$this->count     += $other->count;
		$this->zeroCount += $other->zeroCount;

		if ($other->min !== null) {
			$this->min = $this->min === null || $other->min < $this->min ? $other->min : $this->min;
		}

		if ($other->max !== null) {
			$this->max = $this->max === null || $other->max > $this->max ? $other->max : $this->max;
		}

		foreach ($other->bins as $index => $weight) {
			if (isset($this->bins[$index])) {
				$this->bins[$index] += $weight;
			} else {
				if ($this->minBinIndex === null || $index < $this->minBinIndex) {
					$this->minBinIndex = $index;
				}

				if ($this->maxBinIndex === null || $index > $this->maxBinIndex) {
					$this->maxBinIndex = $index;
				} else {
					$this->binsSorted = false;
				}

				$this->bins[$index] = $weight;
			}
		}

		if (count($this->bins) > $this->maxBins) {
			$this->collapseExcess();
		}
	}

	/**
	 * Total recorded observation weight.
	 *
	 * @return float Total observation count/weight.
	 */
	public function count(): float {
		return $this->count;
	}

	/**
	 * Exact minimum observed value, or null when no observations were added.
	 *
	 * @return float|null Minimum observed value.
	 */
	public function min(): ?float {
		return $this->min;
	}

	/**
	 * Exact maximum observed value, or null when no observations were added.
	 *
	 * @return float|null Maximum observed value.
	 */
	public function max(): ?float {
		return $this->max;
	}

	/**
	 * Configured relative accuracy guarantee alpha.
	 *
	 * @return float Relative error parameter alpha.
	 */
	public function alpha(): float {
		return $this->alpha;
	}

	/**
	 * Configured maximum number of distinct positive buckets.
	 *
	 * @return int Maximum bucket count.
	 */
	public function maxBins(): int {
		return $this->maxBins;
	}

	/**
	 * Configured threshold for the zero bucket.
	 *
	 * @return float Zero-bucket threshold.
	 */
	public function zeroThreshold(): float {
		return $this->zeroThreshold;
	}

	/**
	 * Number of currently occupied positive logarithmic buckets.
	 *
	 * @return int Active positive bucket count.
	 */
	public function binCount(): int {
		return count($this->bins);
	}

	/**
	 * Whether no observations have been recorded yet.
	 *
	 * @return bool True when count is zero.
	 */
	public function isEmpty(): bool {
		return $this->count <= 0.0;
	}

	/**
	 * Serialize the sketch state to a plain array suitable for JSON or IPC transport.
	 *
	 * @return array{alpha: float, max_bins: int, zero_threshold: float, count: float, zero_count: float, min: ?float, max: ?float, bins: array<int, float>}
	 */
	public function toArray(): array {
		$this->ensureSorted();

		return [
			'alpha'          => $this->alpha,
			'max_bins'       => $this->maxBins,
			'zero_threshold' => $this->zeroThreshold,
			'count'          => $this->count,
			'zero_count'     => $this->zeroCount,
			'min'            => $this->min,
			'max'            => $this->max,
			'bins'           => $this->bins,
		];
	}

	/**
	 * Reconstruct a DDSketch from a serialized plain array produced by toArray().
	 *
	 * @param array<string, mixed> $data Serialized sketch payload.
	 * @return self Hydrated DDSketch instance.
	 * @throws \InvalidArgumentException When required fields are missing or have invalid values.
	 */
	public static function fromArray(array $data): self {
		$alpha         = self::requireFloat($data, 'alpha');
		$maxBins       = self::requireInt($data, 'max_bins');
		$zeroThreshold = self::requireFloat($data, 'zero_threshold');
		$count         = self::requireFloat($data, 'count');
		$zeroCount     = self::requireFloat($data, 'zero_count');

		if ($count < 0.0 || $zeroCount < 0.0 || $zeroCount > $count + 1e-9) {
			throw new \InvalidArgumentException('DDSketch serialized counts are inconsistent.');
		}

		$sketch            = new self($alpha, $maxBins, $zeroThreshold);
		$sketch->count     = $count;
		$sketch->zeroCount = $zeroCount;
		$sketch->min       = self::optionalFloat($data, 'min');
		$sketch->max       = self::optionalFloat($data, 'max');

		if ($sketch->min !== null && $sketch->max !== null && $sketch->min > $sketch->max) {
			throw new \InvalidArgumentException('DDSketch serialized min cannot exceed max.');
		}

		if (!isset($data['bins']) || !is_array($data['bins'])) {
			throw new \InvalidArgumentException('DDSketch serialized payload requires a bins map.');
		}

		foreach ($data['bins'] as $key => $weight) {
			if (!is_int($key) && preg_match('/^-?\d+$/', $key) !== 1) {
				throw new \InvalidArgumentException('DDSketch bin index must be an integer.');
			}

			if ((!is_int($weight) && !is_float($weight)) || !is_finite((float) $weight) || (float) $weight <= 0.0) {
				throw new \InvalidArgumentException('DDSketch bin weight must be a positive finite number.');
			}

			$intKey = (int) $key;

			if ($sketch->minBinIndex === null || $intKey < $sketch->minBinIndex) {
				$sketch->minBinIndex = $intKey;
			}

			if ($sketch->maxBinIndex === null || $intKey > $sketch->maxBinIndex) {
				$sketch->maxBinIndex = $intKey;
			} else {
				$sketch->binsSorted = false;
			}

			$sketch->bins[$intKey] = (float) $weight;
		}

		if (count($sketch->bins) > $sketch->maxBins) {
			$sketch->collapseExcess();
		}

		return $sketch;
	}

	/**
	 * Compute the representative midpoint value for a logarithmic bucket index.
	 *
	 * @param int $index Integer bucket index.
	 * @return float Representative latency value for the bucket.
	 */
	private function valueFor(int $index): float {
		return $this->valueScale * ($this->gamma ** $index);
	}

	/**
	 * Sort positive buckets by ascending index only when out-of-order inserts occurred.
	 */
	private function ensureSorted(): void {
		if (!$this->binsSorted) {
			ksort($this->bins, SORT_NUMERIC);
			$this->binsSorted = true;
		}
	}

	/**
	 * Collapse excess lowest-index positive buckets into the lowest retained bucket.
	 *
	 * Uses O(1) popping when sorted with a single excess bin, an O(B) allocation-free
	 * scan for a single unsorted excess bin, and a single O(B log B) sort + linear
	 * fold when merging multiple excess bins.
	 */
	private function collapseExcess(): void {
		$excess = count($this->bins) - $this->maxBins;

		if ($excess <= 0 || count($this->bins) < 2) {
			return;
		}

		$this->ensureSorted();
		$foldedWeight = 0.0;
		$dropped      = 0;

		foreach ($this->bins as $key => $weight) {
			if ($dropped < $excess) {
				$foldedWeight += $weight;
				unset($this->bins[$key]);
				$dropped++;
			} else {
				$this->bins[$key] += $foldedWeight;
				$this->minBinIndex = $key;

				break;
			}
		}
	}

	/**
	 * Extract a required finite float from a serialized array.
	 *
	 * @param array<string, mixed> $data Input payload.
	 * @param string $key Required key name.
	 * @return float Extracted float value.
	 * @throws \InvalidArgumentException When the key is missing or non-finite.
	 */
	private static function requireFloat(array $data, string $key): float {
		if (!array_key_exists($key, $data) || (!is_int($data[$key]) && !is_float($data[$key])) || !is_finite((float) $data[$key])) {
			throw new \InvalidArgumentException(sprintf('DDSketch field %s must be a finite number.', $key));
		}

		return (float) $data[$key];
	}

	/**
	 * Extract an optional finite non-negative float from a serialized array.
	 *
	 * @param array<string, mixed> $data Input payload.
	 * @param string $key Optional key name.
	 * @return float|null Extracted float value or null.
	 * @throws \InvalidArgumentException When present with an invalid value.
	 */
	private static function optionalFloat(array $data, string $key): ?float {
		if (!array_key_exists($key, $data) || $data[$key] === null) {
			return null;
		}

		if ((!is_int($data[$key]) && !is_float($data[$key])) || !is_finite((float) $data[$key]) || (float) $data[$key] < 0.0) {
			throw new \InvalidArgumentException(sprintf('DDSketch field %s must be null or a non-negative finite number.', $key));
		}

		return (float) $data[$key];
	}

	/**
	 * Extract a required integer from a serialized array.
	 *
	 * @param array<string, mixed> $data Input payload.
	 * @param string $key Required key name.
	 * @return int Extracted integer value.
	 * @throws \InvalidArgumentException When the key is missing or not an integer.
	 */
	private static function requireInt(array $data, string $key): int {
		if (!array_key_exists($key, $data) || !is_int($data[$key])) {
			throw new \InvalidArgumentException(sprintf('DDSketch field %s must be an integer.', $key));
		}

		return $data[$key];
	}
}
