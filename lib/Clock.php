<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * Source of the current time, injected wherever freshness or ages are
 * computed so tests can pin every boundary.
 */
interface Clock {
	/**
	 * Unix time in seconds.
	 */
	public function now(): int;
}
