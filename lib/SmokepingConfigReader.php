<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

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
