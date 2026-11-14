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
 * What this installation holds now, as far as an import needs to compare it
 * with a file: effective settings, every target, the mappings with their
 * devices' names, the poller ids, and the devices the file's mappings name.
 */
final readonly class ConfigState {
	/**
	 * @param array<string, string|int|bool> $settings effective values, defaults included
	 * @param array<string, Target> $targets by targetKey()
	 * @param array<string, array{host_id: int, hostname: string, description: string}> $mappings by targetKey()
	 * @param array<int, true> $pollers poller ids
	 * @param array<string, list<int>> $devices host ids by deviceKey(), for the identities the file names
	 */
	public function __construct(
		public array $settings,
		public array $targets,
		public array $mappings,
		public array $pollers,
		public array $devices,
		public string $defaultMountPath,
	) {
	}

	public static function targetKey(int $pollerId, string $path): string {
		return $pollerId . ':' . $path;
	}

	/**
	 * Host names compare without regard to case, as DNS and Cacti's database
	 * collation do; descriptions compare exactly.
	 */
	public static function deviceKey(string $hostname, string $description): string {
		return strtolower($hostname) . "\0" . $description;
	}
}
