<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * Compares a validated configuration file with the installation and decides
 * what an import would do. Pure: it reads a ConfigState and writes nothing.
 *
 * Import only adds and updates. It never deletes a target or a mapping, never
 * moves a mapping to another device (re-linking stays an operator action on
 * the Inventory page), and updates an existing target only in the fields
 * the file carries; whether a target is present stays the inventory's call.
 */
final class ConfigPlanner {
	/** Target fields an import may change on an existing target. */
	private const UPDATABLE = ['host', 'probe', 'menu', 'title', 'slaves', 'nomasterpoll', 'step', 'pings', 'admin_state'];

	public static function plan(ConfigDocument $document, ConfigState $state): ConfigPlan {
		$errors     = [];
		$changes    = [];
		$notApplied = [];

		// Reference-only settings are compared so the plan can say they differ,
		// and are never part of what an apply writes.
		foreach ($document->reference as $name => $value) {
			if (($state->settings[$name] ?? null) !== $value) {
				$notApplied[] = ['name' => $name, 'from' => $state->settings[$name] ?? null, 'to' => $value];
			}
		}

		foreach ($document->settings as $name => $value) {
			if (ConfigSchema::isReferenceOnly($name)) {
				continue;
			}

			$current = $state->settings[$name] ?? null;

			if ($current === $value) {
				continue;
			}

			if ($name === 'smokeping_poller_id' && is_int($value) && !isset($state->pollers[$value])) {
				$errors[] = sprintf('setting %s names poller %d, which this installation does not have', $name, $value);

				continue;
			}

			$changes[] = ['name' => $name, 'from' => $current, 'to' => $value];
		}

		$adds      = [];
		$updates   = [];
		$unchanged = 0;
		$inFile    = [];

		foreach ($document->targets as $target) {
			$key = ConfigState::targetKey($target->pollerId, $target->path->value);

			$inFile[$key] = true;

			if (!isset($state->pollers[$target->pollerId])) {
				$errors[] = sprintf('target %s names poller %d, which this installation does not have', $target->path->value, $target->pollerId);

				continue;
			}

			$existing = $state->targets[$key] ?? null;

			if ($existing === null) {
				$adds[] = $target;

				continue;
			}

			$old    = ConfigPlan::fields($existing);
			$new    = ConfigPlan::fields($target);
			$fields = [];

			foreach (self::UPDATABLE as $field) {
				if ($old[$field] !== $new[$field]) {
					$fields[$field] = ['from' => $old[$field], 'to' => $new[$field]];
				}
			}

			if ($fields === []) {
				$unchanged++;
			} else {
				$updates[] = ['target' => $existing, 'fields' => $fields];
			}
		}

		$mappingAdds = [];

		foreach ($document->mappings as $mapping) {
			$key = $mapping->targetKey();

			if (!isset($inFile[$key]) && !isset($state->targets[$key])) {
				$errors[] = sprintf('mapping for target %s on poller %d: no such target in the file or in this installation', $mapping->path->value, $mapping->pollerId);

				continue;
			}

			$hosts = $state->devices[$mapping->deviceKey()] ?? [];

			if (count($hosts) !== 1) {
				$errors[] = count($hosts) === 0
					? sprintf('mapping for target %s: no device has host name "%s" and description "%s"; create or rename the device first', $mapping->path->value, $mapping->hostname, $mapping->description)
					: sprintf('mapping for target %s: more than one device has host name "%s" and description "%s"; rename one so the name is unique', $mapping->path->value, $mapping->hostname, $mapping->description);

				continue;
			}

			$current = $state->mappings[$key] ?? null;

			if ($current === null) {
				$mappingAdds[] = ['poller_id' => $mapping->pollerId, 'path' => $mapping->path->value, 'host_id' => $hosts[0], 'hostname' => $mapping->hostname, 'description' => $mapping->description];
			} elseif ($current['host_id'] === $hosts[0]) {
				$unchanged++;
			} else {
				// Says nothing about the device it is mapped to: that device may be one the reader cannot see.
				$errors[] = sprintf('mapping for target %s: it is already mapped to a different device; an import never moves a mapping, so re-link it on the Inventory page first', $mapping->path->value);
			}
		}

		return new ConfigPlan($changes, $adds, $updates, $mappingAdds, $unchanged, $errors, $notApplied);
	}

	/**
	 * Settings, targets, and mappings of this installation as a document, for
	 * export. The mount path is left out while it is the default under
	 * rra_path, so a file restored on a host with another rra_path follows
	 * that host.
	 */
	public static function export(ConfigState $state): ConfigDocument {
		$settings  = [];
		$reference = [];

		foreach ($state->settings as $name => $value) {
			if (!ConfigSchema::isReferenceOnly($name)) {
				$settings[$name] = $value;
			} elseif (!($name === 'smokeping_mount_path' && $value === $state->defaultMountPath)) {
				$reference[$name] = $value;
			}
		}

		$targets = array_values($state->targets);

		usort($targets, fn (Target $a, Target $b) => [$a->pollerId, $a->path->value] <=> [$b->pollerId, $b->path->value]);

		$mappings = [];

		foreach ($targets as $target) {
			$key = ConfigState::targetKey($target->pollerId, $target->path->value);

			if (isset($state->mappings[$key])) {
				$mappings[] = new ConfigMapping($target->pollerId, $target->path, $state->mappings[$key]['hostname'], $state->mappings[$key]['description']);
			}
		}

		return new ConfigDocument($settings, $targets, $mappings, $reference);
	}
}
