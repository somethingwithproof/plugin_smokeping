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
	 * Return Google AIP-style plain-array descriptors for SmokePing resources.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function descriptors(): array {
		return [
			'smokeping/targets' => [
				'plugin'     => self::PLUGIN,
				'type'       => 'smokeping.cacti.net/Target',
				'collection' => 'smokeping/targets',
				'pattern'    => 'smokeping/targets/{target}',
				'singular'   => 'target',
				'plural'     => 'targets',
				'read_only'  => true,
				'methods'    => ['list', 'get'],
				'realm'      => Pages::VIEW,
				'fields'     => [
					'name'        => ['type' => 'string', 'behavior' => 'IDENTIFIER'],
					'poller_id'   => ['type' => 'integer', 'behavior' => 'OUTPUT_ONLY'],
					'path'        => ['type' => 'string', 'behavior' => 'OUTPUT_ONLY'],
					'host'        => ['type' => 'string', 'behavior' => 'OUTPUT_ONLY'],
					'probe'       => ['type' => 'string', 'behavior' => 'OUTPUT_ONLY'],
					'title'       => ['type' => 'string', 'behavior' => 'OUTPUT_ONLY'],
					'step'        => ['type' => 'integer', 'behavior' => 'OUTPUT_ONLY'],
					'pings'       => ['type' => 'integer', 'behavior' => 'OUTPUT_ONLY'],
					'present'     => ['type' => 'boolean', 'behavior' => 'OUTPUT_ONLY'],
					'mode'        => ['type' => 'string', 'behavior' => 'OUTPUT_ONLY'],
					'admin_state' => ['type' => 'string', 'behavior' => 'OUTPUT_ONLY'],
				],
			],
			'smokeping/observations' => [
				'plugin'     => self::PLUGIN,
				'type'       => 'smokeping.cacti.net/Observation',
				'collection' => 'smokeping/observations',
				'pattern'    => 'smokeping/targets/{target}/observations/{location}',
				'singular'   => 'observation',
				'plural'     => 'observations',
				'read_only'  => true,
				'methods'    => ['list', 'get'],
				'realm'      => Pages::VIEW,
				'fields'     => [
					'name'              => ['type' => 'string', 'behavior' => 'IDENTIFIER'],
					'target_id'         => ['type' => 'integer', 'behavior' => 'OUTPUT_ONLY'],
					'location'          => ['type' => 'string', 'behavior' => 'OUTPUT_ONLY'],
					'observed_at'       => ['type' => 'integer', 'behavior' => 'OUTPUT_ONLY'],
					'ingested_at'       => ['type' => 'integer', 'behavior' => 'OUTPUT_ONLY'],
					'expected_interval' => ['type' => 'integer', 'behavior' => 'OUTPUT_ONLY'],
					'pings'             => ['type' => 'integer', 'behavior' => 'OUTPUT_ONLY'],
					'loss'              => ['type' => 'number', 'nullable' => true, 'behavior' => 'OUTPUT_ONLY'],
					'median'            => ['type' => 'number', 'nullable' => true, 'behavior' => 'OUTPUT_ONLY'],
					'file_present'      => ['type' => 'boolean', 'behavior' => 'OUTPUT_ONLY'],
				],
			],
			'smokeping/health' => [
				'plugin'     => self::PLUGIN,
				'type'       => 'smokeping.cacti.net/PollerHealth',
				'collection' => 'smokeping/health',
				'pattern'    => 'smokeping/pollers/{poller}/health',
				'singular'   => 'health',
				'plural'     => 'health',
				'read_only'  => true,
				'methods'    => ['list', 'get'],
				'realm'      => Pages::VIEW,
				'fields'     => [
					'name'              => ['type' => 'string', 'behavior' => 'IDENTIFIER'],
					'poller_id'         => ['type' => 'integer', 'behavior' => 'OUTPUT_ONLY'],
					'run_started'       => ['type' => 'integer', 'behavior' => 'OUTPUT_ONLY'],
					'run_finished'      => ['type' => 'integer', 'behavior' => 'OUTPUT_ONLY'],
					'run_duration'      => ['type' => 'number', 'behavior' => 'OUTPUT_ONLY'],
					'series_total'      => ['type' => 'integer', 'behavior' => 'OUTPUT_ONLY'],
					'series_read'       => ['type' => 'integer', 'behavior' => 'OUTPUT_ONLY'],
					'budget_overruns'   => ['type' => 'integer', 'behavior' => 'OUTPUT_ONLY'],
					'inventory_status'  => ['type' => 'string', 'behavior' => 'OUTPUT_ONLY'],
					'inventory_targets' => ['type' => 'integer', 'behavior' => 'OUTPUT_ONLY'],
					'smokeping_version' => ['type' => 'string', 'behavior' => 'OUTPUT_ONLY'],
				],
			],
		];
	}

	/**
	 * Attach SmokePing AIP resource descriptors to an array accumulator or registrar object.
	 *
	 * @param mixed $registry Array accumulator or registrar object with register(string, array).
	 * @return mixed Updated registry value.
	 */
	public static function register($registry) {
		$descriptors = self::descriptors();

		if (is_array($registry)) {
			foreach ($descriptors as $key => $descriptor) {
				$registry[$key] = $descriptor;
			}

			return $registry;
		}

		if (is_object($registry) && method_exists($registry, 'register')) {
			foreach ($descriptors as $key => $descriptor) {
				$registry->register($key, $descriptor);
			}
		}

		return $registry;
	}
}
