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
use Somethingwithproof\Smokeping\ConfigPage;
use Somethingwithproof\Smokeping\ConfigPlan;
use Somethingwithproof\Smokeping\DeviceSaveCheck;
use Somethingwithproof\Smokeping\FixedClock;
use Somethingwithproof\Smokeping\HealthRepository;
use Somethingwithproof\Smokeping\ImportPage;
use Somethingwithproof\Smokeping\ImportPlan;
use Somethingwithproof\Smokeping\Inventory;
use Somethingwithproof\Smokeping\InventoryError;
use Somethingwithproof\Smokeping\InventoryForms;
use Somethingwithproof\Smokeping\InventoryReader;
use Somethingwithproof\Smokeping\InventoryStatus;
use Somethingwithproof\Smokeping\LoadEstimate;
use Somethingwithproof\Smokeping\ObjectRepository;
use Somethingwithproof\Smokeping\Observation;
use Somethingwithproof\Smokeping\ObservationRepository;
use Somethingwithproof\Smokeping\ReaderJob;
use Somethingwithproof\Smokeping\RrdLastUpdate;
use Somethingwithproof\Smokeping\RrdReader;
use Somethingwithproof\Smokeping\Settings;
use Somethingwithproof\Smokeping\SmokepingImportOptions;
use Somethingwithproof\Smokeping\SmokepingImportPlan;
use Somethingwithproof\Smokeping\SmokepingImportRepository;
use Somethingwithproof\Smokeping\Target;
use Somethingwithproof\Smokeping\TargetId;
use Somethingwithproof\Smokeping\TargetMode;
use Somethingwithproof\Smokeping\TargetPath;
use Somethingwithproof\Smokeping\TargetRepository;

require_once dirname(__DIR__) . '/Support/OperatorStubs.php';

if (!function_exists('form_dropdown')) {
	function form_dropdown($form_name, $form_previous_value, $form_column_name, $form_column_id, $form_default_value, $form_none_entry, $form_previous_value_id) {
		print '<select name="' . html_escape($form_name) . '"></select>';
	}
}

if (!function_exists('form_hidden_box')) {
	function form_hidden_box($form_name, $form_previous_value, $form_default_value) {
		print '<input type="hidden" name="' . html_escape($form_name) . '" value="' . html_escape($form_previous_value) . '">';
	}
}

if (!function_exists('form_save_buttons')) {
	function form_save_buttons($buttons) {
		print '<button type="submit">Save</button>';
	}
}

if (!function_exists('draw_edit_form')) {
	function draw_edit_form($array) {
		print '<tr><td>edit-form</td></tr>';
	}
}

if (!function_exists('raise_message')) {
	function raise_message($message_id, $message = '', $level = 0) {
		$GLOBALS['__smokeping_messages'][] = ['id' => $message_id, 'message' => $message, 'level' => $level];
	}
}

if (!defined('MESSAGE_LEVEL_INFO')) {
	define('MESSAGE_LEVEL_INFO', 1);
	define('MESSAGE_LEVEL_WARN', 2);
	define('MESSAGE_LEVEL_ERROR', 3);
}

if (!function_exists('db_fetch_insert_id')) {
	function db_fetch_insert_id($db_conn = false) {
		return $GLOBALS['__smokeping_insert_id'] ?? 42;
	}
}

describe('Coverage hardening: DeviceSaveCheck, ConfigPage, InventoryForms, and ImportPage', function () {
	afterEach(function () {
		$GLOBALS['__smokeping_db_cell']  = 0;
		$GLOBALS['__smokeping_db_assoc'] = [];
		$GLOBALS['__smokeping_db_row']   = [];
		$GLOBALS['__smokeping_db_exec']  = [];
		$GLOBALS['__smokeping_db_fail']  = null;
	});

	it('warns only when a device with SmokePing targets is saved on a non-reader poller', function () {
		$check = new DeviceSaveCheck(new TargetRepository(false));

		expect($check->warning(['id' => 0, 'poller_id' => 2], 1))->toBeNull();

		$GLOBALS['__smokeping_db_cell'] = 0;
		expect($check->warning(['id' => 10, 'poller_id' => 2], 1))->toBeNull();

		$GLOBALS['__smokeping_db_cell'] = 2;
		expect($check->warning(['id' => 10, 'poller_id' => 1], 1))->toBeNull()
			->and($check->warning(['id' => 10, 'poller_id' => 3], 1))->toContain('poller 1')
			->and($check->warning(['id' => 10, 'poller_id' => 3], 1))->toContain('poller 3');
	});

	it('renders all ConfigPage screens cleanly', function () {
		expect(ConfigPage::formats())->toHaveKeys(['yaml', 'xml']);

		ob_start();
		ConfigPage::refused();
		ConfigPage::main();
		ConfigPage::plan(null, ['invalid syntax on line 2']);
		ConfigPage::plan(new ConfigPlan([], [], [], [], 0, ['bad poller'], []), []);
		ConfigPage::plan(new ConfigPlan([], [], [], [], 1, [], []), []);
		ConfigPage::plan(new ConfigPlan([['name' => 'smokeping_reader_budget', 'from' => '120', 'to' => '90']], [], [], [], 0, [], []), []);
		$html = (string) ob_get_clean();

		expect($html)->toContain('invalid syntax on line 2')
			->and($html)->toContain('Apply Import')
			->and($html)->toContain('The export holds the plugin settings');
	});

	it('renders InventoryForms add and relink confirmation dialogs', function () {
		$old = new Target(TargetId::fromInt(1), 1, TargetPath::fromString('Europe/old'), 'host.example', 'FPing', 'Old', 'Old', [], false, 300, 20, TargetMode::Observed, AdminState::Enabled, false, 100, 200);
		$new = new Target(TargetId::fromInt(2), 1, TargetPath::fromString('Europe/new'), 'host.example', 'FPing', 'New', 'New', [], false, 300, 20, TargetMode::Observed, AdminState::Enabled, true, 200, 300);

		ob_start();
		InventoryForms::add($new, [7 => 'Edge Router']);
		InventoryForms::relink(9, $old, $new, 0);
		InventoryForms::relink(9, $old, $new, 7);
		$html = (string) ob_get_clean();

		expect($html)->toContain('Europe/new')
			->and($html)->toContain('Dismiss Proposal')
			->and($html)->toContain('Device 7 is mapped to the old target.');
	});

	it('renders all ImportPage form and preview states', function () {
		$options     = new SmokepingImportOptions(SmokepingImportOptions::PER_HOST, 1, 1, 0, ['Europe'], 0, 'SmokePing');
		$noTreeOpts  = new SmokepingImportOptions(SmokepingImportOptions::PER_HOST, 1, 1, 0, ['Europe'], 0, '');
		$load        = new LoadEstimate(2, 2.0, 120.0, 300, 25);

		$actionablePlan = new SmokepingImportPlan(
			'digest1',
			$options,
			['Europe' => 6, 'Europe/West' => 2],
			[],
			[
				['action' => 'create', 'key' => 'k1', 'host_id' => 0, 'description' => 'Router A', 'hostname' => 'a.example', 'targets' => ['t1', 't2', 't3', 't4', 't5', 't6'], 'reason' => ''],
				['action' => 'map', 'key' => 'k2', 'host_id' => 7, 'description' => 'Router B', 'hostname' => 'b.example', 'targets' => ['t7'], 'reason' => ''],
				['action' => 'mapped', 'key' => 'k3', 'host_id' => 8, 'description' => 'Router C', 'hostname' => 'c.example', 'targets' => ['t8'], 'reason' => ''],
				['action' => 'ambiguous', 'key' => 'k4', 'host_id' => 0, 'description' => 'Router D', 'hostname' => 'd.example', 'targets' => ['t9'], 'reason' => 'two devices'],
			],
			[
				['kind' => 'smoke', 'path' => 'Europe/a', 'location' => '', 'exists' => false],
				['kind' => 'summary', 'path' => 'Europe/a', 'location' => 'slave1', 'exists' => false],
			],
			['Europe/z' => 'unsupported probe'],
			['Europe', 'Europe/West'],
			[['branch' => 'Europe', 'device' => 'k1'], ['branch' => '', 'device' => 'k2']],
			[],
			['Uploaded note'],
			$load
		);

		$errorPlan = new SmokepingImportPlan('digest2', $noTreeOpts, ['Europe' => 1], [], [], [], [], [], [], ['Missing template'], [], $load);
		$emptyPlan = new SmokepingImportPlan('digest3', $noTreeOpts, ['Europe' => 1], [], [], [], [], [], [], [], [], $load);

		ob_start();
		ImportPage::refused('Permission denied');
		ImportPage::form(['source' => 'server', 'device_per' => 'host', 'template' => 1, 'poller' => 1, 'site' => 0, 'tree' => 0, 'tree_name' => ''], '/etc/smokeping/config', [1 => 'Main'], [1 => 'SmokePing Target'], [1 => 'HQ'], [1 => 'Default']);
		ImportPage::preview($actionablePlan, ['source' => 'server']);
		ImportPage::preview($errorPlan, ['source' => 'server']);
		ImportPage::preview($emptyPlan, ['source' => 'server']);
		$html = (string) ob_get_clean();

		expect($html)->toContain('Permission denied')
			->and($html)->toContain('and 1 more')
			->and($html)->toContain('Missing template')
			->and($html)->toContain('Every selected target is already in Cacti.');
	});
});

describe('Coverage hardening: ReaderJob', function () {
	afterEach(function () {
		$GLOBALS['__smokeping_db_assoc'] = [];
		$GLOBALS['__smokeping_db_row']   = [];
		$GLOBALS['__smokeping_db_exec']  = [];
		$GLOBALS['__smokeping_db_fail']  = null;
	});

	it('runs inventory refresh, reads series oldest-first, handles RRD errors and time budgets', function () {
		$clock    = new FixedClock(1700001000);
		$settings = Settings::fromRow([
			'smokeping_poller_id'          => '1',
			'smokeping_mount_path'         => '/rra/smokeping',
			'smokeping_reader_budget'      => '10',
			'smokeping_inventory_interval' => '300',
		], '/rra');

		$targetA = new Target(TargetId::fromInt(1), 1, TargetPath::fromString('Europe/a'), 'a.example', 'FPing', 'A', 'A', ['west'], false, 300, 20, TargetMode::Observed, AdminState::Enabled, true, 100, 200);
		$targetB = new Target(null, 1, TargetPath::fromString('Europe/unsaved'), 'u.example', 'FPing', 'U', 'U', [], false, 300, 20, TargetMode::Observed, AdminState::Enabled, true, 100, 200);

		$inventoryReader = new class ($targetA) implements InventoryReader {
			public bool $fail = false;

			public function __construct(private readonly Target $target) {
			}

			public function read(int $pollerId): Inventory {
				if ($this->fail) {
					throw new InventoryError('config unreadable');
				}

				return new Inventory('2.008002', '/var/lib/smokeping', [$this->target], ['warn 1']);
			}
		};

		$rrdReader = new class ($clock) implements RrdReader {
			public bool $closed = false;

			public function __construct(private readonly FixedClock $clock) {
			}

			public function lastUpdate(string $file): ?RrdLastUpdate {
				if (str_contains($file, '~west')) {
					throw new RuntimeException('corrupt rrd');
				}

				$this->clock->advance(1);

				return new RrdLastUpdate(1700000950, ['loss' => 0.0, 'median' => 0.015]);
			}

			public function maxMedian(string $file, int $seconds): ?float {
				return 0.05;
			}

			public function close(): void {
				$this->closed = true;
			}
		};

		$GLOBALS['__smokeping_db_row']   = ['poller_id' => 1, 'inventory_at' => 0];
		$GLOBALS['__smokeping_db_assoc'] = static function (string $sql): array {
			if (str_contains($sql, 'FROM plugin_smokeping_observation')) {
				return [['target_id' => 1, 'location' => 'west', 'ingested_at' => 1700000500]];
			}

			if (str_contains($sql, "admin_state = 'enabled'")) {
				return [[
					'id' => 1, 'poller_id' => 1, 'path' => 'Europe/a', 'host' => 'a.example',
					'probe' => 'FPing', 'slaves' => 'west', 'step' => 300, 'pings' => 20, 'present' => 1, 'admin_state' => 'enabled',
				]];
			}

			return [];
		};

		$job = new ReaderJob(
			1,
			$settings,
			new TargetRepository(false),
			new ObservationRepository(false),
			new HealthRepository(false),
			$inventoryReader,
			$rrdReader,
			$clock
		);

		$result = $job->run(true);
		expect($result->inventoryRan)->toBeTrue()
			->and($result->inventoryOk)->toBeTrue()
			->and($result->seriesTotal)->toBe(2)
			->and($result->seriesRead)->toBe(1)
			->and($result->seriesFailed)->toBe(1)
			->and($result->overrun)->toBeFalse()
			->and($rrdReader->closed)->toBeTrue();

		// Inventory error branch.
		$inventoryReader->fail = true;
		$errResult             = $job->run(true);
		expect($errResult->inventoryRan)->toBeTrue()
			->and($errResult->inventoryOk)->toBeFalse();

		// Inventory skipped when interval not elapsed + budget overrun triggered.
		$inventoryReader->fail         = false;
		$GLOBALS['__smokeping_db_row'] = ['poller_id' => 1, 'inventory_at' => $clock->now() - 10];
		$tightSettings                 = Settings::fromRow(['smokeping_poller_id' => '1', 'smokeping_reader_budget' => '1', 'smokeping_inventory_interval' => '300'], '/rra');
		$tightJob                      = new ReaderJob(1, $tightSettings, new TargetRepository(false), new ObservationRepository(false), new HealthRepository(false), $inventoryReader, $rrdReader, $clock);
		$tightResult                   = $tightJob->run(false);
		expect($tightResult->inventoryRan)->toBeFalse()
			->and($tightResult->overrun)->toBeTrue();

		// applyImport failure path.
		$GLOBALS['__smokeping_db_fail'] = 'INSERT INTO plugin_smokeping_target';
		$failImport                     = $job->run(true);
		expect($failImport->inventoryRan)->toBeTrue()
			->and($failImport->inventoryOk)->toBeFalse();
	});
});

describe('Coverage hardening: repositories and setup.php hooks', function () {
	afterEach(function () {
		$GLOBALS['__smokeping_db_cell']  = 0;
		$GLOBALS['__smokeping_db_assoc'] = [];
		$GLOBALS['__smokeping_db_row']   = [];
		$GLOBALS['__smokeping_db_exec']  = [];
		$GLOBALS['__smokeping_db_fail']  = null;
		$GLOBALS['__smokeping_options']  = [];
		$GLOBALS['__smokeping_messages'] = [];
		$GLOBALS['__operator_enabled']   = true;
	});

	it('exercises ObjectRepository, ObservationRepository, HealthRepository, TargetRepository, and SmokepingImportRepository', function () {
		$GLOBALS['__smokeping_db_cell']  = 7;
		$GLOBALS['__smokeping_db_row']   = [
			'local_data_id' => 101, 'local_graph_id' => 202,
			'target_id' => 1, 'location' => '', 'observed_at' => 1700000900, 'ingested_at' => 1700000910,
			'expected_interval' => 300, 'pings' => 20, 'loss' => 0.0, 'median' => 0.01, 'file_present' => 1, 'first_seen' => 1700000000,
			'poller_id' => 1, 'path' => 'Europe/a', 'host' => 'a.example', 'probe' => 'FPing', 'id' => 1,
			'old_target_id' => 1, 'new_target_id' => 1,
		];
		$GLOBALS['__smokeping_db_assoc'] = [
			[
				'id' => 1, 'host_id' => 7, 'local_data_id' => 101, 'local_graph_id' => 202, 'location' => '',
				'rrd_path' => 'smokeping/Europe/a.rrd', 'sized_limit' => '0.1', 'graph_template_id' => 15,
				'text_format' => 'old', 'pings' => 20, 'step' => 300, 'probe' => 'FPing', 'probe_desc' => 'ICMP',
				'target_id' => 1, 'ingested_at' => 1700000910, 'observed_at' => 1700000900, 'expected_interval' => 300,
				'loss' => 0.0, 'median' => 0.01, 'file_present' => 1, 'poller_id' => 1, 'path' => 'Europe/a',
				'host' => 'a.example', 'name' => 'Main', 'hostname' => 'a.example', 'description' => 'Edge',
				'state' => 'active', 'mapped_host_id' => 7, 'old_target_id' => 1, 'old_path' => 'Europe/old',
				'new_path' => 'Europe/new', 'proposed_at' => 1700000100,
			],
		];

		$obj = new ObjectRepository(false);
		expect($obj->hostsOf(1))->toBe([7])
			->and($obj->active(1, ''))->toBe(['local_data_id' => 101, 'local_graph_id' => 202])
			->and($obj->summary(1, ''))->toBe(['local_data_id' => 101, 'local_graph_id' => 202])
			->and($obj->summariesOf(1))->toHaveCount(1)
			->and($obj->summaryDataSourcesForTarget(1))->toHaveCount(1)
			->and($obj->forPollerRows(1))->toHaveCount(1)
			->and($obj->smokeGraphs())->toHaveCount(1)
			->and($obj->smokeGraphsDue(1, 1700000000, 10))->toHaveCount(1)
			->and($obj->syncProbeLines(202))->toBe(1)
			->and($obj->recordSizing(202, null, 1700000000))->toBeTrue()
			->and($obj->recordSizing(202, '0.2', 1700000000))->toBeTrue()
			->and($obj->graphsBehindTemplates(4, 10))->toBe([202])
			->and($obj->graphsOnTemplates([], 10))->toBe([])
			->and($obj->graphsOnTemplates([15], 10))->toHaveCount(1)
			->and($obj->recordTemplateVersion(202, 4))->toBeTrue()
			->and($obj->recordTemplateFailure(202, 4))->toBeTrue()
			->and($obj->clearTemplateFailures())->toBe(1)
			->and($obj->hostOf(101))->toBe(7)
			->and($obj->owned([]))->toBe([])
			->and($obj->owned([101]))->toBe([101])
			->and($obj->mapTarget(1, 7, 1, 1700000000))->toBeTrue()
			->and($obj->ownedOnDevices([]))->toBe([])
			->and($obj->ownedOnDevices([7]))->toBe([101]);

		$obj->add(1, '', 7, 101, 202, 'smokeping/Europe/a.rrd', ObjectRepository::ACTIVE, 1700000000);
		$obj->repoint(101, 2, 'smokeping/Europe/b.rrd');
		$obj->forget([]);
		$obj->forget([101]);
		$obj->unmapDevices([]);
		$obj->unmapDevices([7]);

		$obs = new ObservationRepository(false);
		expect($obs->store(new Observation(TargetId::fromInt(1), '', 1700000900, 1700000910, 300, 20, 0.0, 0.01, true, '')))->toBeTrue()
			->and($obs->ingestedAt(1))->toHaveKey('1~')
			->and($obs->forTargets([]))->toBe([])
			->and($obs->forTargets([1]))->toHaveKey(1)
			->and($obs->latest(1, ''))->not->toBeNull();

		$health = new HealthRepository(false);
		expect($health->all())->toHaveCount(1);
		$health->recordRun(1, 100, 105, 5.0, 10, 10, false);
		$health->recordInventory(1, 105, InventoryStatus::Ok, '', 10, '2.008002');

		$targets = new TargetRepository(false);
		expect($targets->find(1, 'Europe/a'))->not->toBeNull()
			->and($targets->byId(1))->not->toBeNull()
			->and($targets->unmappedByHost(1, []))->toBe([])
			->and($targets->unmappedByHost(1, ['a.example']))->toHaveKey('a.example')
			->and($targets->deviceHasTargets(7))->toBeTrue()
			->and($targets->mappedHost(1))->toBe(7)
			->and($targets->openProposal(1))->not->toBeNull()
			->and($targets->mappedTo([]))->toBe([])
			->and($targets->mappedTo([7]))->toHaveCount(1)
			->and($targets->mappings())->toBe([1 => 7])
			->and($targets->openRelinkProposals())->toHaveCount(1);

		$targets->unmap(1);
		$targets->decideProposal(1, 'dismissed', 1, 1700000900);

		$importRepo = new SmokepingImportRepository(false);
		$state      = $importRepo->state(1, false, ['a.example'], true);
		expect($state->targets)->toHaveKey('Europe/a')
			->and($importRepo->choices())->toHaveKeys(['pollers', 'templates', 'sites', 'trees'])
			->and($importRepo->treeByName('Main'))->toBe(7)
			->and($importRepo->createTree('SmokePing', 1))->toBe(42);

		$GLOBALS['__smokeping_db_cell'] = 1;
		expect($importRepo->lock())->toBeTrue();
		$importRepo->unlock();
	});

	it('covers setup.php helper and hook entry points', function () {
		expect(smokeping_php_supported())->toBeTrue()
			->and(smokeping_boot())->toBeTrue()
			->and(smokeping_refuse_unsupported_php())->toBeFalse()
			->and(smokeping_int_list(['1', 2, 'bad', []]))->toBe([1, 2, 0, 0])
			->and(smokeping_loss_colors()->value)->toBe('2.8')
			->and(smokeping_rrd_reader())->toBeNull()
			->and(smokeping_data_source_remove([101]))->toBe([101])
			->and(smokeping_device_remove([7]))->toBe([7])
			->and(smokeping_summary_answer('bad'))->toBe('median:U loss_pct:U age:U');

		// Additional setup.php entry points: config_arrays, show_tab, page_start, device_actions, target_actions, import_service, config_service, allowed_device_ids.
		$GLOBALS['menu']                        = [];
		$GLOBALS['__operator_page']             = 'smokeping.php';
		$GLOBALS['__operator_enabled']          = true;
		$GLOBALS['__operator_grants']           = [101, 102];
		$GLOBALS['user_auth_realm_filenames']   = ['smokeping.php' => 101, 'smokeping_inventory.php' => 102, 'smokeping_operator.php' => 103];
		$_SESSION['sess_user_id']               = 5;
		$GLOBALS['current_user']                = ['id' => 5, 'enabled' => 'on'];
		$GLOBALS['config']['url_path']          = '/cacti/';
		$GLOBALS['__smokeping_options']         = ['smokeping_templates_version' => '4'];
		$GLOBALS['__smokeping_db_cell']         = 0;

		smokeping_config_arrays();
		expect($GLOBALS['menu'])->toHaveKey('Management')
			->and($GLOBALS['menu'])->toHaveKey('Import/Export');

		ob_start();
		smokeping_show_tab();
		$tab = (string) ob_get_clean();
		expect($tab)->toContain('tab_smokeping_down.gif');

		expect(smokeping_page_start())->toBeTrue()
			->and(smokeping_user_is_admin())->toBeTrue()
			->and(smokeping_device_action_array(['a' => 'A']))->toHaveKey('smokeping_add')
			->and(smokeping_target_actions())->toBeInstanceOf(\Somethingwithproof\Smokeping\TargetActions::class)
			->and(smokeping_device_actions())->toBeInstanceOf(\Somethingwithproof\Smokeping\DeviceActions::class)
			->and(smokeping_import_service(true))->toBeInstanceOf(\Somethingwithproof\Smokeping\SmokepingImportService::class)
			->and(smokeping_config_service(true))->toBeInstanceOf(\Somethingwithproof\Smokeping\ConfigService::class);

		// device_action_prepare with admin and non-admin.
		ob_start();
		smokeping_device_action_prepare(['drp_action' => 'smokeping_add', 'host_array' => [7]]);
		ob_end_clean();

		$GLOBALS['__operator_grants'] = [];
		ob_start();
		smokeping_device_action_prepare(['drp_action' => 'smokeping_add', 'host_array' => [7]]);
		$deniedPrepare = (string) ob_get_clean();
		expect($deniedPrepare)->toContain('permission');

		expect(smokeping_device_action_execute('smokeping_add'))->toBe('smokeping_add');

		// page_start when disabled renders notice.
		$GLOBALS['__operator_enabled'] = false;
		ob_start();
		expect(smokeping_page_start())->toBeFalse();
		$disabledHtml = (string) ob_get_clean();
		expect($disabledHtml)->toContain('not enabled');

		// smokeping_allowed_device_ids returns null when auth helpers are absent.
		expect(smokeping_allowed_device_ids(new \Somethingwithproof\Smokeping\ConfigRepository(false, '/rra')))->toBeNull();
		expect(smokeping_user_sees_every_device(new \Somethingwithproof\Smokeping\ConfigRepository(false, '/rra')))->toBeFalse();

		// graph_buttons when enabled and device found prints link.
		$GLOBALS['__operator_enabled'] = true;
		$GLOBALS['__operator_grants']  = [101, 102];
		$GLOBALS['__smokeping_db_cell'] = 7;
		ob_start();
		smokeping_graph_buttons([0, ['local_graph_id' => 99]]);
		$btnHtml = (string) ob_get_clean();
		expect($btnHtml)->toContain('smokeping.php');

		// api_device_save warning path
		$GLOBALS['__smokeping_db_cell'] = 1;
		expect(smokeping_api_device_save(['id' => 7, 'poller_id' => 2]))->toBe(['id' => 7, 'poller_id' => 2])
			->and($GLOBALS['__smokeping_messages'])->not->toBe([]);

		// device_action_prepare and execute non-matching actions short-circuit cleanly.
		expect(smokeping_device_action_prepare(['drp_action' => 'other']))->toBe(['drp_action' => 'other'])
			->and(smokeping_device_action_execute('other'))->toBe('other');

		// graph_buttons when disabled returns payload untouched.
		$GLOBALS['__operator_enabled'] = false;
		expect(smokeping_graph_buttons([0, ['local_graph_id' => 1]]))->toBe([0, ['local_graph_id' => 1]]);
	});

	it('covers TargetActions and SmokepingImportService refusal and coordination paths', function () {
		$clock       = new FixedClock(1700001000);
		$settings    = Settings::fromRow(['smokeping_poller_id' => '1', 'smokeping_mount_path' => '/rra/smokeping'], '/rra');
		$objects     = new ObjectRepository(false);
		$cacti       = new \Somethingwithproof\Smokeping\CactiObjectRepository(false);
		$targets     = new TargetRepository(false);
		$provisioner = new \Somethingwithproof\Smokeping\SmokeProvisioner($settings, '/rra', '/tmp', false, $objects, $cacti, $clock, null, \Somethingwithproof\Smokeping\LossColors::Current);
		$actions     = new \Somethingwithproof\Smokeping\TargetActions($provisioner, $targets, $objects, $cacti, '/tmp', $clock);

		$unsaved = new Target(null, 1, TargetPath::fromString('Europe/unsaved'), 'u.example', 'FPing', 'U', 'U', [], false, 300, 20, TargetMode::Observed, AdminState::Enabled, true, 100, 200);
		expect($actions->add($unsaved, 7, 1)->succeeded())->toBeFalse();

		$saved = new Target(TargetId::fromInt(1), 1, TargetPath::fromString('Europe/a'), 'a.example', 'FPing', 'A', 'A', [], false, 300, 20, TargetMode::Observed, AdminState::Enabled, true, 100, 200);
		$GLOBALS['__smokeping_db_cell'] = 99; // already mapped to device 99
		expect($actions->add($saved, 7, 1)->succeeded())->toBeFalse();

		$GLOBALS['__smokeping_db_cell']  = 0;
		$GLOBALS['__smokeping_db_assoc'] = [['id' => 7, 'hostname' => 'other.example', 'description' => 'Other']];
		$bulk                            = $actions->addToDevices([7], 1, 1);
		expect($bulk)->toHaveCount(1)
			->and($bulk[0]->succeeded())->toBeFalse();

		$GLOBALS['__smokeping_db_row'] = [];
		expect($actions->relink(55, 1, 1)->succeeded())->toBeFalse();
		$actions->dismiss(55, 1);

		// SmokepingImportService: restricted user refusal, upload read, plan, lock contention, plan mismatch.
		$importRepo = new SmokepingImportRepository(false);
		$restricted = new \Somethingwithproof\Smokeping\SmokepingImportService($settings, $importRepo, $targets, $actions, $provisioner, $cacti, '/tmp', false, static fn (): bool => false);
		expect(fn () => $restricted->read("host = example.com\n"))->toThrow(InventoryError::class);

		$service = new \Somethingwithproof\Smokeping\SmokepingImportService($settings, $importRepo, $targets, $actions, $provisioner, $cacti, '/tmp', false, static fn (): bool => true);
		expect($service->readerPollerId())->toBe(1)
			->and($service->defaultTemplateId())->toBe(0);

		$cfg            = (string) file_get_contents(dirname(__DIR__) . '/Fixtures/inventory.conf');
		[$inv, $digest] = $service->read($cfg, 'upload.conf');
		expect($inv->targets)->not->toBe([])
			->and($digest)->not->toBe('');

		$opts = new SmokepingImportOptions(SmokepingImportOptions::PER_HOST, 1, 1, 0, [], 0, '');
		$plan = $service->plan($inv, $digest, $opts, true);
		expect($plan->notes)->not->toBe([]);

		// Lock contention refusal
		$GLOBALS['__smokeping_db_cell'] = 0;
		[$done, $refusals]              = $service->apply($inv, $digest, $opts, true, $plan->id(), 1, true);
		expect($done)->toBe([])
			->and($refusals)->not->toBe([]);

		// Lock acquired + mismatched plan id refusal
		$GLOBALS['__smokeping_db_cell'] = 1;
		[$done2, $refusals2]            = $service->apply($inv, $digest, $opts, true, '0000000000000000', 1, true);
		expect($done2)->toBe([])
			->and($refusals2)->not->toBe([]);
	});
});
