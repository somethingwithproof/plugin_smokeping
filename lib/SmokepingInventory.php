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
 * The target inventory of a parsed SmokePing configuration, as the document
 * Inventory::fromDocument() reads.
 *
 * This is the walk the plugin's former Perl inventory script made over
 * Smokeping::get_parser()'s result, and the differential tests under
 * tests/oracle compare the two field for field: a section with its own
 * host is a target unless the host starts with "/" (a multi-host graph with
 * no RRD of its own); step and pings resolve through the probe, its parent
 * probe and the Database section as SmokePing's probes resolve them.
 */
final class SmokepingInventory {
	/**
	 * @return array{format: int, smokeping: string, datadir: string, step: ?int, pings: ?int, targets: list<array{path: string, host: string, probe: string, menu: string, title: string, slaves: list<string>, nomasterpoll: bool, step: ?int, pings: ?int, probe_desc: ?string, alerts: string}>}
	 */
	public static function document(SmokepingSection $root): array {
		$general  = $root->child('General');
		$database = $root->child('Database');
		$probes   = $root->child('Probes');
		$targets  = [];

		$concurrent = ($general?->value('concurrentprobes') ?? '') !== 'no';
		$dbStep     = $database?->value('step');
		$dbPings    = $database?->value('pings');

		$walk = function (SmokepingSection $node, array $path) use (&$walk, &$targets, $probes, $concurrent, $dbStep, $dbPings): void {
			$host = $node->value('host');

			if ($path !== [] && $host !== null && !str_starts_with($host, '/')) {
				$probe          = $node->value('probe') ?? '';
				[$props, $base, $module] = self::probeProperties($probes, $probe);

				$step = $concurrent ? ($props?->value('step') ?? $base?->value('step') ?? $dbStep) : $dbStep;
				$pings = $node->value('pings') ?? $props?->value('pings') ?? $base?->value('pings') ?? $dbPings;
				$slaves = preg_split('/\s+/', $node->value('slaves') ?? '', -1, PREG_SPLIT_NO_EMPTY);

				$targets[] = [
					'path'         => implode('/', $path),
					'host'         => $host,
					'probe'        => $probe,
					'menu'         => $node->value('menu') ?? '',
					'title'        => $node->value('title') ?? '',
					'slaves'       => $slaves === false ? [] : $slaves,
					'nomasterpoll' => $node->value('nomasterpoll') === 'yes',
					'step'         => self::intOrNull($step),
					'pings'        => self::intOrNull($pings),
					'probe_desc'   => $module === null ? null : ProbeDescription::of($module, $props),
					'alerts'       => $node->value('alerts') ?? '',
				];
			}

			// Perl's sort over the hash keys: byte order, not file order.
			$children = $node->children;
			ksort($children, SORT_STRING);

			foreach ($children as $name => $child) {
				$walk($child, [...$path, (string) $name]);
			}
		};

		$top = $root->child('Targets');

		if ($top !== null) {
			$walk($top, []);
		}

		return [
			'format'    => Inventory::FORMAT,
			'smokeping' => '',
			'datadir'   => $general?->value('datadir') ?? '',
			'step'      => self::intOrNull($dbStep),
			'pings'     => self::intOrNull($dbPings),
			'targets'   => $targets,
		];
	}

	/**
	 * The probe section for a probe or probe instance name, the probe it is
	 * an instance of, and the probe module that runs it (load_probes(): the
	 * probe's own name, or its parent's for an instance).
	 *
	 * @return array{?SmokepingSection, ?SmokepingSection, ?string}
	 */
	private static function probeProperties(?SmokepingSection $probes, string $name): array {
		if ($probes === null) {
			return [null, null, null];
		}

		$own = $probes->child($name);

		if ($own !== null) {
			return [$own, null, $name];
		}

		foreach ($probes->children as $module => $parent) {
			$sub = $parent->child($name);

			if ($sub !== null) {
				return [$sub, $parent, (string) $module];
			}
		}

		return [null, null, null];
	}

	private static function intOrNull(?string $value): ?int {
		if ($value === null || preg_match('/^\d+$/D', $value) !== 1) {
			return null;
		}

		$number = ltrim($value, '0');

		// Perl keeps 64-bit integers exact; longer values are not a usable step or pings either way.
		return $number === '' ? 0 : (strlen($number) > 18 ? null : (int) $number);
	}
}
