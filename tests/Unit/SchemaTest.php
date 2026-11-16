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

use Somethingwithproof\Smokeping\Schema;

/**
 * Schema migrations against the recording database stubs of
 * bootstrap-unit.php. tests/Integration/LifecycleTest.php runs them on
 * MariaDB.
 */

$GLOBALS['__schema_columns'] = [];

if (!function_exists('api_plugin_db_table_create')) {
	function api_plugin_db_table_create($plugin, $table, $data) {
	}
}

if (!function_exists('db_add_column')) {
	function db_add_column($table, $column, $log = true, $db_conn = false) {
		$GLOBALS['__schema_columns'][] = $table . '.' . $column['name'];

		return true;
	}
}

/**
 * A database at schema $from whose information_schema lacks the columns in
 * $missing (table.column).
 *
 * @param list<string> $missing
 */
function schema_database(int $from, array $missing = []): void {
	$GLOBALS['__schema_columns']     = [];
	$GLOBALS['__smokeping_db_exec']  = [];
	$GLOBALS['__smokeping_db_fail']  = null;
	$GLOBALS['__smokeping_db_cell']  = function (string $sql, array $params) use ($from, $missing) {
		return match (true) {
			str_contains($sql, 'FROM settings')               => (string) $from,
			str_contains($sql, 'information_schema.TABLES')   => 1,
			str_contains($sql, 'COLUMN_NAME IN')              => count($params) - 1,
			str_contains($sql, 'information_schema.COLUMNS')  => in_array($params[0] . '.' . $params[1], $missing, true) ? 0 : 1,
			default                                           => 0,
		};
	};
}

afterEach(function () {
	$GLOBALS['__smokeping_db_cell'] = 0;
});

describe('Schema::migrate to versions 5 and 6', function () {
	it('adds the probe description and template version columns, and goes on to version 6', function () {
		schema_database(4);

		expect((new Schema(false))->migrate())->toBeTrue()
			->and($GLOBALS['__schema_columns'])->toContain('plugin_smokeping_target.probe_desc', 'plugin_smokeping_object.template_version', 'plugin_smokeping_object.template_failed')
			->and(end($GLOBALS['__smokeping_db_exec'])['params'])->toBe(['smokeping_schema_version', '7']);
	});

	it('leaves the version alone when a column cannot be added', function () {
		schema_database(4, ['plugin_smokeping_object.template_version']);

		expect((new Schema(false))->migrate())->toBeFalse()
			->and(array_filter($GLOBALS['__smokeping_db_exec'], fn (array $w) => str_contains($w['sql'], 'REPLACE INTO settings')))->toBe([]);
	});

	it('adds the alerts column at version 6', function () {
		schema_database(5);

		expect((new Schema(false))->migrate())->toBeTrue()
			->and($GLOBALS['__schema_columns'])->toContain('plugin_smokeping_target.alerts')
			->and(end($GLOBALS['__smokeping_db_exec'])['params'])->toBe(['smokeping_schema_version', '7']);

		schema_database(5, ['plugin_smokeping_target.alerts']);

		expect((new Schema(false))->migrate())->toBeFalse();
	});

	it('adds the SmokePing version column at version 7 and moves version 3 graphs to templates version 4', function () {
		schema_database(6);

		expect((new Schema(false))->migrate())->toBeTrue()
			->and($GLOBALS['__schema_columns'])->toContain('plugin_smokeping_health.smokeping_version')
			->and(array_filter($GLOBALS['__smokeping_db_exec'], fn (array $w) => str_contains($w['sql'], 'SET template_version = 4 WHERE template_version = 3')))->toHaveCount(1)
			->and(end($GLOBALS['__smokeping_db_exec'])['params'])->toBe(['smokeping_schema_version', '7']);

		schema_database(6, ['plugin_smokeping_health.smokeping_version']);

		expect((new Schema(false))->migrate())->toBeFalse();
	});

	it('defines both columns for a fresh install', function () {
		$tables = Schema::tables();

		expect(array_column($tables['plugin_smokeping_target']['columns'], 'name'))->toContain('probe_desc', 'alerts')
			->and(array_column($tables['plugin_smokeping_object']['columns'], 'name'))->toContain('template_version', 'template_failed')
			->and(array_column($tables['plugin_smokeping_health']['columns'], 'name'))->toContain('smokeping_version');
	});
});
