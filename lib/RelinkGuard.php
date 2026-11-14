<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

namespace Cacti\Smokeping;

/**
 * Whether a re-link may proceed, from the device mappings and pollers of the
 * two targets. The inventory page and TargetActions both ask it, so the
 * service refuses the same cases when a caller skips the page's checks.
 *
 * A re-link moves the old target's device mapping to the new target, so the
 * new target must be free or already mapped to that same device. It must never
 * take a device from, or give a device to, a target that belongs to another.
 */
final class RelinkGuard {
	/**
	 * @return string|null the reason the re-link is refused, or null when it may go ahead
	 */
	public static function refusal(int $oldHost, int $newHost, int $oldPoller, int $newPoller, int $readerPoller): ?string {
		if ($oldPoller !== $readerPoller || $newPoller !== $readerPoller) {
			return __('both targets must be on the poller that runs the reader', 'smokeping');
		}

		if ($newHost > 0 && $newHost !== $oldHost) {
			return __('the new target is already mapped to device %d', $newHost, 'smokeping');
		}

		return null;
	}
}
