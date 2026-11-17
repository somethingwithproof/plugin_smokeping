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
