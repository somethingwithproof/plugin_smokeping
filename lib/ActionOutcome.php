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
 * What an administrator's action did for one subject (a target or a device):
 * the objects it created and the reasons it skipped the rest, so a page can
 * show both.
 */
final readonly class ActionOutcome {
	/**
	 * @param list<string> $done
	 * @param list<string> $skipped
	 */
	public function __construct(
		public string $subject,
		public int $hostId,
		public array $done,
		public array $skipped,
	) {
	}

	public function succeeded(): bool {
		return $this->done !== [];
	}

	/**
	 * @return list<string>
	 */
	public function lines(): array {
		return array_merge(
			array_map(fn (string $line) => $this->subject . ': ' . $line, $this->done),
			array_map(fn (string $line) => $this->subject . ': ' . __('skipped, %s', $line, 'smokeping'), $this->skipped),
		);
	}
}
