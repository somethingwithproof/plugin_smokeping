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
 * The plugin's own configuration as a file carries it: settings, observed
 * targets with their identity and descriptive fields, and device mappings.
 *
 * The document is the same whether it came from YAML or XML. It has no room
 * for managed targets or probe profiles; schema version 1 refuses those keys.
 */
final readonly class ConfigDocument {
	public const VERSION = 1;

	/**
	 * @param array<string, string|int|bool> $settings
	 * @param list<Target> $targets
	 * @param list<ConfigMapping> $mappings
	 * @param array<string, string|int|bool> $reference settings that name a path or program: shown in a file, never imported
	 */
	public function __construct(
		public array $settings,
		public array $targets,
		public array $mappings,
		public array $reference = [],
	) {
	}
}
