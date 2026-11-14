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
 * Administrator view of every target, including unmapped ones, and of the
 * open re-link proposals. Proposals are applied only from a confirmation screen.
 */
final class InventoryPage {
	public function __construct(private readonly TargetRepository $targets) {
	}

	/**
	 * @param array<int, string> $devices device id => description the user may see; a
	 *                                    target mapped to any other device is left out
	 */
	public function render(int $pollerId, array $devices): void {
		$mappings = $this->targets->mappings();
		$rows     = [];

		foreach ($this->targets->forPoller($pollerId) as $target) {
			$host = $mappings[(int) $target->id?->value] ?? 0;

			if ($host > 0 && !isset($devices[$host])) {
				continue;
			}

			$rows[] = [
				$target->path->value,
				$target->displayName(),
				$target->host,
				$target->probe,
				implode(' ', $target->slaves),
				sprintf('%d s / %d', $target->step, $target->pings),
				$target->present ? __('Present', 'smokeping') : __('Gone', 'smokeping'),
				$host > 0 ? $devices[$host] : __('Unmapped', 'smokeping'),
				$target->present && $target->id !== null && !isset($mappings[$target->id->value]) ? Html::link('smokeping_inventory.php', ['action' => 'add', 'id' => $target->id->value], __('Add to Cacti', 'smokeping')) : '',
			];
		}

		Html::table(
			__('SmokePing Inventory', 'smokeping'),
			[__('Path', 'smokeping'), __('Name', 'smokeping'), __('Host', 'smokeping'), __('Probe', 'smokeping'), __('Slaves', 'smokeping'), __('Step / Pings', 'smokeping'), __('Inventory', 'smokeping'), __('Mapping', 'smokeping'), __('Action', 'smokeping')],
			$rows,
			__('No targets have been imported on this poller.', 'smokeping')
		);

		$visible   = array_filter($this->targets->openRelinkProposals(), fn (array $p) => !isset($mappings[$p['old_id']]) || isset($devices[$mappings[$p['old_id']]]));
		$proposals = array_map(fn (array $p) => [$p['old_path'], $p['new_path'], $p['host'], $p['probe'], Html::link('smokeping_inventory.php', ['action' => 'relink', 'id' => $p['id']], __('Review', 'smokeping'))], array_values($visible));

		Html::table(
			__('Re-link Proposals', 'smokeping'),
			[__('Disappeared Path', 'smokeping'), __('New Path', 'smokeping'), __('Host', 'smokeping'), __('Probe', 'smokeping'), __('Action', 'smokeping')],
			$proposals,
			__('No re-link proposals.', 'smokeping')
		);
	}
}
