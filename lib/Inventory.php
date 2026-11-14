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
 * Targets of the SmokePing configuration on one poller.
 */
final readonly class Inventory {
	public const FORMAT = 1;

	/**
	 * @param list<Target> $targets
	 * @param list<string> $warnings entries that were skipped, and why
	 */
	public function __construct(
		public string $smokepingVersion,
		public string $datadir,
		public array $targets,
		public array $warnings,
	) {
	}

	/**
	 * Decode an inventory document in JSON, as tests and the differential
	 * oracle write it.
	 */
	public static function fromJson(string $json, int $pollerId): self {
		try {
			$document = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new InventoryError('Inventory output is not JSON: ' . $e->getMessage());
		}

		return self::fromDocument($document, $pollerId);
	}

	/**
	 * Read an inventory document (see SmokepingInventory::document()). A
	 * document that is not the expected format throws; a single malformed
	 * target is skipped with a warning so one bad node does not hide the
	 * rest of the tree.
	 */
	public static function fromDocument(mixed $document, int $pollerId): self {
		if (!is_array($document) || ($document['format'] ?? null) !== self::FORMAT || !isset($document['targets']) || !is_array($document['targets'])) {
			throw new InventoryError('Inventory output is not format ' . self::FORMAT);
		}

		$targets  = [];
		$warnings = [];
		$seen     = [];

		foreach ($document['targets'] as $index => $entry) {
			if (!is_array($entry)) {
				$warnings[] = sprintf('Entry %s is not an object', $index);

				continue;
			}

			try {
				$path = TargetPath::fromString(is_string($entry['path'] ?? null) ? $entry['path'] : '');
			} catch (\InvalidArgumentException $e) {
				$warnings[] = sprintf('Entry %s: %s', $index, $e->getMessage());

				continue;
			}

			if (isset($seen[$path->value])) {
				$warnings[] = sprintf('Duplicate path %s', $path->value);

				continue;
			}

			$step  = is_int($entry['step'] ?? null) ? $entry['step'] : 0;
			$pings = is_int($entry['pings'] ?? null) ? $entry['pings'] : 0;

			if ($step < 1 || $pings < 1) {
				$warnings[] = sprintf('Path %s has no usable step or pings', $path->value);

				continue;
			}

			$slaves = is_array($entry['slaves'] ?? null) ? array_filter($entry['slaves'], 'is_string') : [];

			$seen[$path->value] = true;
			$targets[] = new Target(
				id: null,
				pollerId: $pollerId,
				path: $path,
				host: self::text($entry, 'host'),
				probe: self::text($entry, 'probe'),
				menu: self::text($entry, 'menu'),
				title: self::text($entry, 'title'),
				slaves: Target::parseSlaves(implode(' ', $slaves)),
				noMasterPoll: ($entry['nomasterpoll'] ?? false) === true,
				step: $step,
				pings: $pings,
				mode: TargetMode::Observed,
				adminState: AdminState::Enabled,
				present: true,
				firstSeen: 0,
				lastSeen: 0,
			);
		}

		return new self(
			smokepingVersion: is_string($document['smokeping'] ?? null) ? $document['smokeping'] : '',
			datadir: is_string($document['datadir'] ?? null) ? $document['datadir'] : '',
			targets: $targets,
			warnings: $warnings,
		);
	}

	/**
	 * @param array<array-key, mixed> $entry
	 */
	private static function text(array $entry, string $key): string {
		return is_string($entry[$key] ?? null) ? mb_substr(trim($entry[$key]), 0, 255) : '';
	}
}
