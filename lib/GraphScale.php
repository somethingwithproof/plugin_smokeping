<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * The upper limit of a smoke graph, sized from the target's data.
 *
 * SmokePing sizes each graph from its data (findmax in Smokeping.pm): the
 * largest median over the range, times 1.2. A Cacti graph has one upper limit
 * for every range, so the plugin applies the same rule to the last day, the
 * range Cacti shows first. With no median in that day the template's limit
 * stays.
 */
final class GraphScale {
	/** The range the largest median is taken from, in seconds. */
	public const WINDOW = 86400;

	/** SmokePing's headroom above the largest median. */
	public const HEADROOM = 1.2;

	/**
	 * The largest median from the output of an rrdtool graph whose only PRINT
	 * is that maximum, or null when there was none (nan, missing, or not
	 * positive).
	 */
	public static function parseMaximum(string $output): ?float {
		$lines = array_values(array_filter(array_map('trim', explode("\n", $output)), static fn (string $line): bool => $line !== ''));
		$last  = $lines === [] ? '' : $lines[count($lines) - 1];

		if (!is_numeric($last)) {
			return null;
		}

		$value = (float) $last;

		return is_finite($value) && $value > 0 ? $value : null;
	}

	/**
	 * The upper limit for a largest median, in seconds and fixed-point
	 * notation (rrdtool's RPN and Cacti's limit field both read it), or null
	 * when there is no median to size from.
	 */
	public static function upperLimit(?float $maxMedian): ?string {
		if ($maxMedian === null || !is_finite($maxMedian) || $maxMedian <= 0) {
			return null;
		}

		$limit = rtrim(rtrim(sprintf('%.9F', $maxMedian * self::HEADROOM), '0'), '.');

		return $limit === '0' ? null : $limit;
	}
}
