<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * A target's device as the configuration file names it: by the device's host
 * name and description, never by its id, because ids differ between the
 * installation that wrote the file and the one that reads it.
 */
final readonly class ConfigMapping {
	public function __construct(
		public int $pollerId,
		public TargetPath $path,
		public string $hostname,
		public string $description,
	) {
	}

	public function targetKey(): string {
		return ConfigState::targetKey($this->pollerId, $this->path->value);
	}

	public function deviceKey(): string {
		return ConfigState::deviceKey($this->hostname, $this->description);
	}
}
