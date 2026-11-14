<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * What this installation holds now, as far as an import needs to compare it
 * with a file: effective settings, every target, the mappings with their
 * devices' names, the poller ids, and the devices the file's mappings name.
 */
final readonly class ConfigState {
	/**
	 * @param array<string, string|int|bool> $settings effective values, defaults included
	 * @param array<string, Target> $targets by targetKey()
	 * @param array<string, array{host_id: int, hostname: string, description: string}> $mappings by targetKey()
	 * @param array<int, true> $pollers poller ids
	 * @param array<string, list<int>> $devices host ids by deviceKey(), for the identities the file names
	 */
	public function __construct(
		public array $settings,
		public array $targets,
		public array $mappings,
		public array $pollers,
		public array $devices,
		public string $defaultMountPath,
	) {
	}

	public static function targetKey(int $pollerId, string $path): string {
		return $pollerId . ':' . $path;
	}

	/**
	 * Host names compare without regard to case, as DNS and Cacti's database
	 * collation do; descriptions compare exactly.
	 */
	public static function deviceKey(string $hostname, string $description): string {
		return strtolower($hostname) . "\0" . $description;
	}
}
