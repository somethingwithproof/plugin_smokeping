<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

final readonly class ReaderResult {
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
