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
 * Append one measurement to a fixture RRD, as the SmokePing daemon does at
 * the end of a round. Used by tests/alert-e2e.sh to drive loss up, back down,
 * and to stop the collector.
 *
 *   php fixture-update.php <rrd file> <pings> <lost> [<unix time>]
 *
 * <lost> pings of <pings> are lost; all lost leaves the median unknown.
 */

list(, $file, $pings, $lost) = $argv;

$at    = isset($argv[4]) ? (int) $argv[4] : time();
$pings = (int) $pings;
$lost  = (int) $lost;
$rtts  = array();

for ($i = 1; $i <= $pings; $i++) {
	$rtts[] = $i <= $pings - $lost ? sprintf('%.6f', 0.010 + $i / 10000) : 'U';
}

$median = $lost >= $pings ? 'U' : sprintf('%.6f', 0.010 + (int) (($pings - $lost) / 2) / 10000);

passthru('rrdtool update ' . escapeshellarg($file) . ' ' . escapeshellarg("$at:3600:$lost:$median:" . implode(':', $rtts)), $status);
exit($status);
