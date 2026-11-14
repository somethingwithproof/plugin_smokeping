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
 * The bulk "Add SmokePing Targets" action of the device list: the confirm
 * screen and the execution, behind the device_action_* hooks.
 *
 * Each selected device gets the unmapped SmokePing targets whose host is the
 * device's host name. The confirm screen shows those matches first, so the
 * operator sees what would be created before continuing.
 */
final class DeviceActions {
	public const ACTION = 'smokeping_add';

	/** Seconds one request may spend on the action. */
	public const TIME_LIMIT = 300;

	public function __construct(
		private readonly TargetActions $actions,
		private readonly TargetRepository $targets,
		private readonly CactiObjectRepository $cacti,
	) {
	}

	/**
	 * @param array<array-key, mixed> $actions
	 * @return array<array-key, mixed>
	 */
	public static function register(array $actions): array {
		$actions[self::ACTION] = __('Add SmokePing Targets', 'smokeping');

		return $actions;
	}

	/**
	 * Rows of the confirm form, for the device_action_prepare hook.
	 *
	 * @param list<int> $hostIds
	 */
	public function preview(array $hostIds, int $pollerId): void {
		$devices = $this->cacti->devices($hostIds);
		$matches = $this->targets->unmappedByHost($pollerId, array_values(array_map(fn (array $d) => $d['hostname'], $devices)));
		$items   = '';
		$found   = 0;
		$matched = [];

		foreach ($hostIds as $hostId) {
			if (!isset($devices[$hostId])) {
				continue;
			}

			$device = $devices[$hostId];
			$paths  = array_map(fn (Target $t) => $t->path->value, $matches[strtolower(trim($device['hostname']))] ?? []);
			$found += count($paths);

			foreach ($matches[strtolower(trim($device['hostname']))] ?? [] as $target) {
				$matched[] = $target;
			}

			$items .= '<li>' . html_escape($device['description']) . ': ' . html_escape($paths === [] ? __('no matching SmokePing target', 'smokeping') : implode(', ', $paths)) . '</li>';
		}

		print '<tr><td class="textArea"><p>' . html_escape(__('Click Continue to add the SmokePing targets whose host is the host name of each selected device. Each target gets a summary graph for alerting and, when SmokePing\'s data can be graphed, a smoke graph. Devices are never created here.', 'smokeping')) . '</p>';
		print '<p>' . html_escape(__('%d targets match.', $found, 'smokeping')) . '</p>';

		foreach (LoadEstimate::forTargets($matched)->lines() as $line) {
			print '<p>' . html_escape($line) . '</p>';
		}

		print '<div class="itemlist"><ul>' . $items . '</ul></div></td></tr>';
	}

	/**
	 * Run the action. Devices the user may not change are skipped, and the
	 * caller must already have checked the plugin's admin realm.
	 *
	 * @param list<int> $hostIds
	 * @return list<ActionOutcome>
	 */
	public function execute(array $hostIds, int $pollerId, int $userId): array {
		// Each target takes a fraction of a second, so a long selection needs more
		// than the default web limit. The budget keeps a very long one from
		// outliving it; running the action again finishes the rest.
		if (function_exists('set_time_limit')) {
			set_time_limit(self::TIME_LIMIT);
		}

		return $this->actions->addToDevices($hostIds, $pollerId, $userId, self::TIME_LIMIT - 60);
	}

	/**
	 * Plain text lines for the message shown after the action.
	 *
	 * @param list<ActionOutcome> $outcomes
	 * @return list<string>
	 */
	public static function summary(array $outcomes): array {
		$created = 0;
		$skipped = 0;

		foreach ($outcomes as $outcome) {
			$created += count($outcome->done);
			$skipped += count($outcome->skipped);
		}

		$lines = [__('%d objects created or mapped, %d skipped.', $created, $skipped, 'smokeping')];

		foreach ($outcomes as $outcome) {
			if ($outcome->skipped !== [] && count($lines) < 11) {
				$lines[] = $outcome->subject . ': ' . $outcome->skipped[0];
			}
		}

		return $lines;
	}
}
