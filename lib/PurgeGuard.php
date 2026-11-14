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
 * Keeps Cacti's RRD purge from reaching SmokePing's files.
 *
 * The primary protection is the read-only mount (ADR 0007). This class is the
 * second layer. Cacti queues a purge row after the data_source_remove and
 * device_remove hooks have run, reading the data source's path at that moment,
 * so the hooks cannot delete the row; they replace the path of the plugin's
 * data sources with one that names no file, and the row Cacti then queues
 * points nowhere. A sweep removes rows queued by other routes, such as the RRD
 * cleaner page.
 */
final class PurgeGuard {
	public function __construct(
		private readonly ObjectRepository $objects,
		private readonly CactiObjectRepository $cacti,
	) {
	}

	/**
	 * data_source_remove hook: data sources about to be removed.
	 *
	 * @param list<int> $localDataIds
	 */
	public function dataSourcesRemoving(array $localDataIds): void {
		$owned = $this->objects->owned($localDataIds);

		if ($owned === []) {
			return;
		}

		$this->cacti->disarmPurge($owned);
		$this->objects->forget($owned);

		Log::info(sprintf('Disarmed the purge of %d SmokePing data sources being removed', count($owned)));
	}

	/**
	 * device_remove hook: devices about to be removed, with their data
	 * sources and mappings.
	 *
	 * @param list<int> $hostIds
	 */
	public function devicesRemoving(array $hostIds): void {
		$this->dataSourcesRemoving($this->cacti->dataSourcesOnDevices($hostIds));
		$this->objects->unmapDevices($hostIds);
	}

	/**
	 * Remove purge rows under the mount, however they were queued or spelled.
	 *
	 * @return int rows that were queued
	 */
	public function sweep(string $mountPath, string $rraPath, string $cactiPath): int {
		$ids = [];

		foreach ($this->cacti->queuedPurges() as $row) {
			if (self::isUnder(self::resolve($row['name'], $rraPath, $cactiPath), self::normalize($mountPath))) {
				$ids[] = $row['id'];
			}
		}

		if ($ids !== []) {
			$this->cacti->dropQueuedPurges($ids);
			Log::warning(sprintf('Removed %d queued purge entries under the SmokePing mount %s', count($ids), $mountPath));
		}

		return count($ids);
	}

	/**
	 * The file a queue name means, as poller_maintenance.php remove_files()
	 * reads it: <path_rra> and <path_cacti> are replaced, and a name that
	 * is still unchanged is relative to rra_path.
	 */
	public static function resolve(string $name, string $rraPath, string $cactiPath): string {
		$real = str_replace('<path_rra>', $rraPath, $name);
		$real = str_replace('<path_cacti>', $cactiPath, $real);

		if ($real === $name) {
			// Core prefixes rra_path. An absolute name already under rra_path is
			// kept as written as well: it is not what core would delete, but a
			// later core could read it that way, and dropping it is harmless.
			$absolute = self::normalize($name);

			if (str_starts_with($name, '/') && str_starts_with($absolute, self::normalize($rraPath) . '/')) {
				return $absolute;
			}

			$real = $rraPath . '/' . $name;
		}

		return self::normalize($real);
	}

	/**
	 * Collapse repeated slashes and resolve "." and ".." without touching the
	 * filesystem, the way the kernel would walk the same string.
	 */
	public static function normalize(string $path): string {
		$parts = [];

		foreach (explode('/', $path) as $part) {
			if ($part === '' || $part === '.') {
				continue;
			}

			if ($part === '..') {
				array_pop($parts);

				continue;
			}

			$parts[] = $part;
		}

		return '/' . implode('/', $parts);
	}

	public static function isUnder(string $file, string $directory): bool {
		return $directory !== '/' && str_starts_with($file, $directory . '/');
	}
}
