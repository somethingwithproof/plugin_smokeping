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
 * What sizing did to one smoke graph's upper limit.
 */
final class GraphSizing {
	/** The plugin set a new limit from the last day's largest median. */
	public const SIZED = 'sized';

	/** The limit stayed: no median in the last day, no rrdtool, or an unreadable file. */
	public const KEPT = 'kept';

	/** The limit was set by hand, so the plugin left it alone; $limit is the one the plugin last set, if any. */
	public const MANUAL = 'manual';

	/**
	 * Create a new GraphSizing instance.
	 *
	 * @param string $status Status.
	 * @param string|null $limit Limit.
	 */
	private function __construct(
		public readonly string $status,
		public readonly ?string $limit,
	) {
	}

	/**
	 * Sized.
	 *
	 * @param string $limit Limit.
	 * @return self
	 */
	public static function sized(string $limit): self {
		return new self(self::SIZED, $limit);
	}

	/**
	 * Kept.
	 *
	 * @return self
	 */
	public static function kept(): self {
		return new self(self::KEPT, null);
	}

	/**
	 * Manual.
	 *
	 * @param string $recorded Recorded.
	 * @return self
	 */
	public static function manual(string $recorded): self {
		return new self(self::MANUAL, $recorded === '' ? null : $recorded);
	}
}
