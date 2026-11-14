<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * Boundary to SmokePing's configuration. Implementations must use
 * SmokePing's own parser; the plugin never parses the config in PHP.
 */
interface InventoryReader {
	/**
	 * @throws InventoryError when the configuration cannot be read or parsed
	 */
	public function read(int $pollerId): Inventory;
}
