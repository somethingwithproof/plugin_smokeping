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
 * What an administrator chose for an import of a SmokePing configuration:
 * which sections, one device per SmokePing host or per target, the device
 * template, poller and site of new devices, and whether to mirror the
 * hierarchy into a graph tree.
 */
final readonly class SmokepingImportOptions {
	/** Per host constant. */
	public const PER_HOST   = 'host';
	/** Per target constant. */
	public const PER_TARGET = 'target';

	/**
	 * @param list<string> $sections section paths to import; empty imports every target
	 * @param int $treeId an existing graph tree to mirror into, or 0
	 * @param string $treeName the graph tree to mirror into, found or created by name, when $treeId is 0; '' for no tree
	 */
	public function __construct(
		public string $devicePer,
		public int $templateId,
		public int $pollerId,
		public int $siteId,
		public array $sections = [],
		public int $treeId = 0,
		public string $treeName = '',
	) {
	}

	/**
	 * Mirrors tree.
	 *
	 * @return bool
	 */
	public function mirrorsTree(): bool {
		return $this->treeId > 0 || $this->treeName !== '';
	}

	/**
	 * Whether the target at $path is in a selected section.
	 */
	public function selects(string $path): bool {
		if ($this->sections === []) {
			return true;
		}

		foreach ($this->sections as $section) {
			if ($path === $section || str_starts_with($path, $section . '/')) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$sections = $this->sections;
		sort($sections, SORT_STRING);

		return [
			'device_per' => $this->devicePer,
			'template'   => $this->templateId,
			'poller'     => $this->pollerId,
			'site'       => $this->siteId,
			'sections'   => $sections,
			'tree_id'    => $this->treeId,
			'tree_name'  => $this->treeName,
		];
	}
}
