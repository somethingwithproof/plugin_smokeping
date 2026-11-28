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

$cloverPath = $argv[1] ?? 'coverage/clover.xml';
$threshold  = isset($argv[2]) && is_numeric($argv[2]) ? (float) $argv[2] : 85.0;

if (!is_file($cloverPath)) {
	fwrite(STDERR, sprintf("Coverage report not found: %s\n", $cloverPath));
	exit(1);
}

$xml = simplexml_load_file($cloverPath);

if ($xml === false || !isset($xml->project->metrics)) {
	fwrite(STDERR, sprintf("Unable to parse Clover XML: %s\n", $cloverPath));
	exit(1);
}

$metrics = $xml->project->metrics;
$total   = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $total > 0 ? ($covered / $total) * 100.0 : 0.0;

printf("Measured line coverage: %.2f%% (%d/%d statements, gate >= %.2f%%)\n", $percent, $covered, $total, $threshold);

if ($percent < $threshold) {
	fwrite(STDERR, sprintf("Coverage gate failed: %.2f%% is below %.2f%%\n", $percent, $threshold));
	exit(1);
}
