<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

/*
 * Create RRD files with SmokePing's layout (Smokeping.pm, RRD create: uptime,
 * loss, median, ping1..pingN, each GAUGE with heartbeat 2 x step, loss capped
 * at pings) in a known measurement state, relative to the current time.
 *
 *   php make-fixture-rrds.php <datadir> <path[~slave]>:<step>:<pings>:<state> ...
 *
 * States: normal, partial (5 of pings lost), loss (all lost, median unknown),
 * delayed (last update 1.5 steps ago), stale (3 steps ago), never (created,
 * never updated). Uses one rrdtool pipe for all files.
 */

$datadir = rtrim($argv[1], '/');
$specs   = array_slice($argv, 2);
$now     = time();
$rrd     = popen('rrdtool - > /dev/null', 'w');

foreach ($specs as $spec) {
	list($path, $step, $pings, $state) = explode(':', $spec);

	$step  = (int) $step;
	$pings = (int) $pings;
	$file  = $datadir . '/' . $path . '.rrd';
	$hb    = 2 * $step;

	if (!is_dir(dirname($file))) {
		mkdir(dirname($file), 0755, true);
	}

	$ds = array("DS:uptime:GAUGE:$hb:0:U", "DS:loss:GAUGE:$hb:0:$pings", "DS:median:GAUGE:$hb:0:180");

	for ($i = 1; $i <= $pings; $i++) {
		$ds[] = "DS:ping$i:GAUGE:$hb:0:180";
	}

	$start = $now - 20 * $step;

	fwrite($rrd, "create $file --step $step --start $start " . implode(' ', $ds) . " RRA:AVERAGE:0.5:1:1008\n");

	$age = array('normal' => 10, 'partial' => 10, 'loss' => 10, 'delayed' => (int) (1.5 * $step), 'stale' => 3 * $step);

	if ($state === 'never') {
		continue;
	}

	$at    = $now - $age[$state];
	$lost  = $state === 'loss' ? $pings : ($state === 'partial' ? 5 : 0);
	$rtts  = array();

	for ($i = 1; $i <= $pings; $i++) {
		$rtts[] = $i <= $pings - $lost ? sprintf('%.6f', 0.010 + $i / 10000) : 'U';
	}

	$median = $lost >= $pings ? 'U' : sprintf('%.6f', 0.010 + (int) (($pings - $lost) / 2) / 10000);

	fwrite($rrd, "update $file $at:3600:$lost:$median:" . implode(':', $rtts) . "\n");
}

pclose($rrd);
