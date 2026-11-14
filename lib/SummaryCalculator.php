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
 * Turns the reader's cached observation into the summary values Thold reads.
 *
 * median and loss_pct are unknown unless the measurement is current or
 * delayed, so a stopped collector or a missing file never reads as zero loss.
 * age is numeric whenever the RRD exists, so staleness itself can alert.
 */
final class SummaryCalculator {
	public function __construct(private readonly FreshnessEvaluator $freshness, private readonly Clock $clock) {
	}

	/**
	 * @param int $firstSeen when the plugin first saw the target; the age of a
	 *                       file that was created but never updated
	 */
	public function compute(?Observation $observation, int $firstSeen): SummaryValues {
		if ($observation === null || !$observation->filePresent) {
			return SummaryValues::unknown();
		}

		$age = $this->freshness->age($observation);

		if ($age === null && $firstSeen > 0) {
			$age = max(0, $this->clock->now() - $firstSeen);
		}

		$state = $this->freshness->freshness($observation);

		if ($state === Freshness::Never || $state === Freshness::Stale) {
			return new SummaryValues(null, null, $age === null ? null : max(0, $age));
		}

		$loss = null;

		if ($observation->loss !== null && $observation->pings > 0) {
			$loss = $observation->loss / $observation->pings * 100;
		}

		return new SummaryValues(
			$observation->median === null ? null : $observation->median * 1000,
			$loss,
			$age === null ? null : max(0, $age),
		);
	}
}
