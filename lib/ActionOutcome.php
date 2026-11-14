<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * What an administrator's action did for one subject (a target or a device):
 * the objects it created and the reasons it skipped the rest, so a page can
 * show both.
 */
final readonly class ActionOutcome {
	/**
	 * @param list<string> $done
	 * @param list<string> $skipped
	 */
	public function __construct(
		public string $subject,
		public int $hostId,
		public array $done,
		public array $skipped,
	) {
	}

	public function succeeded(): bool {
		return $this->done !== [];
	}

	/**
	 * @return list<string>
	 */
	public function lines(): array {
		return array_merge(
			array_map(fn (string $line) => $this->subject . ': ' . $line, $this->done),
			array_map(fn (string $line) => $this->subject . ': ' . __('skipped, %s', $line, 'smokeping'), $this->skipped),
		);
	}
}
