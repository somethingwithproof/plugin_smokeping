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
 * Which of SmokePing's loss color tables the smoke graphs draw. SmokePing
 * 2.7 draws seven fixed loss levels; 2.8 replaced them with levels by share
 * of pings and added complete loss (docs/research/graph-fidelity.md). Each
 * table has its own smoke graph templates over the same data templates.
 */
enum LossColors: string {
	case Current = '2.8';
	case Legacy  = '2.7';

	/** Setting constant. */
	public const SETTING = 'smokeping_loss_colors';

	/** The setting's value that follows the detected SmokePing version. */
	public const AUTO = 'auto';

	/**
	 * The table of a SmokePing version as Smokeping.pm writes it, such as
	 * 2.007003; null for a version that is not in that form.
	 */
	public static function ofVersion(string $version): ?self {
		if (preg_match('/^(\d+)\.(\d{3})\d{3}$/D', $version, $m) !== 1) {
			return null;
		}

		return ((int) $m[1] < 2 || ((int) $m[1] === 2 && (int) $m[2] < 8)) ? self::Legacy : self::Current;
	}

	/**
	 * The setting when it names a table, else the detected version's table,
	 * else the current one.
	 */
	public static function resolve(string $setting, string $detectedVersion): self {
		return self::tryFrom($setting) ?? self::ofVersion($detectedVersion) ?? self::Current;
	}

	/**
	 * A version as Smokeping.pm writes it, 2.007003, as people write it,
	 * 2.7.3; anything else unchanged.
	 */
	public static function displayVersion(string $version): string {
		if (preg_match('/^(\d+)\.(\d{3})(\d{3})$/D', $version, $m) !== 1) {
			return $version;
		}

		return sprintf('%d.%d.%d', $m[1], $m[2], $m[3]);
	}

	/**
	 * Other.
	 *
	 * @return self
	 */
	public function other(): self {
		return $this === self::Current ? self::Legacy : self::Current;
	}
}
