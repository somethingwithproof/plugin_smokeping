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
 * The Cacti data sources and graphs the plugin created for targets.
 *
 * One row per smoke data source. A row is "active" while its data source is
 * the live one for a target and location, and "archived" when a re-link left
 * it pointing at a file that no longer belongs to the target.
 */
final class ObjectRepository {
	/** Graphs behind the template version (bound twice) and not failed at it, in the given states. */
	private const BEHIND_TEMPLATES = 'o.template_version < ? AND o.template_failed < ? AND o.local_graph_id > 0 AND o.state IN (?, ?, ?) AND gl.graph_template_id > 0';

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
	 * Every smoke graph, live or archived, with its file relative to rra_path
	 * and the limit the plugin last set on it.
	 *
	 * @return list<array{local_graph_id: int, rrd_path: string, sized_limit: string}>
	 */
	public function smokeGraphs(): array {
		$rows = Row::rows(db_fetch_assoc_prepared('SELECT local_graph_id, rrd_path, sized_limit FROM plugin_smokeping_object WHERE state IN (?, ?) AND local_graph_id > 0 ORDER BY local_graph_id', [self::ACTIVE, self::ARCHIVED], true, $this->connection));

		return self::graphRows($rows);
	}

	/**
	 * Smoke graphs of a poller's targets last sized at or before a time, the
	 * longest waiting first, at most $limit of them. A graph deleted in Cacti
	 * without its data source is left out, so it cannot hold a batch's places.
	 *
	 * @return list<array{local_graph_id: int, rrd_path: string, sized_limit: string}>
	 */
	public function smokeGraphsDue(int $pollerId, int $sizedBefore, int $limit): array {
		$rows = Row::rows(db_fetch_assoc_prepared('SELECT o.local_graph_id, o.rrd_path, o.sized_limit
			FROM plugin_smokeping_object AS o
			INNER JOIN plugin_smokeping_target AS t ON t.id = o.target_id
			INNER JOIN graph_templates_graph AS g ON g.local_graph_id = o.local_graph_id
			WHERE t.poller_id = ? AND o.state IN (?, ?) AND o.local_graph_id > 0 AND o.sized_at <= ?
			ORDER BY o.sized_at, o.local_graph_id
			LIMIT ' . max(0, $limit), [$pollerId, self::ACTIVE, self::ARCHIVED, $sizedBefore], true, $this->connection));

		return self::graphRows($rows);
	}

	/**
	 * Write each smoke graph's probe line, or one graph's: the graph item made
	 * from the template's probe line item gets its target's text (see
	 * ProbeDescription::line()). Importing the templates again resets the
	 * item to the template's text, and a target's probe can change, so the
	 * poller runs this every cycle; only items whose text differs are written.
	 *
	 * @return int the number of graph items written
	 */
	public function syncProbeLines(?int $localGraphId = null): int {
		$params = [ProbeDescription::PLACEHOLDER, self::ACTIVE, self::ARCHIVED];
		$where  = '';

		if ($localGraphId !== null) {
			$where    = ' AND o.local_graph_id = ?';
			$params[] = $localGraphId;
		}

		$rows = Row::rows(db_fetch_assoc_prepared('SELECT gi.id, gi.text_format, t.pings, t.step, t.probe, t.probe_desc
			FROM plugin_smokeping_object AS o
			INNER JOIN plugin_smokeping_target AS t ON t.id = o.target_id
			INNER JOIN graph_templates_item AS gi ON gi.local_graph_id = o.local_graph_id
			INNER JOIN graph_templates_item AS ti ON ti.id = gi.local_graph_template_item_id AND ti.local_graph_id = 0
			WHERE ti.graph_type_id = 1 AND ti.text_format = ? AND o.state IN (?, ?) AND o.local_graph_id > 0' . $where, $params, true, $this->connection));

		$written = 0;

		foreach ($rows as $row) {
			$text = ProbeDescription::line(Row::int($row, 'pings'), Row::string($row, 'probe_desc'), Row::string($row, 'probe'), Row::int($row, 'step'));

			if ($text === Row::string($row, 'text_format')) {
				continue;
			}

			if (db_execute_prepared('UPDATE graph_templates_item SET text_format = ? WHERE id = ?', [$text, Row::int($row, 'id')], true, $this->connection) !== false) {
				$written++;
			}
		}

		return $written;
	}

	/**
	 * Record a sizing of a graph: the limit the plugin set, or null to keep
	 * the recorded limit and only move the time.
	 */
	public function recordSizing(int $localGraphId, ?string $limit, int $now): bool {
		if ($limit === null) {
			return db_execute_prepared('UPDATE plugin_smokeping_object SET sized_at = ? WHERE local_graph_id = ?', [$now, $localGraphId], true, $this->connection) !== false;
		}

		return db_execute_prepared('UPDATE plugin_smokeping_object SET sized_limit = ?, sized_at = ? WHERE local_graph_id = ?', [$limit, $now, $localGraphId], true, $this->connection) !== false;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 *
	 * @return list<array{local_graph_id: int, rrd_path: string, sized_limit: string}>
	 */
	private static function graphRows(array $rows): array {
		return array_values(array_map(fn (array $row) => ['local_graph_id' => Row::int($row, 'local_graph_id'), 'rrd_path' => Row::string($row, 'rrd_path'), 'sized_limit' => Row::string($row, 'sized_limit')], $rows));
	}

	public function add(int $targetId, string $location, int $hostId, int $localDataId, int $localGraphId, string $rrdPath, string $state, int $now): void {
		// A new graph has the items of the templates installed now, whichever
		// version that is; the setting is the installed one, not this code's.
		db_execute_prepared('INSERT INTO plugin_smokeping_object (target_id, location, host_id, local_data_id, local_graph_id, rrd_path, state, created_at, template_version)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, COALESCE((SELECT CAST(value AS UNSIGNED) FROM settings WHERE name = ?), 0))',
			[$targetId, $location, $hostId, $localDataId, $localGraphId, $rrdPath, $state, $now, TemplateInstaller::SETTING], true, $this->connection);
	}

	/**
	 * The plugin's graphs whose items follow templates older than $version,
	 * at most $limit of them. A graph deleted in Cacti is left out.
	 *
	 * @return list<int> local graph ids
	 */
	public function graphsBehindTemplates(int $version, int $limit): array {
		$rows = Row::rows(db_fetch_assoc_prepared('SELECT o.local_graph_id
			FROM plugin_smokeping_object AS o
			INNER JOIN graph_local AS gl ON gl.id = o.local_graph_id
			WHERE ' . self::BEHIND_TEMPLATES . '
			ORDER BY o.local_graph_id
			LIMIT ' . max(0, $limit), [$version, $version, self::ACTIVE, self::ARCHIVED, self::SUMMARY], true, $this->connection));

		return array_map(fn (array $row) => Row::int($row, 'local_graph_id'), $rows);
	}

	/**
	 * How many graphs graphsBehindTemplates() has left to give.
	 */
	public function countGraphsBehindTemplates(int $version): int {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*)
			FROM plugin_smokeping_object AS o
			INNER JOIN graph_local AS gl ON gl.id = o.local_graph_id
			WHERE ' . self::BEHIND_TEMPLATES, [$version, $version, self::ACTIVE, self::ARCHIVED, self::SUMMARY], '', true, $this->connection);
	}

	public function recordTemplateVersion(int $localGraphId, int $version): bool {
		return db_execute_prepared('UPDATE plugin_smokeping_object SET template_version = ?, template_failed = 0 WHERE local_graph_id = ?', [$version, $localGraphId], true, $this->connection) !== false;
	}

	/**
	 * Mark a graph whose upgrade to $version failed. It keeps its version, so
	 * it is still behind, but the poller passes over it until upgrade-graphs
	 * clears the mark or a newer template version arrives.
	 */
	public function recordTemplateFailure(int $localGraphId, int $version): bool {
		return db_execute_prepared('UPDATE plugin_smokeping_object SET template_failed = ? WHERE local_graph_id = ?', [$version, $localGraphId], true, $this->connection) !== false;
	}

	/**
	 * Make the graphs whose upgrade failed eligible again; returns how many.
	 */
	public function clearTemplateFailures(): int {
		if (db_execute_prepared('UPDATE plugin_smokeping_object SET template_failed = 0 WHERE template_failed > 0', [], true, $this->connection) === false) {
			return 0;
		}

		return (int) db_affected_rows($this->connection);
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
