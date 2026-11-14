<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * Result of one health check. Unknown is its own level so that missing
 * evidence is never rendered as healthy.
 */
enum HealthLevel: string {
	case Ok = 'ok';
	case Warning = 'warning';
	case Error = 'error';
	case Unknown = 'unknown';
}
