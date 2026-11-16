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
 * The installed SmokePing's version, read from the $VERSION line of its
 * Smokeping.pm, for the loss colors (LossColors). Only the reader's host can
 * know it, so the reader records it with each inventory.
 */
final class SmokepingVersion {
	/** Where the packages and the source install put Smokeping.pm. */
	public const PATHS = [
		'/usr/share/perl5/Smokeping.pm',      // Debian, Ubuntu
		'/usr/share/smokeping/Smokeping.pm',  // Fedora, EL (EPEL)
		'/opt/smokeping/lib/Smokeping.pm',    // source install default prefix
		'/usr/local/smokeping/lib/Smokeping.pm',
	];

	/**
	 * The first version found, such as 2.007003, or '' when none is.
	 *
	 * @param list<string> $paths
	 */
	public static function detect(array $paths = self::PATHS): string {
		foreach ($paths as $path) {
			if (!@is_file($path) || !@is_readable($path)) {
				continue;
			}

			// $VERSION is set near the top of the module.
			$head = @file_get_contents($path, false, null, 0, 16384);

			if (is_string($head) && preg_match('/^\s*\$VERSION\s*=\s*["\']?(\d+\.\d{6})["\']?\s*;/m', $head, $m) === 1) {
				return $m[1];
			}
		}

		return '';
	}
}
