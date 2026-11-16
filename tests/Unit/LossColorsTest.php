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

use Somethingwithproof\Smokeping\HealthCheck;
use Somethingwithproof\Smokeping\HealthLevel;
use Somethingwithproof\Smokeping\LossColors;
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
