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
 * Derive measurement state from a cached observation and the clock.
 *
 * Freshness is computed at read time from the RRD's own update time. When the
 * reader last looked (ingestedAt) and when a page was refreshed play no part,
 * so neither can make old data look current.
 */
final class FreshnessEvaluator {
	public function __construct(private readonly Clock $clock, private readonly int $allowance) {
	}

	/**
	 * Current up to step + allowance, delayed up to SmokePing's heartbeat
	 * (2 x step), stale beyond it. Never observed when the file is absent or
	 * has had no update since it was created.
	 */
	public function freshness(Observation $observation): Freshness {
		if (!$observation->filePresent || $observation->observedAt <= 0 || $observation->expectedInterval <= 0) {
			return Freshness::Never;
		}

		$age = $this->clock->now() - $observation->observedAt;

		if ($age <= $observation->expectedInterval + $this->allowance) {
			return Freshness::Current;
		}

		if ($age <= 2 * $observation->expectedInterval) {
			return Freshness::Delayed;
		}

		return Freshness::Stale;
	}

	/**
	 * A round completed when it wrote a numeric loss. Stale or never-observed
	 * data says nothing about the last round. Probe-specific failure
	 * signatures (research #20, #22) are not mapped yet, so a round without
	 * loss is unknown rather than a probe error.
	 */
	public function execution(Observation $observation): ExecutionState {
		$freshness = $this->freshness($observation);

		if ($freshness === Freshness::Never || $freshness === Freshness::Stale) {
			return ExecutionState::Unknown;
		}

		return $observation->loss === null ? ExecutionState::Unknown : ExecutionState::Completed;
	}

	/**
	 * Complete loss is loss = pings with median unknown, from a completed
	 * round. A stopped collector instead goes stale, so it reads as unknown,
	 * never as loss.
	 */
	public function condition(Observation $observation): Condition {
		if ($this->execution($observation) !== ExecutionState::Completed || $observation->loss === null) {
			return Condition::Unknown;
		}

		if ($observation->pings > 0 && $observation->loss >= $observation->pings && $observation->median === null) {
			return Condition::CompleteLoss;
		}

		return $observation->loss > 0 ? Condition::Degraded : Condition::WithinPolicy;
	}

	public function age(Observation $observation): ?int {
		return $observation->observedAt > 0 ? $this->clock->now() - $observation->observedAt : null;
	}
}
