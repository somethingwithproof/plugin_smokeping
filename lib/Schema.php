<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

namespace Cacti\Smokeping;

/**
 * Plugin tables, their migrations, and the plugin's registration records.
 *
 * Tables are created through api_plugin_db_table_create() so Cacti tracks them
 * in plugin_db_changes and drops them on uninstall. The schema version is kept
 * in its own setting because api_plugin_install() writes plugin_config.version
 * before the plugin's install function runs, so that column cannot tell us
 * whether the tables exist.
 */
final class Schema {
	public const VERSION = 1;

	public const HOOKS = [
		'config_arrays'        => 'smokeping_config_arrays',
		'config_settings'      => 'smokeping_config_settings',
		'draw_navigation_text' => 'smokeping_draw_navigation_text',
		'poller_bottom'        => 'smokeping_poller_bottom',
	];

	private const PLUGIN  = 'smokeping';
	private const SETTING = 'smokeping_schema_version';

	public function __construct(private readonly \PDO|false $connection) {
	}

	public function storedVersion(): int {
		$value = db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', [self::SETTING], '', true, $this->connection);

		return is_numeric($value) ? (int) $value : 0;
	}

	public function isCurrent(): bool {
		return $this->storedVersion() === self::VERSION;
	}

	/**
	 * Apply every pending migration.
	 *
	 * Table creation is idempotent and runs on every call, so a run interrupted
	 * between two tables is completed by the next one. The stored version moves
	 * only after all tables are confirmed present.
	 */
	public function migrate(): bool {
		$from = $this->storedVersion();

		if ($from > self::VERSION) {
			Log::error(sprintf('Schema version %d is newer than this code (%d); refusing to downgrade', $from, self::VERSION));

			return false;
		}

		foreach (self::tables() as $table => $definition) {
			api_plugin_db_table_create(self::PLUGIN, $table, $definition);

			if (!$this->tableExists($table)) {
				Log::error(sprintf('Unable to create table %s; schema left at version %d', $table, $from));

				return false;
			}
		}

		db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', [self::SETTING, (string) self::VERSION], true, $this->connection);

		if ($from !== self::VERSION) {
			Log::info(sprintf('Schema migrated from version %d to %d', $from, self::VERSION));
		}

		return true;
	}

	public function recordedPluginVersion(): string {
		return (string) db_fetch_cell_prepared('SELECT version FROM plugin_config WHERE directory = ?', [self::PLUGIN], '', true, $this->connection);
	}

	public function recordPluginVersion(string $version, string $author, string $homepage): void {
		db_execute_prepared('UPDATE plugin_config SET version = ?, author = ?, webpage = ? WHERE directory = ?', [$version, $author, $homepage, self::PLUGIN], true, $this->connection);
	}

	public function hooksRegistered(): bool {
		$placeholders = implode(', ', array_fill(0, count(self::HOOKS), '?'));

		$hooks = (int) db_fetch_cell_prepared("SELECT COUNT(DISTINCT hook) FROM plugin_hooks WHERE name = ? AND hook IN ($placeholders)", array_merge([self::PLUGIN], array_keys(self::HOOKS)), '', true, $this->connection);
		$realms = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_realms WHERE plugin = ?', [self::PLUGIN], '', true, $this->connection);

		return $hooks === count(self::HOOKS) && $realms === count(Pages::realms());
	}

	/**
	 * Stop Cacti from dropping the plugin tables on uninstall, and keep the
	 * schema version so a reinstall reuses them.
	 */
	public function untrackTables(): void {
		db_execute_prepared("DELETE FROM plugin_db_changes WHERE plugin = ? AND method = 'create'", [self::PLUGIN], true, $this->connection);
	}

	public function forgetVersion(): void {
		db_execute_prepared('DELETE FROM settings WHERE name = ?', [self::SETTING], true, $this->connection);
	}

	private function tableExists(string $table): bool {
		return db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table], '', true, $this->connection) > 0;
	}

	/**
	 * Table definitions in api_plugin_db_table_create() format.
	 *
	 * State values are stored as varchar enum values, never list positions.
	 * Times are Unix seconds, matching rrdtool and the plugin's Clock.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function tables(): array {
		$innodb = ['type' => 'InnoDB', 'charset' => 'utf8mb4', 'collate' => 'utf8mb4_unicode_ci', 'row_format' => 'Dynamic'];

		return [
			// One row per SmokePing target node, identified by tree path on its poller.
			'plugin_smokeping_target' => $innodb + [
				'comment' => 'SmokePing targets read from inventory',
				'columns' => [
					['name' => 'id', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true],
					['name' => 'poller_id', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '1'],
					['name' => 'path', 'type' => 'varchar(255)', 'NULL' => false, 'default' => ''],
					['name' => 'host', 'type' => 'varchar(255)', 'NULL' => false, 'default' => ''],
					['name' => 'probe', 'type' => 'varchar(64)', 'NULL' => false, 'default' => ''],
					['name' => 'menu', 'type' => 'varchar(255)', 'NULL' => false, 'default' => ''],
					['name' => 'title', 'type' => 'varchar(255)', 'NULL' => false, 'default' => ''],
					['name' => 'slaves', 'type' => 'varchar(1024)', 'NULL' => false, 'default' => ''],
					['name' => 'nomasterpoll', 'type' => 'tinyint(1)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'step', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'pings', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'mode', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'observed'],
					['name' => 'admin_state', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'enabled'],
					['name' => 'present', 'type' => 'tinyint(1)', 'unsigned' => true, 'NULL' => false, 'default' => '1'],
					['name' => 'first_seen', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'last_seen', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
				],
				'primary'     => 'id',
				'unique_keys' => [['name' => 'poller_path', 'columns' => ['poller_id', 'path']]],
				'keys'        => [['name' => 'host_probe', 'columns' => ['host', 'probe']]],
			],
			// Operator-confirmed link between a target and a Cacti device.
			'plugin_smokeping_mapping' => $innodb + [
				'comment' => 'SmokePing target to Cacti device mappings',
				'columns' => [
					['name' => 'target_id', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false],
					['name' => 'host_id', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false],
					['name' => 'mapped_by', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'mapped_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
				],
				'primary' => 'target_id',
				'keys'    => [['name' => 'host_id', 'columns' => ['host_id']]],
			],
			// A disappeared path and a new path with the same host and probe.
			'plugin_smokeping_relink' => $innodb + [
				'comment' => 'SmokePing re-link proposals awaiting operator confirmation',
				'columns' => [
					['name' => 'id', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true],
					['name' => 'old_target_id', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false],
					['name' => 'new_target_id', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false],
					['name' => 'status', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'proposed'],
					['name' => 'proposed_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'decided_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'decided_by', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
				],
				'primary'     => 'id',
				'unique_keys' => [['name' => 'old_new', 'columns' => ['old_target_id', 'new_target_id']]],
			],
			// Latest reading per target and location. Freshness is derived at read
			// time from observed_at, never stored, so a stale row cannot look current.
			'plugin_smokeping_observation' => $innodb + [
				'comment' => 'Latest SmokePing observation per target and location',
				'columns' => [
					['name' => 'target_id', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false],
					['name' => 'location', 'type' => 'varchar(64)', 'NULL' => false, 'default' => ''],
					['name' => 'observed_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'ingested_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'expected_interval', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'pings', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'loss', 'type' => 'double', 'NULL' => true],
					['name' => 'median', 'type' => 'double', 'NULL' => true],
					['name' => 'file_present', 'type' => 'tinyint(1)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'revision', 'type' => 'varchar(64)', 'NULL' => false, 'default' => ''],
				],
				'primary' => 'target_id`,`location',
				'keys'    => [['name' => 'ingested_at', 'columns' => ['ingested_at']]],
			],
			// Reader and inventory status per poller, for the health page.
			'plugin_smokeping_health' => $innodb + [
				'comment' => 'SmokePing reader and inventory health per poller',
				'columns' => [
					['name' => 'poller_id', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false],
					['name' => 'run_started', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'run_finished', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'run_duration', 'type' => 'double', 'NULL' => false, 'default' => '0'],
					['name' => 'series_total', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'series_read', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'budget_overruns', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'last_overrun', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'inventory_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'inventory_status', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'never'],
					['name' => 'inventory_error', 'type' => 'varchar(1024)', 'NULL' => false, 'default' => ''],
					['name' => 'inventory_targets', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
				],
				'primary' => 'poller_id',
			],
		];
	}
}
