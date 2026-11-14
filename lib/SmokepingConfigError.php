<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * A SmokePing configuration file, or one of its includes, could not be read
 * or is not valid. $source and $sourceLine name the place the way SmokePing's
 * own parser does; $sourceLine is 0 when the problem has no single line,
 * such as a mandatory variable that is missing.
 */
final class SmokepingConfigError extends \RuntimeException {
	public function __construct(public readonly string $reason, public readonly string $source = '', public readonly int $sourceLine = 0, public readonly string $section = '') {
		parent::__construct(self::format($reason, $source, $sourceLine, $section));
	}

	private static function format(string $reason, string $file, int $line, string $section): string {
		if ($file === '') {
			return $reason;
		}

		if ($line > 0) {
			return sprintf('%s, line %d: %s', $file, $line, $reason);
		}

		return $section === '' ? sprintf('%s: %s', $file, $reason) : sprintf('%s (%s): %s', $file, $section, $reason);
	}
}
