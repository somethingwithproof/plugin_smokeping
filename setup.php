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

/**
 * The loss colors the smoke graphs draw: the setting, or on Automatic the
 * version of the SmokePing the reader found (LossColors::resolve()).
 */
function smokeping_loss_colors(): \Somethingwithproof\Smokeping\LossColors {
	$version = (new \Somethingwithproof\Smokeping\HealthRepository(false))->forPoller(smokeping_settings()->pollerId)->smokepingVersion;

	return \Somethingwithproof\Smokeping\LossColors::resolve((string) read_config_option(\Somethingwithproof\Smokeping\LossColors::SETTING), $version);
}

function smokeping_settings(): \Somethingwithproof\Smokeping\Settings {
	global $config;

	$row = array();

	foreach (\Somethingwithproof\Smokeping\Settings::NAMES as $name) {
		$row[$name] = read_config_option($name);
	}

	return \Somethingwithproof\Smokeping\Settings::fromRow($row, $config['rra_path']);
}

/**
 * Common start after include/auth.php establishes the user. Recheck the
 * realm here because cookie and Basic login restore the user after the
 * alternate-realms hook. Disabled plugins and unsupported runtimes refuse.
 */
function smokeping_page_start(): bool {
	if (smokeping_boot() && api_plugin_is_enabled('smokeping')) {
		if (smokeping_page_allowed()) {
			return true;
		}
		http_response_code(403);

		return false;
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

	plugin_smokeping_upgrade(true);
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
 *
 * A failed template import does not block the plugin and is not retried on
 * every request: it is recorded for the health page, and retried only on
 * install, when the plugin or template version changes, or when $retryTemplates
 * is set by an administrator's explicit action.
 */
function plugin_smokeping_upgrade(bool $retryTemplates = false): bool {
	global $config;

	if (!smokeping_boot()) {
		return false;
	}

	$info      = plugin_smokeping_version();
	$schema    = new \Somethingwithproof\Smokeping\Schema(false);
	$templates = new \Somethingwithproof\Smokeping\TemplateInstaller(__DIR__ . '/templates/smokeping-templates.xml', $config['library_path'], new \Somethingwithproof\Smokeping\CactiObjectRepository(false));

	$retry = $retryTemplates || $templates->mayRetry($info['version']);

	if ($schema->isCurrent() && $schema->recordedPluginVersion() === $info['version'] && $schema->hooksRegistered() && ($templates->isCurrent() || !$retry)) {
		return true;
	}

	if (!$schema->migrate()) {
		return false;
	}

	smokeping_setup_hooks();

	// Templates are imported last. A failure is recorded and logged once, and
	// the plugin still records its version so the next request does not
	// repeat the import.
	if (!$templates->isCurrent() && $retry) {
		$templates->install($info['version']);
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

	$enabled = (new \Somethingwithproof\Smokeping\Schema(false))->pluginEnabled();

	foreach (\Somethingwithproof\Smokeping\Schema::HOOKS as $hook => $function) {
		api_plugin_register_hook('smokeping', $hook, $function, 'setup.php', $enabled);
	}

	foreach (\Somethingwithproof\Smokeping\Pages::realms() as $realm) {
		api_plugin_register_realm('smokeping', implode(',', $realm['files']), $realm['name'], $realm['files'] !== \Somethingwithproof\Smokeping\Pages::OPERATOR);
	}
}

/**
 * Uninstall never touches SmokePing's RRD files or configuration. Core drops
 * the tracked plugin tables afterwards unless the administrator chose to keep
 * them in the plugin settings. Before that, the plugin's own configuration is
 * exported to Cacti's cache directory as YAML, so settings, targets, and
 * device mappings survive the table drop. A failed export is logged and does
 * not stop the uninstall.
 */
function plugin_smokeping_uninstall(): void {
	global $config;

	if (!smokeping_boot()) {
		return;
	}

	$exported = smokeping_config_service()->writeBackup($config['base_path'] . '/cache/smokeping', smokeping_settings()->backupDir, time(), $config['base_path']);

	if ($exported !== null) {
		$message = __('The SmokePing configuration was exported to %s', $exported, 'smokeping');

		cacti_log('NOTE: Uninstall exported the SmokePing configuration to ' . $exported, false, 'SMOKEPING');
	} else {
		$message = __('The SmokePing configuration was not exported; see the SmokePing log. The uninstall went ahead.', 'smokeping');
	}

	// Tell the administrator where it went, on the page that ran the uninstall or on the console.
	if (PHP_SAPI === 'cli') {
		fwrite(STDERR, $message . PHP_EOL);
	} elseif (function_exists('raise_message')) {
		raise_message('smokeping_export', html_escape($message), $exported !== null ? MESSAGE_LEVEL_INFO : MESSAGE_LEVEL_WARN);
	}

	$schema = new \Somethingwithproof\Smokeping\Schema(false);

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

	foreach (\Somethingwithproof\Smokeping\Pages::menu() as $file => $title) {
		$menu[__('Management')]['plugins/smokeping/' . $file] = $title;
	}

	foreach (\Somethingwithproof\Smokeping\Pages::importMenu() as $file => $title) {
		$menu[__('Import/Export')]['plugins/smokeping/' . $file] = $title;
	}

	if (in_array(get_current_page(), \Somethingwithproof\Smokeping\Pages::upgradeTriggers(), true)) {
		plugin_smokeping_upgrade();
	}
}

/**
 * top_header_tabs and top_graph_header_tabs hook: the SmokePing tab for users
 * with the SmokePing View realm. Cacti draws plugin tabs from the link's alt
 * text in its current themes, and from the image in the classic one.
 */
function smokeping_show_tab(): void {
	global $config;

	if (!smokeping_boot() || !api_plugin_is_enabled('smokeping') || !api_plugin_user_realm_auth(\Somethingwithproof\Smokeping\Pages::VIEW)) {
		return;
	}

	$down = get_current_page() === \Somethingwithproof\Smokeping\Pages::VIEW ? '_down' : '';

	print '<a id="smokeping" href="' . html_escape($config['url_path'] . 'plugins/smokeping/' . \Somethingwithproof\Smokeping\Pages::VIEW) . '"><img src="' . html_escape($config['url_path'] . 'plugins/smokeping/images/tab_smokeping' . $down . '.gif') . '" alt="' . html_escape(__('SmokePing', 'smokeping')) . '"></a>';
}

function smokeping_config_settings(): void {
	global $tabs, $settings, $config;

	if (PHP_SAPI !== 'cli' && !api_plugin_user_realm_auth('smokeping_inventory.php')) {
		return;
	}

	if (!smokeping_boot()) {
		return;
	}

	$tabs['smokeping']     = __('SmokePing', 'smokeping');
	$settings['smokeping'] = \Somethingwithproof\Smokeping\Settings::form($config['rra_path']);
}

/**
 * @param array<string, mixed> $nav
 * @return array<string, mixed>
 */
function smokeping_draw_navigation_text($nav) {
	if (!smokeping_boot()) {
		return $nav;
	}

	return array_merge($nav, \Somethingwithproof\Smokeping\Pages::navigation());
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

function smokeping_purge_guard(): \Somethingwithproof\Smokeping\PurgeGuard {
	return new \Somethingwithproof\Smokeping\PurgeGuard(new \Somethingwithproof\Smokeping\ObjectRepository(false), new \Somethingwithproof\Smokeping\CactiObjectRepository(false));
}

/**
 * The configuration export and import. With $webUser the service also checks,
 * on every call, that the acting user may see every device; the command line
 * acts as the system and is unrestricted.
 */
function smokeping_config_service(bool $webUser = false): \Somethingwithproof\Smokeping\ConfigService {
	global $config;

	$repository = new \Somethingwithproof\Smokeping\ConfigRepository(false, $config['rra_path']);

	return new \Somethingwithproof\Smokeping\ConfigService($repository, $webUser ? static function () use ($repository): bool {
		return smokeping_user_sees_every_device($repository);
	} : null);
}

/**
 * Whether the user's device permissions cover every device: only when
 * get_allowed_devices() returns every device Cacti lists. A user limited to
 * some devices, however many, is not unrestricted.
 */
function smokeping_user_sees_every_device(\Somethingwithproof\Smokeping\ConfigRepository $repository): bool {
	$allowed = get_allowed_devices();

	return is_array($allowed) && count($allowed) === $repository->deviceCount();
}

/**
 * The import of an existing SmokePing configuration. With $webUser the
 * service checks, on every call, that the acting user may see every device.
 */
function smokeping_import_service(bool $webUser = false): \Somethingwithproof\Smokeping\SmokepingImportService {
	global $config;

	$cacti       = new \Somethingwithproof\Smokeping\CactiObjectRepository(false);
	$objects     = new \Somethingwithproof\Smokeping\ObjectRepository(false);
	$settings    = smokeping_settings();
	$rrdProxy    = read_config_option('storage_location') != 0;
	$provisioner = new \Somethingwithproof\Smokeping\SmokeProvisioner($settings, $config['rra_path'], $config['library_path'], $rrdProxy, $objects, $cacti, new \Somethingwithproof\Smokeping\SystemClock(), smokeping_rrd_reader(), smokeping_loss_colors());
	$repository  = new \Somethingwithproof\Smokeping\ConfigRepository(false, $config['rra_path']);

	return new \Somethingwithproof\Smokeping\SmokepingImportService(
		$settings,
		new \Somethingwithproof\Smokeping\SmokepingImportRepository(false),
		new \Somethingwithproof\Smokeping\TargetRepository(false),
		smokeping_target_actions(),
		$provisioner,
		$cacti,
		$config['library_path'],
		$rrdProxy,
		$webUser ? static function () use ($repository): bool {
			return smokeping_user_sees_every_device($repository);
		} : null
	);
}

function smokeping_target_actions(): \Somethingwithproof\Smokeping\TargetActions {
	global $config;

	$cacti   = new \Somethingwithproof\Smokeping\CactiObjectRepository(false);
	$objects = new \Somethingwithproof\Smokeping\ObjectRepository(false);
	$clock   = new \Somethingwithproof\Smokeping\SystemClock();

	$provisioner = new \Somethingwithproof\Smokeping\SmokeProvisioner(smokeping_settings(), $config['rra_path'], $config['library_path'], read_config_option('storage_location') != 0, $objects, $cacti, $clock, smokeping_rrd_reader(), smokeping_loss_colors());

	return new \Somethingwithproof\Smokeping\TargetActions($provisioner, new \Somethingwithproof\Smokeping\TargetRepository(false), $objects, $cacti, $config['library_path'], $clock);
}

/**
 * rrdtool for sizing smoke graphs, or null when Cacti has no rrdtool binary
 * configured; the graphs then keep the template's upper limit.
 */
function smokeping_rrd_reader(): ?\Somethingwithproof\Smokeping\RrdReader {
	$rrdtool = (string) read_config_option('path_rrdtool');

	return $rrdtool !== '' && is_executable($rrdtool) ? new \Somethingwithproof\Smokeping\RrdtoolProcess($rrdtool) : null;
}

function smokeping_device_actions(): \Somethingwithproof\Smokeping\DeviceActions {
	return new \Somethingwithproof\Smokeping\DeviceActions(smokeping_target_actions(), new \Somethingwithproof\Smokeping\TargetRepository(false), new \Somethingwithproof\Smokeping\CactiObjectRepository(false));
}

/**
 * Whether the user may administer SmokePing targets. Cacti keeps a disabled
 * plugin's realms registered, so the plugin must also be enabled.
 */
function smokeping_user_is_admin(): bool {
	return smokeping_boot() && api_plugin_is_enabled('smokeping') && api_plugin_user_realm_auth('smokeping_inventory.php');
}

/**
 * device_action_array hook: the bulk "Add SmokePing Targets" action, offered
 * only to users with the SmokePing admin realm.
 *
 * @param mixed $actions
 * @return mixed
 */
function smokeping_device_action_array($actions) {
	if (is_array($actions) && smokeping_user_is_admin()) {
		return \Somethingwithproof\Smokeping\DeviceActions::register($actions);
	}

	return $actions;
}

/**
 * device_action_prepare hook: the confirm screen of the bulk action.
 *
 * @param mixed $save
 * @return mixed
 */
function smokeping_device_action_prepare($save) {
	if (!is_array($save) || ($save['drp_action'] ?? '') !== 'smokeping_add' || !smokeping_boot()) {
		return $save;
	}

	if (!smokeping_user_is_admin()) {
		print '<tr><td class="textArea">' . html_escape(__('You do not have permission to add SmokePing targets.', 'smokeping')) . '</td></tr>';

		return $save;
	}

	$hostIds = is_array($save['host_array'] ?? null) ? smokeping_int_list($save['host_array']) : [];

	smokeping_device_actions()->preview($hostIds, smokeping_settings()->pollerId);

	return $save;
}

/**
 * device_action_execute hook: run the bulk action once the operator confirmed.
 * Devices the user may not see are skipped, however they got into the request.
 *
 * @param mixed $action
 * @return mixed
 */
function smokeping_device_action_execute($action) {
	if ($action !== 'smokeping_add' || !smokeping_boot()) {
		return $action;
	}

	if (!smokeping_user_is_admin()) {
		raise_message('smokeping_add', __('You do not have permission to add SmokePing targets.', 'smokeping'), MESSAGE_LEVEL_ERROR);

		return $action;
	}

	$selected = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));
	$allowed  = [];

	if (is_array($selected)) {
		foreach (smokeping_int_list($selected) as $hostId) {
			if (is_device_allowed($hostId)) {
				$allowed[] = $hostId;
			}
		}
	}

	$userId   = isset($_SESSION['sess_user_id']) && is_numeric($_SESSION['sess_user_id']) ? (int) $_SESSION['sess_user_id'] : 0;
	$outcomes = smokeping_device_actions()->execute($allowed, smokeping_settings()->pollerId, $userId);

	raise_message('smokeping_add', implode('<br>', array_map('html_escape', \Somethingwithproof\Smokeping\DeviceActions::summary($outcomes))), MESSAGE_LEVEL_INFO);

	return $action;
}

/**
 * api_device_save hook: warn when a device with SmokePing targets is saved on
 * a poller that does not run the reader. The save goes ahead.
 *
 * @param mixed $save
 * @return mixed
 */
function smokeping_api_device_save($save) {
	if (!is_array($save) || !smokeping_boot()) {
		return $save;
	}

	$warning = (new \Somethingwithproof\Smokeping\DeviceSaveCheck(new \Somethingwithproof\Smokeping\TargetRepository(false)))->warning($save, smokeping_settings()->pollerId);

	if ($warning !== null) {
		cacti_log('WARNING: ' . $warning, false, 'SMOKEPING');
		raise_message('smokeping_device_poller', $warning, MESSAGE_LEVEL_WARN);
	}

	return $save;
}

/**
 * graph_buttons hook: a link from a graph of a device with SmokePing targets
 * to the SmokePing page filtered to that device.
 *
 * @param mixed $data
 * @return mixed
 */
function smokeping_graph_buttons($data) {
	global $config;

	if (!smokeping_boot() || !api_plugin_is_enabled('smokeping') || !api_plugin_user_realm_auth('smokeping.php')) {
		return $data;
	}

	$payload = is_array($data) && isset($data[1]) && is_array($data[1]) ? $data[1] : [];
	$graphId = isset($payload['local_graph_id']) && is_numeric($payload['local_graph_id']) ? (int) $payload['local_graph_id'] : 0;
	$links   = new \Somethingwithproof\Smokeping\GraphLinks(new \Somethingwithproof\Smokeping\TargetRepository(false), new \Somethingwithproof\Smokeping\CactiObjectRepository(false));
	$hostId  = $links->deviceFor($graphId);

	if ($hostId > 0) {
		print \Somethingwithproof\Smokeping\GraphLinks::forPage($config['url_path'], $hostId);
	}

	return $data;
}

/**
 * The summary data source's answer for one series, read from the reader's
 * cache with the settings of this poller.
 */
function smokeping_summary_answer(string $series): string {
	$clock      = new \Somethingwithproof\Smokeping\SystemClock();
	$calculator = new \Somethingwithproof\Smokeping\SummaryCalculator(new \Somethingwithproof\Smokeping\FreshnessEvaluator($clock, smokeping_settings()->freshnessAllowance), $clock);

	return (new \Somethingwithproof\Smokeping\SummaryScript(new \Somethingwithproof\Smokeping\ObservationRepository(false), $calculator))->run($series);
}

/**
 * poller_top and poller_finishing hook: remove queued purge rows under the
 * mount. poller.php starts poller_maintenance.php in the background before it
 * runs the poller_bottom hooks, so a sweep there races the purge and loses
 * whenever an earlier plugin's poller_bottom work takes a moment. poller_top
 * runs before the purge on every poller run, and poller_finishing just before
 * it once collection is done, which leaves only a row queued in the last
 * instant before the purge to the read-only mount.
 */
function smokeping_purge_sweep(): void {
	global $config;

	// Cacti's purge queue lives in the main database, which poller 1 owns.
	if (!smokeping_boot() || (int) $config['poller_id'] !== 1) {
		return;
	}

	smokeping_purge_guard()->sweep(smokeping_settings()->mountPath, $config['rra_path'], $config['base_path']);
}

function smokeping_poller_bottom(): void {
	global $config;

	if (!smokeping_boot()) {
		return;
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

/** Resolve role inheritance against Cacti's current user and group grants. */
function smokeping_auth_alternate_realms(): void {
	global $user_auth_realm_filenames;

	static $deferred = array();

	foreach ($deferred as $file => $realm) {
		$user_auth_realm_filenames[$file] = $realm;
	}
	$deferred = array();

	if (!api_plugin_is_enabled('smokeping')) {
		return;
	}

	if (empty($_SESSION['sess_user_id'])) {
		// Core restores cookie and Basic authentication after this hook. Only
		// these entrypoints defer the realm check to their post-auth guard.
		$page = get_current_page();

		if (in_array($page, array('smokeping.php', 'smokeping_operator.php'), true) && isset($user_auth_realm_filenames[$page])) {
			$deferred[$page]                  = $user_auth_realm_filenames[$page];
			$user_auth_realm_filenames[$page] = -1;
		}

		return;
	}
	$operator = 'smokeping_operator.php';
	$admin = 'smokeping_inventory.php';
	$files = array('smokeping.php');
	$realm = $user_auth_realm_filenames[$admin] ?? 0;
	if ($realm > 0 && is_realm_allowed($realm)) {
		$files[] = $operator;
	} else {
		$realm = $user_auth_realm_filenames[$operator] ?? 0;
		if ($realm <= 0 || !is_realm_allowed($realm)) {
			return;
		}
	}
	foreach ($files as $file) {
		$user_auth_realm_filenames[$file] = $realm;
	}
}

/** Recheck the authenticated user before a plugin page reads data or acts. */
function smokeping_page_allowed(): bool {
	global $current_user;

	smokeping_auth_alternate_realms();
	$userId    = $current_user['id'] ?? null;
	$sessionId = $_SESSION['sess_user_id'] ?? null;

	return (is_int($userId) || (is_string($userId) && ctype_digit($userId)))
		&& (is_int($sessionId) || (is_string($sessionId) && ctype_digit($sessionId)))
		&& (int) $userId > 0 && (int) $userId === (int) $sessionId
		&& api_plugin_is_enabled('smokeping')
		&& ($current_user['enabled'] ?? '') === 'on'
		&& api_plugin_user_realm_auth(get_current_page());
}
