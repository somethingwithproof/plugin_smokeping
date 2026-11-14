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
 * open re-link proposals. Proposals are shown, never applied, here.
 */
final class InventoryPage {
	public function __construct(private readonly TargetRepository $targets) {
	}

	public function render(int $pollerId): void {
		$mappings = $this->targets->mappings();
		$rows     = [];

		foreach ($this->targets->forPoller($pollerId) as $target) {
			$rows[] = [
				$target->path->value,
				$target->displayName(),
				$target->host,
				$target->probe,
				implode(' ', $target->slaves),
				sprintf('%d s / %d', $target->step, $target->pings),
				$target->present ? __('Present', 'smokeping') : __('Gone', 'smokeping'),
				isset($mappings[(int) $target->id?->value]) ? sprintf(__('Device %d', 'smokeping'), $mappings[(int) $target->id?->value]) : __('Unmapped', 'smokeping'),
			];
		}

		Html::table(
			__('SmokePing Inventory', 'smokeping'),
			[__('Path', 'smokeping'), __('Name', 'smokeping'), __('Host', 'smokeping'), __('Probe', 'smokeping'), __('Slaves', 'smokeping'), __('Step / Pings', 'smokeping'), __('Inventory', 'smokeping'), __('Mapping', 'smokeping')],
			$rows,
			__('No targets have been imported on this poller.', 'smokeping')
		);

		$proposals = array_map(fn (array $p) => [$p['old_path'], $p['new_path'], $p['host'], $p['probe']], $this->targets->openRelinkProposals());

		Html::table(
			__('Re-link Proposals', 'smokeping'),
			[__('Disappeared Path', 'smokeping'), __('New Path', 'smokeping'), __('Host', 'smokeping'), __('Probe', 'smokeping')],
			$proposals,
			__('No re-link proposals.', 'smokeping')
		);
	}
}
