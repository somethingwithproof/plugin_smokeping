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

use Somethingwithproof\Smokeping\AbilitiesProvider;
use Somethingwithproof\Smokeping\ApiResources;
use Somethingwithproof\Smokeping\CascProvider;
use Somethingwithproof\Smokeping\ConfigCodec;
use Somethingwithproof\Smokeping\ConfigRepository;
use Somethingwithproof\Smokeping\ConfigService;
use Somethingwithproof\Smokeping\DoctorChecks;
use Somethingwithproof\Smokeping\FixedClock;
use Somethingwithproof\Smokeping\FreshnessEvaluator;
use Somethingwithproof\Smokeping\Health;
use Somethingwithproof\Smokeping\HealthRepository;
use Somethingwithproof\Smokeping\MountCheck;
use Somethingwithproof\Smokeping\ObjectRepository;
use Somethingwithproof\Smokeping\ObservationRepository;
use Somethingwithproof\Smokeping\OrphanRrdScanner;
use Somethingwithproof\Smokeping\RemoteReplication;
use Somethingwithproof\Smokeping\Schema;
use Somethingwithproof\Smokeping\Settings;
use Somethingwithproof\Smokeping\TargetRepository;

require_once dirname(__DIR__) . '/Support/OperatorStubs.php';

describe('Ecosystem hook registrations', function () {
	afterEach(function () {
		$GLOBALS['__operator_enabled']     = true;
		$GLOBALS['__operator_devices']     = [];
		$GLOBALS['__smokeping_db_assoc']   = [];
		$GLOBALS['__smokeping_db_row']     = [];
		$GLOBALS['__smokeping_db_cell']    = 0;
		$GLOBALS['__smokeping_db_queries'] = [];
		$GLOBALS['__smokeping_options']    = [];
	});

	it('declares all ecosystem hooks in Schema::HOOKS including doctor_register_debug_info and api_register_resources', function () {
		expect(Schema::HOOKS)->toHaveKey('abilities_register', 'smokeping_abilities_register')
			->and(Schema::HOOKS)->toHaveKey('api_register_resources', 'smokeping_api_register_resources')
			->and(Schema::HOOKS)->toHaveKey('doctor_register_checks', 'smokeping_doctor_register_checks')
			->and(Schema::HOOKS)->toHaveKey('doctor_register_debug_info', 'smokeping_doctor_register_debug_info')
			->and(Schema::HOOKS)->toHaveKey('casc_register_providers', 'smokeping_casc_register_providers')
			->and(Schema::HOOKS)->toHaveKey('replicate_out', 'smokeping_replicate_out')
			->and(Schema::HOOKS)->toHaveCount(23);

		foreach ([
			'smokeping_abilities_register',
			'smokeping_api_register_resources',
			'smokeping_doctor_register_checks',
			'smokeping_doctor_register_debug_info',
			'smokeping_casc_register_providers',
			'smokeping_replicate_out',
		] as $fn) {
			expect(function_exists($fn))->toBeTrue($fn);
		}
	});

	it('wires setup.php hooks cleanly when enabled and returns early when disabled', function () {
		$GLOBALS['__operator_enabled']  = true;
		$GLOBALS['__operator_devices']  = [['id' => 7, 'description' => 'Edge Router']];
		$GLOBALS['__smokeping_db_cell'] = static function (string $sql): mixed {
			if (str_contains($sql, 'FROM user_auth WHERE')) {
				return 'on';
			}

			return str_contains($sql, 'user_auth_realm') ? 1 : 0;
		};
		$GLOBALS['__smokeping_db_assoc'] = [];
		$GLOBALS['__smokeping_db_row']   = [];

		$registrar = new class {
			/** @var array<string, string> */
			public array $categories = [];
			/** @var array<string, array<string, mixed>> */
			public array $abilities = [];

			public function registerCategory(string $id, string $label): void {
				$this->categories[$id] = $label;
			}

			/** @param array<string, mixed> $def */
			public function register(string $id, array $def): void {
				$this->abilities[$id] = $def;
			}
		};

		smokeping_abilities_register($registrar);
		expect($registrar->abilities)->toHaveKeys(['smokeping-list-targets', 'smokeping-get-health'])
			->and(($registrar->abilities['smokeping-list-targets']['permission'])(42))->toBeTrue()
			->and(($registrar->abilities['smokeping-list-targets']['execute'])(42, new stdClass()))->toBe(['total' => 0, 'targets' => []]);

		$doctorChecks = smokeping_doctor_register_checks([]);
		expect($doctorChecks)->toHaveCount(4);

		$debugSections = smokeping_doctor_register_debug_info([]);
		expect($debugSections)->toHaveKey('smokeping')
			->and($debugSections['smokeping']['fields']['config_path']['value'])->toBe('[redacted]')
			->and($debugSections['smokeping']['fields']['mount_path']['value'])->toBe('[redacted]')
			->and($debugSections['smokeping']['fields']['schema_version']['value'])->toBe((string) Schema::VERSION);

		$apiResources = smokeping_api_register_resources([]);
		expect(array_keys($apiResources))->toBe([
			'smokeping/targets',
			'smokeping/observations',
			'smokeping/health',
		]);

		foreach ($apiResources as $key => $descriptor) {
			expect($descriptor['plugin'])->toBe('smokeping', $key)
				->and($descriptor['read_only'])->toBeTrue($key)
				->and($descriptor['methods'])->toBe(['list', 'get'], $key);
		}

		$cascProviders = smokeping_casc_register_providers([]);
		expect($cascProviders)->toHaveKey('smokeping');

		$repOut = smokeping_replicate_out(['remote_poller_id' => 1, 'rcnn_id' => false, 'class' => 'all']);
		expect($repOut['remote_poller_id'])->toBe(1);

		// Disabled plugin short-circuits all ecosystem hooks safely.
		$GLOBALS['__operator_enabled'] = false;
		$emptyReg                      = new stdClass();
		smokeping_abilities_register($emptyReg);
		expect(smokeping_doctor_register_checks(['keep']))->toBe(['keep'])
			->and(smokeping_doctor_register_debug_info(['keep' => []]))->toBe(['keep' => []])
			->and(smokeping_api_register_resources(['keep' => []]))->toBe(['keep' => []])
			->and(smokeping_casc_register_providers(['keep']))->toBe(['keep'])
			->and(smokeping_replicate_out('not-array'))->toBe('not-array');
	});

	it('supports registrar objects for doctor_register_debug_info and api_register_resources and ships schema YAML with zero cross-plugin imports', function () {
		$settings = Settings::fromRow([
			'smokeping_poller_id'   => '1',
			'smokeping_config_file' => '/secret/internal/smokeping.conf',
			'smokeping_mount_path'  => '/secret/rra/smokeping',
		], '/secret/rra');
		$clock  = new FixedClock(1700001000);
		$doctor = new DoctorChecks(
			$settings,
			new TargetRepository(false),
			new ObservationRepository(false),
			new FreshnessEvaluator($clock, 60),
			new MountCheck(''),
			new OrphanRrdScanner(),
			'/secret/rra',
			false
		);

		$debugReg = new class {
			/** @var array<string, array<string, mixed>> */
			public array $sections = [];

			/** @param array<string, mixed> $section */
			public function register(string $id, array $section): void {
				$this->sections[$id] = $section;
			}
		};

		$doctor->registerDebugInfo($debugReg, Health::never(1), '1.0.0', Schema::VERSION);
		$serialized = (string) json_encode($debugReg->sections);
		expect($debugReg->sections)->toHaveKey('smokeping')
			->and($serialized)->not->toContain('/secret/internal/smokeping.conf')
			->and($serialized)->not->toContain('/secret/rra/smokeping');

		$apiReg = new class {
			/** @var array<string, array<string, mixed>> */
			public array $resources = [];

			/** @param array<string, mixed> $descriptor */
			public function register(string $id, array $descriptor): void {
				$this->resources[$id] = $descriptor;
			}
		};

		ApiResources::register($apiReg);
		expect(array_keys($apiReg->resources))->toBe([
			'smokeping/targets',
			'smokeping/observations',
			'smokeping/health',
		]);

		// Verify static Drupal-style schema separates config from state cleanly.
		$schemaYaml = (string) file_get_contents(dirname(__DIR__, 2) . '/schema/smokeping.schema.yaml');
		expect($schemaYaml)->toContain('plugin: smokeping')
			->and($schemaYaml)->toContain("\nconfig:\n")
			->and($schemaYaml)->toContain("\nstate:\n")
			->and($schemaYaml)->toContain('smokeping_poller_id:')
			->and($schemaYaml)->toContain('plugin_smokeping_observation:');

		// Zero cross-plugin PHP imports in setup.php, DoctorChecks.php, or ApiResources.php.
		foreach (['setup.php', 'lib/DoctorChecks.php', 'lib/ApiResources.php'] as $rel) {
			$src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
			expect($src)->not->toMatch('/plugin_(doctor|api)\b/i', $rel)
				->and($src)->not->toMatch('/use\s+[^\n;]*(Doctor|ApiPlugin)\\\\/i', $rel);
		}
	});
});

describe('AbilitiesProvider (abilities_register)', function () {
	afterEach(function () {
		$GLOBALS['__smokeping_db_assoc'] = [];
		$GLOBALS['__smokeping_db_row']   = [];
	});

	it('registers strictly read-only smokeping-list-targets and smokeping-get-health gated by View realm', function () {
		$now      = 1700001000;
		$clock    = new FixedClock($now);
		$settings = Settings::fromRow(['smokeping_poller_id' => '1', 'smokeping_freshness_allowance' => '60'], '/rra');

		$GLOBALS['__smokeping_db_assoc'] = static function (string $sql): array {
			if (str_contains($sql, 'plugin_smokeping_mapping')) {
				return [[
					'id' => 11, 'poller_id' => 1, 'path' => 'Europe/paris', 'host' => 'paris.example',
					'probe' => 'FPing', 'title' => 'Paris', 'step' => 300, 'pings' => 20,
					'present' => 1, 'first_seen' => 1700000000, 'mapped_host_id' => 7,
				]];
			}

			return [[
				'target_id' => 11, 'location' => '', 'observed_at' => 1700000950, 'ingested_at' => 1700000960,
				'expected_interval' => 300, 'pings' => 20, 'loss' => 0, 'median' => 0.012, 'file_present' => 1,
			]];
		};
		$GLOBALS['__smokeping_db_row'] = [
			'poller_id' => 1, 'run_started' => 1700000900, 'run_finished' => 1700000955,
			'run_duration' => 1.2, 'series_total' => 1, 'series_read' => 1,
			'budget_overruns' => 0, 'inventory_at' => 1700000900, 'inventory_status' => 'ok', 'inventory_targets' => 1,
		];

		$provider = new AbilitiesProvider(
			$settings,
			new TargetRepository(false),
			new ObservationRepository(false),
			new HealthRepository(false),
			new FreshnessEvaluator($clock, 60),
			$clock,
			300,
			static fn (int $userId): bool => $userId === 42,
			static fn (int $userId): array => $userId === 42 ? [7 => 'Edge Router'] : []
		);

		$registrar = new class {
			/** @var array<string, string> */
			public array $categories = [];
			/** @var array<string, array<string, mixed>> */
			public array $abilities = [];

			public function registerCategory(string $id, string $label): void {
				$this->categories[$id] = $label;
			}

			/** @param array<string, mixed> $def */
			public function register(string $id, array $def): void {
				$this->abilities[$id] = $def;
			}
		};

		$provider->register($registrar);

		expect($registrar->categories)->toBe(['smokeping' => 'SmokePing'])
			->and(array_keys($registrar->abilities))->toBe(['smokeping-list-targets', 'smokeping-get-health']);

		foreach ($registrar->abilities as $id => $def) {
			expect($def['annotations'])->toBe(['readOnly' => true, 'destructive' => false, 'idempotent' => true], $id)
				->and($def['input_schema']['additionalProperties'])->toBeFalse($id)
				->and($def['output_schema']['additionalProperties'])->toBeFalse($id)
				->and(($def['permission'])(42))->toBeTrue($id)
				->and(($def['permission'])(99))->toBeFalse($id)
				->and(($def['permission'])(0))->toBeFalse($id);
		}

		$list = ($registrar->abilities['smokeping-list-targets']['execute'])(42, (object) ['search' => 'paris', 'limit' => 10]);
		expect($list['total'])->toBe(1)
			->and($list['targets'][0]['path'])->toBe('Europe/paris')
			->and($list['targets'][0]['freshness'])->toBe('current')
			->and($list['targets'][0]['median_ms'])->toBe(12.0);

		$deniedList = ($registrar->abilities['smokeping-list-targets']['execute'])(99, new stdClass());
		expect($deniedList)->toBe(['total' => 0, 'targets' => []]);

		$health = ($registrar->abilities['smokeping-get-health']['execute'])(42, new stdClass());
		expect($health['poller_id'])->toBe(1)
			->and($health['status'])->toBe('ok')
			->and($health['freshness_breaches'])->toBe(0)
			->and($health['stale_series'])->toBe(0);

		// Probe and search non-matches, denied health, error/never/warning health states, and non-registrar object.
		expect(($registrar->abilities['smokeping-list-targets']['execute'])(42, (object) ['probe' => 'DNS']))->toBe(['total' => 0, 'targets' => []])
			->and(($registrar->abilities['smokeping-list-targets']['execute'])(42, (object) ['search' => 'nomatch']))->toBe(['total' => 0, 'targets' => []])
			->and(($registrar->abilities['smokeping-get-health']['execute'])(99, new stdClass())['status'])->toBe('unknown');

		$GLOBALS['__smokeping_db_row']['inventory_status'] = 'error';
		expect(($registrar->abilities['smokeping-get-health']['execute'])(42, new stdClass())['status'])->toBe('error');

		$GLOBALS['__smokeping_db_row']['inventory_status'] = 'never';
		expect(($registrar->abilities['smokeping-get-health']['execute'])(42, new stdClass())['status'])->toBe('unknown');

		$GLOBALS['__smokeping_db_row']['inventory_status'] = 'ok';
		$GLOBALS['__smokeping_db_row']['budget_overruns']  = 2;
		expect(($registrar->abilities['smokeping-get-health']['execute'])(42, new stdClass())['status'])->toBe('warning');

		$provider->register(new stdClass());
	});
});

describe('DoctorChecks (doctor_register_checks)', function () {
	beforeEach(function () {
		$this->root = sys_get_temp_dir() . '/smokeping-doctor-' . getmypid() . '-' . random_int(0, PHP_INT_MAX);
		mkdir($this->root . '/rra/smokeping/Europe', 0755, true);
		touch($this->root . '/rra/smokeping/Europe/paris.rrd');
		touch($this->root . '/rra/smokeping/Europe/orphan.rrd');
		$this->root = (string) realpath($this->root);
	});

	afterEach(function () {
		exec('rm -rf ' . escapeshellarg($this->root));
		$GLOBALS['__smokeping_db_assoc'] = [];
	});

	it('reports stale targets, freshness breaches, orphan RRDs, and config path health', function () {
		$now      = 1700001000;
		$clock    = new FixedClock($now);
		$settings = Settings::fromRow([
			'smokeping_poller_id'           => '1',
			'smokeping_config_file'         => $this->root . '/missing.conf',
			'smokeping_mount_path'          => $this->root . '/rra/smokeping',
			'smokeping_freshness_allowance' => '60',
		], $this->root . '/rra');

		$GLOBALS['__smokeping_db_assoc'] = static function (string $sql): array {
			if (str_contains($sql, 'plugin_smokeping_target')) {
				return [[
					'id' => 1, 'poller_id' => 1, 'path' => 'Europe/paris', 'host' => 'paris.example',
					'probe' => 'FPing', 'step' => 300, 'pings' => 20, 'present' => 1, 'admin_state' => 'enabled',
				]];
			}

			return [[
				'target_id' => 1, 'location' => '', 'observed_at' => 1700000000, 'ingested_at' => 1700000100,
				'expected_interval' => 300, 'pings' => 20, 'loss' => 0, 'median' => 0.01, 'file_present' => 1,
			]];
		};

		$doctor = new DoctorChecks(
			$settings,
			new TargetRepository(false),
			new ObservationRepository(false),
			new FreshnessEvaluator($clock, 60),
			new MountCheck(''),
			new OrphanRrdScanner(),
			$this->root . '/rra',
			true
		);

		$checks = $doctor->register([]);
		$byId   = [];

		foreach ($checks as $entry) {
			$byId[$entry['id']] = $entry;
		}

		expect(array_keys($byId))->toBe([
			'smokeping_stale_targets',
			'smokeping_freshness_breaches',
			'smokeping_orphan_rrds',
			'smokeping_config_path',
		])
			->and($byId['smokeping_stale_targets']['status'])->toBe('warn')
			->and($byId['smokeping_freshness_breaches']['status'])->toBe('warn')
			->and($byId['smokeping_orphan_rrds']['status'])->toBe('warn')
			->and($byId['smokeping_orphan_rrds']['detail'])->toContain('Europe/orphan.rrd')
			->and($byId['smokeping_config_path']['status'])->toBe('fail');

		// Object registrar, non-reader host, healthy targets, and clean mount paths.
		$objReg = new class {
			/** @var array<string, array<string, mixed>> */
			public array $entries = [];

			/** @param array<string, mixed> $entry */
			public function register(string $id, array $entry): void {
				$this->entries[$id] = $entry;
			}
		};
		$doctor->register($objReg);
		expect(array_keys($objReg->entries))->toHaveCount(4);

		unlink($this->root . '/rra/smokeping/Europe/orphan.rrd');
		touch($this->root . '/smokeping.conf');
		$GLOBALS['__smokeping_db_assoc'] = static function (string $sql): array {
			if (str_contains($sql, 'plugin_smokeping_target')) {
				return [[
					'id' => 1, 'poller_id' => 1, 'path' => 'Europe/paris', 'host' => 'paris.example',
					'probe' => 'FPing', 'step' => 300, 'pings' => 20, 'present' => 1, 'admin_state' => 'enabled',
				]];
			}

			return [[
				'target_id' => 1, 'location' => '', 'observed_at' => 1700000950, 'ingested_at' => 1700000960,
				'expected_interval' => 300, 'pings' => 20, 'loss' => 0, 'median' => 0.01, 'file_present' => 1,
			]];
		};

		$okSettings = Settings::fromRow([
			'smokeping_poller_id'           => '1',
			'smokeping_config_file'         => $this->root . '/smokeping.conf',
			'smokeping_mount_path'          => $this->root . '/rra/smokeping',
			'smokeping_freshness_allowance' => '60',
		], $this->root . '/rra');
		$okDoctor = new DoctorChecks($okSettings, new TargetRepository(false), new ObservationRepository(false), new FreshnessEvaluator($clock, 60), new MountCheck(''), new OrphanRrdScanner(), $this->root . '/rra', true);
		$okById   = array_column($okDoctor->run(), null, 'id');
		expect($okById['smokeping_stale_targets']['status'])->toBe('ok')
			->and($okById['smokeping_freshness_breaches']['status'])->toBe('ok')
			->and($okById['smokeping_orphan_rrds']['status'])->toBe('ok');

		// Non-reader host + empty targets
		$GLOBALS['__smokeping_db_assoc'] = [];
		$remoteDoctor                    = new DoctorChecks($okSettings, new TargetRepository(false), new ObservationRepository(false), new FreshnessEvaluator($clock, 60), new MountCheck(''), new OrphanRrdScanner(), $this->root . '/rra', false);
		$remoteById                      = array_column($remoteDoctor->run(), null, 'id');
		expect($remoteById['smokeping_stale_targets']['status'])->toBe('unknown')
			->and($remoteById['smokeping_freshness_breaches']['status'])->toBe('unknown')
			->and($remoteById['smokeping_orphan_rrds']['status'])->toBe('unknown');

		// Missing mount on reader host -> fail
		$badMountSettings = Settings::fromRow([
			'smokeping_poller_id'   => '1',
			'smokeping_config_file' => $this->root . '/smokeping.conf',
			'smokeping_mount_path'  => $this->root . '/rra/missing_mount',
		], $this->root . '/rra');
		$badMountDoctor = new DoctorChecks($badMountSettings, new TargetRepository(false), new ObservationRepository(false), new FreshnessEvaluator($clock, 60), new MountCheck(''), new OrphanRrdScanner(), $this->root . '/rra', true);
		$badById        = array_column($badMountDoctor->run(), null, 'id');
		expect($badById['smokeping_orphan_rrds']['status'])->toBe('fail');
	});
});

describe('CascProvider (casc_register_providers)', function () {
	afterEach(function () {
		$GLOBALS['__smokeping_db_assoc'] = [];
		$GLOBALS['__smokeping_db_exec']  = [];
	});

	it('bridges export, validate, plan, and apply directly through ConfigCodec and ConfigService', function () {
		$GLOBALS['__smokeping_db_assoc'] = static function (string $sql): array {
			if (str_contains($sql, 'FROM poller')) {
				return [['id' => 1]];
			}

			return [];
		};

		$service  = new ConfigService(new ConfigRepository(false, '/rra'));
		$provider = new CascProvider($service, new FixedClock(1700000000));
		$registry = $provider->register([]);

		expect($registry)->toHaveKey('smokeping')
			->and($registry['smokeping']['formats'])->toBe([ConfigCodec::YAML, ConfigCodec::XML]);

		$yaml = ($registry['smokeping']['export'])('yaml');
		expect($yaml)->toContain('schema_version: 1');

		$valid = ($registry['smokeping']['validate'])($yaml, 'yaml');
		expect($valid['valid'])->toBeTrue()
			->and($valid['errors'])->toBe([]);

		$plan = ($registry['smokeping']['plan'])($yaml, 'yaml');
		expect($plan['has_errors'])->toBeFalse()
			->and($plan['empty'])->toBeTrue()
			->and($plan['plan_id'])->not->toBe('');

		$bad = ($registry['smokeping']['validate'])("schema_version: 999\n", 'yaml');
		expect($bad['valid'])->toBeFalse()
			->and($bad['errors'])->not->toBe([]);

		$badPlan = ($registry['smokeping']['plan'])("schema_version: 999\n", 'yaml');
		expect($badPlan['has_errors'])->toBeTrue()
			->and($badPlan['plan_id'])->toBe('');

		$badApply = ($registry['smokeping']['apply'])("schema_version: 999\n", 'wrong', 'yaml', 1);
		expect($badApply['applied'])->toBeFalse()
			->and($badApply['errors'])->not->toBe([]);

		$mismatchApply = ($registry['smokeping']['apply'])($yaml, '0000000000000000', 'yaml', 1);
		expect($mismatchApply['applied'])->toBeFalse();

		$okApply = ($registry['smokeping']['apply'])($yaml, $plan['plan_id'], 'yaml', 1);
		expect($okApply['applied'])->toBeTrue()
			->and($okApply['errors'])->toBe([]);

		// Object registrar support
		$objReg = new class {
			/** @var array<string, array<string, mixed>> */
			public array $providers = [];

			/** @param array<string, mixed> $desc */
			public function register(string $slug, array $desc): void {
				$this->providers[$slug] = $desc;
			}
		};
		$provider->register($objReg);
		expect($objReg->providers)->toHaveKey('smokeping');
	});
});

describe('RemoteReplication (replicate_out)', function () {
	afterEach(function () {
		$GLOBALS['__smokeping_db_assoc']   = [];
		$GLOBALS['__smokeping_db_queries'] = [];
	});

	it('replicates only poller-scoped configuration tables for remote pollers and skips local poller 1', function () {
		$GLOBALS['__smokeping_db_assoc'] = static function (string $sql, array $params): array {
			expect($params)->toBe([2]);

			if (str_contains($sql, 'FROM plugin_smokeping_target')) {
				return [['id' => 5, 'poller_id' => 2, 'path' => 'Remote/node']];
			}

			if (str_contains($sql, 'FROM plugin_smokeping_mapping')) {
				return [['target_id' => 5, 'host_id' => 33]];
			}

			return [];
		};

		$replication = new RemoteReplication(new TargetRepository(false), new ObjectRepository(false));
		$captured    = [];
		$replicator  = static function ($rcnnId, array $rows, string $table, int $pollerId) use (&$captured): void {
			$captured[$table] = ['poller_id' => $pollerId, 'rows' => $rows];
		};

		// Poller 1 (local mode) must not trigger remote table replication.
		$replication->handle(['remote_poller_id' => 1, 'rcnn_id' => 'conn1', 'class' => 'all'], $replicator);
		expect($captured)->toBe([]);

		// Remote poller 2 replicates scoped configuration tables only (no observation/health tables).
		$replication->handle(['remote_poller_id' => 2, 'rcnn_id' => 'conn2', 'class' => 'all'], $replicator);
		expect(array_keys($captured))->toBe([
			'plugin_smokeping_target',
			'plugin_smokeping_mapping',
			'plugin_smokeping_object',
			'plugin_smokeping_relink',
		])
			->and($captured['plugin_smokeping_target']['rows'])->toHaveCount(1)
			->and($captured['plugin_smokeping_mapping']['rows'])->toHaveCount(1);

		expect($replication->payloadForPoller(1))->toBe([]);

		// Non-matching replication class is ignored; poller_id fallback key works.
		$captured = [];
		$replication->handle(['poller_id' => 2, 'rcnn_id' => 'conn2', 'class' => 'hosts'], $replicator);
		expect($captured)->toBe([]);
		$replication->handle(['poller_id' => 2, 'rcnn_id' => 'conn2', 'class' => 'plugins'], $replicator);
		expect(array_keys($captured))->toHaveCount(4);
	});
});
