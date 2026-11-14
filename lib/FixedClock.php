<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

final class FixedClock implements Clock {
	public function __construct(private int $now) {
	}

	public function now(): int {
		return $this->now;
	}

	public function advance(int $seconds): void {
		$this->now += $seconds;
	}
}
