<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent


namespace Somethingwithproof\Smokeping;

/**
 * Outcome of provisioning one smoke data source and graph.
 *
 * A skipped result carries the reason shown to the operator; the target keeps
 * its summary objects only.
 */
final readonly class ProvisionResult {
	private function __construct(
		public bool $created,
		public string $reason,
		public int $localDataId,
		public int $localGraphId,
	) {
	}

	public static function created(int $localDataId, int $localGraphId): self {
		return new self(true, '', $localDataId, $localGraphId);
	}

	public static function skipped(string $reason): self {
		return new self(false, $reason, 0, 0);
	}
}
