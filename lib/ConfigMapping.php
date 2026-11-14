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
 * A target's device as the configuration file names it: by the device's host
 * name and description, never by its id, because ids differ between the
 * installation that wrote the file and the one that reads it.
 */
final readonly class ConfigMapping {
	public function __construct(
		public int $pollerId,
		public TargetPath $path,
		public string $hostname,
		public string $description,
	) {
	}

	public function targetKey(): string {
		return ConfigState::targetKey($this->pollerId, $this->path->value);
	}

	public function deviceKey(): string {
		return ConfigState::deviceKey($this->hostname, $this->description);
	}
}
