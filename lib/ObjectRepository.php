<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent


namespace Somethingwithproof\Smokeping;

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
	 * Devices that hold any data source of a target.
	 *
	 * @return list<int>
	 */
	public function hostsOf(int $targetId): array {
		$rows = Row::rows(db_fetch_assoc_prepared('SELECT DISTINCT host_id FROM plugin_smokeping_object WHERE target_id = ?', [$targetId], true, $this->connection));

		return array_map(static fn (array $row): int => Row::int($row, 'host_id'), $rows);
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

	/**
	 * Every smoke graph, live or archived, with its file relative to rra_path.
	 *
	 * @return list<array{local_graph_id: int, rrd_path: string}>
	 */
	public function smokeGraphs(): array {
		$rows = Row::rows(db_fetch_assoc_prepared('SELECT local_graph_id, rrd_path FROM plugin_smokeping_object WHERE state IN (?, ?) AND local_graph_id > 0 ORDER BY local_graph_id', [self::ACTIVE, self::ARCHIVED], true, $this->connection));

		return array_values(array_map(fn (array $row) => ['local_graph_id' => Row::int($row, 'local_graph_id'), 'rrd_path' => Row::string($row, 'rrd_path')], $rows));
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

	/**
	 * Map a target to a device. An existing mapping is never replaced: a
	 * target belongs to one device until it is unmapped.
	 *
	 * @return bool true when the target is now mapped to this device
	 */
	public function mapTarget(int $targetId, int $hostId, int $userId, int $now): bool {
		db_execute_prepared('INSERT IGNORE INTO plugin_smokeping_mapping (target_id, host_id, mapped_by, mapped_at) VALUES (?, ?, ?, ?)', [$targetId, $hostId, $userId, $now], true, $this->connection);

		return (int) db_fetch_cell_prepared('SELECT host_id FROM plugin_smokeping_mapping WHERE target_id = ?', [$targetId], '', true, $this->connection) === $hostId;
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
