<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_smokeping, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_smokeping
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

namespace Somethingwithproof\Smokeping;

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
