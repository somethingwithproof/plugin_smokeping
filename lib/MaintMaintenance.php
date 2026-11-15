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
 * Maintenance from Maint, through the is_device_in_maintenance hook that Maint
 * registers and Cacti's SNMP agent also calls. Without Maint the hook returns
 * its argument, the host id, so only a literal true counts.
 */
final class MaintMaintenance implements DeviceMaintenance {
	/** @var array<int, bool> */
	private array $known = [];

	public function inMaintenance(int $hostId): bool {
		if ($hostId < 1) {
			return false;
		}

		return $this->known[$hostId] ??= api_plugin_hook_function('is_device_in_maintenance', $hostId) === true;
	}
}
