<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

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

	public function close(): void;
}
