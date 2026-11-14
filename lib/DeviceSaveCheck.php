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
 * Keeps mappings consistent when a device is saved, for the api_device_save
 * hook. A mapping is by device id, so editing a device never breaks it; what
 * can break is moving a device with summary data sources to a poller other
 * than the one that runs the reader, because the summary values come from
 * that poller's cache. The save is never blocked: the operator is told, and
 * the health page keeps listing the device until it is fixed.
 */
final class DeviceSaveCheck {
	public function __construct(private readonly TargetRepository $targets) {
	}

	/**
	 * @param array<array-key, mixed> $save the device row about to be saved
	 * @return string|null a warning for the operator, or null when nothing is wrong
	 */
	public function warning(array $save, int $readerPollerId): ?string {
		$hostId = Row::int($save, 'id', 0, 0);

		if ($hostId === 0 || !$this->targets->deviceHasTargets($hostId)) {
			return null;
		}

		$poller = Row::int($save, 'poller_id', $readerPollerId, 0);

		if ($poller === $readerPollerId) {
			return null;
		}

		return __('This device has SmokePing targets, but the reader that fills their summary values runs on poller %d and the device is saved on poller %d. Summary values and alerts stop until the device is assigned to poller %d again.', $readerPollerId, $poller, $readerPollerId, 'smokeping');
	}
}
