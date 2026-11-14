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
 * Outcome of provisioning one smoke data source and graph.
 *
 * A skipped result carries the reason shown to the operator; the target keeps
 * its summary objects only.
 */
final readonly class ProvisionResult {
	private function __construct(
		public bool $created,
		public string $reason,
		public int $localDataId,
		public int $localGraphId,
	) {
	}

	public static function created(int $localDataId, int $localGraphId): self {
		return new self(true, '', $localDataId, $localGraphId);
	}

	public static function skipped(string $reason): self {
		return new self(false, $reason, 0, 0);
	}
}
