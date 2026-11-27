<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_smokeping, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_smokeping
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

namespace Somethingwithproof\Smokeping;

/**
 * A SmokePing configuration file, or one of its includes, could not be read
 * or is not valid. $source and $sourceLine name the place the way SmokePing's
 * own parser does; $sourceLine is 0 when the problem has no single line,
 * such as a mandatory variable that is missing.
 */
final class SmokepingConfigError extends \RuntimeException {
	/**
	 * Create a new SmokepingConfigError instance.
	 *
	 * @param string $reason Reason.
	 * @param string $source Source.
	 * @param int $sourceLine Source line.
	 * @param string $section Section.
	 */
	public function __construct(public readonly string $reason, public readonly string $source = '', public readonly int $sourceLine = 0, public readonly string $section = '') {
		parent::__construct(self::format($reason, $source, $sourceLine, $section));
	}

	/**
	 * Format.
	 *
	 * @param string $reason Reason.
	 * @param string $file File.
	 * @param int $line Line.
	 * @param string $section Section.
	 * @return string
	 */
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
