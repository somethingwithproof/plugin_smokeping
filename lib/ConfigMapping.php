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
 * A target's device as the configuration file names it: by the device's host
 * name and description, never by its id, because ids differ between the
 * installation that wrote the file and the one that reads it.
 */
final readonly class ConfigMapping {
	/**
	 * Create a new ConfigMapping instance.
	 *
	 * @param int $pollerId Poller id.
	 * @param TargetPath $path Path.
	 * @param string $hostname Hostname.
	 * @param string $description Description.
	 */
	public function __construct(
		public int $pollerId,
		public TargetPath $path,
		public string $hostname,
		public string $description,
	) {
	}

	/**
	 * Target key.
	 *
	 * @return string
	 */
	public function targetKey(): string {
		return ConfigState::targetKey($this->pollerId, $this->path->value);
	}

	/**
	 * Device key.
	 *
	 * @return string
	 */
	public function deviceKey(): string {
		return ConfigState::deviceKey($this->hostname, $this->description);
	}
}
