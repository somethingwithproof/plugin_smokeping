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
 * What the import planner needs to know about the installation, read once
 * per check. Repository code fills it; the planner never queries.
 */
final readonly class SmokepingImportState {
	/**
	 * @param array<string, Target> $targets the reader poller's stored targets by path
	 * @param array<int, int> $mapped target id => the device it is mapped to
	 * @param array<string, true> $objects "target id~location~kind" for each existing smoke ("smoke") or summary ("summary") object
	 * @param list<array{id: int, hostname: string, description: string, poller_id: int}> $devices devices whose host name is a host of the configuration
	 * @param array<int, string> $templates device template id => name
	 * @param array<int, string> $sites site id => name
	 * @param array<int, string> $trees graph tree id => name
	 */
	public function __construct(
		public int $readerPollerId,
		public bool $rrdProxy,
		public array $targets,
		public array $mapped,
		public array $objects,
		public array $devices,
		public array $templates,
		public array $sites,
		public array $trees,
		public bool $summaryTemplate = true,
	) {
	}

	public function hasObject(int $targetId, string $location, string $kind): bool {
		return isset($this->objects[$targetId . '~' . $location . '~' . $kind]);
	}
}
