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

use Somethingwithproof\Smokeping\OperatorPage;
use Somethingwithproof\Smokeping\Pages;
use Somethingwithproof\Smokeping\Schema;

require_once __DIR__ . '/../Support/OperatorStubs.php';
require_once dirname(__DIR__, 2) . '/include/operator.php';

beforeEach(function () {
	$GLOBALS['__operator_enabled'] = true;
	$GLOBALS['__operator_page'] = 'smokeping_operator.php';
	$GLOBALS['__operator_grants'] = [102];
	$GLOBALS['__operator_devices'] = [['id' => '7', 'description' => 'gateway']];
	$GLOBALS['__operator_csrf_failure'] = false;
	$GLOBALS['__operator_request_method'] = $_SERVER['REQUEST_METHOD'] ?? null;
	$GLOBALS['user_auth_realm_filenames'] = ['smokeping.php' => 101, 'smokeping_operator.php' => 102, 'smokeping_inventory.php' => 103];
	$GLOBALS['current_user'] = ['id' => '2', 'enabled' => 'on'];
	$_SESSION = ['sess_user_id' => 2];
	$_REQUEST = [];
	$GLOBALS['__smokeping_db_assoc'] = [];
	$GLOBALS['__smokeping_db_queries'] = [];
	$GLOBALS['__smokeping_db_exec'] = [];
	$GLOBALS['__smokeping_db_cell'] = 0;
	$GLOBALS['__smokeping_log'] = [];
	http_response_code(200);
});

afterEach(function () {
	$GLOBALS['__operator_enabled'] = false;
	smokeping_auth_alternate_realms();
	$GLOBALS['__operator_enabled'] = true;
	$_SESSION = [];
	$_REQUEST = [];
	$GLOBALS['__smokeping_db_assoc'] = [];
	$GLOBALS['__smokeping_db_cell'] = 0;
	if ($GLOBALS['__operator_request_method'] === null) {
		unset($_SERVER['REQUEST_METHOD']);
	} else {
		$_SERVER['REQUEST_METHOD'] = $GLOBALS['__operator_request_method'];
	}
	unset($GLOBALS['current_user']);
});

function smokeping_triage_fixture(): array {
	return ['id' => '7', 'name' => '<script>hidden</script>', 'state' => 'failed', 'observed' => 900, 'interval' => 60, 'detail' => 'timeout'];
}

it('requires an operator, a known action, POST and a valid CSRF token', function () {
	$actions = ['acknowledge', 'retry'];
	expect(OperatorPage::actionStatus(false, 'retry', 'POST', true, $actions))->toBe(403)
		->and(OperatorPage::actionStatus(true, 'delete', 'POST', true, $actions))->toBe(400)
		->and(OperatorPage::actionStatus(true, 'retry', 'GET', true, $actions))->toBe(405)
		->and(OperatorPage::actionStatus(true, 'retry', 'POST', false, $actions))->toBe(403)
		->and(OperatorPage::actionStatus(true, 'retry', 'POST', true, $actions))->toBe(200);
});

it('acknowledges only unchanged, current evidence for the acting user', function () {
	$row         = smokeping_triage_fixture();
	$session     = [];
	$fingerprint = OperatorPage::fingerprint($row);
	expect(OperatorPage::acknowledge($session, $row, $fingerprint, 2, 1000))->toBeTrue()
		->and(OperatorPage::acknowledged($session, $row, 2, 1000))->toBeTrue()
		->and(OperatorPage::acknowledged($session, $row, 3, 1000))->toBeFalse()
		->and(OperatorPage::acknowledged($session, $row, 2, 1021))->toBeFalse()
		->and(OperatorPage::acknowledged($session, array_replace($row, ['state' => 'ok']), 2, 1000))->toBeFalse()
		->and(OperatorPage::acknowledged($session, array_replace($row, ['observed' => 950]), 2, 1000))->toBeFalse()
		->and(OperatorPage::acknowledge($session, $row, 'forged', 2, 1000))->toBeFalse()
		->and(OperatorPage::acknowledge($session, $row, $fingerprint, 0, 1000))->toBeFalse()
		->and(OperatorPage::acknowledge($session, $row, $fingerprint, 2, 800))->toBeFalse();
});

it('bounds personal acknowledgments and drops the previous users entries', function () {
	$session = [];

	for ($i = 1; $i <= 120; $i++) {
		$row = array_replace(smokeping_triage_fixture(), ['id' => (string) $i]);
		OperatorPage::acknowledge($session, $row, OperatorPage::fingerprint($row), 2, 1000);
	}
	expect($session['entries'])->toHaveCount(100)
		->and($session['entries'])->not->toHaveKey('1');
	$row = smokeping_triage_fixture();
	OperatorPage::acknowledge($session, $row, OperatorPage::fingerprint($row), 3, 1000);
	expect($session['entries'])->toHaveCount(1)->and($session['user'])->toBe(3);
});

it('never treats absent or future observations as fresh', function () {
	$row = smokeping_triage_fixture();
	expect(OperatorPage::fresh(array_replace($row, ['observed' => 0]), 1000))->toBeFalse()
		->and(OperatorPage::fresh(array_replace($row, ['observed' => 1001]), 1000))->toBeFalse()
		->and(OperatorPage::fresh($row, 1020))->toBeTrue()
		->and(OperatorPage::fresh($row, 1021))->toBeFalse();
});

it('ignores malformed session acknowledgments without hiding current health', function () {
	$row = smokeping_triage_fixture();
	expect(OperatorPage::acknowledged(['user' => 2, 'entries' => 'bad'], $row, 2, 1000))->toBeFalse();
	$session = ['user' => 2, 'entries' => ['7' => ['at' => 'invalid']]];
	expect(OperatorPage::acknowledge($session, $row, OperatorPage::fingerprint($row), 2, 1000))->toBeTrue()
		->and($row['state'])->toBe('failed');
});

function smokeping_operator_database(int $now): void {
	$GLOBALS['__smokeping_db_assoc'] = function (string $sql, array $params) use ($now): array {
		if (str_contains($sql, 'plugin_smokeping_mapping')) {
			expect($params)->toBe([7]);

			return [
				['id' => 11, 'path' => 'Network/gateway', 'title' => '<script>gateway</script>', 'mapped_host_id' => 7],
				['id' => 12, 'path' => 'Network/quiet', 'mapped_host_id' => 7],
			];
		}
		expect($sql)->toContain('plugin_smokeping_observation')
			->and($params)->toBe([11, 12]);

		return [
			['target_id' => 11, 'location' => '', 'observed_at' => $now - 30, 'expected_interval' => 300, 'pings' => 20, 'loss' => 20, 'file_present' => 1],
			['target_id' => 11, 'location' => 'remote', 'observed_at' => $now - 900, 'expected_interval' => 300, 'pings' => 20, 'loss' => 0, 'median' => 0.02, 'file_present' => 1],
		];
	};
}

it('reads only mapped targets in the current device scope and preserves separate freshness and loss', function () {
	$now = time();
	smokeping_operator_database($now);
	$rows = smokeping_operator_rows();

	expect(array_column($rows, 'id'))->toBe(['11:', '11:remote'])
		->and($rows[0]['name'])->toBe('<script>gateway</script> / Master')
		->and($rows[0]['state'])->toBe('current / complete_loss')
		->and($rows[1]['state'])->toBe('stale / unknown')
		->and($rows[0]['observed'])->toBe($now - 30)
		->and($rows[0]['interval'])->toBe(300)
		->and($rows[0]['detail'])->toContain('Escalate missing files, imports and relinking to an administrator.')
		->and($GLOBALS['__smokeping_db_exec'])->toBe([]);
});

it('reads no cache rows when no devices are allowed', function () {
	$GLOBALS['__operator_devices'] = [];

	expect(smokeping_operator_rows())->toBe([])
		->and($GLOBALS['__smokeping_db_queries'])->toBe([]);
});

it('does not turn a mapping without a target identity into an observation', function () {
	$GLOBALS['__smokeping_db_assoc'] = [['path' => 'Network/incomplete', 'mapped_host_id' => 7]];

	expect(smokeping_operator_rows())->toBe([])
		->and($GLOBALS['__smokeping_db_queries'])->toHaveCount(1)
		->and($GLOBALS['__smokeping_db_exec'])->toBe([]);
});

it('renders escaped current, acknowledged, stale and absent observations without changing health', function () {
	$row = smokeping_triage_fixture();
	$ack = array_replace($row, ['id' => 'ack']);
	$stale = array_replace($row, ['id' => 'stale', 'observed' => 1]);
	$never = array_replace($row, ['id' => 'never', 'observed' => 0]);
	$session = [];
	OperatorPage::acknowledge($session, $ack, OperatorPage::fingerprint($ack), 2, 1000);
	ob_start();
	OperatorPage::render([$row, $ack, $stale, $never], $session, 2, 1000, ['inspect"' => '<Inspect>']);
	$html = (string) ob_get_clean();

	expect($html)->not->toContain('<script>hidden</script>')
		->and($html)->toContain('&lt;script&gt;hidden&lt;/script&gt;', 'Acknowledged; health unchanged.', 'failed / Unknown or stale', '>Never<', 'inspect&quot;', '&lt;Inspect&gt;', 'method="post"', 'name="fingerprint"')
		->and(substr_count($html, 'name="action" value="acknowledge"'))->toBe(1)
		->and($ack['state'])->toBe('failed');
	ob_start();
	OperatorPage::render([], [], 2, 1000, []);
	expect((string) ob_get_clean())->toContain('No observations are available for devices you may access.');
});

it('expires acknowledgments after one hour even while a long-interval observation stays current', function () {
	$row = array_replace(smokeping_triage_fixture(), ['interval' => 7200]);
	$session = [];
	OperatorPage::acknowledge($session, $row, OperatorPage::fingerprint($row), 2, 1000);

	expect(OperatorPage::acknowledged($session, $row, 2, 4600))->toBeTrue()
		->and(OperatorPage::fresh($row, 4601))->toBeTrue()
		->and(OperatorPage::acknowledged($session, $row, 2, 4601))->toBeFalse();
	$session['entries']['expired'] = ['at' => 1];
	$session['entries']['future'] = ['at' => 5000];
	$session['entries']['malformed'] = 'invalid';
	OperatorPage::acknowledge($session, $row, OperatorPage::fingerprint($row), 2, 4601);
	expect(array_keys($session['entries']))->toBe([7]);
});

it('inherits only less privileged pages before and after a restored login', function (array $grants, array $expected) {
	$GLOBALS['__operator_grants'] = $grants;
	$native = $GLOBALS['user_auth_realm_filenames'];

	foreach (array_keys($native) as $index => $page) {
		$GLOBALS['__operator_page'] = $page;
		foreach ([false, true] as $restore) {
			$GLOBALS['user_auth_realm_filenames'] = $native;
			$_SESSION = $restore ? [] : ['sess_user_id' => 2];
			smokeping_auth_alternate_realms();
			if ($restore) {
				expect($GLOBALS['user_auth_realm_filenames'][$page] === -1)->toBe($index < 2);
				$_SESSION = ['sess_user_id' => 2];
			}
			expect(smokeping_page_allowed())->toBe($expected[$index])
				->and($GLOBALS['user_auth_realm_filenames'][$page])->toBeGreaterThan(0);
		}
	}
})->with([
	'viewer' => [[101], [true, false, false]],
	'operator' => [[102], [true, true, false]],
	'administrator' => [[103], [true, true, true]],
	'no grant' => [[], [false, false, false]],
]);

it('rejects disabled, missing and mismatched authenticated accounts', function ($current, $sessionId) {
	$GLOBALS['current_user'] = $current;
	$_SESSION = ['sess_user_id' => $sessionId];

	expect(smokeping_page_allowed())->toBeFalse()
		->and(smokeping_page_start())->toBeFalse()
		->and(http_response_code())->toBe(403);
})->with([
	'disabled' => [['id' => 2, 'enabled' => ''], 2],
	'missing' => [[], 2],
	'wrong account' => [['id' => 3, 'enabled' => 'on'], 2],
	'invalid account id' => [['id' => '2suffix', 'enabled' => 'on'], 2],
	'invalid session id' => [['id' => 2, 'enabled' => 'on'], '2suffix'],
	'no login' => [['id' => 2, 'enabled' => 'on'], null],
]);

it('fails closed for missing realm registrations and restores native mappings when disabled', function () {
	unset($GLOBALS['user_auth_realm_filenames']['smokeping_operator.php']);
	expect(smokeping_page_allowed())->toBeFalse();
	$GLOBALS['user_auth_realm_filenames']['smokeping_operator.php'] = 102;
	$_SESSION = [];
	smokeping_auth_alternate_realms();
	$GLOBALS['__operator_enabled'] = false;
	$_SESSION = ['sess_user_id' => 2];

	expect(smokeping_page_allowed())->toBeFalse()
		->and($GLOBALS['user_auth_realm_filenames']['smokeping_operator.php'])->toBe(102);
});

it('registers the operator realm without an automatic administrator grant and retains disabled hook state', function (int $status) {
	$GLOBALS['__smokeping_db_cell'] = $status;
	$GLOBALS['__operator_hooks'] = [];
	$GLOBALS['__operator_registered_realms'] = [];
	smokeping_setup_hooks();

	expect($GLOBALS['__operator_hooks'])->toHaveCount(count(Schema::HOOKS))
		->and($GLOBALS['__operator_hooks']['auth_alternate_realms'])->toBe(['smokeping_auth_alternate_realms', 'setup.php', $status === 1])
		->and(array_column($GLOBALS['__operator_hooks'], 2))->toBe(array_fill(0, count(Schema::HOOKS), $status === 1))
		->and($GLOBALS['__operator_registered_realms']['smokeping_operator.php'])->toBe(['SmokePing Operator', false])
		->and($GLOBALS['__operator_registered_realms']['smokeping.php'])->toBe(['SmokePing View', true])
		->and(Pages::files())->toContain('smokeping_operator.php')
		->and(Pages::menu()['smokeping_operator.php'])->toBe('SmokePing Operations');
	smokeping_config_settings();
	expect($GLOBALS['settings'])->toHaveKey('smokeping');
})->with([0, 1]);

it('refreshes settings only on settings navigation and preserves existing Cacti entries', function (string $page, bool $refresh) {
	$GLOBALS['__operator_page'] = $page;
	$GLOBALS['__operator_grants'] = [103];
	$tabs = $GLOBALS['tabs'] ?? [];
	$settings = $GLOBALS['settings'] ?? [];
	$GLOBALS['tabs'] = ['general' => 'General'];
	$GLOBALS['settings'] = ['general' => ['existing' => true]];

	try {
		$navigation = smokeping_draw_navigation_text(['index.php:' => ['title' => 'Console']]);

		expect($navigation['index.php:'])->toBe(['title' => 'Console'])
			->and($navigation['smokeping_operator.php:']['title'])->toBe('SmokePing Operations')
			->and($GLOBALS['tabs']['general'])->toBe('General')
			->and($GLOBALS['settings']['general'])->toBe(['existing' => true])
			->and(isset($GLOBALS['tabs']['smokeping']))->toBe($refresh)
			->and(isset($GLOBALS['settings']['smokeping']))->toBe($refresh);
		if ($refresh) {
			expect($GLOBALS['tabs']['smokeping'])->toBe('SmokePing')
				->and($GLOBALS['settings']['smokeping'])->toHaveKey('smokeping_freshness_allowance');
		}
	} finally {
		$GLOBALS['tabs'] = $tabs;
		$GLOBALS['settings'] = $settings;
	}
})->with(['monitoring' => ['smokeping.php', false], 'settings' => ['settings.php', true]]);

function smokeping_test_entrypoint(string $page): string {
	$root = dirname(__DIR__, 2);
	$fixture = sys_get_temp_dir() . '/smokeping-operator-' . bin2hex(random_bytes(6));
	mkdir($fixture . '/include', 0700, true);
	mkdir($fixture . '/plugins/smokeping/include', 0700, true);
	file_put_contents($fixture . '/include/auth.php', '<?php // Identity is supplied by the Cacti boundary stubs.');
	symlink($root . '/setup.php', $fixture . '/plugins/smokeping/setup.php');
	symlink($root . '/include/operator.php', $fixture . '/plugins/smokeping/include/operator.php');
	$cwd = getcwd();
	try {
		chdir($fixture . '/plugins/smokeping');
		ob_start();
		include $root . '/' . $page;
		$html = (string) ob_get_clean();
	} finally {
		chdir($cwd);
		unlink($fixture . '/include/auth.php');
		unlink($fixture . '/plugins/smokeping/setup.php');
		unlink($fixture . '/plugins/smokeping/include/operator.php');
		rmdir($fixture . '/plugins/smokeping/include');
		rmdir($fixture . '/plugins/smokeping');
		rmdir($fixture . '/plugins');
		rmdir($fixture . '/include');
		rmdir($fixture);
	}

	return $html;
}

it('runs the shipped operator entrypoint and redacts repository or CSRF failures', function (string $failure) {
	smokeping_operator_database(time());
	if ($failure === 'repository') {
		$GLOBALS['__smokeping_db_assoc'] = function () {
			throw new RuntimeException('sensitive database detail');
		};
	}
	if ($failure === 'csrf') {
		$GLOBALS['__operator_csrf_failure'] = true;
		$_REQUEST = ['action' => 'acknowledge', 'id' => '11:'];
		$_SERVER['REQUEST_METHOD'] = 'POST';
	}
	$html = smokeping_test_entrypoint('smokeping_operator.php');
	if ($failure !== '') {
		expect(http_response_code())->toBe(503)
			->and($html)->toContain('Observations could not be read. Ask an administrator to check the plugin log.')
			->and($html)->not->toContain('sensitive database detail', 'sensitive CSRF validation detail')
			->and($GLOBALS['__smokeping_log'][0]['message'])->toBe('Operator page failed (RuntimeException)')
			->and($_SESSION)->not->toHaveKey('smokeping_triage');
	} else {
		expect(http_response_code())->toBe(200)
			->and($html)->toContain('Monitoring view', '&lt;script&gt;gateway&lt;/script&gt; / Master', 'current / complete_loss', 'stale / unknown')
			->and($GLOBALS['__smokeping_db_exec'])->toBe([])
			->and($_SESSION)->not->toHaveKey('smokeping_triage');
	}
})->with(['render' => '', 'repository failure' => 'repository', 'CSRF failure' => 'csrf']);


it('exposes the operations route from the monitoring tab only to operators and administrators without console access', function (int $grant, bool $visible) {
	$GLOBALS['__operator_grants'] = [$grant];
	$GLOBALS['__operator_page'] = 'smokeping.php';
	$html = smokeping_test_entrypoint('smokeping.php');

	expect(http_response_code())->toBe(200)
		->and($GLOBALS['__operator_grants'])->not->toContain(8)
		->and(str_contains($html, '<a href="smokeping_operator.php">SmokePing Operations</a>'))->toBe($visible)
		->and($GLOBALS['__smokeping_db_exec'])->toBe([]);
})->with(['viewer' => [101, false], 'operator' => [102, true], 'administrator' => [103, true]]);
