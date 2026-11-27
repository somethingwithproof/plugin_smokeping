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
 * Poller-scoped replication of SmokePing configuration tables to Cacti remote
 * data collectors through the replicate_out hook.
 *
 * Only configuration rows belonging to the target remote poller are replicated
 * (plugin_smokeping_target, plugin_smokeping_mapping, plugin_smokeping_object,
 * plugin_smokeping_relink). Local-mode poller 1 is never overwritten, and the
 * collector's own observation cache and health rows stay local to the collector.
 */
final class RemoteReplication {
	public function __construct(
		private readonly TargetRepository $targets,
		private readonly ObjectRepository $objects,
	) {
	}

	/**
	 * Build poller-scoped table payloads for replication to $remotePollerId.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	public function payloadForPoller(int $remotePollerId): array {
		if ($remotePollerId <= 1) {
			return [];
		}

		return [
			'plugin_smokeping_target'  => $this->targets->forPollerRows($remotePollerId),
			'plugin_smokeping_mapping' => $this->targets->mappingsForPollerRows($remotePollerId),
			'plugin_smokeping_object'  => $this->objects->forPollerRows($remotePollerId),
			'plugin_smokeping_relink'  => $this->targets->relinkForPollerRows($remotePollerId),
		];
	}

	/**
	 * Execute the replicate_out hook payload using the supplied table replicator
	 * callable (defaults to Cacti's replicate_out_table()).
	 *
	 * @param array<string, mixed> $data
	 * @param (\Closure(mixed, array<int, array<string, mixed>>, string, int): void)|null $replicator
	 * @return array<string, mixed>
	 */
	public function handle(array $data, ?\Closure $replicator = null): array {
		$pollerId = isset($data['remote_poller_id']) && is_numeric($data['remote_poller_id'])
			? (int) $data['remote_poller_id']
			: (isset($data['poller_id']) && is_numeric($data['poller_id']) ? (int) $data['poller_id'] : 0);
		$rcnnId = $data['rcnn_id'] ?? false;
		$class  = isset($data['class']) && is_string($data['class']) ? $data['class'] : 'all';

		if ($pollerId <= 1 || $rcnnId === false || ($class !== 'all' && $class !== 'plugins')) {
			return $data;
		}

		$payload = $this->payloadForPoller($pollerId);

		foreach ($payload as $table => $rows) {
			if ($replicator !== null) {
				$replicator($rcnnId, $rows, $table, $pollerId);
			} elseif (is_object($rcnnId) && function_exists('replicate_out_table')) {
				replicate_out_table($rcnnId, $rows, $table, $pollerId);
			}
		}

		return $data;
	}
}
