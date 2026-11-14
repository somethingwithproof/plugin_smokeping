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
 * Reads of Cacti's own object tables (templates, devices, data sources,
 * graphs). Writes to those tables go through Cacti's API functions, never
 * through SQL here.
 */
final class CactiObjectRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * The profile data templates import against; the stock default is id 1.
	 */
	public function defaultProfileId(): int {
		$id = (int) db_fetch_cell_prepared("SELECT id FROM data_source_profiles WHERE `default` = 'on' ORDER BY id LIMIT 1", [], '', true, $this->connection);

		return $id > 0 ? $id : 1;
	}

	public function deviceTemplateCount(string $name): int {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host_template WHERE name = ?', [$name], '', true, $this->connection);
	}

	public function graphTemplateCount(string $name): int {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates WHERE name = ?', [$name], '', true, $this->connection);
	}

	public function graphTemplateId(string $name): int {
		return (int) db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE name = ?', [$name], '', true, $this->connection);
	}

	public function deviceTemplateId(string $name): int {
		return (int) db_fetch_cell_prepared('SELECT id FROM host_template WHERE name = ?', [$name], '', true, $this->connection);
	}

	/**
	 * The data template a graph template draws from; the smoke templates use one.
	 */
	public function dataTemplateOfGraphTemplate(int $graphTemplateId): int {
		return (int) db_fetch_cell_prepared('SELECT dtr.data_template_id
			FROM graph_templates_item AS gti
			INNER JOIN data_template_rrd AS dtr ON dtr.id = gti.task_item_id
			WHERE gti.graph_template_id = ? AND gti.local_graph_id = 0 AND dtr.local_data_id = 0
			LIMIT 1', [$graphTemplateId], '', true, $this->connection);
	}

	/**
	 * The data sources a graph draws.
	 *
	 * @return list<int>
	 */
	public function dataSourcesOfGraph(int $localGraphId): array {
		$rows = Row::rows(db_fetch_assoc_prepared('SELECT DISTINCT dtr.local_data_id
			FROM graph_templates_item AS gti
			INNER JOIN data_template_rrd AS dtr ON dtr.id = gti.task_item_id
			WHERE gti.local_graph_id = ? AND dtr.local_data_id > 0
			ORDER BY dtr.local_data_id', [$localGraphId], true, $this->connection));

		return array_values(array_map(fn (array $row) => Row::int($row, 'local_data_id'), $rows));
	}

	/**
	 * Point a data source at an RRD file and give it the target's step.
	 */
	public function pointDataSource(int $localDataId, string $dataSourcePath, int $step): void {
		db_execute_prepared('UPDATE data_template_data SET data_source_path = ?, rrd_step = ? WHERE local_data_id = ?', [$dataSourcePath, $step, $localDataId], true, $this->connection);
	}

	public function setGraphTitle(int $localGraphId, string $title): void {
		db_execute_prepared("UPDATE graph_templates_graph SET title = ?, t_title = 'on' WHERE local_graph_id = ?", [$title, $localGraphId], true, $this->connection);
	}

	/**
	 * Replace the path of data sources about to be removed with one that names
	 * no file, so the purge row Cacti queues next cannot reach a SmokePing RRD.
	 *
	 * @param list<int> $localDataIds
	 */
	public function disarmPurge(array $localDataIds): void {
		$localDataIds = array_values(array_filter(array_map('intval', $localDataIds), fn (int $id) => $id > 0));

		if ($localDataIds === []) {
			return;
		}

		$placeholders = implode(', ', array_fill(0, count($localDataIds), '?'));

		db_execute_prepared("UPDATE data_template_data SET data_source_path = CONCAT('<path_rra>/smokeping-removed/', local_data_id, '.rrd') WHERE local_data_id IN ($placeholders)", $localDataIds, true, $this->connection);
	}

	/**
	 * @param list<int> $hostIds
	 * @return list<int>
	 */
	public function dataSourcesOnDevices(array $hostIds): array {
		$hostIds = array_values(array_filter(array_map('intval', $hostIds), fn (int $id) => $id > 0));

		if ($hostIds === []) {
			return [];
		}

		$placeholders = implode(', ', array_fill(0, count($hostIds), '?'));
		$rows         = Row::rows(db_fetch_assoc_prepared("SELECT id FROM data_local WHERE host_id IN ($placeholders)", $hostIds, true, $this->connection));

		return array_values(array_map(fn (array $row) => Row::int($row, 'id'), $rows));
	}

	/**
	 * Every queued purge row. The queue is small, and matching is done in PHP
	 * because one file has many spellings.
	 *
	 * @return list<array{id: int, name: string}>
	 */
	public function queuedPurges(): array {
		$rows = Row::rows(db_fetch_assoc_prepared('SELECT id, name FROM data_source_purge_action', [], true, $this->connection));

		return array_values(array_map(fn (array $row) => ['id' => Row::int($row, 'id'), 'name' => Row::string($row, 'name')], $rows));
	}

	/**
	 * @param list<int> $ids
	 */
	public function dropQueuedPurges(array $ids): void {
		foreach ($ids as $id) {
			db_execute_prepared('DELETE FROM data_source_purge_action WHERE id = ?', [$id], true, $this->connection);
		}
	}
}
