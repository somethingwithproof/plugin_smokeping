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
