<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

namespace Cacti\Smokeping;

/**
 * Page files, realms, menu entries, and breadcrumbs.
 *
 * Cacti's auth.php denies any page that is not listed in a realm, so every
 * page script the plugin ships must appear in exactly one realm below.
 */
final class Pages {
	public const VIEW  = 'smokeping.php';
	public const IMPORT = 'smokeping_import.php';
	public const ADMIN  = ['smokeping_inventory.php', 'smokeping_health.php', 'smokeping_config.php', self::IMPORT];

	/**
	 * @return list<array{name: string, files: list<string>}>
	 */
	public static function realms(): array {
		return [
			['name' => __('SmokePing View', 'smokeping'), 'files' => [self::VIEW]],
			['name' => __('SmokePing Admin', 'smokeping'), 'files' => self::ADMIN],
		];
	}

	/**
	 * @return list<string>
	 */
	public static function files(): array {
		return array_merge([self::VIEW], self::ADMIN);
	}

	/**
	 * @return array<string, string>
	 */
	public static function menu(): array {
		return [
			'smokeping.php'           => __('SmokePing', 'smokeping'),
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
		$nav = [];

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
