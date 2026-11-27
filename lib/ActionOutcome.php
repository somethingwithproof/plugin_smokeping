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

	/**
	 * Succeeded.
	 *
	 * @return bool
	 */
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
