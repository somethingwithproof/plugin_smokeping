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
 * One SmokePing target node as recorded by the plugin.
 */
final readonly class Target {
	/**
	 * @param list<string> $slaves
	 */
	public function __construct(
		public ?TargetId $id,
		public int $pollerId,
		public TargetPath $path,
		public string $host,
		public string $probe,
		public string $menu,
		public string $title,
		public array $slaves,
		public bool $noMasterPoll,
		public int $step,
		public int $pings,
		public TargetMode $mode,
		public AdminState $adminState,
		public bool $present,
		public int $firstSeen,
		public int $lastSeen,
	) {
	}

	/**
	 * Build from a plugin_smokeping_target row.
	 *
	 * A missing or malformed path throws, because the path is the target's
	 * identity and guessing one could point the plugin at the wrong file. An
	 * empty mode or state takes the column default; an unknown one fails
	 * closed (observed, disabled) so the plugin never acts on a value it does
	 * not understand.
	 *
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): self {
		$id = Row::int($row, 'id', 0, 0);

		return new self(
			id: $id > 0 ? TargetId::fromInt($id) : null,
			pollerId: Row::int($row, 'poller_id', 1, 1),
			path: TargetPath::fromString(Row::string($row, 'path')),
			host: Row::string($row, 'host'),
			probe: Row::string($row, 'probe'),
			menu: Row::string($row, 'menu'),
			title: Row::string($row, 'title'),
			slaves: self::parseSlaves(Row::string($row, 'slaves')),
			noMasterPoll: Row::bool($row, 'nomasterpoll'),
			step: Row::int($row, 'step', 0, 0),
			pings: Row::int($row, 'pings', 0, 0),
			mode: self::enumOr(TargetMode::class, Row::string($row, 'mode'), TargetMode::Observed, TargetMode::Observed),
			adminState: self::enumOr(AdminState::class, Row::string($row, 'admin_state'), AdminState::Enabled, AdminState::Disabled),
			present: Row::bool($row, 'present'),
			firstSeen: Row::int($row, 'first_seen', 0, 0),
			lastSeen: Row::int($row, 'last_seen', 0, 0),
		);
	}

	/**
	 * Every measurement location: '' for the master unless nomasterpoll is
	 * set, then each slave, whose data SmokePing stores in path~slave.rrd.
	 *
	 * @return list<string>
	 */
	public function locations(): array {
		return $this->noMasterPoll ? $this->slaves : array_merge([''], $this->slaves);
	}

	public function displayName(): string {
		return $this->title !== '' ? $this->title : ($this->menu !== '' ? $this->menu : $this->path->value);
	}

	/**
	 * Slave names are space separated; invalid names are dropped rather than
	 * turned into file names.
	 *
	 * @return list<string>
	 */
	public static function parseSlaves(string $slaves): array {
		$names = preg_split('/\s+/', trim($slaves), -1, PREG_SPLIT_NO_EMPTY);

		return array_values(array_unique(array_filter($names === false ? [] : $names, [TargetPath::class, 'isLocation'])));
	}

	/**
	 * @template T of \BackedEnum
	 * @param class-string<T> $enum
	 * @param T $empty
	 * @param T $unknown
	 * @return T
	 */
	private static function enumOr(string $enum, string $value, \BackedEnum $empty, \BackedEnum $unknown): \BackedEnum {
		if ($value === '') {
			return $empty;
		}

		return $enum::tryFrom($value) ?? $unknown;
	}
}
