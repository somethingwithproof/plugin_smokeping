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

/** Target id component. */
final readonly class TargetId {
	/**
	 * Create a new TargetId instance.
	 *
	 * @param int $value Value.
	 */
	private function __construct(public int $value) {
	}

	/**
	 * From int.
	 *
	 * @param int $value Value.
	 * @return self
	 */
	public static function fromInt(int $value): self {
		if ($value < 1) {
			throw new \InvalidArgumentException(sprintf('Target id must be positive, got %d', $value));
		}

		return new self($value);
	}
}
