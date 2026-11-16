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
use Somethingwithproof\Smokeping\HealthCheck;
use Somethingwithproof\Smokeping\HealthRepository;
use Somethingwithproof\Smokeping\InventoryStatus;
use Somethingwithproof\Smokeping\HealthLevel;
use Somethingwithproof\Smokeping\LossColors;
use Somethingwithproof\Smokeping\ObjectRepository;
use Somethingwithproof\Smokeping\SmokepingConfigReader;
use Somethingwithproof\Smokeping\SmokepingVersion;
use Somethingwithproof\Smokeping\TemplateInstaller;

/**
 * Which loss colors the smoke graphs draw: the setting, the SmokePing
 * version the reader finds, and the templates for each. tests/fidelity
 * compares both template sets with SmokePing's own graphs.
 */

/**
 * A directory holding the given files, for SmokepingVersion::detect().
 *
 * @param array<string, string> $files name => content
 */
function lc_dir(array $files): string {
	$dir = sys_get_temp_dir() . '/lc-' . bin2hex(random_bytes(4));
	mkdir($dir);

	foreach ($files as $name => $content) {
		file_put_contents("$dir/$name", $content);
	}

	return $dir;
}

describe('LossColors', function () {
	it('takes 2.7 colors for SmokePing before 2.8, and current ones from 2.8 on', function (string $version, ?LossColors $colors) {
		expect(LossColors::ofVersion($version))->toBe($colors);
	})->with([
		['2.007003', LossColors::Legacy],
		['1.002004', LossColors::Legacy],
		['2.008002', LossColors::Current],
		['2.009000', LossColors::Current],
		['3.000000', LossColors::Current],
		['', null],
		['2.8.2', null],
	]);

	it('follows the setting, else the detected version, else 2.8 and later', function () {
		expect(LossColors::resolve('2.7', '2.009000'))->toBe(LossColors::Legacy)
			->and(LossColors::resolve('2.8', '2.007003'))->toBe(LossColors::Current)
			->and(LossColors::resolve(LossColors::AUTO, '2.007003'))->toBe(LossColors::Legacy)
			->and(LossColors::resolve(LossColors::AUTO, ''))->toBe(LossColors::Current)
			->and(LossColors::resolve('', '2.007003'))->toBe(LossColors::Legacy)
			->and(LossColors::resolve('bogus', ''))->toBe(LossColors::Current)
			->and(LossColors::Legacy->other())->toBe(LossColors::Current)
			->and(LossColors::displayVersion('2.007003'))->toBe('2.7.3')
			->and(LossColors::displayVersion('odd'))->toBe('odd');
	});

	it('names a graph template per ping count and color table', function () {
		expect(TemplateInstaller::graphTemplateName(20))->toBe('SmokePing Smoke (20 pings)')
			->and(TemplateInstaller::graphTemplateName(20, LossColors::Legacy))->toBe('SmokePing Smoke (20 pings, 2.7 loss colors)')
			->and(TemplateInstaller::smokeGraphTemplateNames())->toHaveCount(6)
			->and(array_unique(TemplateInstaller::smokeGraphTemplateNames()))->toHaveCount(6);
	});
});

describe('SmokepingVersion', function () {
	it('reads $VERSION from the first Smokeping.pm it finds', function () {
		$dir = lc_dir([
			'a.pm' => "package Smokeping;\nuse strict;\n\$VERSION = \"2.007003\";\n",
			'b.pm' => "\$VERSION = '2.009000';\n",
			'c.pm' => "# no version here\n",
		]);

		expect(SmokepingVersion::detect(["$dir/missing.pm", "$dir/c.pm", "$dir/a.pm", "$dir/b.pm"]))->toBe('2.007003')
			->and(SmokepingVersion::detect(["$dir/b.pm"]))->toBe('2.009000')
			->and(SmokepingVersion::detect(["$dir/c.pm", "$dir/missing.pm", $dir]))->toBe('');
	});

	it('gives the reader\'s inventory the version it found', function () {
		$dir = lc_dir([
			'config'       => "*** General ***\nowner = o\ncontact = o@example.net\ncgiurl = http://localhost/\nimgcache = /c\nimgurl = c\ndatadir = /var/lib/smokeping\npiddir = /run\nsmokemail = /m\n*** Database ***\nstep = 300\npings = 20\n*** Presentation ***\ntemplate = /t\n+ overview\nwidth = 600\nheight = 50\n+ detail\nwidth = 600\nheight = 200\n*** Probes ***\n+ FPing\nbinary = /usr/bin/fping\n*** Targets ***\nprobe = FPing\nmenu = m\ntitle = t\n",
			'Smokeping.pm' => "\$VERSION = \"2.008002\";\n",
		]);

		expect((new SmokepingConfigReader("$dir/config", [], ["$dir/Smokeping.pm"]))->read(1)->smokepingVersion)->toBe('2.008002')
			->and((new SmokepingConfigReader("$dir/config", [], []))->read(1)->smokepingVersion)->toBe('');
	});
});

describe('HealthCheck::lossColors', function () {
	it('says which colors the graphs draw and why', function () {
		$found    = HealthCheck::lossColors(LossColors::AUTO, '2.007003');
		$none     = HealthCheck::lossColors(LossColors::AUTO, '');
		$set      = HealthCheck::lossColors('2.7', '');
		$agrees   = HealthCheck::lossColors('2.7', '2.007003');
		$disagree = HealthCheck::lossColors('2.7', '2.009000');

		expect($found->level)->toBe(HealthLevel::Ok)
			->and($found->message)->toContain('2.7.3')
			->and($none->level)->toBe(HealthLevel::Unknown)
			->and($none->message)->toContain('2.8 and later')
			->and($set->level)->toBe(HealthLevel::Ok)
			->and($agrees->level)->toBe(HealthLevel::Ok)
			->and($disagree->level)->toBe(HealthLevel::Warning)
			->and($disagree->message)->toContain('2.9.0');
	});
});

describe('The shipped smoke templates', function () {
	$xml = static fn () => simplexml_load_file(dirname(__DIR__, 2) . '/templates/smokeping-templates.xml');

	it('ship both loss color variants of each ping count, over the same data source items', function () use ($xml) {
		$sources = [];

		foreach ($xml()->children() as $node) {
			if (str_starts_with($node->getName(), 'hash_00') && str_starts_with((string) $node->name, 'SmokePing Smoke')) {
				$items = [];

				foreach ($node->items->children() as $item) {
					$items[] = (string) $item->task_item_id;
				}

				$sources[(string) $node->name] = array_values(array_unique(array_filter($items, fn (string $id) => $id !== '0')));
			}
		}

		expect(array_keys($sources))->toEqualCanonicalizing(TemplateInstaller::smokeGraphTemplateNames());

		foreach (TemplateInstaller::PINGS as $pings) {
			expect($sources[TemplateInstaller::graphTemplateName($pings, LossColors::Legacy)])->toEqualCanonicalizing($sources[TemplateInstaller::graphTemplateName($pings)])
				->and($sources[TemplateInstaller::graphTemplateName($pings)])->toHaveCount($pings + 2); // median, loss, and each ping; uptime is not drawn
		}
	});

	it('leave the smoke data templates without data input values, which belong to the summary', function () use ($xml) {
		$inputs = [];

		foreach ($xml()->children() as $node) {
			if (str_starts_with($node->getName(), 'hash_01')) {
				$inputs[(string) $node->name] = preg_match_all('#<data_input_field_id>hash_07#', (string) $node->asXML());
			}
		}

		$summary = $inputs['SmokePing Summary'];
		unset($inputs['SmokePing Summary']);

		expect($inputs)->toBe(['SmokePing Smoke (10 pings)' => 0, 'SmokePing Smoke (20 pings)' => 0, 'SmokePing Smoke (30 pings)' => 0])
			->and($summary)->toBeGreaterThan(0);
	});
});

describe('the stored SmokePing version and the restyle query', function () {
	beforeEach(function () {
		$GLOBALS['__smokeping_db_fail']    = null;
		$GLOBALS['__smokeping_db_exec']    = [];
		$GLOBALS['__smokeping_db_queries'] = [];
		$GLOBALS['__smokeping_db_assoc']   = [];
		$GLOBALS['__smokeping_db_row']     = [];
	});

	it('records the version with a successful inventory and keeps it on a failed one', function () {
		$health = new HealthRepository(false);
		$health->recordInventory(1, 100, InventoryStatus::Ok, '', 12, '2.007003');
		$health->recordInventory(1, 200, InventoryStatus::Error, 'unreadable', 0, '');

		$updates = array_values(array_filter($GLOBALS['__smokeping_db_exec'], fn (array $e) => str_starts_with($e['sql'], 'UPDATE plugin_smokeping_health')));

		expect($updates)->toHaveCount(2)
			->and($updates[0]['sql'])->toContain('smokeping_version = IF(? = 1, ?, smokeping_version)')
			->and(array_slice($updates[0]['params'], 3))->toBe([1, 12, 1, '2.007003', 1])
			->and(array_slice($updates[1]['params'], 3))->toBe([0, 0, 0, '', 1]);
	});

	it('reads the stored version back, and none for a poller never read', function () {
		$GLOBALS['__smokeping_db_row'] = ['poller_id' => '1', 'smokeping_version' => '2.008002'];

		expect((new HealthRepository(false))->forPoller(1)->smokepingVersion)->toBe('2.008002');

		$GLOBALS['__smokeping_db_row'] = [];

		expect((new HealthRepository(false))->forPoller(1)->smokepingVersion)->toBe('');
	});

	it('lists graphs on the given templates after a graph, and nothing for no templates', function () {
		$objects = new ObjectRepository(false);

		expect($objects->graphsOnTemplates([], 10))->toBe([])
			->and($GLOBALS['__smokeping_db_queries'])->toBe([]);

		$GLOBALS['__smokeping_db_assoc'] = [['local_graph_id' => '7', 'graph_template_id' => '110']];

		expect($objects->graphsOnTemplates([110, 120], 5, 3))->toBe([['local_graph_id' => 7, 'graph_template_id' => 110]]);

		$query = end($GLOBALS['__smokeping_db_queries']);

		expect($query['sql'])->toContain('gl.graph_template_id IN (?, ?)')->toContain('o.template_failed = 0')->toContain('LIMIT 5')
			->and($query['params'])->toBe([110, 120, 'active', 'archived', 3]);
	});
});

describe('restyle progress on the loss_colors row', function () {
	beforeEach(function () {
		$GLOBALS['__smokeping_db_queries'] = [];
		$GLOBALS['__smokeping_db_row']     = [];
		$GLOBALS['__smokeping_db_cell']    = 0;
	});

	it('names graphs still to move without changing the level', function () {
		$row = HealthCheck::lossColors('2.7', '', 3, 0);

		expect($row->level)->toBe(HealthLevel::Ok)
			->and($row->message)->toContain('3 smoke graphs still draw the other colors')
			->not->toContain('could not be moved');
	});

	it('warns about graphs whose move failed, even when no version was found', function () {
		$failed  = HealthCheck::lossColors('2.7', '', 0, 2);
		$unknown = HealthCheck::lossColors(LossColors::AUTO, '', 1, 1);
		$warned  = HealthCheck::lossColors('2.7', '2.009000', 0, 1);

		expect($failed->level)->toBe(HealthLevel::Warning)
			->and($failed->message)->toContain('2 smoke graphs could not be moved')->toContain('upgrade-graphs')
			->and($unknown->level)->toBe(HealthLevel::Warning)
			->and($unknown->message)->toContain('1 smoke graphs still draw')->toContain('1 smoke graphs could not be moved')
			->and($warned->level)->toBe(HealthLevel::Warning)
			->and($warned->message)->toContain('2.9.0');
	});

	it('says nothing more once every graph has moved', function () {
		expect(HealthCheck::lossColors('2.7', '', 0, 0)->message)->not->toContain('smoke graphs still')->not->toContain('could not be moved');
	});

	it('counts graphs on the other colors\' templates, split by failure', function () {
		// Templates 110, 120 and 130 exist for the other (2.8) colors.
		$GLOBALS['__smokeping_db_cell'] = fn (string $sql, array $params) => str_contains($sql, 'FROM graph_templates WHERE name')
			? (preg_match('/^SmokePing Smoke \((\d+) pings\)$/', (string) $params[0], $m) === 1 ? 100 + (int) $m[1] : 0)
			: 0;
		$GLOBALS['__smokeping_db_row'] = ['pending' => '4', 'failed' => '1'];

		expect((new CactiObjectRepository(false))->graphsOnOtherLossColors(LossColors::Legacy))->toBe(['pending' => 4, 'failed' => 1]);

		$query = end($GLOBALS['__smokeping_db_queries']);

		expect($query['sql'])->toContain('gl.graph_template_id IN (?, ?, ?)')
			->and($query['params'])->toBe([110, 120, 130, 'active', 'archived']);
	});

	it('counts nothing when the other templates are not imported', function () {
		expect((new CactiObjectRepository(false))->graphsOnOtherLossColors(LossColors::Current))->toBe(['pending' => 0, 'failed' => 0])
			->and($GLOBALS['__smokeping_db_queries'])->toBe([]);
	});
});
