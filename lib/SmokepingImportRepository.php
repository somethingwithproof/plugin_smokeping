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
 * Reads for the SmokePing configuration import, and the graph tree writes
 * Cacti has no API function for (finding and creating a tree by name).
 * Branches and leaves go through Cacti's api_tree_item_save().
 */
final class SmokepingImportRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * @param list<string> $hostnames hosts of the configuration, to find matching devices
	 */
	public function state(int $readerPollerId, bool $rrdProxy, array $hostnames, bool $summaryTemplate): SmokepingImportState {
		$targets = [];

		foreach ((new TargetRepository($this->connection))->forPoller($readerPollerId) as $target) {
			$targets[$target->path->value] = $target;
		}

		$mapped = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT m.target_id, m.host_id FROM plugin_smokeping_mapping AS m
			JOIN plugin_smokeping_target AS t ON t.id = m.target_id WHERE t.poller_id = ?', [$readerPollerId], true, $this->connection)) as $row) {
			$mapped[Row::int($row, 'target_id')] = Row::int($row, 'host_id');
		}

		$objects = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT o.target_id, o.location, o.state FROM plugin_smokeping_object AS o
			JOIN plugin_smokeping_target AS t ON t.id = o.target_id WHERE t.poller_id = ? AND o.state IN (?, ?)', [$readerPollerId, ObjectRepository::ACTIVE, ObjectRepository::SUMMARY], true, $this->connection)) as $row) {
			$kind = Row::string($row, 'state') === ObjectRepository::SUMMARY ? 'summary' : 'smoke';

			$objects[Row::int($row, 'target_id') . '~' . Row::string($row, 'location') . '~' . $kind] = true;
		}

		return new SmokepingImportState(
			$readerPollerId,
			$rrdProxy,
			$targets,
			$mapped,
			$objects,
			$this->devices($hostnames),
			$this->names('SELECT id, name FROM host_template ORDER BY name'),
			$this->names('SELECT id, name FROM sites ORDER BY name'),
			$this->names('SELECT id, name FROM graph_tree ORDER BY sequence, name'),
			$summaryTemplate,
		);
	}

	/**
	 * The choices of the import form.
	 *
	 * @return array{pollers: array<int, string>, templates: array<int, string>, sites: array<int, string>, trees: array<int, string>}
	 */
	public function choices(): array {
		return [
			'pollers'   => $this->names('SELECT id, name FROM poller ORDER BY id'),
			'templates' => $this->names('SELECT id, name FROM host_template ORDER BY name'),
			'sites'     => $this->names('SELECT id, name FROM sites ORDER BY name'),
			'trees'     => $this->names('SELECT id, name FROM graph_tree ORDER BY sequence, name'),
		];
	}

	/**
	 * The id of the graph tree with this name, or 0.
	 */
	public function treeByName(string $name): int {
		return (int) db_fetch_cell_prepared('SELECT id FROM graph_tree WHERE name = ? ORDER BY id LIMIT 1', [$name], '', true, $this->connection);
	}

	/**
	 * Create an enabled graph tree, last in the tree order, as Cacti's tree
	 * page does.
	 */
	public function createTree(string $name, int $userId): int {
		$sequence = (int) db_fetch_cell_prepared('SELECT MAX(sequence) FROM graph_tree', [], '', true, $this->connection) + 1;

		$ok = db_execute_prepared("INSERT INTO graph_tree (name, sort_type, enabled, sequence, user_id, last_modified, modified_by)
			VALUES (?, ?, 'on', ?, ?, NOW(), ?)", [$name, 1, $sequence, max(1, $userId), max(1, $userId)], true, $this->connection);

		return $ok ? (int) db_fetch_insert_id($this->connection) : 0;
	}

	/**
	 * @param list<string> $hostnames
	 * @return list<array{id: int, hostname: string, description: string, poller_id: int}>
	 */
	private function devices(array $hostnames): array {
		$hostnames = array_values(array_unique(array_filter(array_map(fn (string $h) => strtolower(trim($h)), $hostnames), fn (string $h) => $h !== '')));
		$devices   = [];

		foreach (array_chunk($hostnames, 500) as $chunk) {
			$placeholders = implode(', ', array_fill(0, count($chunk), '?'));

			foreach (Row::rows(db_fetch_assoc_prepared("SELECT id, hostname, description, poller_id FROM host WHERE LOWER(TRIM(hostname)) IN ($placeholders) ORDER BY id", $chunk, true, $this->connection)) as $row) {
				$devices[] = ['id' => Row::int($row, 'id'), 'hostname' => Row::string($row, 'hostname'), 'description' => Row::string($row, 'description'), 'poller_id' => Row::int($row, 'poller_id')];
			}
		}

		return $devices;
	}

	/**
	 * @return array<int, string>
	 */
	private function names(string $sql): array {
		$names = [];

		foreach (Row::rows(db_fetch_assoc_prepared($sql, [], true, $this->connection)) as $row) {
			$names[Row::int($row, 'id')] = Row::string($row, 'name');
		}

		return $names;
	}
}
