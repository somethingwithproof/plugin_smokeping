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
 * Compares what the plugin reports for every SmokePing series on the mount with
 * what rrdtool reports for the same file. Run from the Cacti root, after the
 * plugin has imported a SmokePing configuration and the reader has run
 * (tests/live/live-e2e.sh):
 *
 *   php plugins/smokeping/tests/live/check-values.php
 *
 * SmokePing keeps writing its files, so a file can move on between the two
 * reads. For each series the reader runs, rrdtool lastupdate is read before and
 * after, and the series is compared only when the file did not move. A series
 * that keeps moving through every attempt passes when the plugin's reading is
 * from the previous round, no more than one step older than the file.
 *
 * Prints one RESULT line per series and one for the whole run; exits non-zero
 * on any FAIL.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');

$mount   = rtrim((string) read_config_option('smokeping_mount_path'), '/');
$rrdtool = (string) read_config_option('path_rrdtool');
$php     = (string) read_config_option('path_php_binary');
$attempts = 6;

/** @return array{time: int, values: array<string, ?float>}|null */
function lastupdate(string $rrdtool, string $file): ?array {
	$output = [];
	exec(escapeshellarg($rrdtool) . ' lastupdate ' . escapeshellarg($file) . ' 2>&1', $output, $status);

	if ($status !== 0) {
		return null;
	}

	$names = preg_split('/\s+/', trim((string) ($output[0] ?? '')));
	$data  = '';

	foreach ($output as $line) {
		if (preg_match('/^\d+:/', trim($line)) === 1) {
			$data = trim($line);
		}
	}

	if ($names === false || $data === '') {
		return null;
	}

	[$time, $rest] = explode(':', $data, 2);
	$cells = preg_split('/\s+/', trim($rest));
	$values = [];

	foreach ($names as $i => $name) {
		$cell = $cells[$i] ?? 'U';
		$values[$name] = $cell === 'U' || !is_numeric($cell) ? null : (float) $cell;
	}

	return ['time' => (int) $time, 'values' => $values];
}

function same(?float $a, ?float $b): bool {
	if ($a === null || $b === null) {
		return $a === $b;
	}

	return abs($a - $b) <= 1e-9 * max(1.0, abs($a), abs($b));
}

function summary(string $php, string $series): string {
	$out = [];
	exec(escapeshellarg($php) . ' plugins/smokeping/scripts/ss_smokeping_summary.php ' . escapeshellarg($series) . ' 2>&1', $out);

	return trim(implode(' ', $out));
}

/** @return array{median: ?float, loss: ?float, age: ?float} */
function parse_summary(string $text): array {
	$result = ['median' => null, 'loss' => null, 'age' => null];

	foreach (['median' => 'median', 'loss_pct' => 'loss', 'age' => 'age'] as $key => $field) {
		if (preg_match('/\b' . $key . ':(\S+)/', $text, $m) === 1 && is_numeric($m[1])) {
			$result[$field] = (float) $m[1];
		}
	}

	return $result;
}

$targets = db_fetch_assoc('SELECT id, path, pings, step FROM plugin_smokeping_target WHERE present = 1 ORDER BY path');
$series  = [];

foreach ($targets as $t) {
	foreach (db_fetch_assoc_prepared('SELECT location FROM plugin_smokeping_observation WHERE target_id = ? ORDER BY location', [(int) $t['id']]) as $o) {
		$series[] = $t + ['location' => (string) $o['location']];
	}
}

$failures = 0;
$checked  = 0;
$pending  = $series;
$final    = [];

for ($attempt = 1; $attempt <= $attempts && $pending !== []; $attempt++) {
	$before = [];

	foreach ($pending as $s) {
		$file = $mount . '/' . $s['path'] . ($s['location'] === '' ? '' : '~' . $s['location']) . '.rrd';
		$before[$s['id'] . '~' . $s['location']] = [$file, lastupdate($rrdtool, $file)];
	}

	exec(escapeshellarg($php) . ' plugins/smokeping/poller_smokeping.php >/dev/null 2>&1');

	$still = [];

	foreach ($pending as $s) {
		$key = $s['id'] . '~' . $s['location'];
		[$file, $first] = $before[$key];
		$second = lastupdate($rrdtool, $file);
		$obs = db_fetch_row_prepared('SELECT observed_at, loss, median, pings, file_present FROM plugin_smokeping_observation WHERE target_id = ? AND location = ?', [(int) $s['id'], $s['location']]);

		if ($first === null || $second === null || !is_array($obs)) {
			$final[$key] = [$s, false, 'cannot read ' . $file . ' or the observation'];

			continue;
		}

		if ($first['time'] !== $second['time'] && $attempt < $attempts) {
			$still[] = $s;

			continue;
		}

		$final[$key] = [$s, true, $second, $obs, $first['time'] === $second['time'], $file];
	}

	$pending = $still;
}

foreach ($final as $key => $entry) {
	$s    = $entry[0];
	$name = $s['path'] . ($s['location'] === '' ? '' : '@' . $s['location']);

	if ($entry[1] === false) {
		printf("RESULT FAIL values-%s (%s)\n", $name, $entry[2]);
		$failures++;

		continue;
	}

	[, , $lu, $obs, $stable] = $entry;
	$checked++;
	$observed = (int) $obs['observed_at'];
	$lag      = $lu['time'] - $observed;

	if ($stable) {
		$lossOk   = same($obs['loss'] === null ? null : (float) $obs['loss'], $lu['values']['loss'] ?? null);
		$medianOk = same($obs['median'] === null ? null : (float) $obs['median'], $lu['values']['median'] ?? null);
		$timeOk   = $observed === $lu['time'] || ($observed === 0 && $lu['values']['loss'] === null && $lu['values']['median'] === null);
		$mode     = 'file did not move';
	} else {
		// The file moved during every attempt: the reading must be the previous round.
		$lossOk = $medianOk = true;
		$timeOk = $lag >= 0 && $lag <= (int) $s['step'];
		$mode   = 'file moved on; the reading is ' . $lag . ' s behind, within one ' . (int) $s['step'] . ' s step';
	}

	// The summary the poller would read, from the cache, against the same file.
	$sum   = parse_summary(summary($php, $s['id'] . '~' . $s['location']));
	$pings = (int) $s['pings'];
	$expectLoss   = $stable && $lu['values']['loss'] !== null && $pings > 0 ? round($lu['values']['loss'] / $pings * 100, 3) : null;
	$expectMedian = $stable && $lu['values']['median'] !== null ? round($lu['values']['median'] * 1000, 3) : null;
	$summaryOk    = true;

	if ($stable && $sum['loss'] !== null) {
		$summaryOk = same($sum['loss'], $expectLoss) && same($sum['median'], $expectMedian);
	} elseif ($stable && $expectLoss !== null) {
		$summaryOk = false;
	}

	if ($lossOk && $medianOk && $timeOk && $summaryOk) {
		printf("RESULT PASS values-%s (%s; loss %s of %d pings, median %s ms, summary %s)\n", $name, $mode, $lu['values']['loss'] ?? 'U', $pings, $lu['values']['median'] === null ? 'U' : round($lu['values']['median'] * 1000, 3), summary($php, $s['id'] . '~' . $s['location']));
	} else {
		printf("RESULT FAIL values-%s (%s; rrdtool loss %s median %s at %d; cache loss %s median %s at %d; summary %s)\n", $name, $mode, json_encode($lu['values']['loss'] ?? null), json_encode($lu['values']['median'] ?? null), $lu['time'], json_encode($obs['loss']), json_encode($obs['median']), $observed, summary($php, $s['id'] . '~' . $s['location']));
		$failures++;
	}
}

if ($checked === 0) {
	echo "RESULT FAIL values-compared (no series was compared)\n";
	$failures++;
} else {
	printf("RESULT %s values-compared (%d series: the cache and the summary against rrdtool lastupdate on the same files)\n", $failures === 0 ? 'PASS' : 'FAIL', $checked);
}

exit($failures === 0 ? 0 : 1);
