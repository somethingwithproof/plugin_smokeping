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
 * Target, mapping, and re-link records.
 *
 * Every query takes the connection passed to the constructor. On a remote
 * data collector, CLI code uses the collector's local database by default and
 * web code the main one, so the caller decides which connection applies;
 * false means Cacti's default for the current process.
 */
final class TargetRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * @return list<Target>
	 */
	public function forPoller(int $pollerId): array {
		$rows = Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_smokeping_target WHERE poller_id = ? ORDER BY path', [$pollerId], true, $this->connection));

		return $this->targets($rows);
	}

	/**
	 * Targets the reader should read: present in the last inventory and enabled.
	 *
	 * @return list<Target>
	 */
	public function readable(int $pollerId): array {
		$rows = Row::rows(db_fetch_assoc_prepared("SELECT * FROM plugin_smokeping_target WHERE poller_id = ? AND present = 1 AND admin_state = 'enabled' ORDER BY id", [$pollerId], true, $this->connection));

		return $this->targets($rows);
	}

	/**
	 * Mapped targets on the given devices. Unmapped targets are never returned
	 * here, which keeps them out of every non-administrative view.
	 *
	 * @param list<int> $hostIds
	 * @return list<array{target: Target, host_id: int}>
	 */
	public function mappedTo(array $hostIds): array {
		$hostIds = array_values(array_unique(array_filter(array_map('intval', $hostIds), fn (int $id) => $id > 0)));

		if ($hostIds === []) {
			return [];
		}

		$placeholders = implode(', ', array_fill(0, count($hostIds), '?'));

		$rows = Row::rows(db_fetch_assoc_prepared("SELECT t.*, m.host_id AS mapped_host_id
			FROM plugin_smokeping_target AS t
			INNER JOIN plugin_smokeping_mapping AS m ON m.target_id = t.id
			WHERE m.host_id IN ($placeholders)
			ORDER BY t.path", $hostIds, true, $this->connection));

		$mapped = [];

		foreach ($rows as $row) {
			$mapped[] = ['target' => Target::fromRow($row), 'host_id' => Row::int($row, 'mapped_host_id')];
		}

		return $mapped;
	}

	/**
	 * @return array<int, int> target id => host id
	 */
	public function mappings(): array {
		$rows = Row::rows(db_fetch_assoc_prepared('SELECT target_id, host_id FROM plugin_smokeping_mapping', [], true, $this->connection));

		$mappings = [];

		foreach ($rows as $row) {
			$mappings[Row::int($row, 'target_id')] = Row::int($row, 'host_id');
		}

		return $mappings;
	}

	/**
	 * @return list<array{id: int, old_path: string, new_path: string, host: string, probe: string, proposed_at: int}>
	 */
	public function openRelinkProposals(): array {
		$rows = Row::rows(db_fetch_assoc_prepared("SELECT r.id, o.path AS old_path, n.path AS new_path, n.host, n.probe, r.proposed_at
			FROM plugin_smokeping_relink AS r
			INNER JOIN plugin_smokeping_target AS o ON o.id = r.old_target_id
			INNER JOIN plugin_smokeping_target AS n ON n.id = r.new_target_id
			WHERE r.status = 'proposed'
			ORDER BY r.proposed_at, r.id", [], true, $this->connection));

		$proposals = [];

		foreach ($rows as $row) {
			$proposals[] = [
				'id'          => Row::int($row, 'id'),
				'old_path'    => Row::string($row, 'old_path'),
				'new_path'    => Row::string($row, 'new_path'),
				'host'        => Row::string($row, 'host'),
				'probe'       => Row::string($row, 'probe'),
				'proposed_at' => Row::int($row, 'proposed_at'),
			];
		}

		return $proposals;
	}

	/**
	 * Apply an import plan in one transaction, so a failed or interrupted
	 * import leaves the previous inventory intact. Mappings are never touched:
	 * re-linking always waits for an operator.
	 */
	public function applyImport(ImportPlan $plan, int $pollerId, int $now): bool {
		db_begin_transaction($this->connection);

		try {
			// Every target still in the inventory was seen now, changed or not.
			$this->execute('UPDATE plugin_smokeping_target SET last_seen = ? WHERE poller_id = ? AND present = 1', [$now, $pollerId]);

			foreach ($plan->added as $target) {
				$this->insert($target, $now);
			}

			foreach ($plan->changed as $target) {
				$this->update($target, $now);
			}

			foreach ($plan->disappeared as $target) {
				$this->execute('UPDATE plugin_smokeping_target SET present = 0 WHERE id = ?', [$target->id?->value]);
			}

			foreach ($plan->proposals as $proposal) {
				$newId = db_fetch_cell_prepared('SELECT id FROM plugin_smokeping_target WHERE poller_id = ? AND path = ?', [$pollerId, $proposal['new_path']], '', true, $this->connection);

				if (!is_numeric($newId)) {
					throw new \RuntimeException(sprintf('New target %s was not stored', $proposal['new_path']));
				}

				$this->execute("INSERT IGNORE INTO plugin_smokeping_relink
					(old_target_id, new_target_id, status, proposed_at)
					VALUES (?, ?, 'proposed', ?)", [$proposal['old_id'], (int) $newId, $now]);
			}

			db_commit_transaction($this->connection);
		} catch (\Throwable $e) {
			db_rollback_transaction($this->connection);

			Log::error('Inventory import rolled back: ' . $e->getMessage());

			return false;
		}

		return true;
	}

	private function insert(Target $target, int $now): void {
		$this->execute('INSERT INTO plugin_smokeping_target
			(poller_id, path, host, probe, menu, title, slaves, nomasterpoll, step, pings, mode, admin_state, present, first_seen, last_seen)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
			[
				$target->pollerId, $target->path->value, $target->host, $target->probe, $target->menu, $target->title,
				implode(' ', $target->slaves), (int) $target->noMasterPoll, $target->step, $target->pings,
				$target->mode->value, $target->adminState->value, $now, $now,
			]);
	}

	private function update(Target $target, int $now): void {
		$this->execute('UPDATE plugin_smokeping_target
			SET host = ?, probe = ?, menu = ?, title = ?, slaves = ?, nomasterpoll = ?, step = ?, pings = ?, present = 1, last_seen = ?
			WHERE id = ?',
			[
				$target->host, $target->probe, $target->menu, $target->title, implode(' ', $target->slaves),
				(int) $target->noMasterPoll, $target->step, $target->pings, $now, $target->id?->value,
			]);
	}

	/**
	 * Cacti's db helpers log and return false instead of throwing; turn that
	 * into an exception so a transaction rolls back on the first failure.
	 *
	 * @param list<mixed> $params
	 */
	private function execute(string $sql, array $params): void {
		if (!db_execute_prepared($sql, $params, true, $this->connection)) {
			throw new \RuntimeException('Query failed: ' . strtok($sql, "\n"));
		}
	}

	/**
	 * @param list<array<array-key, mixed>> $rows
	 * @return list<Target>
	 */
	private function targets(array $rows): array {
		$targets = [];

		foreach ($rows as $row) {
			try {
				$targets[] = Target::fromRow($row);
			} catch (\InvalidArgumentException $e) {
				Log::warning(sprintf('Skipping target row %s: %s', Row::string($row, 'id'), $e->getMessage()));
			}
		}

		return $targets;
	}
}
