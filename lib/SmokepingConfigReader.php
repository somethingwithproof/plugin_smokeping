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
 * Reads the target inventory from the SmokePing configuration file with the
 * plugin's own parser. Nothing outside the configuration file's directory
 * tree, or the directories given, is read, and nothing is written.
 */
final class SmokepingConfigReader implements InventoryReader {
	/**
	 * @param list<string> $allowedDirectories
	 */
	public function __construct(
		private readonly string $configFile,
		private readonly array $allowedDirectories = [],
	) {
	}

	public function read(int $pollerId): Inventory {
		if ($this->configFile === '') {
			throw new InventoryError('No SmokePing configuration file is set');
		}

		try {
			$root = (new SmokepingConfigParser($this->allowedDirectories))->parseFile($this->configFile);
		} catch (SmokepingConfigError $e) {
			throw new InventoryError(mb_substr($e->getMessage(), 0, 900));
		}

		return Inventory::fromDocument(SmokepingInventory::document($root), $pollerId);
	}

	/**
	 * Read a configuration given as text, such as an uploaded file. It is read
	 * on its own: an @include in it is refused, because the text has no
	 * directory the included file could be resolved against.
	 */
	public static function fromText(string $text, string $name, int $pollerId): Inventory {
		try {
			$root = (new SmokepingConfigParser())->parseString($text, $name);
		} catch (SmokepingConfigError $e) {
			throw new InventoryError(mb_substr($e->getMessage(), 0, 900));
		}

		return Inventory::fromDocument(SmokepingInventory::document($root), $pollerId);
	}
}
