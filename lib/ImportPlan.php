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
 * What an inventory import would change. Built without side effects by
 * InventoryImporter and applied by TargetRepository::applyImport().
 */
final readonly class ImportPlan {
	/**
	 * @param list<Target> $added       new paths, without ids
	 * @param list<Target> $changed     existing ids with updated attributes
	 * @param list<Target> $disappeared existing ids no longer in the inventory
	 * @param list<array{old_id: int, new_path: string}> $proposals re-link proposals
	 * @param list<string> $warnings
	 */
	public function __construct(
		public array $added,
		public array $changed,
		public array $disappeared,
		public array $proposals,
		public array $warnings,
	) {
	}

	public function isEmpty(): bool {
		return $this->added === [] && $this->changed === [] && $this->disappeared === [] && $this->proposals === [];
	}
}
