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
 * Plain-array Google AIP resource descriptors for the api_register_resources hook.
 *
 * Emits read-only AIP-121/122/131/132 resource descriptors without importing
 * any external API plugin classes, preserving full runtime decoupling.
 */
final class ApiResources {
	/** Plugin slug prefix for registered AIP resource names. */
	public const PLUGIN = 'smokeping';

	/**
	 * Return canonical Google AIP plain-array descriptors for SmokePing resources.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function descriptors(): array {
		return [
			[
				'plugin'        => self::PLUGIN,
				'collection'    => 'smokepingTargets',
				'singleton'     => 'smokepingTarget',
				'description'   => 'Read-only SmokePing monitored targets.',
				'realm_read'    => Pages::VIEW,
				'schema'        => 'schema/smokeping.schema.yaml',
				'filter_fields' => ['id', 'name', 'poller_id', 'path', 'host', 'probe', 'present', 'mode', 'admin_state'],
				'order_fields'  => ['id', 'name', 'path', 'host', 'probe'],
				'handlers'      => [
					'list' => [self::class, 'listTargets'],
					'get'  => [self::class, 'getTarget'],
				],
			],
		];
	}

	/**
	 * List SmokePing targets for the smokepingTargets API collection.
	 *
	 * @param array<string, mixed> $context API handler context.
	 * @return list<array<string, mixed>>
	 */
	public static function listTargets(array $context = []): array {
		unset($context);

		if (!function_exists('db_fetch_assoc_prepared')) {
			return [];
		}

		$pollerId = function_exists('read_config_option') ? max(1, (int) read_config_option('smokeping_poller_id')) : 1;
		$targets  = (new TargetRepository(false))->forPoller($pollerId);
		$items    = [];

		foreach ($targets as $target) {
			$id      = (string) ($target->id?->value ?? 0);
			$items[] = [
				'id'          => $id,
				'name'        => 'smokepingTargets/' . $id,
				'poller_id'   => $target->pollerId,
				'path'        => $target->path->value,
				'host'        => $target->host,
				'probe'       => $target->probe,
				'title'       => $target->title,
				'step'        => $target->step,
				'pings'       => $target->pings,
				'present'     => $target->present,
				'mode'        => $target->mode->value,
				'admin_state' => $target->adminState->value,
			];
		}

		return $items;
	}

	/**
	 * Retrieve a single SmokePing target by numeric identifier.
	 *
	 * @param string               $id      Target identifier.
	 * @param array<string, mixed> $context API handler context.
	 * @return array<string, mixed>|null
	 */
	public static function getTarget(string $id, array $context = []): ?array {
		$targetId = trim($id);

		if ($targetId === '') {
			return null;
		}

		foreach (self::listTargets($context) as $item) {
			if (($item['id'] ?? '') === $targetId) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Append SmokePing AIP resource descriptors to a plain list.
	 *
	 * @param mixed $registry Existing resource list passed by api_register_resources.
	 * @return list<array<string, mixed>>
	 */
	public static function register($registry = []): array {
		$list = [];

		if (is_array($registry)) {
			foreach ($registry as $existing) {
				if (is_array($existing)) {
					$list[] = $existing;
				}
			}
		}

		foreach (self::descriptors() as $descriptor) {
			$list[] = $descriptor;
		}

		return $list;
	}
}
