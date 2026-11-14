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
 * Plan the import of a SmokePing configuration into Cacti: which devices to
 * create or map, which graphs to create, and which tree branches and leaves
 * mirror SmokePing's hierarchy. Pure: it reads the inventory, the chosen
 * options, and a snapshot of the installation, and writes nothing.
 *
 * A device is matched by host name and poller (and, with one device per
 * target, by description too). A target already mapped keeps its device, so
 * running the same import again plans nothing new.
 */
final class SmokepingImportPlanner {
	public static function plan(Inventory $inventory, SmokepingImportOptions $options, SmokepingImportState $state, string $digest): SmokepingImportPlan {
		$errors = self::errors($options, $state);
		$notes  = $inventory->warnings;

		$sections = [];

		foreach ($inventory->targets as $target) {
			$parts = explode('/', $target->path->value);

			for ($i = 1; $i < count($parts); $i++) {
				$section = implode('/', array_slice($parts, 0, $i));
				$sections[$section] = ($sections[$section] ?? 0) + 1;
			}
		}

		ksort($sections, SORT_STRING);

		foreach ($options->sections as $section) {
			if (!isset($sections[$section])) {
				$errors[] = sprintf('The configuration has no section %s.', $section);
			}
		}

		$selected = [];

		foreach ($inventory->targets as $target) {
			if ($options->selects($target->path->value)) {
				$selected[] = $state->targets[$target->path->value] ?? $target;
			}
		}

		if ($selected === [] && $errors === []) {
			$errors[] = 'No target is selected.';
		}

		$groups = [];

		foreach ($selected as $target) {
			$key = $options->devicePer === SmokepingImportOptions::PER_TARGET ? 'target:' . $target->path->value : 'host:' . strtolower(trim($target->host));

			$groups[$key][] = $target;
		}

		$devices     = [];
		$graphs      = [];
		$summaryOnly = [];
		$branches    = [];
		$leaves      = [];
		$work        = [];

		foreach ($groups as $key => $targets) {
			$device = self::device((string) $key, $targets, $options, $state);
			$devices[] = $device;

			if ($device['action'] === 'ambiguous') {
				continue;
			}

			foreach ($targets as $target) {
				$id   = $target->id !== null ? $target->id->value : 0;
				$todo = false;

				foreach ($target->locations() as $location) {
					$exists   = $id > 0 && $state->hasObject($id, $location, 'summary');
					$graphs[] = ['path' => $target->path->value, 'location' => $location, 'kind' => 'summary', 'exists' => $exists];
					$todo     = $todo || !$exists;

					$reason = self::smokeRefusal($target, $state);

					if ($reason !== null) {
						$summaryOnly[$target->path->value] = $reason;

						continue;
					}

					$exists   = $id > 0 && $state->hasObject($id, $location, 'smoke');
					$graphs[] = ['path' => $target->path->value, 'location' => $location, 'kind' => 'smoke', 'exists' => $exists];
					$todo     = $todo || !$exists;
				}

				if ($todo) {
					$work[] = $target;
				}

				if ($options->mirrorsTree()) {
					$parts  = explode('/', $target->path->value);
					$branch = implode('/', array_slice($parts, 0, -1));

					for ($i = 1; $i < count($parts); $i++) {
						$branches[implode('/', array_slice($parts, 0, $i))] = true;
					}

					$leaves[$branch . "\0" . $device['key']] = ['branch' => $branch, 'device' => $device['key']];
				}
			}
		}

		$branches = array_map('strval', array_keys($branches));
		sort($branches, SORT_STRING);
		ksort($leaves, SORT_STRING);

		return new SmokepingImportPlan($digest, $options, $sections, $selected, $devices, $graphs, $summaryOnly, $branches, array_values($leaves), $errors, $notes, LoadEstimate::forTargets($work));
	}

	/**
	 * Why a target gets summary data only, or null when it can have a smoke graph.
	 */
	public static function smokeRefusal(Target $target, SmokepingImportState $state): ?string {
		if (!in_array($target->pings, TemplateInstaller::PINGS, true)) {
			return sprintf('it sends %d pings per round; smoke graphs exist for 10, 20, and 30', $target->pings);
		}

		if ($state->rrdProxy) {
			return 'Cacti keeps RRD files on an RRDproxy, where SmokePing\'s files do not exist';
		}

		return null;
	}

	/**
	 * @return list<string>
	 */
	private static function errors(SmokepingImportOptions $options, SmokepingImportState $state): array {
		$errors = [];

		if (!in_array($options->devicePer, [SmokepingImportOptions::PER_HOST, SmokepingImportOptions::PER_TARGET], true)) {
			$errors[] = 'Choose one device per SmokePing host or one per target.';
		}

		if ($options->pollerId !== $state->readerPollerId) {
			$errors[] = sprintf('Devices must be polled by poller %d, which runs the SmokePing reader; on another poller their summary data sources have no data.', $state->readerPollerId);
		}

		if (!isset($state->templates[$options->templateId])) {
			$errors[] = 'The device template does not exist.';
		}

		if ($options->siteId !== 0 && !isset($state->sites[$options->siteId])) {
			$errors[] = 'The site does not exist.';
		}

		if ($options->treeId > 0 && !isset($state->trees[$options->treeId])) {
			$errors[] = 'The graph tree does not exist.';
		}

		if (!$state->summaryTemplate) {
			$errors[] = 'The SmokePing templates are not imported; retry the import on the SmokePing Health page.';
		}

		return $errors;
	}

	/**
	 * The device a group of targets goes to.
	 *
	 * @param non-empty-list<Target> $targets
	 * @return array{key: string, hostname: string, description: string, action: string, host_id: int, targets: list<string>, reason: string}
	 */
	private static function device(string $key, array $targets, SmokepingImportOptions $options, SmokepingImportState $state): array {
		$first       = $targets[0];
		$perTarget   = $options->devicePer === SmokepingImportOptions::PER_TARGET;
		$hostname    = trim($first->host);
		$description = $perTarget ? $first->displayName() : $hostname;
		$paths       = array_map(fn (Target $t) => $t->path->value, $targets);
		$mapped      = [];

		foreach ($targets as $target) {
			$hostId = $target->id !== null ? ($state->mapped[$target->id->value] ?? 0) : 0;

			if ($hostId > 0) {
				$mapped[$hostId] = true;
			}
		}

		$entry = ['key' => $perTarget ? $first->path->value : $hostname, 'hostname' => $hostname, 'description' => $description, 'action' => 'create', 'host_id' => 0, 'targets' => $paths, 'reason' => ''];

		if ($mapped !== []) {
			$ids = array_keys($mapped);
			sort($ids);

			$entry['host_id'] = $ids[0];
			$entry['action']  = count($mapped) === 1 && count(array_filter($targets, fn (Target $t) => $t->id !== null && isset($state->mapped[$t->id->value]))) === count($targets) ? 'mapped' : 'map';

			return $entry;
		}

		$candidates = [];

		foreach ($state->devices as $device) {
			if (strcasecmp(trim($device['hostname']), $hostname) === 0 && $device['poller_id'] === $options->pollerId && (!$perTarget || $device['description'] === $description)) {
				$candidates[] = $device['id'];
			}
		}

		if (count($candidates) === 1) {
			$entry['action']  = 'map';
			$entry['host_id'] = $candidates[0];
		} elseif (count($candidates) > 1) {
			sort($candidates);

			$entry['action'] = 'ambiguous';
			$entry['reason'] = sprintf('devices %s on poller %d all have the host name %s; map the targets on the SmokePing Inventory page', implode(', ', $candidates), $options->pollerId, $hostname);
		}

		return $entry;
	}
}
