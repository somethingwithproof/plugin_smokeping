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
 * The latest reading of one target location.
 *
 * observedAt is the RRD's own last update time; ingestedAt is when the reader
 * looked. Only observedAt says anything about measurement freshness.
 */
final readonly class Observation {
	public function __construct(
		public TargetId $targetId,
		public string $location,
		public int $observedAt,
		public int $ingestedAt,
		public int $expectedInterval,
		public int $pings,
		public ?float $loss,
		public ?float $median,
		public bool $filePresent,
		public string $revision,
	) {
	}

	/**
	 * Build from a plugin_smokeping_observation row. NULL, '' and non-numeric
	 * loss or median are unknown (null), never zero.
	 *
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): self {
		return new self(
			targetId: TargetId::fromInt(Row::int($row, 'target_id', 0)),
			location: Row::string($row, 'location'),
			observedAt: Row::int($row, 'observed_at', 0, 0),
			ingestedAt: Row::int($row, 'ingested_at', 0, 0),
			expectedInterval: Row::int($row, 'expected_interval', 0, 0),
			pings: Row::int($row, 'pings', 0, 0),
			loss: Row::float($row, 'loss'),
			median: Row::float($row, 'median'),
			filePresent: Row::bool($row, 'file_present'),
			revision: Row::string($row, 'revision'),
		);
	}
}
