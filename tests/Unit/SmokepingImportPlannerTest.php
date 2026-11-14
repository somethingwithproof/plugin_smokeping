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

use Somethingwithproof\Smokeping\Inventory;
use Somethingwithproof\Smokeping\SmokepingConfigReader;
use Somethingwithproof\Smokeping\SmokepingImportOptions;
use Somethingwithproof\Smokeping\SmokepingImportPlanner;
use Somethingwithproof\Smokeping\SmokepingImportState;
use Somethingwithproof\Smokeping\Target;
use Somethingwithproof\Smokeping\TargetId;

/**
 * A packaged-style configuration: two sections, a nested one, a host probed
 * by two targets, a slave location, a 15-ping DismanPing target, and a
 * multi-host graph.
 */
function ip_inventory(): Inventory {
	$text = "*** General ***\nowner = o\ncontact = o@example.net\ncgiurl = http://localhost/\nimgcache = /c\nimgurl = c\ndatadir = /var/lib/smokeping\npiddir = /run\nsmokemail = /m\n"
		. "*** Database ***\nstep = 300\npings = 20\n*** Presentation ***\ntemplate = /t\n+ overview\nwidth = 600\nheight = 50\n+ detail\nwidth = 600\nheight = 200\n"
		. "*** Probes ***\n+ FPing\nbinary = /usr/bin/fping\n+ DismanPing\n"
		. "*** Slaves ***\nsecrets = /s\n+ boston\ndisplay_name = Boston\ncolor = 0000ff\n"
		. "*** Targets ***\nprobe = FPing\nmenu = Top\ntitle = Top\n"
		. "+ Europe\nmenu = Europe\ntitle = Europe\n++ paris\nhost = paris.example.net\ntitle = Paris\nslaves = boston\n++ paris_dns\nhost = paris.example.net\n"
		. "++ Nordic\nmenu = Nordic\ntitle = Nordic\n+++ oslo\nhost = oslo.example.net\n"
		. "+ Lab\nmenu = Lab\ntitle = Lab\n++ disman\nprobe = DismanPing\nhost = lab.example.net\npinghost = 192.0.2.1\n++ multi\nhost = /Europe/paris /Lab/disman\n";

	return SmokepingConfigReader::fromText($text, 'test.conf', 1);
}

/**
 * @param array<string, mixed> $over
 */
function ip_state(array $over = []): SmokepingImportState {
	return new SmokepingImportState(
		$over['poller'] ?? 1,
		$over['rrdproxy'] ?? false,
		$over['targets'] ?? [],
		$over['mapped'] ?? [],
		$over['objects'] ?? [],
		$over['devices'] ?? [],
		[7 => 'SmokePing Target', 8 => 'Other'],
		[1 => 'Edge'],
		[1 => 'Default Tree'],
		$over['summary'] ?? true,
	);
}

function ip_options(string $per = 'host', array $sections = [], int $treeId = 0, string $treeName = '', int $poller = 1): SmokepingImportOptions {
	return new SmokepingImportOptions($per, 7, $poller, 0, $sections, $treeId, $treeName);
}

/**
 * The inventory's targets as stored, with ids from 1 in path order.
 *
 * @return array<string, Target>
 */
function ip_stored(): array {
	$stored = [];
	$id     = 1;

	foreach (ip_inventory()->targets as $t) {
		$stored[$t->path->value] = new Target(TargetId::fromInt($id++), $t->pollerId, $t->path, $t->host, $t->probe, $t->menu, $t->title, $t->slaves, $t->noMasterPoll, $t->step, $t->pings, $t->mode, $t->adminState, true, 0, 0);
	}

	return $stored;
}

describe('SmokepingImportPlanner', function () {
	it('lists the sections, and plans one device per host with summary and smoke graphs per location', function () {
		$plan = SmokepingImportPlanner::plan(ip_inventory(), ip_options(), ip_state(), 'd');

		expect($plan->sections)->toBe(['Europe' => 3, 'Europe/Nordic' => 1, 'Lab' => 1])
			->and(array_column($plan->devices, 'action', 'key'))->toBe(['oslo.example.net' => 'create', 'paris.example.net' => 'create', 'lab.example.net' => 'create'])
			->and($plan->devices[1]['targets'])->toBe(['Europe/paris', 'Europe/paris_dns'])
			->and($plan->devices[1]['description'])->toBe('paris.example.net')
			->and(count(array_filter($plan->graphs, fn ($g) => $g['path'] === 'Europe/paris')))->toBe(4)
			->and($plan->summaryOnly)->toBe(['Lab/disman' => 'it sends 15 pings per round; smoke graphs exist for 10, 20, and 30'])
			->and($plan->counts())->toMatchArray(['create' => 3, 'graphs' => 9, 'existing' => 0])
			->and($plan->hasErrors())->toBeFalse()
			->and($plan->load->series)->toBe(5);
	});

	it('plans one device per target, named by its title or path', function () {
		$plan = SmokepingImportPlanner::plan(ip_inventory(), ip_options('target'), ip_state(), 'd');

		expect(array_column($plan->devices, 'description', 'key'))->toBe(['Europe/Nordic/oslo' => 'Europe/Nordic/oslo', 'Europe/paris' => 'Paris', 'Europe/paris_dns' => 'Europe/paris_dns', 'Lab/disman' => 'Lab/disman']);
	});

	it('selects targets by section, including nested sections', function () {
		$plan = SmokepingImportPlanner::plan(ip_inventory(), ip_options('host', ['Europe/Nordic', 'Lab']), ip_state(), 'd');

		expect(array_map(fn (Target $t) => $t->path->value, $plan->targets))->toBe(['Europe/Nordic/oslo', 'Lab/disman'])
			->and(SmokepingImportPlanner::plan(ip_inventory(), ip_options('host', ['Asia']), ip_state(), 'd')->errors)->toContain('The configuration has no section Asia.');
	});

	it('uses a device with the host name on the same poller, and refuses to guess between two', function () {
		$devices = [
			['id' => 40, 'hostname' => 'PARIS.example.net', 'description' => 'p', 'poller_id' => 1],
			['id' => 41, 'hostname' => 'oslo.example.net', 'description' => 'o', 'poller_id' => 2],
			['id' => 42, 'hostname' => 'lab.example.net', 'description' => 'a', 'poller_id' => 1],
			['id' => 43, 'hostname' => 'lab.example.net', 'description' => 'b', 'poller_id' => 1],
		];
		$plan = SmokepingImportPlanner::plan(ip_inventory(), ip_options(), ip_state(['devices' => $devices]), 'd');

		expect(array_column($plan->devices, 'action', 'key'))->toBe(['oslo.example.net' => 'create', 'paris.example.net' => 'map', 'lab.example.net' => 'ambiguous'])
			->and($plan->devices[1]['host_id'])->toBe(40)
			->and($plan->devices[2]['reason'])->toContain('devices 42, 43')
			->and(array_unique(array_column($plan->graphs, 'path')))->not->toContain('Lab/disman');
	});

	it('plans nothing new when the targets are mapped and their graphs exist, so a re-run is empty', function () {
		$stored  = ip_stored();
		$mapped  = [];
		$objects = [];

		foreach ($stored as $target) {
			$mapped[$target->id->value] = 100 + $target->id->value;

			foreach ($target->locations() as $location) {
				$objects[$target->id->value . '~' . $location . '~summary'] = true;
				$objects[$target->id->value . '~' . $location . '~smoke']   = true;
			}
		}

		$plan = SmokepingImportPlanner::plan(ip_inventory(), ip_options('target'), ip_state(['targets' => $stored, 'mapped' => $mapped, 'objects' => $objects]), 'd');

		expect(array_unique(array_column($plan->devices, 'action')))->toBe(['mapped'])
			->and($plan->counts()['graphs'])->toBe(0)
			->and($plan->isEmpty())->toBeTrue()
			->and($plan->load->series)->toBe(0);
	});

	it('joins unmapped targets of a host to the device its mapped target uses', function () {
		$stored = ip_stored();
		$plan   = SmokepingImportPlanner::plan(ip_inventory(), ip_options(), ip_state(['targets' => $stored, 'mapped' => [$stored['Europe/paris']->id->value => 55]]), 'd');

		expect($plan->devices[1])->toMatchArray(['key' => 'paris.example.net', 'action' => 'map', 'host_id' => 55]);
	});

	it('mirrors sections as tree branches and devices as leaves under their section', function () {
		$plan = SmokepingImportPlanner::plan(ip_inventory(), ip_options('host', [], 0, 'SmokePing'), ip_state(), 'd');

		expect($plan->branches)->toBe(['Europe', 'Europe/Nordic', 'Lab'])
			->and($plan->leaves)->toBe([
				['branch' => 'Europe', 'device' => 'paris.example.net'],
				['branch' => 'Europe/Nordic', 'device' => 'oslo.example.net'],
				['branch' => 'Lab', 'device' => 'lab.example.net'],
			])
			->and(SmokepingImportPlanner::plan(ip_inventory(), ip_options(), ip_state(), 'd')->branches)->toBe([]);
	});

	it('refuses another poller, a missing template, site or tree, and missing SmokePing templates', function () {
		$errors = SmokepingImportPlanner::plan(ip_inventory(), new SmokepingImportOptions('host', 99, 2, 9, [], 9), ip_state(['summary' => false]), 'd')->errors;

		expect($errors)->toHaveCount(5)
			->and($errors[0])->toContain('poller 1, which runs the SmokePing reader');
	});

	it('gives summary data only on RRDproxy', function () {
		$plan = SmokepingImportPlanner::plan(ip_inventory(), ip_options(), ip_state(['rrdproxy' => true]), 'd');

		expect(array_unique(array_column($plan->graphs, 'kind')))->toBe(['summary'])
			->and($plan->summaryOnly)->toHaveCount(4);
	});

	it('gives a plan id that changes with the configuration, the options, and the installation', function () {
		$base = SmokepingImportPlanner::plan(ip_inventory(), ip_options(), ip_state(), 'd')->id();

		expect(SmokepingImportPlanner::plan(ip_inventory(), ip_options(), ip_state(), 'd')->id())->toBe($base)
			->and(SmokepingImportPlanner::plan(ip_inventory(), ip_options(), ip_state(), 'e')->id())->not->toBe($base)
			->and(SmokepingImportPlanner::plan(ip_inventory(), ip_options('target'), ip_state(), 'd')->id())->not->toBe($base)
			->and(SmokepingImportPlanner::plan(ip_inventory(), ip_options(), ip_state(['devices' => [['id' => 40, 'hostname' => 'paris.example.net', 'description' => 'p', 'poller_id' => 1]]]), 'd')->id())->not->toBe($base);
	});

	it('prints the plan as lines for the command line', function () {
		$lines = SmokepingImportPlanner::plan(ip_inventory(), ip_options('host', ['Lab'], 0, 'SP'), ip_state(), 'd')->lines();

		expect($lines)->toContain('create device "lab.example.net" (lab.example.net) for Lab/disman')
			->and($lines)->toContain('create summary graph for Lab/disman')
			->and($lines)->toContain('tree "SP": device lab.example.net under Lab');
	});
});
