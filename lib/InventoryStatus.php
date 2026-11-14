<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * Outcome of the last inventory run on a poller.
 *
 * Wherever it is stored, the case value goes in a varchar column, never its
 * position, so reordering cases cannot change stored data.
 */
enum InventoryStatus: string {
	case Ok = 'ok';
	case Error = 'error';
	case Never = 'never';
}
