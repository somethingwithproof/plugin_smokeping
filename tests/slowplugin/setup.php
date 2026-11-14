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

/*
 * A stand-in for a plugin that Cacti runs before this one and whose
 * poller_bottom work takes a few seconds, as Thold's threshold checks can.
 * tests/objects-e2e.sh mounts it at plugins/slowplugin to show that the purge
 * sweep does not depend on the order or speed of other plugins' hooks.
 */

function plugin_slowplugin_version() {
	return array('name' => 'slowplugin', 'version' => '1.0');
}

function slowplugin_poller_bottom() {
	sleep(3);
}
