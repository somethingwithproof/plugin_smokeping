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
 * Reads of Cacti's own object tables (templates, devices, data sources,
 * graphs). Writes to those tables go through Cacti's API functions, never
 * through SQL here.
 */
final class CactiObjectRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * The profile data templates import against; the stock default is id 1.
	 */
	public function defaultProfileId(): int {
		$id = (int) db_fetch_cell_prepared("SELECT id FROM data_source_profiles WHERE `default` = 'on' ORDER BY id LIMIT 1", [], '', true, $this->connection);

		return $id > 0 ? $id : 1;
	}

	public function deviceTemplateCount(string $name): int {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host_template WHERE name = ?', [$name], '', true, $this->connection);
	}

	public function graphTemplateCount(string $name): int {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates WHERE name = ?', [$name], '', true, $this->connection);
	}
}
