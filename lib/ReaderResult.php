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

/** Reader result component. */
final readonly class ReaderResult {
	/**
	 * Create a new ReaderResult instance.
	 *
	 * @param bool $inventoryRan Inventory ran.
	 * @param bool $inventoryOk Inventory ok.
	 * @param int $seriesTotal Series total.
	 * @param int $seriesRead Series read.
	 * @param int $seriesFailed Series failed.
	 * @param bool $overrun Overrun.
	 */
	public function __construct(
		public bool $inventoryRan,
		public bool $inventoryOk,
		public int $seriesTotal,
		public int $seriesRead,
		public int $seriesFailed,
		public bool $overrun,
	) {
	}
}
