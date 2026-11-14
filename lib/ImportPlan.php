<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

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
