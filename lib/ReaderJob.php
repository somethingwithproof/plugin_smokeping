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
 * One reader run on one poller: refresh the inventory when it is due, then
 * read each target location's last update into the observation cache.
 *
 * Series are read oldest-ingested first and each is stored as soon as it is
 * read, so a run that hits its time budget, or is killed, leaves a
 * consistent cache and the next run starts with the series it skipped.
 */
final class ReaderJob {
	public function __construct(
		private readonly int $pollerId,
		private readonly Settings $settings,
		private readonly TargetRepository $targets,
		private readonly ObservationRepository $observations,
		private readonly HealthRepository $health,
		private readonly InventoryReader $inventory,
		private readonly RrdReader $rrd,
		private readonly Clock $clock,
	) {
	}

	public function run(bool $forceInventory = false): ReaderResult {
		$started = $this->clock->now();
		$timer   = hrtime(true);

		[$inventoryRan, $inventoryOk] = $this->refreshInventory($forceInventory);

		$series = $this->queue();
		$read   = 0;
		$failed = 0;
		$over   = false;

		foreach ($series as [$target, $location]) {
			if ($this->clock->now() - $started >= $this->settings->readerBudget) {
				$over = true;

				break;
			}

			if ($this->readSeries($target, $location)) {
				$read++;
			} else {
				$failed++;
			}
		}

		$this->rrd->close();

		$duration = (hrtime(true) - $timer) / 1e9;

		$this->health->recordRun($this->pollerId, $started, $this->clock->now(), $duration, count($series), $read, $over);

		if ($over) {
			Log::warning(sprintf('Reader on poller %d hit its %d second budget after %d of %d series; the rest resume next cycle', $this->pollerId, $this->settings->readerBudget, $read + $failed, count($series)));
		}

		return new ReaderResult($inventoryRan, $inventoryOk, count($series), $read, $failed, $over);
	}

	/**
	 * @return array{bool, bool} ran, succeeded
	 */
	private function refreshInventory(bool $force): array {
		$last = $this->health->forPoller($this->pollerId);
		$now  = $this->clock->now();

		if (!$force && $last->inventoryAt > 0 && $now - $last->inventoryAt < $this->settings->inventoryInterval) {
			return [false, true];
		}

		try {
			$inventory = $this->inventory->read($this->pollerId);
		} catch (InventoryError $e) {
			// The stored targets stay as they were; the health page shows the error.
			$this->health->recordInventory($this->pollerId, $now, InventoryStatus::Error, $e->getMessage(), 0);

			Log::error(sprintf('Inventory on poller %d failed: %s', $this->pollerId, $e->getMessage()));

			return [true, false];
		}

		$plan = (new InventoryImporter())->plan($inventory, $this->targets->forPoller($this->pollerId));

		foreach ($plan->warnings as $warning) {
			Log::warning('Inventory: ' . $warning);
		}

		if (!$this->targets->applyImport($plan, $this->pollerId, $now)) {
			$this->health->recordInventory($this->pollerId, $now, InventoryStatus::Error, 'Import failed; see the Cacti log', 0);

			return [true, false];
		}

		$this->health->recordInventory($this->pollerId, $now, InventoryStatus::Ok, implode('; ', $plan->warnings), count($inventory->targets));

		Log::info(sprintf('Inventory on poller %d: %d targets, %d added, %d changed, %d gone, %d re-link proposals', $this->pollerId, count($inventory->targets), count($plan->added), count($plan->changed), count($plan->disappeared), count($plan->proposals)));

		return [true, true];
	}

	/**
	 * @return list<array{Target, string}>
	 */
	private function queue(): array {
		$ingested = $this->observations->ingestedAt($this->pollerId);
		$queue    = [];

		foreach ($this->targets->readable($this->pollerId) as $target) {
			foreach ($target->locations() as $location) {
				$queue[] = [$target, $location, $ingested[$target->id?->value . '~' . $location] ?? 0];
			}
		}

		usort($queue, fn (array $a, array $b) => $a[2] <=> $b[2]);

		return array_map(fn (array $entry) => [$entry[0], $entry[1]], $queue);
	}

	private function readSeries(Target $target, string $location): bool {
		if ($target->id === null) {
			return false;
		}

		$file = $target->path->rrdFile($this->settings->mountPath, $location);

		try {
			$update = $this->rrd->lastUpdate($file);
		} catch (\RuntimeException $e) {
			Log::warning(sprintf('Unable to read %s: %s', $file, $e->getMessage()));

			return false;
		}

		$this->observations->store(new Observation(
			targetId: $target->id,
			location: $location,
			observedAt: $update === null || $update->allUnknown() ? 0 : $update->time,
			ingestedAt: $this->clock->now(),
			expectedInterval: $target->step,
			pings: $target->pings,
			loss: $update?->value('loss'),
			median: $update?->value('median'),
			filePresent: $update !== null,
			revision: '',
		));

		return true;
	}
}
