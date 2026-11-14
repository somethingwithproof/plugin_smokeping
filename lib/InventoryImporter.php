<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * Compare a fresh inventory with the stored targets of one poller.
 *
 * Targets are identified by tree path. A path that disappears while a new
 * path appears with the same host and probe is most likely a rename or move;
 * the importer proposes a re-link and never makes it, because a wrong guess
 * would attach one device's history to another.
 */
final class InventoryImporter {
	/**
	 * @param list<Target> $existing
	 */
	public function plan(Inventory $inventory, array $existing): ImportPlan {
		$stored = [];

		foreach ($existing as $target) {
			$stored[$target->path->value] = $target;
		}

		$added    = [];
		$changed  = [];
		$warnings = $inventory->warnings;
		$current  = [];

		foreach ($inventory->targets as $target) {
			$current[$target->path->value] = true;
			$old = $stored[$target->path->value] ?? null;

			if ($old === null) {
				$added[] = $target;

				continue;
			}

			if ($old->step !== $target->step || $old->pings !== $target->pings) {
				// SmokePing fixes both when it creates the RRD, so the file on disk
				// still has the old layout until someone migrates it.
				$warnings[] = sprintf('Path %s changed step or pings (%d/%d to %d/%d); the existing RRD keeps its original layout', $target->path->value, $old->step, $old->pings, $target->step, $target->pings);
			}

			if (!$old->present || self::differs($old, $target)) {
				$changed[] = new Target(
					id: $old->id,
					pollerId: $old->pollerId,
					path: $old->path,
					host: $target->host,
					probe: $target->probe,
					menu: $target->menu,
					title: $target->title,
					slaves: $target->slaves,
					noMasterPoll: $target->noMasterPoll,
					step: $target->step,
					pings: $target->pings,
					mode: $old->mode,
					adminState: $old->adminState,
					present: true,
					firstSeen: $old->firstSeen,
					lastSeen: $old->lastSeen,
				);
			}
		}

		$disappeared = [];

		foreach ($existing as $target) {
			if ($target->present && !isset($current[$target->path->value])) {
				$disappeared[] = $target;
			}
		}

		$proposals = [];

		foreach ($disappeared as $gone) {
			if ($gone->host === '' || $gone->id === null) {
				continue;
			}

			foreach ($added as $new) {
				if ($new->host === $gone->host && $new->probe === $gone->probe) {
					$proposals[] = ['old_id' => $gone->id->value, 'new_path' => $new->path->value];
				}
			}
		}

		return new ImportPlan($added, $changed, $disappeared, $proposals, $warnings);
	}

	private static function differs(Target $a, Target $b): bool {
		return $a->host !== $b->host
			|| $a->probe !== $b->probe
			|| $a->menu !== $b->menu
			|| $a->title !== $b->title
			|| $a->slaves !== $b->slaves
			|| $a->noMasterPoll !== $b->noMasterPoll
			|| $a->step !== $b->step
			|| $a->pings !== $b->pings;
	}
}
