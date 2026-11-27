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
 * The administrator's actions on targets: add a target to Cacti (to a new or
 * an existing device), add the matching targets to many devices at once, and
 * confirm a re-link. Pages, the device actions, and the command line share
 * it, so each does the same thing.
 *
 * Nothing here runs without an operator's decision. A device is created only
 * when the caller asks for one, and a re-link applies only a proposal that is
 * still open.
 */
final class TargetActions {
	/**
	 * Create a new TargetActions instance.
	 *
	 * @param SmokeProvisioner $provisioner Provisioner.
	 * @param TargetRepository $targets Targets.
	 * @param ObjectRepository $objects Objects.
	 * @param CactiObjectRepository $cacti Cacti.
	 * @param string $coreLibrary Core library.
	 * @param Clock $clock Clock.
	 */
	public function __construct(
		private readonly SmokeProvisioner $provisioner,
		private readonly TargetRepository $targets,
		private readonly ObjectRepository $objects,
		private readonly CactiObjectRepository $cacti,
		private readonly string $coreLibrary,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Add a target to Cacti: map it to the device, or create one from the
	 * "SmokePing Target" template when $hostId is 0, then create the summary
	 * and smoke objects for every location the target can have.
	 */
	public function add(Target $target, int $hostId, int $userId): ActionOutcome {
		$subject = $target->path->value;

		if ($target->id === null) {
			return new ActionOutcome($subject, 0, [], [__('the target has not been saved yet', 'smokeping')]);
		}

		$mapped = $this->targets->mappedHost($target->id->value);

		if ($mapped > 0 && ($hostId === 0 || $hostId !== $mapped)) {
			return new ActionOutcome($subject, $mapped, [], [__('already mapped to device %d', $mapped, 'smokeping')]);
		}

		$done = [];

		if ($hostId === 0) {
			$hostId = $this->provisioner->createDevice($target);

			if ($hostId === 0) {
				return new ActionOutcome($subject, 0, [], [__('Cacti did not create the device', 'smokeping')]);
			}

			$done[] = __('created device %d', $hostId, 'smokeping');

			// Another request may have mapped the target between the check above
			// and createDevice(); its mapping stands and no graphs are added here.
			if ($this->targets->mappedHost($target->id->value) !== $hostId) {
				return new ActionOutcome($subject, $hostId, $done, [__('the target was mapped to another device meanwhile; device %d has no SmokePing graphs', $hostId, 'smokeping')]);
			}
		} elseif ($mapped === 0) {
			if (!$this->objects->mapTarget($target->id->value, $hostId, $userId, $this->clock->now())) {
				return new ActionOutcome($subject, $hostId, [], [__('the target was mapped to another device meanwhile', 'smokeping')]);
			}

			$done[] = __('mapped to device %d', $hostId, 'smokeping');
		}

		$skipped = [];

		foreach ($target->locations() as $location) {
			$name = $location === '' ? __('master', 'smokeping') : $location;

			$summary = $this->provisioner->provisionSummary($target, $hostId, $location);

			if ($summary->created) {
				$done[] = __('summary graph %d for %s', $summary->localGraphId, $name, 'smokeping');
			} else {
				$skipped[] = __('no summary for %s: %s', $name, $summary->reason, 'smokeping');
			}

			$smoke = $this->provisioner->provision($target, $hostId, $location);

			if ($smoke->created) {
				$done[] = __('smoke graph %d for %s', $smoke->localGraphId, $name, 'smokeping');
			} else {
				$skipped[] = __('no smoke graph for %s: %s', $name, $smoke->reason, 'smokeping');
			}
		}

		return new ActionOutcome($subject, $hostId, $done, $skipped);
	}

	/**
	 * The bulk action on selected devices: each device gets the unmapped
	 * targets whose SmokePing host is the device's host name. A device with no
	 * match is reported and left alone.
	 *
	 * @param list<int> $hostIds devices the user may change
	 * @param int $budgetSeconds stop starting new devices after this many seconds, 0 for no limit;
	 *                           the rest is reported, and running the action again finishes it
	 * @return list<ActionOutcome>
	 */
	public function addToDevices(array $hostIds, int $pollerId, int $userId, int $budgetSeconds = 0): array {
		$started = $this->clock->now();
		$devices = $this->cacti->devices($hostIds);
		$matches = $this->targets->unmappedByHost($pollerId, array_values(array_map(fn (array $d) => $d['hostname'], $devices)));
		$results = [];

		foreach ($hostIds as $position => $hostId) {
			if ($budgetSeconds > 0 && $this->clock->now() - $started >= $budgetSeconds) {
				$results[] = new ActionOutcome(__('Time limit', 'smokeping'), 0, [], [__('%d devices were not processed in the time allowed. Run the action again for them; devices already added are skipped.', count($hostIds) - $position, 'smokeping')]);

				break;
			}

			if (!isset($devices[$hostId])) {
				continue;
			}

			$device  = $devices[$hostId];
			$subject = $device['description'] !== '' ? $device['description'] : (string) $hostId;
			$found   = $matches[strtolower(trim($device['hostname']))] ?? [];

			if ($found === []) {
				$results[] = new ActionOutcome($subject, $hostId, [], [__('no unmapped SmokePing target has the host %s', $device['hostname'], 'smokeping')]);

				continue;
			}

			foreach ($found as $target) {
				$outcome = $this->add($target, $hostId, $userId);

				$results[] = new ActionOutcome($subject . ' / ' . $target->path->value, $hostId, $outcome->done, $outcome->skipped);
			}
		}

		return $results;
	}

	/**
	 * Confirm a re-link proposal: the live smoke data source of every location
	 * moves to the new target and an archived data source keeps the old file,
	 * the summary data sources follow the new target, and the device mapping
	 * moves with them.
	 */
	public function relink(int $proposalId, int $userId, int $readerPollerId): ActionOutcome {
		$proposal = $this->targets->openProposal($proposalId);

		$old      = $proposal['old'] ?? null;
		$new      = $proposal['new'] ?? null;
		$oldRef = $old?->id;
		$newRef = $new?->id;

		if ($old === null || $new === null || $oldRef === null || $newRef === null) {
			return new ActionOutcome(sprintf('#%d', $proposalId), 0, [], [__('the proposal is no longer open', 'smokeping')]);
		}

		$oldId   = $oldRef->value;
		$newId   = $newRef->value;
		$subject = $old->path->value . ' -> ' . $new->path->value;
		$done    = [];
		$skipped = [];
		$hostId  = $this->targets->mappedHost($oldId);

		$refusal = RelinkGuard::refusal($hostId, $this->targets->mappedHost($newId), $old->pollerId, $new->pollerId, $readerPollerId, $this->objects->hostsOf($oldId));

		if ($refusal !== null) {
			return new ActionOutcome($subject, $hostId, [], [$refusal]);
		}

		// Claim the new target for the device before any data source moves, so
		// a concurrent mapping of it to another device cannot leave this
		// device graphing that device's file. Mapping never replaces another
		// device's mapping; the old mapping is dropped only once all succeeds.
		if ($hostId > 0 && !$this->objects->mapTarget($newId, $hostId, $userId, $this->clock->now())) {
			return new ActionOutcome($subject, $hostId, [], [__('the new target is mapped to another device', 'smokeping')]);
		}

		foreach ($old->locations() as $location) {
			$result = $this->provisioner->relink($old, $new, $location);

			if ($result->created) {
				$done[] = __('smoke graph %d now reads the new file; the old file stays graphable in an archived graph', $result->localGraphId, 'smokeping');
			} elseif ($this->objects->active($oldId, $location) !== null) {
				$skipped[] = $result->reason;
			}
		}

		// A file that cannot be moved leaves the proposal open for another try.
		if ($skipped !== []) {
			return new ActionOutcome($subject, $hostId, $done, $skipped);
		}

		include_once($this->coreLibrary . '/utility.php');

		foreach ($this->objects->summariesOf($oldId) as $summary) {
			$this->cacti->setSummarySeries($summary['local_data_id'], SummaryScript::series($newId, $summary['location']));
			$this->objects->repoint($summary['local_data_id'], $newId, '');
			push_out_host(0, $summary['local_data_id']);
			$done[] = __('summary data source %d follows the new target', $summary['local_data_id'], 'smokeping');
		}

		if ($hostId > 0) {
			$this->targets->unmap($oldId);
			$done[] = __('device %d is mapped to the new target', $hostId, 'smokeping');
		}

		$this->targets->decideProposal($proposalId, 'accepted', $userId, $this->clock->now());

		return new ActionOutcome($subject, $hostId, $done, $skipped);
	}

	/**
	 * Close a proposal without applying it.
	 */
	public function dismiss(int $proposalId, int $userId): void {
		$this->targets->decideProposal($proposalId, 'dismissed', $userId, $this->clock->now());
	}
}
