<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

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
