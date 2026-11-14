<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * Administrative state of a target record.
 *
 * Wherever it is stored, the case value goes in a varchar column, never its
 * position, so reordering cases cannot change stored data.
 */
enum AdminState: string {
	case Enabled = 'enabled';
	case Disabled = 'disabled';
	case Archived = 'archived';
}
