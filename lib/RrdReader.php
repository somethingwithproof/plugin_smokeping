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
 * Boundary to rrdtool. The plugin only ever reads SmokePing's RRD files.
 */
interface RrdReader {
	/**
	 * @return RrdLastUpdate|null null when the file does not exist
	 * @throws \RuntimeException when rrdtool cannot read an existing file
	 */
	public function lastUpdate(string $file): ?RrdLastUpdate;

	/**
	 * The largest median in the file over the last $seconds, or null when the
	 * file does not exist or has no median in that range.
	 *
	 * @throws \RuntimeException when rrdtool cannot read an existing file
	 */
	public function maxMedian(string $file, int $seconds): ?float;

	/** Close. */
	public function close(): void;
}
