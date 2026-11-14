<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

final readonly class TargetId {
	private function __construct(public int $value) {
	}

	public static function fromInt(int $value): self {
		if ($value < 1) {
			throw new \InvalidArgumentException(sprintf('Target id must be positive, got %d', $value));
		}

		return new self($value);
	}
}
