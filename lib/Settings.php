<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * Plugin settings, read once per request from Cacti's settings table.
 *
 * SmokePing's datadir and the Cacti-side mount are separate settings. Cacti
 * 1.2.32 and later refuse a data source path outside rra_path or reached
 * through a symlink, so SmokePing's datadir is exposed to Cacti as a read-only
 * bind or NFS mount under rra_path. Inventory paths are relative to
 * SmokePing's datadir; files are read through the mount.
 */
final readonly class Settings {
	public const NAMES = [
		'smokeping_config_file',
		'smokeping_datadir',
		'smokeping_mount_path',
		'smokeping_poller_id',
		'smokeping_reader_budget',
		'smokeping_inventory_interval',
		'smokeping_freshness_allowance',
		'smokeping_uninstall_keep_tables',
		'smokeping_backup_dir',
	];

	public function __construct(
		public string $configFile,
		public string $datadir,
		public string $mountPath,
		public int $pollerId,
		public int $readerBudget,
		public int $inventoryInterval,
		public int $freshnessAllowance,
		public bool $keepTablesOnUninstall,
		public string $backupDir = '',
	) {
	}

	public static function defaultMountPath(string $rraPath): string {
		return rtrim($rraPath, '/') . '/smokeping';
	}

	/**
	 * @param array<string, mixed> $row setting name => stored value
	 */
	public static function fromRow(array $row, string $rraPath): self {
		$text = static function (string $key, string $default) use ($row): string {
			$value = Row::string($row, $key);

			return $value === '' ? $default : $value;
		};

		return new self(
			configFile: $text('smokeping_config_file', '/etc/smokeping/config'),
			datadir: rtrim($text('smokeping_datadir', '/var/lib/smokeping'), '/'),
			mountPath: rtrim($text('smokeping_mount_path', self::defaultMountPath($rraPath)), '/'),
			pollerId: Row::int($row, 'smokeping_poller_id', 1, 1),
			readerBudget: Row::int($row, 'smokeping_reader_budget', 120, 1),
			inventoryInterval: Row::int($row, 'smokeping_inventory_interval', 300, 0),
			freshnessAllowance: Row::int($row, 'smokeping_freshness_allowance', 60, 0),
			keepTablesOnUninstall: Row::bool($row, 'smokeping_uninstall_keep_tables'),
			backupDir: rtrim(Row::string($row, 'smokeping_backup_dir'), '/'),
		);
	}

	/**
	 * Settings tab definition for the config_settings hook.
	 *
	 * Paths are plain text boxes, not Cacti filepath/dirpath fields: the
	 * SmokePing paths exist on the poller next to SmokePing, which need not be
	 * the server rendering this form. The health page checks them instead.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function form(string $rraPath): array {
		return [
			'smokeping_header_source' => [
				'friendly_name' => __('SmokePing Source', 'smokeping'),
				'method'        => 'spacer',
			],
			'smokeping_poller_id' => [
				'friendly_name' => __('SmokePing Poller', 'smokeping'),
				'description'   => __('The Cacti poller that runs on, or shares a filesystem with, the SmokePing host. Only this poller reads SmokePing data.', 'smokeping'),
				'method'        => 'drop_sql',
				'sql'           => 'SELECT id, name FROM poller ORDER BY id',
				'default'       => '1',
			],
			'smokeping_config_file' => [
				'friendly_name' => __('SmokePing Configuration File', 'smokeping'),
				'description'   => __('The main SmokePing configuration file, read by the poller that runs the reader. The plugin reads it and the files it includes with its own parser and never writes to them. Includes must stay inside this file\'s directory.', 'smokeping'),
				'method'        => 'textbox',
				'default'       => '/etc/smokeping/config',
				'max_length'    => '255',
				'size'          => '60',
			],
			'smokeping_datadir' => [
				'friendly_name' => __('SmokePing Data Directory', 'smokeping'),
				'description'   => __('The datadir from SmokePing\'s configuration, as SmokePing sees it.', 'smokeping'),
				'method'        => 'textbox',
				'default'       => '/var/lib/smokeping',
				'max_length'    => '255',
				'size'          => '60',
			],
			'smokeping_mount_path' => [
				'friendly_name' => __('Cacti Mount of the Data Directory', 'smokeping'),
				'description'   => __('A read-only bind or NFS mount of the SmokePing data directory inside the Cacti RRA path. Cacti only reads RRD files inside its RRA path and never through a symlink.', 'smokeping'),
				'method'        => 'textbox',
				'default'       => self::defaultMountPath($rraPath),
				'max_length'    => '255',
				'size'          => '60',
			],
			'smokeping_header_reader' => [
				'friendly_name' => __('Reader', 'smokeping'),
				'method'        => 'spacer',
			],
			'smokeping_reader_budget' => [
				'friendly_name' => __('Reader Time Budget', 'smokeping'),
				'description'   => __('Seconds one reader run may spend reading RRD files before it stops and resumes on the next poller cycle.', 'smokeping'),
				'method'        => 'textbox',
				'default'       => '120',
				'filter'        => FILTER_VALIDATE_INT,
				'options'       => ['options' => ['min_range' => 1, 'max_range' => 2147483647]],
				'max_length'    => '5',
				'size'          => '5',
			],
			'smokeping_inventory_interval' => [
				'friendly_name' => __('Inventory Interval', 'smokeping'),
				'description'   => __('Minimum seconds between inventory runs. The inventory also runs when the configuration file changes.', 'smokeping'),
				'method'        => 'textbox',
				'default'       => '300',
				'filter'        => FILTER_VALIDATE_INT,
				'options'       => ['options' => ['min_range' => 0, 'max_range' => 2147483647]],
				'max_length'    => '6',
				'size'          => '6',
			],
			'smokeping_freshness_allowance' => [
				'friendly_name' => __('Freshness Allowance', 'smokeping'),
				'description'   => __('Seconds past one step for which a measurement still counts as current.', 'smokeping'),
				'method'        => 'textbox',
				'default'       => '60',
				'filter'        => FILTER_VALIDATE_INT,
				'options'       => ['options' => ['min_range' => 0, 'max_range' => 2147483647]],
				'max_length'    => '5',
				'size'          => '5',
			],
			'smokeping_header_uninstall' => [
				'friendly_name' => __('Uninstall', 'smokeping'),
				'method'        => 'spacer',
			],
			'smokeping_uninstall_keep_tables' => [
				'friendly_name' => __('Keep Plugin Tables on Uninstall', 'smokeping'),
				'description'   => __('When checked, uninstalling the plugin keeps its inventory, mapping, and health tables. SmokePing files are never removed either way.', 'smokeping'),
				'method'        => 'checkbox',
				'default'       => '',
			],
			'smokeping_backup_dir' => [
				'friendly_name' => __('Backup Directory', 'smokeping'),
				'description'   => __('Where the uninstall writes its export of the plugin configuration. Leave blank to use the cache/smokeping directory of Cacti, which the plugin protects with a deny-all rule. A directory set here must already exist, be writable, and be outside the web root; when it is not writable no export is written.', 'smokeping'),
				'method'        => 'textbox',
				'default'       => '',
				'max_length'    => '255',
				'size'          => '60',
			],
		];
	}
}
