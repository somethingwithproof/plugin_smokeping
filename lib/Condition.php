<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * What the last completed round observed.
 *
 * Wherever it is stored, the case value goes in a varchar column, never its
 * position, so reordering cases cannot change stored data.
 */
enum Condition: string {
	case WithinPolicy = 'within_policy';
	case Degraded = 'degraded';
	case CompleteLoss = 'complete_loss';
	case Unknown = 'unknown';
}
