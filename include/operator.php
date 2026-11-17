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

use Somethingwithproof\Smokeping\SystemClock;
use Somethingwithproof\Smokeping\TargetRepository;
use Somethingwithproof\Smokeping\ObservationRepository;
use Somethingwithproof\Smokeping\FreshnessEvaluator;

/** @return list<array{id: string, name: string, state: string, observed: int, interval: int, detail: string}> */
function smokeping_operator_rows(): array {
	$rows = [];
	$ids  = [];

	foreach (get_allowed_devices() as $device) {
		$ids[] = (int) $device['id'];
	}
	$targets   = (new TargetRepository(false))->mappedTo($ids);
	$targetIds = [];

	foreach ($targets as $entry) {
		if ($entry['target']->id !== null) {
			$targetIds[] = $entry['target']->id->value;
		}
	}
	$observations = (new ObservationRepository(false))->forTargets($targetIds);
	$freshness    = new FreshnessEvaluator(new SystemClock(), smokeping_settings()->freshnessAllowance);

	foreach ($targets as $entry) {
		$target = $entry['target'];

		if ($target->id === null) {
			continue;
		}

		foreach ($observations[$target->id->value] ?? [] as $observation) {
			$rows[] = [
			'id' => $target->id->value . ':' . $observation->location,
			'name' => $target->displayName() . ' / ' . ($observation->location === '' ? __('Master', 'smokeping') : $observation->location),
			'state' => $freshness->freshness($observation)->value . ' / ' . $freshness->condition($observation)->value,
			'observed' => $observation->observedAt,
			'interval' => $observation->expectedInterval,
				'detail'       => __('Compare loss and latency with the SmokePing graphs and maintenance state. Escalate missing files, imports and relinking to an administrator.', 'smokeping')
		];
		}
	}

	return $rows;
}
