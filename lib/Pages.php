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
	public const ADMIN  = ['smokeping_inventory.php', 'smokeping_health.php'];

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
		];
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

		foreach (self::menu() as $file => $title) {
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
