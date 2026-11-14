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

/*
 * Cacti includes this file on every request, on whatever PHP version the site
 * runs. It must parse on PHP 8.0 (CI lints it there), and nothing under lib/
 * is loaded until smokeping_boot() has confirmed PHP 8.2 or later. Every hook
 * and lifecycle function therefore starts with that check, so an unsupported
 * runtime gets a refusal instead of a parse error that would take down the
 * whole Cacti UI.
 */

function smokeping_php_supported(): bool {
	return version_compare(PHP_VERSION, '8.2.0', '>=');
}

function smokeping_boot(): bool {
	if (!smokeping_php_supported()) {
		return false;
	}

	require_once(__DIR__ . '/include/autoload.php');

	return true;
}

/**
 * Refuse with a message naming the required PHP version.
 *
 * Used by the lifecycle functions only. Hook shims return silently instead,
 * because they run on every page and a refused plugin must not flood the log.
 */
function smokeping_refuse_unsupported_php(): bool {
	if (smokeping_php_supported()) {
		return false;
	}

	$message = __('The SmokePing plugin requires PHP %s or later. This server runs PHP %s, so the plugin was not installed or enabled.', '8.2.0', PHP_VERSION, 'smokeping');

	cacti_log('ERROR: ' . $message, false, 'SMOKEPING');

	if (PHP_SAPI === 'cli') {
		fwrite(STDERR, $message . PHP_EOL);
	} elseif (function_exists('raise_message')) {
		raise_message('smokeping_php', $message, MESSAGE_LEVEL_ERROR);
	}

	return true;
}

function smokeping_settings(): \Cacti\Smokeping\Settings {
	global $config;

	$row = array();

	foreach (\Cacti\Smokeping\Settings::NAMES as $name) {
		$row[$name] = read_config_option($name);
	}

	return \Cacti\Smokeping\Settings::fromRow($row, $config['rra_path']);
}

/**
 * Common start of every page script, after include/auth.php has authorized
 * the realm. Refuses on an unsupported PHP or a disabled plugin, because
 * Cacti keeps plugin realms registered while a plugin is disabled.
 */
function smokeping_page_start(): bool {
	if (smokeping_boot() && api_plugin_is_enabled('smokeping')) {
		return true;
	}

	top_header();

	html_start_box(__('SmokePing', 'smokeping'), '100%', '', '3', 'center', '');

	print '<tr><td>' . html_escape(smokeping_php_supported() ? __('The SmokePing plugin is not enabled.', 'smokeping') : __('The SmokePing plugin requires PHP %s or later.', '8.2.0', 'smokeping')) . '</td></tr>';

	html_end_box();

	bottom_footer();

	return false;
}

/**
 * @return array<string, string>
 */
function plugin_smokeping_version(): array {
	$info = parse_ini_file(__DIR__ . '/INFO', true);

	if (!is_array($info) || !isset($info['info']) || !is_array($info['info'])) {
		return array();
	}

	$version = array();

	foreach ($info['info'] as $key => $value) {
		$version[(string) $key] = is_scalar($value) ? (string) $value : '';
	}

	return $version;
}

function plugin_smokeping_install(): void {
	if (smokeping_refuse_unsupported_php()) {
		return;
	}

	plugin_smokeping_upgrade();
}

function plugin_smokeping_check_config(): bool {
	if (smokeping_refuse_unsupported_php()) {
		return false;
	}

	return plugin_smokeping_upgrade();
}

/**
 * Bring the schema, hooks, and realms up to the code's version.
 *
 * Cacti 1.2.31 never calls this itself; check_config and the config_arrays
 * hook do. Fresh installs take the same path as upgrades. The version stored
 * in plugin_config changes only after every migration has succeeded, so an
 * interrupted run is finished by the next one.
 */
function plugin_smokeping_upgrade(): bool {
	global $config;

	if (!smokeping_boot()) {
		return false;
	}

	$info      = plugin_smokeping_version();
	$schema    = new \Cacti\Smokeping\Schema(false);
	$templates = new \Cacti\Smokeping\TemplateInstaller(__DIR__ . '/templates/smokeping-templates.xml', $config['library_path'], new \Cacti\Smokeping\CactiObjectRepository(false));

	if ($schema->isCurrent() && $schema->recordedPluginVersion() === $info['version'] && $schema->hooksRegistered() && $templates->isCurrent()) {
		return true;
	}

	if (!$schema->migrate()) {
		return false;
	}

	smokeping_setup_hooks();

	// Templates are imported last: a failed import leaves the version unrecorded,
	// so the next request or upgrade retries it.
	if (!$templates->install()) {
		return false;
	}

	$schema->recordPluginVersion($info['version'], $info['author'], $info['homepage']);

	return true;
}

/**
 * Register hooks and realms.
 *
 * Kept procedural and named *_setup_* because api_plugin_register_hook() and
 * api_plugin_register_realm() refuse callers whose function name does not
 * contain install, upgrade, or setup.
 */
function smokeping_setup_hooks(): void {
	global $config;

	// Registering a hook replicates plugin config, which needs core lib/poller.php.
	if (!function_exists('replicate_out')) {
		include_once($config['library_path'] . '/poller.php');
	}

	foreach (\Cacti\Smokeping\Schema::HOOKS as $hook => $function) {
		api_plugin_register_hook('smokeping', $hook, $function, 'setup.php');
	}

	foreach (\Cacti\Smokeping\Pages::realms() as $realm) {
		api_plugin_register_realm('smokeping', implode(',', $realm['files']), $realm['name'], true);
	}
}

/**
 * Uninstall never touches SmokePing's RRD files or configuration. Core drops
 * the tracked plugin tables afterwards unless the administrator chose to keep
 * them in the plugin settings.
 */
function plugin_smokeping_uninstall(): void {
	if (!smokeping_boot()) {
		return;
	}

	$schema = new \Cacti\Smokeping\Schema(false);

	if (read_config_option('smokeping_uninstall_keep_tables') == 'on') {
		$schema->untrackTables();

		cacti_log('NOTE: Uninstall kept the SmokePing plugin tables as configured', false, 'SMOKEPING');
	} else {
		$schema->forgetVersion();
	}
}

function smokeping_config_arrays(): void {
	global $menu;

	if (!smokeping_boot()) {
		return;
	}

	foreach (\Cacti\Smokeping\Pages::menu() as $file => $title) {
		$menu[__('Management')]['plugins/smokeping/' . $file] = $title;
	}

	if (in_array(get_current_page(), \Cacti\Smokeping\Pages::upgradeTriggers(), true)) {
		plugin_smokeping_upgrade();
	}
}

function smokeping_config_settings(): void {
	global $tabs, $settings, $config;

	if (!smokeping_boot()) {
		return;
	}

	$tabs['smokeping']     = __('SmokePing', 'smokeping');
	$settings['smokeping'] = \Cacti\Smokeping\Settings::form($config['rra_path']);
}

/**
 * @param array<string, mixed> $nav
 * @return array<string, mixed>
 */
function smokeping_draw_navigation_text($nav) {
	if (!smokeping_boot()) {
		return $nav;
	}

	return array_merge($nav, \Cacti\Smokeping\Pages::navigation());
}

/**
 * data_source_remove hook. Cacti queues the RRD purge after this runs, so the
 * hook disarms the plugin's data sources instead of deleting a queue row.
 *
 * @param mixed $localDataIds
 * @return mixed
 */
function smokeping_data_source_remove($localDataIds) {
	if (smokeping_boot() && is_array($localDataIds)) {
		smokeping_purge_guard()->dataSourcesRemoving(smokeping_int_list($localDataIds));
	}

	return $localDataIds;
}

/**
 * device_remove hook: the devices' plugin data sources and mappings.
 *
 * @param mixed $hostIds
 * @return mixed
 */
function smokeping_device_remove($hostIds) {
	if (smokeping_boot() && is_array($hostIds)) {
		smokeping_purge_guard()->devicesRemoving(smokeping_int_list($hostIds));
	}

	return $hostIds;
}

/**
 * @param array<array-key, mixed> $values
 * @return list<int>
 */
function smokeping_int_list(array $values): array {
	$ints = [];

	foreach ($values as $value) {
		$ints[] = (int) (is_scalar($value) ? $value : 0);
	}

	return $ints;
}

function smokeping_purge_guard(): \Cacti\Smokeping\PurgeGuard {
	return new \Cacti\Smokeping\PurgeGuard(new \Cacti\Smokeping\ObjectRepository(false), new \Cacti\Smokeping\CactiObjectRepository(false));
}

function smokeping_poller_bottom(): void {
	global $config;

	if (!smokeping_boot()) {
		return;
	}

	// Cacti's purge queue lives in the main database, which poller 1 owns.
	if ((int) $config['poller_id'] === 1) {
		smokeping_purge_guard()->sweep(smokeping_settings()->mountPath, $config['rra_path']);
	}

	if (!function_exists('exec_background')) {
		include_once($config['library_path'] . '/poller.php');
	}

	// Only the poller next to SmokePing has anything to read.
	$settings = smokeping_settings();

	if ($settings->pollerId !== (int) $config['poller_id']) {
		return;
	}

	// exec_background() silently does nothing for a binary that does not
	// exist, so a bare 'php' fallback would never start the reader.
	$php = trim((string) read_config_option('path_php_binary'));

	if ($php === '' || !is_file($php) || !is_executable($php)) {
		cacti_log('ERROR: The SmokePing reader was not started because the PHP binary path setting is empty or not an executable file', false, 'SMOKEPING');

		return;
	}

	// Core accepts an argument array and escapes each element, although its
	// docblock declares a string.
	// @phpstan-ignore argument.type
	exec_background($php, array('-q', __DIR__ . '/poller_smokeping.php', '--poller=' . (int) $config['poller_id']));
}
