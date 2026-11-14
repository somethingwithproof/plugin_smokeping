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
 * The Cacti data sources and graphs the plugin created for targets.
 *
 * One row per smoke data source. A row is "active" while its data source is
 * the live one for a target and location, and "archived" when a re-link left
 * it pointing at a file that no longer belongs to the target.
 */
final class ObjectRepository {
	public const ACTIVE   = 'active';
	public const ARCHIVED = 'archived';

	/**
	 * A polled summary data source. It has no SmokePing file, so the purge
	 * protection leaves it to Cacti.
	 */
	public const SUMMARY  = 'summary';

	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * @return array{local_data_id: int, local_graph_id: int}|null
	 */
	public function active(int $targetId, string $location): ?array {
		$row = db_fetch_row_prepared('SELECT local_data_id, local_graph_id FROM plugin_smokeping_object WHERE target_id = ? AND location = ? AND state = ?', [$targetId, $location, self::ACTIVE], true, $this->connection);

		return is_array($row) && $row !== [] ? ['local_data_id' => Row::int($row, 'local_data_id'), 'local_graph_id' => Row::int($row, 'local_graph_id')] : null;
	}

	/**
	 * @return array{local_data_id: int, local_graph_id: int}|null
	 */
	public function summary(int $targetId, string $location): ?array {
		$row = db_fetch_row_prepared('SELECT local_data_id, local_graph_id FROM plugin_smokeping_object WHERE target_id = ? AND location = ? AND state = ?', [$targetId, $location, self::SUMMARY], true, $this->connection);

		return is_array($row) && $row !== [] ? ['local_data_id' => Row::int($row, 'local_data_id'), 'local_graph_id' => Row::int($row, 'local_graph_id')] : null;
	}

	/**
	 * The summary data sources of a target, one per location.
	 *
	 * @return list<array{location: string, local_data_id: int}>
	 */
	public function summariesOf(int $targetId): array {
		$rows = Row::rows(db_fetch_assoc_prepared('SELECT location, local_data_id FROM plugin_smokeping_object WHERE target_id = ? AND state = ? ORDER BY location', [$targetId, self::SUMMARY], true, $this->connection));

		return array_values(array_map(fn (array $row) => ['location' => Row::string($row, 'location'), 'local_data_id' => Row::int($row, 'local_data_id')], $rows));
	}

	public function add(int $targetId, string $location, int $hostId, int $localDataId, int $localGraphId, string $rrdPath, string $state, int $now): void {
		db_execute_prepared('INSERT INTO plugin_smokeping_object (target_id, location, host_id, local_data_id, local_graph_id, rrd_path, state, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$targetId, $location, $hostId, $localDataId, $localGraphId, $rrdPath, $state, $now], true, $this->connection);
	}

	public function hostOf(int $localDataId): int {
		return (int) db_fetch_cell_prepared('SELECT host_id FROM plugin_smokeping_object WHERE local_data_id = ?', [$localDataId], '', true, $this->connection);
	}

	public function repoint(int $localDataId, int $targetId, string $rrdPath): void {
		db_execute_prepared('UPDATE plugin_smokeping_object SET target_id = ?, rrd_path = ? WHERE local_data_id = ?', [$targetId, $rrdPath, $localDataId], true, $this->connection);
	}

	/**
	 * Which of these data sources the plugin owns.
	 *
	 * @param list<int> $localDataIds
	 * @return list<int>
	 */
	public function owned(array $localDataIds): array {
		$localDataIds = array_values(array_filter(array_map('intval', $localDataIds), fn (int $id) => $id > 0));

		if ($localDataIds === []) {
			return [];
		}

		$placeholders = implode(', ', array_fill(0, count($localDataIds), '?'));
		$rows         = Row::rows(db_fetch_assoc_prepared("SELECT local_data_id FROM plugin_smokeping_object WHERE local_data_id IN ($placeholders) AND state <> ?", array_merge($localDataIds, [self::SUMMARY]), true, $this->connection));

		return array_values(array_map(fn (array $row) => Row::int($row, 'local_data_id'), $rows));
	}

	/**
	 * @param list<int> $localDataIds
	 */
	public function forget(array $localDataIds): void {
		$localDataIds = array_values(array_filter(array_map('intval', $localDataIds), fn (int $id) => $id > 0));

		if ($localDataIds === []) {
			return;
		}

		$placeholders = implode(', ', array_fill(0, count($localDataIds), '?'));

		db_execute_prepared("DELETE FROM plugin_smokeping_object WHERE local_data_id IN ($placeholders)", $localDataIds, true, $this->connection);
	}

	public function mapTarget(int $targetId, int $hostId, int $userId, int $now): void {
		db_execute_prepared('REPLACE INTO plugin_smokeping_mapping (target_id, host_id, mapped_by, mapped_at) VALUES (?, ?, ?, ?)', [$targetId, $hostId, $userId, $now], true, $this->connection);
	}

	/**
	 * @param list<int> $hostIds
	 */
	public function unmapDevices(array $hostIds): void {
		$hostIds = array_values(array_filter(array_map('intval', $hostIds), fn (int $id) => $id > 0));

		if ($hostIds === []) {
			return;
		}

		$placeholders = implode(', ', array_fill(0, count($hostIds), '?'));

		db_execute_prepared("DELETE FROM plugin_smokeping_mapping WHERE host_id IN ($placeholders)", $hostIds, true, $this->connection);
	}

	/**
	 * Data sources of the plugin on these devices.
	 *
	 * @param list<int> $hostIds
	 * @return list<int>
	 */
	public function ownedOnDevices(array $hostIds): array {
		$hostIds = array_values(array_filter(array_map('intval', $hostIds), fn (int $id) => $id > 0));

		if ($hostIds === []) {
			return [];
		}

		$placeholders = implode(', ', array_fill(0, count($hostIds), '?'));
		$rows         = Row::rows(db_fetch_assoc_prepared("SELECT local_data_id FROM plugin_smokeping_object WHERE host_id IN ($placeholders) AND state <> ?", array_merge($hostIds, [self::SUMMARY]), true, $this->connection));

		return array_values(array_map(fn (array $row) => Row::int($row, 'local_data_id'), $rows));
	}
}
