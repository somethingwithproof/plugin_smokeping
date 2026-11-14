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
 * Checks that SmokePing's datadir reaches Cacti as a read-only mount inside
 * rra_path.
 *
 * Cacti 1.2.32 and later replace any data source path outside rra_path, or
 * reached through a symlink, with a generated path (data_source_path_within_rra()),
 * so a symlink is an error even on 1.2.31, where the layout must stay the same
 * to survive an upgrade. A writable mount is an error because read-only access
 * is the only reliable protection against Cacti's RRD purge logic.
 */
final class MountCheck {
	/**
	 * @param string $mountinfo contents of /proc/self/mountinfo, or '' where
	 *                          the platform has none
	 */
	public function __construct(private readonly string $mountinfo) {
	}

	/**
	 * @param list<string> $expectedFiles RRD files the inventory says exist
	 * @return list<Finding>
	 */
	public function check(string $mountPath, string $rraPath, array $expectedFiles): array {
		$findings = [];

		$containment = self::withinRra($mountPath, $rraPath);

		if ($containment !== null) {
			return [new Finding('mount', HealthLevel::Error, $containment)];
		}

		if (!is_dir($mountPath)) {
			return [new Finding('mount', HealthLevel::Error, __('The SmokePing mount %s does not exist. Mount SmokePing\'s data directory there read-only.', $mountPath, 'smokeping'))];
		}

		$findings[] = new Finding('mount', HealthLevel::Ok, __('%s is inside the RRA path and not reached through a symlink.', $mountPath, 'smokeping'));
		$findings[] = $this->readOnly($mountPath);

		if (is_writable($mountPath)) {
			$findings[] = new Finding('writable', HealthLevel::Error, __('The Cacti user can write to %s. SmokePing\'s RRD files must be read-only to Cacti.', $mountPath, 'smokeping'));
		}

		$findings[] = self::visibility($expectedFiles);

		return $findings;
	}

	/**
	 * Same rules as Cacti's data_source_path_within_rra(): a string prefix
	 * match on rra_path, no empty, '.' or '..' segments, and no symlink in any
	 * segment below the resolved rra_path.
	 *
	 * @return string|null an error message, or null when the path is acceptable
	 */
	public static function withinRra(string $path, string $rraPath): ?string {
		$base = rtrim($rraPath, '/');

		if ($path === '' || str_contains($path, "\0") || !str_starts_with($path, $base . '/')) {
			return __('The SmokePing mount %s is not inside the Cacti RRA path %s.', $path, $base, 'smokeping');
		}

		$baseReal = realpath($base);

		if ($baseReal === false) {
			return __('The Cacti RRA path %s does not exist.', $base, 'smokeping');
		}

		$walk = $baseReal;

		foreach (explode('/', substr($path, strlen($base) + 1)) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				return __('The SmokePing mount %s contains an empty, "." or ".." segment.', $path, 'smokeping');
			}

			$walk .= '/' . $segment;

			if (is_link($walk)) {
				return __('The SmokePing mount %s is reached through a symlink, which Cacti refuses. Use a read-only bind mount instead.', $path, 'smokeping');
			}
		}

		return null;
	}

	/**
	 * The mount containing the path, by longest mount point prefix, must carry
	 * the ro option. Without mountinfo the mode cannot be proven.
	 */
	private function readOnly(string $mountPath): Finding {
		$real  = realpath($mountPath);
		$entry = $real === false ? null : self::containingMount($this->mountinfo, $real);

		if ($entry === null) {
			return new Finding('readonly', HealthLevel::Unknown, __('Unable to read the mount table, so the mount could not be confirmed read-only.', 'smokeping'));
		}

		if ($entry['readonly']) {
			return new Finding('readonly', HealthLevel::Ok, __('%s is on a read-only mount.', $entry['mountpoint'], 'smokeping'));
		}

		return new Finding('readonly', HealthLevel::Error, __('%s is on the writable mount %s. Mount SmokePing\'s data directory read-only.', $mountPath, $entry['mountpoint'], 'smokeping'));
	}

	/**
	 * Parse /proc/self/mountinfo (proc(5)): field 5 is the mount point with
	 * octal escapes, field 6 the per-mount options, and the super options
	 * follow the " - fstype source" separator.
	 *
	 * @return array{mountpoint: string, readonly: bool}|null
	 */
	public static function containingMount(string $mountinfo, string $path): ?array {
		$best = null;

		foreach (explode("\n", $mountinfo) as $line) {
			$halves = explode(' - ', $line, 2);
			$fields = explode(' ', $halves[0]);

			if (count($fields) < 6 || count($halves) < 2) {
				continue;
			}

			$point = preg_replace_callback('/\\\\([0-7]{3})/', fn (array $m) => chr((int) octdec($m[1])), $fields[4]) ?? $fields[4];

			if ($point !== '/' && $path !== $point && !str_starts_with($path, rtrim($point, '/') . '/')) {
				continue;
			}

			// Later lines are mounted over earlier ones at the same point.
			if ($best === null || strlen($point) >= strlen($best['mountpoint'])) {
				$super    = explode(' ', $halves[1]);
				$options  = explode(',', $fields[5]);
				$soptions = explode(',', $super[2] ?? '');

				$best = ['mountpoint' => $point, 'readonly' => in_array('ro', $options, true) || in_array('ro', $soptions, true)];
			}
		}

		return $best;
	}

	/**
	 * @param list<string> $expectedFiles
	 */
	private static function visibility(array $expectedFiles): Finding {
		if ($expectedFiles === []) {
			return new Finding('visible', HealthLevel::Unknown, __('No inventory yet, so no RRD files are expected.', 'smokeping'));
		}

		$missing = array_values(array_filter($expectedFiles, fn (string $file) => !is_file($file)));

		if ($missing === []) {
			return new Finding('visible', HealthLevel::Ok, __('All %d expected RRD files are visible through the mount.', count($expectedFiles), 'smokeping'));
		}

		return new Finding('visible', HealthLevel::Warning, __('%d of %d expected RRD files are not visible through the mount, for example %s.', count($missing), count($expectedFiles), implode(', ', array_slice($missing, 0, 3)), 'smokeping'));
	}
}
