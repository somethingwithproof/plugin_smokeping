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
use Somethingwithproof\Smokeping\ObjectRepository;
use Somethingwithproof\Smokeping\Settings;
use Somethingwithproof\Smokeping\SmokeProvisioner;
use Somethingwithproof\Smokeping\SystemClock;

/**
 * The probe line each graph carries and the upgrade of graphs made from
 * earlier templates, against the recording database stubs of
 * bootstrap-unit.php. tests/objects-e2e.sh runs both on Cacti.
 */

$GLOBALS['__gtu_changed'] = [];
$GLOBALS['__gtu_change']  = true;
$GLOBALS['__gtu_titles']  = [];

if (!function_exists('change_graph_template')) {
	function change_graph_template($local_graph_id, $graph_template_id, $force = false) {
		$GLOBALS['__gtu_changed'][] = [$local_graph_id, $graph_template_id, $force];

		return is_callable($GLOBALS['__gtu_change']) ? ($GLOBALS['__gtu_change'])() : $GLOBALS['__gtu_change'];
	}
}

if (!function_exists('update_graph_title_cache')) {
	function update_graph_title_cache($local_graph_id) {
		$GLOBALS['__gtu_titles'][] = $local_graph_id;
	}
}

/**
 * Statements run so far whose SQL contains $text.
 *
 * @return list<array{sql: string, params: list<mixed>}>
 */
function gtu_writes(string $text): array {
	return array_values(array_filter($GLOBALS['__smokeping_db_exec'], fn (array $exec) => str_contains($exec['sql'], $text)));
}

function gtu_provisioner(): SmokeProvisioner {
	// retemplateDue() includes Cacti's template.php and variables.php; the
	// functions it calls are the stubs above.
	$core = sys_get_temp_dir() . '/gtu-core-' . getmypid();

	if (!is_dir($core)) {
		mkdir($core);
		touch($core . '/template.php');
		touch($core . '/variables.php');
	}

	return new SmokeProvisioner(Settings::fromRow([], '/rra'), '/rra', $core, false, new ObjectRepository(false), new CactiObjectRepository(false), new SystemClock(), null);
}

beforeEach(function () {
	$GLOBALS['__smokeping_db_fail']  = null;
	$GLOBALS['__smokeping_db_exec']  = [];
	$GLOBALS['__smokeping_db_assoc'] = [];
	$GLOBALS['__smokeping_db_row']   = [];
	$GLOBALS['__smokeping_db_cell']  = 0;
	$GLOBALS['__gtu_changed']        = [];
	$GLOBALS['__gtu_change']         = true;
	$GLOBALS['__gtu_titles']         = [];
});

describe('ObjectRepository::syncProbeLines', function () {
	it('writes each probe line that differs from its target\'s, and only those', function () {
		$GLOBALS['__smokeping_db_assoc'] = [
			['id' => '11', 'text_format' => 'probe:', 'pings' => '20', 'step' => '300', 'probe' => 'FPing', 'probe_desc' => 'ICMP Echo Pings (56 Bytes)'],
			['id' => '12', 'text_format' => 'probe:       10 DNS requests every 60s', 'pings' => '10', 'step' => '60', 'probe' => 'DNS', 'probe_desc' => 'DNS requests'],
			['id' => '13', 'text_format' => 'probe:', 'pings' => '5', 'step' => '300', 'probe' => 'FTPtransfer', 'probe_desc' => ''],
		];

		expect((new ObjectRepository(false))->syncProbeLines())->toBe(2)
			->and(array_map(fn (array $w) => $w['params'], gtu_writes('UPDATE graph_templates_item')))->toBe([
				['probe:       20 ICMP Echo Pings (56 Bytes) every 300s', 11],
				['probe:       5 FTPtransfer every 300s', 13],
			]);
	});

	it('limits the query to one graph when asked', function () {
		(new ObjectRepository(false))->syncProbeLines(7);

		$query = end($GLOBALS['__smokeping_db_queries']);

		expect($query['sql'])->toContain('o.local_graph_id = ?')
			->and($query['params'])->toBe(['probe:', 'active', 'archived', 7]);
	});

	it('does not count a write that failed', function () {
		$GLOBALS['__smokeping_db_assoc'] = [['id' => '11', 'text_format' => 'probe:', 'pings' => '20', 'step' => '300', 'probe' => 'FPing', 'probe_desc' => '']];
		$GLOBALS['__smokeping_db_fail']  = 'UPDATE graph_templates_item';

		expect((new ObjectRepository(false))->syncProbeLines())->toBe(0);
	});
});

describe('ObjectRepository template versions', function () {
	it('records the installed template version on a new graph', function () {
		(new ObjectRepository(false))->add(1, '', 2, 3, 4, 'smokeping/a.rrd', ObjectRepository::ACTIVE, 100);

		$insert = gtu_writes('INSERT INTO plugin_smokeping_object')[0];

		expect($insert['sql'])->toContain('SELECT CAST(value AS UNSIGNED) FROM settings WHERE name = ?')
			->and(end($insert['params']))->toBe('smokeping_templates_version');
	});

	it('lists the graphs behind a template version and records a new one', function () {
		$GLOBALS['__smokeping_db_assoc'] = [['local_graph_id' => '4'], ['local_graph_id' => '9']];
		$objects                         = new ObjectRepository(false);

		expect($objects->graphsBehindTemplates(3, 200))->toBe([4, 9])
			->and(end($GLOBALS['__smokeping_db_queries'])['sql'])->toContain('LIMIT 200')
			->and($objects->recordTemplateVersion(4, 3))->toBeTrue()
			->and(gtu_writes('SET template_version')[0]['params'])->toBe([3, 4]);
	});
});

describe('CactiObjectRepository::retemplateGraph', function () {
	it('retemplates the graph, copies the template\'s item columns, and puts the title back', function () {
		$GLOBALS['__smokeping_db_cell'] = 5;
		$GLOBALS['__smokeping_db_row']  = ['title' => 'Europe/paris - SmokePing', 't_title' => 'on'];

		expect((new CactiObjectRepository(false))->retemplateGraph(4))->toBeTrue()
			->and($GLOBALS['__gtu_changed'])->toBe([[4, 5, true]])
			->and(gtu_writes('gi.vdef_id = ti.vdef_id')[0]['params'])->toBe([4])
			->and(gtu_writes('UPDATE graph_templates_graph SET title')[0]['params'])->toBe(['Europe/paris - SmokePing', 'on', 4]);
	});

	it('leaves a graph without a template or a graph row alone', function () {
		$repository = new CactiObjectRepository(false);

		expect($repository->retemplateGraph(4))->toBeFalse();

		$GLOBALS['__smokeping_db_cell'] = 5;

		expect($repository->retemplateGraph(4))->toBeFalse()
			->and($GLOBALS['__gtu_changed'])->toBe([]);
	});

	it('stops when Cacti cannot change the graph template', function () {
		$GLOBALS['__smokeping_db_cell'] = 5;
		$GLOBALS['__smokeping_db_row']  = ['title' => 't', 't_title' => ''];
		$GLOBALS['__gtu_change']        = false;

		expect((new CactiObjectRepository(false))->retemplateGraph(4))->toBeFalse()
			->and(gtu_writes('gi.vdef_id = ti.vdef_id'))->toBe([]);
	});

	it('counts the VDEF items of the named templates', function () {
		$GLOBALS['__smokeping_db_cell'] = 6;
		$repository                     = new CactiObjectRepository(false);

		expect($repository->graphTemplateVdefItems(['A', 'B']))->toBe(6)
			->and($repository->graphTemplateVdefItems([]))->toBe(0);
	});
});

describe('SmokeProvisioner::retemplateDue', function () {
	it('counts a graph whose retemplate throws as failed and goes on', function () {
		$GLOBALS['__smokeping_db_assoc'] = [['local_graph_id' => '4'], ['local_graph_id' => '5']];
		$GLOBALS['__smokeping_db_cell']  = 5;
		$GLOBALS['__smokeping_db_row']   = ['title' => 't', 't_title' => 'on'];
		$GLOBALS['__gtu_change']         = fn () => throw new RuntimeException('boom');

		expect(gtu_provisioner()->retemplateDue(3, 200))->toBe(['upgraded' => 0, 'failed' => 2])
			->and(gtu_writes('SET template_version'))->toHaveCount(2);
	});

	it('upgrades each graph in one transaction and records its version', function () {
		$GLOBALS['__smokeping_db_assoc'] = [['local_graph_id' => '4']];
		$GLOBALS['__smokeping_db_cell']  = 5;
		$GLOBALS['__smokeping_db_row']   = ['title' => 't', 't_title' => 'on'];

		$counts = gtu_provisioner()->retemplateDue(3, 200);
		$order  = array_values(array_filter(array_map(fn (array $w) => match (true) {
			$w['sql'] === 'BEGIN', $w['sql'] === 'COMMIT', $w['sql'] === 'ROLLBACK' => $w['sql'],
			str_contains($w['sql'], 'SET template_version') => 'version',
			default => null,
		}, $GLOBALS['__smokeping_db_exec'])));

		expect($counts)->toBe(['upgraded' => 1, 'failed' => 0])
			->and($order)->toBe(['BEGIN', 'version', 'COMMIT'])
			->and($GLOBALS['__gtu_titles'])->toBe([4]);
	});

	it('rolls a failed graph back, records its version so it does not hold a place, and counts it', function () {
		$GLOBALS['__smokeping_db_assoc'] = [['local_graph_id' => '4']];
		$GLOBALS['__smokeping_db_cell']  = 5;
		$GLOBALS['__smokeping_db_row']   = ['title' => 't', 't_title' => 'on'];
		$GLOBALS['__gtu_change']         = false;

		$counts = gtu_provisioner()->retemplateDue(3, 200);
		$sql    = array_column($GLOBALS['__smokeping_db_exec'], 'sql');

		expect($counts)->toBe(['upgraded' => 0, 'failed' => 1])
			->and(array_slice($sql, 0, 2))->toBe(['BEGIN', 'ROLLBACK'])
			->and(gtu_writes('SET template_version'))->toHaveCount(1)
			->and($GLOBALS['__gtu_titles'])->toBe([]);
	});
});

$GLOBALS['__gtu_imports'] = [];

if (!function_exists('import_xml_data')) {
	function import_xml_data(&$xml_data, $import_as_new, $profile_id, $remove_orphans = false, $replace_svalues = false, $import_hashes = array(), $class = '') {
		$GLOBALS['__gtu_imports'][] = $xml_data;

		return ['graph_template' => [['result' => 'success']]];
	}
}

if (!function_exists('set_config_option')) {
	function set_config_option($config_name, $value) {
		$GLOBALS['__smokeping_options'][$config_name] = $value;
	}
}

/**
 * A template file with one VDEF and a graph item that uses it, and an
 * installer whose Cacti answers $vdefItems for the templates' VDEF items.
 */
function gtu_installer(int $vdefItems): \Somethingwithproof\Smokeping\TemplateInstaller {
	$dir = sys_get_temp_dir() . '/gtu-tpl-' . getmypid();

	if (!is_dir($dir)) {
		mkdir($dir);
		touch($dir . '/utility.php');
		touch($dir . '/import.php');
	}

	file_put_contents($dir . '/t.xml', "<cacti>\n\t<hash_000103aa>\n\t\t<items><vdef_id>hash_180103" . str_repeat('b', 32) . "</vdef_id></items>\n\t</hash_000103aa>\n"
		. "\t<hash_180103" . str_repeat('b', 32) . ">\n\t\t<name>avg</name>\n\t</hash_180103" . str_repeat('b', 32) . ">\n</cacti>\n");

	// Every template is present once; the VDEF items are counted separately.
	$GLOBALS['__smokeping_db_cell'] = fn (string $sql) => str_contains($sql, 'vdef_id > 0') ? $vdefItems : 1;

	return new \Somethingwithproof\Smokeping\TemplateInstaller($dir . '/t.xml', $dir, new CactiObjectRepository(false));
}

describe('TemplateInstaller VDEFs', function () {
	beforeEach(function () {
		$GLOBALS['__gtu_imports']       = [];
		$GLOBALS['__smokeping_options'] = [];
	});

	it('imports the file\'s VDEFs on their own before the file', function () {
		expect(gtu_installer(1)->install('1.0.0'))->toBeTrue()
			->and($GLOBALS['__gtu_imports'])->toHaveCount(2)
			->and($GLOBALS['__gtu_imports'][0])->toStartWith("<cacti>\n<hash_180103")
			->and($GLOBALS['__gtu_imports'][0])->not->toContain('hash_000103')
			->and($GLOBALS['__gtu_imports'][1])->toContain('hash_000103aa')
			->and($GLOBALS['__smokeping_options']['smokeping_templates_version'])->toBe((string) \Somethingwithproof\Smokeping\TemplateInstaller::VERSION);
	});

	it('fails the install when a graph item is left without its VDEF', function () {
		expect(gtu_installer(0)->install('1.0.0'))->toBeFalse()
			->and($GLOBALS['__smokeping_options']['smokeping_templates_failure'] ?? '')->toContain('missing their VDEFs');
	});
});

describe('TargetRepository probe descriptions', function () {
	it('stores the probe description of added and changed targets', function () {
		$target  = \Somethingwithproof\Smokeping\Inventory::fromDocument(['format' => \Somethingwithproof\Smokeping\Inventory::FORMAT, 'targets' => [['path' => 'a/b', 'host' => 'h', 'probe' => 'FPing', 'step' => 300, 'pings' => 20, 'probe_desc' => 'ICMP Echo Pings (56 Bytes)']]], 1)->targets[0];
		$changed = new \Somethingwithproof\Smokeping\Target(\Somethingwithproof\Smokeping\TargetId::fromInt(9), 1, $target->path, 'h', 'FPing', '', '', [], false, 300, 20, $target->mode, $target->adminState, true, 0, 0, 'DNS requests');

		expect((new \Somethingwithproof\Smokeping\TargetRepository(false))->applyImport(new \Somethingwithproof\Smokeping\ImportPlan([$target], [$changed], [], [], []), 1, 100))->toBeTrue()
			->and(gtu_writes('INSERT INTO plugin_smokeping_target')[0]['params'][4])->toBe('ICMP Echo Pings (56 Bytes)')
			->and(gtu_writes('UPDATE plugin_smokeping_target
			SET host')[0]['params'][2])->toBe('DNS requests');
	});
});
