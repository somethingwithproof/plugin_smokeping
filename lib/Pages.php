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
 * Page files, realms, menu entries, and breadcrumbs.
 *
 * Cacti's auth.php denies any page that is not listed in a realm, so every
 * page script the plugin ships must appear in exactly one realm below.
 */
final class Pages {
	/** Operator constant. */
	public const OPERATOR = ['smokeping_operator.php'];
	/** View constant. */
	public const VIEW  = 'smokeping.php';
	/** Import constant. */
	public const IMPORT = 'smokeping_import.php';
	/** Admin constant. */
	public const ADMIN  = ['smokeping_inventory.php', 'smokeping_health.php', 'smokeping_config.php', self::IMPORT];

	/**
	 * @return list<array{name: string, files: list<string>}>
	 */
	public static function realms(): array {
		return [
			['name' => __('SmokePing Operator', 'smokeping'), 'files' => self::OPERATOR],
			['name' => __('SmokePing View', 'smokeping'), 'files' => [self::VIEW]],
			['name' => __('SmokePing Admin', 'smokeping'), 'files' => self::ADMIN],
		];
	}

	/**
	 * @return list<string>
	 */
	public static function files(): array {
		return array_merge([self::VIEW], self::ADMIN, self::OPERATOR);
	}

	/**
	 * Console > Management entries: the administrator pages. The view page is
	 * a top tab instead, like Cacti's own graph pages, so a user without
	 * console access reaches it.
	 *
	 * @return array<string, string>
	 */
	public static function menu(): array {
		return [
			'smokeping_operator.php' => __('SmokePing Operations', 'smokeping'),
			'smokeping_inventory.php' => __('SmokePing Inventory', 'smokeping'),
			'smokeping_health.php'    => __('SmokePing Health', 'smokeping'),
			'smokeping_config.php'    => __('SmokePing Configuration', 'smokeping'),
		];
	}

	/**
	 * Entries for Console > Import/Export, where Cacti lists its own imports.
	 *
	 * @return array<string, string>
	 */
	public static function importMenu(): array {
		return [self::IMPORT => __('Import SmokePing Configuration', 'smokeping')];
	}

	/**
	 * Pages whose load re-checks the schema, matching how maintained plugins
	 * apply upgrades in a core that never calls plugin_*_upgrade() itself.
	 *
	 * @return list<string>
	 */
	public static function upgradeTriggers(): array {
		return array_merge(['plugins.php'], self::files());
	}

	/**
	 * @return array<string, array{title: string, mapping: string, url: string, level: string}>
	 */
	public static function navigation(): array {
		$nav = [
			self::VIEW . ':' => [
				'title'   => __('SmokePing', 'smokeping'),
				'mapping' => '',
				'url'     => self::VIEW,
				'level'   => '0',
			],
		];

		foreach (array_merge(self::menu(), self::importMenu()) as $file => $title) {
			$nav[$file . ':'] = [
				'title'   => $title,
				'mapping' => 'index.php:',
				'url'     => $file,
				'level'   => '1',
			];
		}

		return $nav;
	}
}
