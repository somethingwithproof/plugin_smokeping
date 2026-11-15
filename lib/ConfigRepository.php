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

namespace Somethingwithproof\Smokeping;

/**
 * Reads the installation into a ConfigState and applies an import plan.
 *
 * The only place SQL for configuration export and import lives. Every query
 * takes the connection given to the constructor, as TargetRepository does.
 */
final class ConfigRepository {
	private const DEVICE_CHUNK = 500;

	public function __construct(private readonly \PDO|false $connection, private readonly string $rraPath) {
	}

	/**
	 * @param list<ConfigMapping> $mappings the mappings of a file, whose devices to look up
	 */
	public function state(array $mappings = []): ConfigState {
		$stored   = $this->storedSettings();
		$settings = Settings::fromRow($stored, $this->rraPath);

		$effective = [
			'smokeping_config_file'           => $settings->configFile,
			'smokeping_datadir'               => $settings->datadir,
			'smokeping_backup_dir'            => $settings->backupDir,
			'smokeping_mount_path'            => $settings->mountPath,
			'smokeping_poller_id'             => $settings->pollerId,
			'smokeping_reader_budget'         => $settings->readerBudget,
			'smokeping_inventory_interval'    => $settings->inventoryInterval,
			'smokeping_freshness_allowance'   => $settings->freshnessAllowance,
			'smokeping_uninstall_keep_tables' => $settings->keepTablesOnUninstall,
		];

		return new ConfigState($effective, $this->targets(), $this->mappingRows(), $this->pollers(), $this->devices($mappings), Settings::defaultMountPath($this->rraPath));
	}

	/**
	 * Devices Cacti lists, which is what get_allowed_devices() returns for a
	 * user with unrestricted device access.
	 */
	public function deviceCount(): int {
		return (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host WHERE id > 0 AND deleted = ''", [], '', true, $this->connection);
	}

	/**
	 * Write a plan in one transaction. Nothing changes when any statement
	 * fails. The plan is applied as decided; the caller has already compared
	 * its id with the administrator's confirmation. Creates no data source,
	 * RRD file, or graph, and reads or writes nothing under SmokePing's mount.
	 */
	public function apply(ConfigPlan $plan, int $userId, int $now): bool {
		db_begin_transaction($this->connection);

		try {
			foreach ($plan->settings as $change) {
				// Never write a setting that names a path or program, whatever the plan holds.
				if (ConfigSchema::isReferenceOnly($change['name'])) {
					throw new \RuntimeException(sprintf('The setting %s is reference only', $change['name']));
				}

				$value = $change['to'];

				$this->execute('REPLACE INTO settings (name, value) VALUES (?, ?)', [$change['name'], is_bool($value) ? ($value ? 'on' : '') : (string) $value]);
			}

			foreach ($plan->targetAdds as $target) {
				$this->execute('INSERT INTO plugin_smokeping_target
					(poller_id, path, host, probe, menu, title, slaves, nomasterpoll, step, pings, mode, admin_state, present, first_seen, last_seen)
					VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
					[
						$target->pollerId, $target->path->value, $target->host, $target->probe, $target->menu, $target->title,
						implode(' ', $target->slaves), (int) $target->noMasterPoll, $target->step, $target->pings,
						$target->mode->value, $target->adminState->value, (int) $target->present, $now, $now,
					]);
			}

			foreach ($plan->targetChanges as $change) {
				$target = $change['target'];

				// The columns an import may change are fixed here, never taken from the file.
				$this->execute('UPDATE plugin_smokeping_target SET host = ?, probe = ?, menu = ?, title = ?, slaves = ?, nomasterpoll = ?, step = ?, pings = ?, admin_state = ? WHERE id = ?',
					[
						self::updated($change['fields'], 'host', $target->host), self::updated($change['fields'], 'probe', $target->probe),
						self::updated($change['fields'], 'menu', $target->menu), self::updated($change['fields'], 'title', $target->title),
						self::updated($change['fields'], 'slaves', implode(' ', $target->slaves)), (int) self::updated($change['fields'], 'nomasterpoll', $target->noMasterPoll),
						self::updated($change['fields'], 'step', $target->step), self::updated($change['fields'], 'pings', $target->pings),
						self::updated($change['fields'], 'admin_state', $target->adminState->value), $target->id?->value,
					]);
			}

			foreach ($plan->mappingAdds as $mapping) {
				$id = db_fetch_cell_prepared('SELECT id FROM plugin_smokeping_target WHERE poller_id = ? AND path = ?', [$mapping['poller_id'], $mapping['path']], '', true, $this->connection);

				if (!is_numeric($id)) {
					throw new \RuntimeException(sprintf('Target %s was not stored', $mapping['path']));
				}

				$this->execute('INSERT INTO plugin_smokeping_mapping (target_id, host_id, mapped_by, mapped_at) VALUES (?, ?, ?, ?)', [(int) $id, $mapping['host_id'], $userId, $now]);
			}

			db_commit_transaction($this->connection);
		} catch (\Throwable $e) {
			db_rollback_transaction($this->connection);

			Log::error('Configuration import rolled back: ' . $e->getMessage());

			return false;
		}

		return true;
	}

	/**
	 * @param array<string, array{from: string|int|bool, to: string|int|bool}> $fields
	 */
	private static function updated(array $fields, string $name, string|int|bool $current): string|int|bool {
		return isset($fields[$name]) ? $fields[$name]['to'] : $current;
	}

	/**
	 * @param list<mixed> $params
	 */
	private function execute(string $sql, array $params): void {
		if (!db_execute_prepared($sql, $params, true, $this->connection)) {
			throw new \RuntimeException('Query failed: ' . strtok($sql, "\n"));
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function storedSettings(): array {
		$names        = Settings::NAMES;
		$placeholders = implode(', ', array_fill(0, count($names), '?'));
		$rows         = Row::rows(db_fetch_assoc_prepared("SELECT name, value FROM settings WHERE name IN ($placeholders)", $names, true, $this->connection));
		$stored       = [];

		foreach ($rows as $row) {
			$stored[Row::string($row, 'name')] = Row::string($row, 'value');
		}

		return $stored;
	}

	/**
	 * @return array<string, Target>
	 */
	private function targets(): array {
		$rows    = Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_smokeping_target ORDER BY poller_id, path', [], true, $this->connection));
		$targets = [];

		foreach ($rows as $row) {
			try {
				$target = Target::fromRow($row);
			} catch (\InvalidArgumentException $e) {
				Log::warning(sprintf('Leaving target row %s out of the configuration: %s', Row::string($row, 'id'), $e->getMessage()));

				continue;
			}

			// The file format carries no control characters; a stored label with one
			// is exported with a space instead, and the next inventory restores it.
			$targets[ConfigState::targetKey($target->pollerId, $target->path->value)] = new Target(
				$target->id, $target->pollerId, $target->path,
				self::clean($target->host), self::clean($target->probe), self::clean($target->menu), self::clean($target->title),
				$target->slaves, $target->noMasterPoll, $target->step, $target->pings, $target->mode, $target->adminState,
				$target->present, $target->firstSeen, $target->lastSeen, $target->probeDesc
			);
		}

		return $targets;
	}

	private static function clean(string $text): string {
		return trim((string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $text));
	}

	/**
	 * @return array<string, array{host_id: int, hostname: string, description: string}>
	 */
	private function mappingRows(): array {
		$rows = Row::rows(db_fetch_assoc_prepared('SELECT t.poller_id, t.path, h.id AS host_id, h.hostname, h.description
			FROM plugin_smokeping_mapping AS m
			INNER JOIN plugin_smokeping_target AS t ON t.id = m.target_id
			INNER JOIN host AS h ON h.id = m.host_id', [], true, $this->connection));

		$mappings = [];

		foreach ($rows as $row) {
			$mappings[ConfigState::targetKey(Row::int($row, 'poller_id', 1, 1), Row::string($row, 'path'))] = [
				'host_id'     => Row::int($row, 'host_id'),
				'hostname'    => self::clean(Row::string($row, 'hostname')),
				'description' => self::clean(Row::string($row, 'description')),
			];
		}

		return $mappings;
	}

	/**
	 * @return array<int, true>
	 */
	private function pollers(): array {
		$rows    = Row::rows(db_fetch_assoc_prepared('SELECT id FROM poller', [], true, $this->connection));
		$pollers = [];

		foreach ($rows as $row) {
			$pollers[Row::int($row, 'id')] = true;
		}

		return $pollers;
	}

	/**
	 * Host ids by identity for the devices a file's mappings name. The host name
	 * narrows the query; description is compared exactly here.
	 *
	 * @param list<ConfigMapping> $mappings
	 * @return array<string, list<int>>
	 */
	private function devices(array $mappings): array {
		$wanted = [];

		foreach ($mappings as $mapping) {
			$wanted[$mapping->deviceKey()] = $mapping->hostname;
		}

		$found = [];

		foreach (array_chunk(array_values(array_unique($wanted)), self::DEVICE_CHUNK) as $chunk) {
			$placeholders = implode(', ', array_fill(0, count($chunk), '?'));
			$rows         = Row::rows(db_fetch_assoc_prepared("SELECT id, hostname, description FROM host WHERE hostname IN ($placeholders) ORDER BY id", $chunk, true, $this->connection));

			foreach ($rows as $row) {
				$key = ConfigState::deviceKey(Row::string($row, 'hostname'), Row::string($row, 'description'));

				if (isset($wanted[$key])) {
					$found[$key][] = Row::int($row, 'id');
				}
			}
		}

		return $found;
	}
}
