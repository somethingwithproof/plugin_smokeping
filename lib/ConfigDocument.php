<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

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
