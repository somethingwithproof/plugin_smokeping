<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * Plugin log lines go through cacti_log() under the SMOKEPING tag, so they
 * share Cacti's log file, rotation, and verbosity filter.
 */
final class Log {
	public static function debug(string $message): void {
		cacti_log('DEBUG: ' . $message, false, 'SMOKEPING', POLLER_VERBOSITY_DEBUG);
	}

	public static function info(string $message): void {
		cacti_log('NOTE: ' . $message, false, 'SMOKEPING', POLLER_VERBOSITY_MEDIUM);
	}

	public static function warning(string $message): void {
		cacti_log('WARNING: ' . $message, false, 'SMOKEPING');
	}

	public static function error(string $message): void {
		cacti_log('ERROR: ' . $message, false, 'SMOKEPING');
	}
}
