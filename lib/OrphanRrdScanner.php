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
 * Read-only, budgeted scanner for RRD files under the SmokePing mount that no
 * target in the inventory owns (for example Local/LocalMachine.rrd or files
 * left behind after renames). Never modifies, deletes, or follows symlinks.
 */
final class OrphanRrdScanner {
	/** Default max files constant. */
	public const DEFAULT_MAX_FILES   = 20000;
	/** Default max seconds constant. */
	public const DEFAULT_MAX_SECONDS = 2.0;
	/** Default max depth constant. */
	public const DEFAULT_MAX_DEPTH   = 16;

	/** SmokePing's own internal directories under datadir that never hold targets. */
	private const SKIP_DIRS = ['__cgi', '__sortercache'];

	/**
	 * Create a new OrphanRrdScanner instance.
	 *
	 * @param int $maxFiles Max files.
	 * @param float $maxSeconds Max seconds.
	 * @param int $maxDepth Max depth.
	 */
	public function __construct(
		private readonly int $maxFiles = self::DEFAULT_MAX_FILES,
		private readonly float $maxSeconds = self::DEFAULT_MAX_SECONDS,
		private readonly int $maxDepth = self::DEFAULT_MAX_DEPTH,
	) {
	}

	/**
	 * Walk $mountPath read-only and list .rrd files absent from $expectedFiles.
	 *
	 * @param list<string> $expectedFiles absolute paths under $mountPath owned by targets
	 * @return array{
	 *     orphans: list<array{relative: string, mtime: int}>,
	 *     truncated: bool,
	 *     scanned: int
	 * }
	 */
	public function scan(string $mountPath, array $expectedFiles): array {
		$base = rtrim($mountPath, '/');

		if ($base === '' || is_link($base) || !is_dir($base) || !is_readable($base)) {
			return ['orphans' => [], 'truncated' => false, 'scanned' => 0];
		}

		$owned = [];

		foreach ($expectedFiles as $path) {
			$owned[$path] = true;
		}

		$deadlineNs = hrtime(true) + (int) (max(0.01, $this->maxSeconds) * 1e9);
		$scanned    = 0;
		$checked    = 0;
		$truncated  = false;
		$orphans    = [];
		$queue      = [['dir' => $base, 'rel' => '', 'depth' => 0]];
		$head       = 0;
		$queueCount = 1;

		while ($head < $queueCount) {
			if ($scanned >= $this->maxFiles || hrtime(true) >= $deadlineNs) {
				$truncated = true;

				break;
			}

			$frame = $queue[$head];
			unset($queue[$head]);
			$head++;

			$entries = @scandir($frame['dir']);

			if (!is_array($entries)) {
				continue;
			}

			$dirPrefix = $frame['dir'] . '/';
			$relPrefix = $frame['rel'] === '' ? '' : $frame['rel'] . '/';
			$depth     = $frame['depth'];

			foreach ($entries as $entry) {
				if ($entry === '.' || $entry === '..') {
					continue;
				}

				if ($scanned >= $this->maxFiles || ((++$checked & 63) === 0 && hrtime(true) >= $deadlineNs)) {
					$truncated = true;

					break 2;
				}

				$full = $dirPrefix . $entry;

				// Never follow symlinks inside the mount.
				if (is_link($full)) {
					continue;
				}

				if (is_dir($full)) {
					if ($depth === 0 && in_array($entry, self::SKIP_DIRS, true)) {
						continue;
					}

					if ($depth < $this->maxDepth) {
						$queue[$queueCount++] = ['dir' => $full, 'rel' => $relPrefix . $entry, 'depth' => $depth + 1];
					}

					continue;
				}

				if (!str_ends_with($entry, '.rrd') || !is_file($full)) {
					continue;
				}

				$scanned++;

				if (!isset($owned[$full])) {
					$mtime     = @filemtime($full);
					$orphans[] = [
						'relative' => $relPrefix . $entry,
						'mtime'    => is_int($mtime) ? $mtime : 0,
					];
				}
			}
		}

		usort($orphans, static fn (array $a, array $b): int => strcmp($a['relative'], $b['relative']));

		return [
			'orphans'   => $orphans,
			'truncated' => $truncated,
			'scanned'   => $scanned,
		];
	}
}
