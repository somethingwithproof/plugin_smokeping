<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * A parsed configuration file: the data, and the source line of every map key
 * and list item, keyed by its path joined with "/" (for example "targets/0/host"),
 * so schema errors can name a line.
 */
final readonly class ConfigTree {
	/**
	 * @param array<array-key, mixed> $data
	 * @param array<string, int> $lines
	 */
	public function __construct(public array $data, public array $lines) {
	}
}
