<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

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
	 * @param list<int> $objectHosts devices that hold the old target's data sources
	 *
	 * @return string|null the reason the re-link is refused, or null when it may go ahead
	 */
	public static function refusal(int $oldHost, int $newHost, int $oldPoller, int $newPoller, int $readerPoller, array $objectHosts = []): ?string {
		if ($oldPoller !== $readerPoller || $newPoller !== $readerPoller) {
			return __('both targets must be on the poller that runs the reader', 'smokeping');
		}

		if ($newHost > 0 && $newHost !== $oldHost) {
			return __('the new target is already mapped to device %d', $newHost, 'smokeping');
		}

		// Device permission is checked against the old target's mapping, so
		// data sources on any other device (possible after `provision
		// --device=N` without a mapping) would move unchecked.
		foreach ($objectHosts as $objectHost) {
			if ($objectHost > 0 && $objectHost !== $oldHost) {
				return __('the old target has data sources on device %d, which it is not mapped to', $objectHost, 'smokeping');
			}
		}

		return null;
	}
}
