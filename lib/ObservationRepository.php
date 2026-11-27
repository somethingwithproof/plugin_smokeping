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
 * Observation cache: the latest reading per target and location.
 *
 * Each write replaces one row by primary key, so a reader killed mid-run
 * leaves every row either at its previous reading or at its new one.
 */
final class ObservationRepository {
	/**
	 * Create a new ObservationRepository instance.
	 *
	 * @param \PDO|false $connection Connection.
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Store.
	 *
	 * @param Observation $observation Observation.
	 * @return bool
	 */
	public function store(Observation $observation): bool {
		return (bool) db_execute_prepared('REPLACE INTO plugin_smokeping_observation
			(target_id, location, observed_at, ingested_at, expected_interval, pings, loss, median, file_present, revision)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[
				$observation->targetId->value, $observation->location, $observation->observedAt,
				$observation->ingestedAt, $observation->expectedInterval, $observation->pings,
				$observation->loss, $observation->median, (int) $observation->filePresent, $observation->revision,
			], true, $this->connection);
	}

	/**
	 * When each series of the poller was last read, so a run can start with
	 * the series that waited longest and an interrupted run resumes there.
	 *
	 * @return array<string, int> "targetId~location" => ingested_at
	 */
	public function ingestedAt(int $pollerId): array {
		$rows = Row::rows(db_fetch_assoc_prepared('SELECT o.target_id, o.location, o.ingested_at
			FROM plugin_smokeping_observation AS o
			INNER JOIN plugin_smokeping_target AS t ON t.id = o.target_id
			WHERE t.poller_id = ?', [$pollerId], true, $this->connection));

		$times = [];

		foreach ($rows as $row) {
			$times[Row::int($row, 'target_id') . '~' . Row::string($row, 'location')] = Row::int($row, 'ingested_at');
		}

		return $times;
	}

	/**
	 * @param list<int> $targetIds
	 * @return array<int, list<Observation>>
	 */
	public function forTargets(array $targetIds): array {
		$targetIds = array_values(array_filter(array_map('intval', $targetIds), fn (int $id) => $id > 0));

		if ($targetIds === []) {
			return [];
		}

		$placeholders = implode(', ', array_fill(0, count($targetIds), '?'));
		$rows = Row::rows(db_fetch_assoc_prepared("SELECT * FROM plugin_smokeping_observation WHERE target_id IN ($placeholders) ORDER BY target_id, location", $targetIds, true, $this->connection));

		$observations = [];

		foreach ($rows as $row) {
			$observation = Observation::fromRow($row);
			$observations[$observation->targetId->value][] = $observation;
		}

		return $observations;
	}

	/**
	 * One cache row with the time the target was first seen, for the summary
	 * script. A primary key lookup and a primary key join.
	 *
	 * @return array{observation: Observation, firstSeen: int}|null
	 */
	public function latest(int $targetId, string $location): ?array {
		$row = db_fetch_row_prepared('SELECT o.*, t.first_seen
			FROM plugin_smokeping_observation AS o
			INNER JOIN plugin_smokeping_target AS t ON t.id = o.target_id
			WHERE o.target_id = ? AND o.location = ?', [$targetId, $location], true, $this->connection);

		if (!is_array($row) || $row === []) {
			return null;
		}

		return ['observation' => Observation::fromRow($row), 'firstSeen' => Row::int($row, 'first_seen', 0, 0)];
	}
}
