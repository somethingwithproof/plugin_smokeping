<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

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
	 * Console > Management entries: the administrator pages. The view page is
	 * a top tab instead, like Cacti's own graph pages, so a user without
	 * console access reaches it.
	 *
	 * @return array<string, string>
	 */
	public static function menu(): array {
		return [
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
