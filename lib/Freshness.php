<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * How recent a location's last RRD update is, measured against its step.
 *
 * Wherever it is stored, the case value goes in a varchar column, never its
 * position, so reordering cases cannot change stored data.
 */
enum Freshness: string {
	case Current = 'current';
	case Delayed = 'delayed';
	case Stale = 'stale';
	case Never = 'never';
}
