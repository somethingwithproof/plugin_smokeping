<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

final readonly class Finding {
	public function __construct(public string $check, public HealthLevel $level, public string $message) {
	}
}
