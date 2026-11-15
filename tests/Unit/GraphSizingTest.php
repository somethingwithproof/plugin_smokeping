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


use Somethingwithproof\Smokeping\CactiObjectRepository;
use Somethingwithproof\Smokeping\FixedClock;
use Somethingwithproof\Smokeping\GraphScale;
use Somethingwithproof\Smokeping\GraphSizing;
use Somethingwithproof\Smokeping\ObjectRepository;
use Somethingwithproof\Smokeping\RrdLastUpdate;
use Somethingwithproof\Smokeping\RrdReader;
use Somethingwithproof\Smokeping\Settings;
use Somethingwithproof\Smokeping\SmokeProvisioner;

const GS_NOW = 1_800_000_000;

/**
 * A provisioner whose rrdtool reports $median as the last day's largest
 * median, and whose graph currently has the upper limit $current.
 */
function gs_provisioner(?float $median, ?string $current): SmokeProvisioner {
	$GLOBALS['__smokeping_db_row'] = $current === null ? [] : ['upper_limit' => $current];

	$rrd = new class ($median) implements RrdReader {
		/** @var list<string> */
		public array $read = [];

		public function __construct(private ?float $median) {
		}

		public function lastUpdate(string $file): ?RrdLastUpdate {
			return null;
		}

		public function maxMedian(string $file, int $seconds): ?float {
			$this->read[] = $file;

			return $this->median;
		}

		public function close(): void {
		}
	};

	return new SmokeProvisioner(Settings::fromRow([], '/rra'), '/rra', '/nonexistent', false, new ObjectRepository(false), new CactiObjectRepository(false), new FixedClock(GS_NOW), $rrd);
}

/**
 * @return list<array{sql: string, params: array<int, mixed>}>
 */
function gs_writes(string $table): array {
	return array_values(array_filter($GLOBALS['__smokeping_db_exec'], fn (array $exec) => str_contains($exec['sql'], $table)));
}

function gs_graph(string $sizedLimit): array {
	return ['local_graph_id' => 7, 'rrd_path' => 'smokeping/Europe/paris.rrd', 'sized_limit' => $sizedLimit];
}

beforeEach(function () {
	$GLOBALS['__smokeping_db_exec']    = [];
	$GLOBALS['__smokeping_db_assoc']   = [];
	$GLOBALS['__smokeping_db_queries'] = [];
	$GLOBALS['__smokeping_db_row']     = [];
});

afterEach(function () {
	$GLOBALS['__smokeping_db_assoc'] = [];
	$GLOBALS['__smokeping_db_row']   = [];
});

it('re-sizes a graph whose limit is the one the plugin set, and records the new limit', function () {
	$sizing = gs_provisioner(0.143, '0.05')->resize(gs_graph('0.05'));

	expect($sizing->status)->toBe(GraphSizing::SIZED)
		->and($sizing->limit)->toBe('0.1716')
		->and(gs_writes('graph_templates_graph')[0]['params'])->toBe(['0.1716', 7])
		->and(gs_writes('plugin_smokeping_object')[0]['params'])->toBe(['0.1716', GS_NOW, 7]);
});

it('keeps a limit set by hand and only moves the sizing time', function (string $recorded) {
	$sizing = gs_provisioner(0.143, '0.3')->resize(gs_graph($recorded));

	expect($sizing->status)->toBe(GraphSizing::MANUAL)
		->and($sizing->limit)->toBe($recorded === '' ? null : $recorded)
		->and(gs_writes('graph_templates_graph'))->toBe([])
		->and(gs_writes('plugin_smokeping_object')[0]['params'])->toBe([GS_NOW, 7]);
})->with(['changed from the recorded limit' => '0.05', 'nothing recorded' => '']);

it('keeps the current limit when the last day has no median', function () {
	$sizing = gs_provisioner(null, '0.1716')->resize(gs_graph('0.1716'));

	expect($sizing->status)->toBe(GraphSizing::KEPT)
		->and(gs_writes('graph_templates_graph'))->toBe([])
		->and(gs_writes('plugin_smokeping_object')[0]['params'])->toBe([GS_NOW, 7]);
});

it('leaves a graph that no longer exists alone', function () {
	$sizing = gs_provisioner(0.143, null)->resize(gs_graph('0.05'));

	expect($sizing->status)->toBe(GraphSizing::KEPT)
		->and($GLOBALS['__smokeping_db_exec'])->toBe([]);
});

it('asks only for the poller\'s graphs sized a day ago or more, at most a batch of them', function () {
	$GLOBALS['__smokeping_db_assoc'] = [gs_graph('0.05'), ['local_graph_id' => 8, 'rrd_path' => 'smokeping/Europe/rome.rrd', 'sized_limit' => '0.9']];

	$counts = gs_provisioner(0.143, '0.05')->resizeDue(3, GraphScale::BATCH);
	$query  = $GLOBALS['__smokeping_db_queries'][0];

	expect($counts)->toBe([GraphSizing::SIZED => 1, GraphSizing::KEPT => 0, GraphSizing::MANUAL => 1])
		->and($query['params'])->toBe([3, ObjectRepository::ACTIVE, ObjectRepository::ARCHIVED, GS_NOW - GraphScale::WINDOW])
		->and($query['sql'])->toContain('LIMIT ' . GraphScale::BATCH)
		->and($query['sql'])->toContain('ORDER BY o.sized_at');
});
