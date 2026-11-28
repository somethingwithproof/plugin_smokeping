<!--
SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
SPDX-License-Identifier: GPL-2.0-or-later

This file is part of plugin_smokeping, a plugin for Cacti.
https://github.com/somethingwithproof/plugin_smokeping
-->

# Algorithmic Review and PHP Performance Profile

This document records the algorithmic audit, real PHP profiling measurements (`hrtime(true)` and `memory_get_peak_usage(true)`), and targeted optimizations applied to `plugin_smokeping` hot paths.

## Measurement Environment

- Runtime: PHP 8.4.25 (CLI, NTS, no coverage or debugger extension loaded)
- Platform: Apple Silicon macOS (arm64)
- Timing: monotonic nanosecond clock via `hrtime(true)`
- Memory: real OS-allocated peak memory via `memory_get_peak_usage(true)` and per-workload `memory_get_usage(true)` deltas

## Hot-Path Audit Summary

| Component | Baseline Bottleneck | Root Cause | Fix Applied |
|---|---|---|---|
| `DDSketch::add()` (bounded collapse) | `O(B log B)` per excess insert | `collapseLowest()` ran `ksort()` + `array_keys()` on every sample above `maxBins` | Track `minBinIndex` and `binsSorted`; fold any sample below `minBinIndex` at capacity in `O(1)` without inserting or sorting |
| `DDSketch::merge()` / `fromArray()` | `O(K * B log B)` when collapsing `K` excess bins | `while (count($bins) > $maxBins)` invoked `collapseLowest()` repeatedly | `collapseExcess()` sorts once (`O(B log B)`) and folds all `K` lowest buckets in a single linear pass |
| `DDSketch::summary()` | 4x `ksort()` + 4x full bin scans | Called `quantile()` four separate times, sorting on every call | Single `ensureSorted()` guard plus one ascending pass resolving `p50`, `p95`, `p99`, and `p99.9` together |
| `PeltChangepointDetector::detect()` | High per-candidate constant factor and cancellation risk on large means | Static `segmentCost()` method call frame inside both inner DP loops; unshifted `sum(x^2)` prefix sums; duplicate `normalizeSeries()` in `segments()` and `resolvePenalty()` | Shift series by `values[0]` for numerical stability; inline SSE arithmetic in pruning and minimization loops; add internal `detectNormalized()` and `estimateNoiseVarianceNormalized()` |
| `OrphanRrdScanner::scan()` | `O(D^2)` BFS dequeue and per-entry syscall overhead | `array_shift($queue)` reindexed directory queue; `microtime(true)` called on every entry | `O(1)` head-index queue, `hrtime(true)` deadline checked per directory and every 64 entries, and `.rrd` suffix filter before `is_file()` |
| `CsvExporter::exportTargets()` | High transient allocation on 10k-row exports | Built an intermediate 10,000-row string matrix, ran closure `array_map()` per cell, and concatenated `$out .=` per row | Stream-encode rows directly into `$lines[]` without closures or intermediate matrix, finishing with one `implode()` |
| `SummaryCalculator` / `TargetFilter` / `TargetRepository` | Audited, no algorithmic defect | `SummaryCalculator` is `O(1)` per row; `TargetFilter` clamps inputs in `O(1)`; `TargetRepository` uses indexed prepared queries with SQL `LIMIT`/`OFFSET` | Preserved existing contracts; added `ExtendedSummaryValues` and `computeExtended()` without altering default `SummaryValues::format()` |

## Measured Before and After Results

| Workload | Before (ms) | After (ms) | Speedup | Memory Note |
|---|---:|---:|---:|---|
| `DDSketch::add()` 100k inserts (`maxBins=2048`) | 26.81 | 25.25 | 1.06x | 2.00 MB peak |
| `DDSketch::add()` 100k inserts with collapse (`maxBins=64`) | 143.75 | 28.69 | 5.01x | 2.00 MB peak |
| `DDSketch::summary()` x1,000 (`1,500` bins) | 223.25 | 104.41 | 2.14x | 2.00 MB peak |
| `DDSketch::merge()` 100-poller merge with collapse (`maxBins=128`) | 17.31 | 1.38 | 12.54x | 4.00 MB peak |
| `PeltChangepointDetector::detect()` `n=2,000` (multi-regime) | 49.17 | 29.23 | 1.68x | 4.00 MB peak |
| `PeltChangepointDetector::detect()` `n=5,000` (multi-regime) | 119.25 | 70.18 | 1.70x | 6.00 MB peak |
| `PeltChangepointDetector::detect()` `n=10,000` (multi-regime) | 245.38 | 147.63 | 1.66x | 6.00 MB peak (linear scaling confirmed: 2.0x input = 2.10x time) |
| `PeltChangepointDetector::detect()` `n=2,000` (stationary, 0 changepoints) | 322.17 | 180.68 | 1.78x | 6.00 MB peak |
| `OrphanRrdScanner::scan()` 4,000 files (400 orphans) | 17.19 | 16.52 | 1.04x | 6.00 MB peak (warm cache) |
| `CsvExporter::exportTargets()` 10,000 rows | 36.40 | 27.51 | 1.32x | Transient delta reduced from +8.00 MB to +2.00 MB; peak RSS 22.00 MB to 16.00 MB |

## Notes on Profiling Methodology

During profiling, an initial experiment replaced `OrphanRrdScanner`'s `is_link()`/`is_dir()`/`is_file()` checks with `@lstat()`, which regressed runtime from 17.19 ms to 34.06 ms because PHP's `lstat()` allocates a 26-element associative array per entry whereas `is_link()`/`is_dir()`/`is_file()` reuse PHP's internal C statcache without array allocation. That experiment was reverted, keeping only the verified `O(1)` queue cursor, batched `hrtime(true)` deadline check, and early `.rrd` suffix filter.

Similarly, an early `DDSketch` single-collapse linear key scan regressed PHP userland runtime (143.75 ms to 194.10 ms) before the exact `minBinIndex` `O(1)` fold was introduced, reducing the same workload to 28.69 ms (5.01x faster than baseline) while preserving identical quantile bounds.

## Complexity Verification

- `DDSketch`: `O(1)` amortized insert (including below-floor collapse at `maxBins`), `O(B log B)` worst-case bulk collapse on `merge()`, `O(B)` single-pass `summary()` once sorted.
- `PeltChangepointDetector`: Verified exact match against brute-force `O(n^2)` optimal partitioning across `minSegmentLength` in `{1, 3, 5}` and penalties `{10, 40, 200}`; verified empirical linear scaling across `n = 2,000` (29.23 ms), `n = 5,000` (70.18 ms), and `n = 10,000` (147.63 ms).
- `ExtendedSummaryValues`: `O(k log k)` on `k <= 20` ping samples per round (`ping1..ping20`) and `O(r)` single pass over `r` recent round losses for `loss_burst_max`.
