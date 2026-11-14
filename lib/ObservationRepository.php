<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * Observation cache: the latest reading per target and location.
 *
 * Each write replaces one row by primary key, so a reader killed mid-run
 * leaves every row either at its previous reading or at its new one.
 */
final class ObservationRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

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
}
