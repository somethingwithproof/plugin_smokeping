<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * Whether the last probe round completed.
 *
 * Wherever it is stored, the case value goes in a varchar column, never its
 * position, so reordering cases cannot change stored data.
 */
enum ExecutionState: string {
	case Completed = 'completed';
	case ProbeError = 'probe_error';
	case Unknown = 'unknown';
}
