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

use Somethingwithproof\Smokeping\AdminState;
use Somethingwithproof\Smokeping\CactiObjectRepository;
use Somethingwithproof\Smokeping\CsvExporter;
use Somethingwithproof\Smokeping\DeviceMaintenance;
use Somethingwithproof\Smokeping\FixedClock;
use Somethingwithproof\Smokeping\FreshnessEvaluator;
use Somethingwithproof\Smokeping\HealthCheck;
use Somethingwithproof\Smokeping\HealthLevel;
use Somethingwithproof\Smokeping\InventoryPage;
use Somethingwithproof\Smokeping\ObjectRepository;
use Somethingwithproof\Smokeping\ObservationRepository;
use Somethingwithproof\Smokeping\OrphanRrdScanner;
use Somethingwithproof\Smokeping\SmokepingConfigParser;
use Somethingwithproof\Smokeping\Target;
use Somethingwithproof\Smokeping\TargetFilter;
use Somethingwithproof\Smokeping\TargetId;
use Somethingwithproof\Smokeping\TargetMode;
use Somethingwithproof\Smokeping\TargetPath;
use Somethingwithproof\Smokeping\TargetRepository;
use Somethingwithproof\Smokeping\TholdGenerator;
use Somethingwithproof\Smokeping\TholdTranslator;
use Somethingwithproof\Smokeping\ViewPage;

describe('CsvExporter (RFC 4180 + formula neutralization)', function () {
	it('neutralizes leading formula trigger characters', function (string $input, string $expected) {
		expect(CsvExporter::neutralize($input))->toBe($expected);
	})->with([
		'equals' => ['=CMD("calc")', "'=CMD(\"calc\")"],
		'plus'   => ['+1+1', "'+1+1"],
		'minus'  => ['-2+3', "'-2+3"],
		'at'     => ['@SUM(A1:A2)', "'@SUM(A1:A2)"],
		'tab'    => ["\t1+1", "'\t1+1"],
		'cr'     => ["\r1+1", "'\r1+1"],
		'normal' => ['Europe/paris', 'Europe/paris'],
		'empty'  => ['', ''],
	]);

	it('emits RFC 4180 CRLF lines with escaped quotes and neutralized target fields', function () {
		$csv = CsvExporter::exportTargets([
			[
				'path'        => 'Europe/paris',
				'name'        => '=HYPERLINK("http://bad","Paris, FR")',
				'host'        => 'paris.example',
				'probe'       => 'FPing',
				'device'      => '+Edge "Router"',
				'location'    => '',
				'median_ms'   => 12.345,
				'loss_pct'    => 5.0,
				'age_seconds' => 42,
				'freshness'   => 'current',
				'condition'   => 'degraded',
			],
			[
				'path'        => 'Europe/berlin',
				'name'        => 'Berlin',
				'host'        => 'berlin.example',
				'probe'       => 'FPing',
				'device'      => 'Core',
				'location'    => 'boston',
				'median_ms'   => null,
				'loss_pct'    => null,
				'age_seconds' => null,
				'freshness'   => 'never',
				'condition'   => 'unknown',
			],
		]);

		$lines = explode("\r\n", $csv);
		expect($lines[0])->toBe('path,name,host,probe,device,location,median_ms,loss_pct,age_seconds,freshness,condition')
			->and($lines[1])->toBe('Europe/paris,"\'=HYPERLINK(""http://bad"",""Paris, FR"")",paris.example,FPing,"\'+Edge ""Router""",master,12.345,5,42,current,degraded')
			->and($lines[2])->toBe('Europe/berlin,Berlin,berlin.example,FPing,Core,boston,,,,never,unknown')
			->and($lines[3])->toBe('');
	});
});

describe('TargetFilter, TargetRepository::filteredForPoller, and ViewPage filtering', function () {
	afterEach(function () {
		$GLOBALS['__smokeping_db_assoc']   = [];
		$GLOBALS['__smokeping_db_queries'] = [];
		$GLOBALS['__smokeping_db_cell']    = 0;
	});

	it('clamps invalid filter values safely', function () {
		$filter = new TargetFilter('  paris  ', 'FPing', 'bogus', 'nope', 'invalid', -3, 99999);

		expect($filter->search)->toBe('paris')
			->and($filter->probe)->toBe('FPing')
			->and($filter->presence)->toBe('all')
			->and($filter->mapping)->toBe('all')
			->and($filter->state)->toBe('all')
			->and($filter->page)->toBe(1)
			->and($filter->rows)->toBe(TargetFilter::MAX_ROWS)
			->and($filter->isFiltered())->toBeTrue();
	});

	it('applies device permission filtering before counting and paginates in SQL', function () {
		$GLOBALS['__smokeping_db_queries'] = [];
		$GLOBALS['__smokeping_db_cell']    = static function (string $sql, array $params): int {
			expect($sql)->toContain('SELECT COUNT(*)')
				->and($sql)->toContain('m.host_id IS NULL OR m.host_id IN (?, ?)')
				->and($params)->toContain(1, 10, 20, 'FPing');

			return 75;
		};
		$GLOBALS['__smokeping_db_assoc'] = static function (string $sql, array $params): array {
			if (str_contains($sql, 'SELECT DISTINCT t.probe')) {
				return [['probe' => 'FPing'], ['probe' => 'DNS']];
			}

			expect($sql)->toContain('LIMIT 25 OFFSET 25')
				->and($sql)->toContain('t.present = 1')
				->and($sql)->toContain('m.host_id IS NOT NULL');

			return [[
				'id' => 5, 'poller_id' => 1, 'path' => 'Europe/paris', 'host' => 'paris.example',
				'probe' => 'FPing', 'menu' => 'Paris', 'title' => 'Paris', 'slaves' => '',
				'nomasterpoll' => 0, 'step' => 300, 'pings' => 20, 'mode' => 'observed',
				'admin_state' => 'enabled', 'present' => 1, 'first_seen' => 100, 'last_seen' => 200,
			]];
		};

		$filter = new TargetFilter('paris', 'FPing', 'present', 'mapped', 'all', 2, 25);
		$result = (new TargetRepository(false))->filteredForPoller(1, $filter, [10, 20]);

		expect($result['total'])->toBe(75)
			->and($result['probes'])->toBe(['FPing', 'DNS'])
			->and($result['targets'])->toHaveCount(1)
			->and($result['targets'][0]->path->value)->toBe('Europe/paris');
	});

	it('returns zero mapped targets without querying when a user has no allowed devices', function () {
		$GLOBALS['__smokeping_db_assoc'] = [];
		$result                          = (new TargetRepository(false))->filteredForPoller(1, new TargetFilter('', '', 'all', 'mapped'), []);

		expect($result['total'])->toBe(0)
			->and($result['targets'])->toBe([]);
	});

	it('filters ViewPage rows by search, probe, and freshness state', function () {
		$now = 1700001000;
		$GLOBALS['__smokeping_db_assoc'] = static function (string $sql): array {
			if (str_contains($sql, 'plugin_smokeping_mapping')) {
				return [
					['id' => 1, 'poller_id' => 1, 'path' => 'Europe/paris', 'host' => 'paris.example', 'probe' => 'FPing', 'title' => 'Paris', 'step' => 300, 'pings' => 20, 'present' => 1, 'first_seen' => 1700000000, 'mapped_host_id' => 10],
					['id' => 2, 'poller_id' => 1, 'path' => 'DNS/resolver', 'host' => 'ns.example', 'probe' => 'DNS', 'title' => 'Resolver', 'step' => 300, 'pings' => 5, 'present' => 1, 'first_seen' => 1700000000, 'mapped_host_id' => 10],
				];
			}

			return [
				['target_id' => 1, 'location' => '', 'observed_at' => 1700000950, 'ingested_at' => 1700000960, 'expected_interval' => 300, 'pings' => 20, 'loss' => 1, 'median' => 0.015, 'file_present' => 1],
				['target_id' => 2, 'location' => '', 'observed_at' => 1700000000, 'ingested_at' => 1700000100, 'expected_interval' => 300, 'pings' => 5, 'loss' => 0, 'median' => 0.005, 'file_present' => 1],
			];
		};

		$clock       = new FixedClock($now);
		$maintenance = new class implements DeviceMaintenance {
			public function inMaintenance(int $hostId): bool {
				return false;
			}
		};
		$page = new ViewPage(new TargetRepository(false), new ObservationRepository(false), new FreshnessEvaluator($clock, 60), $maintenance, $clock);

		$stale = $page->rows([10 => 'Edge'], new TargetFilter('', '', 'all', 'all', 'stale'));
		expect($stale)->toHaveCount(1)
			->and($stale[0]['path'])->toBe('DNS/resolver')
			->and($stale[0]['freshness'])->toBe('stale');

		$current = $page->rows([10 => 'Edge'], new TargetFilter('paris', 'FPing', 'all', 'all', 'current'));
		expect($current)->toHaveCount(1)
			->and($current[0]['median_ms'])->toBe(15.0)
			->and($current[0]['loss_pct'])->toBe(5.0)
			->and($current[0]['age_seconds'])->toBe(50);
	});
});

describe('OrphanRrdScanner and HealthCheck::orphanRrds', function () {
	beforeEach(function () {
		$this->mount = sys_get_temp_dir() . '/smokeping-orphan-' . getmypid() . '-' . random_int(0, PHP_INT_MAX);
		mkdir($this->mount . '/Local', 0755, true);
		mkdir($this->mount . '/Europe', 0755, true);
		mkdir($this->mount . '/__cgi', 0755, true);
		mkdir($this->mount . '/__sortercache', 0755, true);
		touch($this->mount . '/Europe/paris.rrd');
		touch($this->mount . '/Local/LocalMachine.rrd');
		touch($this->mount . '/__cgi/ignored.rrd');
		touch($this->mount . '/__sortercache/cache.rrd');
		symlink($this->mount . '/Europe/paris.rrd', $this->mount . '/Europe/link.rrd');
	});

	afterEach(function () {
		exec('rm -rf ' . escapeshellarg($this->mount));
	});

	it('detects unowned RRD files while skipping __cgi, __sortercache, and symlinks', function () {
		$scan = (new OrphanRrdScanner())->scan($this->mount, [$this->mount . '/Europe/paris.rrd']);

		expect($scan['truncated'])->toBeFalse()
			->and($scan['scanned'])->toBe(2)
			->and(array_column($scan['orphans'], 'relative'))->toBe(['Local/LocalMachine.rrd']);

		$finding = HealthCheck::orphanRrds($scan, [
			['id' => 9, 'old_id' => 1, 'old_path' => 'Local/LocalMachine', 'new_path' => 'Local/Renamed', 'host' => '127.0.0.1', 'probe' => 'FPing', 'proposed_at' => 1],
		]);

		expect($finding->check)->toBe('orphan_rrds')
			->and($finding->level)->toBe(HealthLevel::Warning)
			->and($finding->message)->toContain('Local/LocalMachine.rrd')
			->and($finding->message)->toContain('re-link proposal #9');
	});

	it('respects the file budget on large directories', function () {
		$scan = (new OrphanRrdScanner(1, 5.0))->scan($this->mount, []);

		expect($scan['truncated'])->toBeTrue()
			->and($scan['scanned'])->toBe(1);
	});
});

describe('TholdTranslator and TholdGenerator', function () {
	it('translates supported SmokePing loss patterns with exact round-by-round firing equivalence', function (string $pattern, array $rounds, bool $edgeOnly) {
		$spec = TholdTranslator::translate(['name' => 'lossalert', 'type' => 'loss', 'pattern' => $pattern], 20);

		expect($spec['supported'])->toBeTrue()
			->and($spec['data_source_name'])->toBe('loss_pct');

		$spFires    = TholdTranslator::evaluateSmokepingPattern($pattern, $rounds);
		$tholdFires = TholdTranslator::evaluateTholdSpec(
			['thold_hi' => (float) $spec['thold_hi'], 'thold_fail_trigger' => (int) $spec['thold_fail_trigger']],
			$rounds,
			$edgeOnly
		);

		expect($tholdFires)->toBe($spFires);
	})->with([
		'two rounds >0% after 0% baseline' => ['==0%,>0%,>0%', [0.0, 5.0, 10.0, 15.0, 0.0, 5.0, 0.0, 5.0, 5.0], true],
		'consecutive >10% x2'              => ['>10%,>10%', [0.0, 10.0, 15.0, 20.0, 25.0, 5.0, 15.0], false],
		'consecutive >=20% x2'             => ['>=20%,>=20%', [0.0, 15.0, 20.0, 25.0, 10.0, 20.0, 20.0], false],
		'complete loss ==100% x2'          => ['==100%,==100%', [0.0, 95.0, 100.0, 100.0, 50.0, 100.0], false],
	]);

	it('refuses unsupported patterns without creating any Thold threshold', function (array $alert) {
		$spec = TholdTranslator::translate($alert, 20);

		expect($spec['supported'])->toBeFalse()
			->and($spec['reason'])->not->toBe('');

		$GLOBALS['__smokeping_db_exec']         = [];
		$GLOBALS['__smokeping_db_table_exists'] = true;
		$GLOBALS['__smokeping_db_assoc']        = [['local_data_id' => 44, 'host_id' => 7, 'location' => '']];

		$target = new Target(TargetId::fromInt(1), 1, TargetPath::fromString('Europe/paris'), 'paris.example', 'FPing', 'Paris', 'Paris', [], false, 300, 20, TargetMode::Observed, AdminState::Enabled, true, 100, 200, '', $alert['name']);
		$gen    = new TholdGenerator(new TargetRepository(false), new ObjectRepository(false), new CactiObjectRepository(false));
		$res    = $gen->apply($target, [$alert['name'] => $alert + ['comment' => '', 'edgetrigger' => false]]);

		expect($res['created'])->toBe([])
			->and($res['skipped'])->toHaveCount(1)
			->and($GLOBALS['__smokeping_db_exec'])->toBe([]);
	})->with([
		'rtt pattern'        => [['name' => 'highrtt', 'type' => 'rtt', 'pattern' => '>100,>100']],
		'wildcard decay'     => [['name' => 'someloss', 'type' => 'loss', 'pattern' => '>0%,*12*,>0%,*12*,>0%']],
		'mixed levels'       => [['name' => 'ramp', 'type' => 'loss', 'pattern' => '>10%,>20%']],
		'non-100% equality'  => [['name' => 'half', 'type' => 'loss', 'pattern' => '==50%,==50%']],
	]);

	it('parses SmokePing Alerts section from configuration', function () {
		$conf = <<<'CONF'
*** General ***
owner = Ops
contact = ops@example.test
mailhost = mail.example.test
imgcache = /var/cache/smokeping/images
imgurl = /smokeping/images
datadir = /var/lib/smokeping
piddir = /run/smokeping
cgiurl = http://localhost/smokeping.cgi
smokemail = /etc/smokeping/smokemail
tmail = /etc/smokeping/tmail

*** Database ***
step = 300
pings = 20
AVERAGE 0.5 1 1008

*** Presentation ***
template = /etc/smokeping/basepage.html
+ charts
menu = Charts
title = The most interesting destinations
++ stddev
sorter = StdDev(entries=>4)
title = Top Standard Deviation
menu = Std Deviation
format = Standard Deviation %f
+ overview
width = 600
height = 50
range = 10h
+ detail
width = 600
height = 200
unison_tolerance = 2
"Last 3 Hours"    3h
"Last 30 Hours"   30h
"Last 10 Days"    10d
"Last 400 Days"   400d

*** Probes ***
+ FPing
binary = /usr/bin/fping

*** Alerts ***
to = ops@example.test
from = smokeping@example.test
+highloss
type = loss
pattern = >10%,>10%
comment = sustained packet loss

*** Targets ***
probe = FPing
menu = Top
title = Top
+ Europe
menu = Europe
title = Europe
++ paris
host = 192.0.2.1
alerts = highloss
CONF;
		$root   = (new SmokepingConfigParser())->parseString($conf, 'test.conf');
		$alerts = TholdTranslator::parseAlerts($root);

		expect($alerts)->toHaveKey('highloss')
			->and($alerts['highloss']['type'])->toBe('loss')
			->and($alerts['highloss']['pattern'])->toBe('>10%,>10%');
	});
});
