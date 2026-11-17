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

// These boundaries are supplied by Cacti in production. The recording DB
// stubs in bootstrap-unit.php continue to exercise the real repositories.
function api_plugin_is_enabled($plugin) {
	return $GLOBALS['__operator_enabled'] ?? true;
}

function get_current_page($basename = true) {
	$page = $GLOBALS['__operator_page'] ?? 'smokeping_operator.php';

	return $basename ? basename($page) : $page;
}

function is_realm_allowed($realm, $check_user = false) {
	return !empty($_SESSION['sess_user_id']) && in_array($realm, $GLOBALS['__operator_grants'] ?? [], true);
}

function api_plugin_user_realm_auth($filename = '') {
	global $user_auth_realm_filenames;

	return $filename !== '' && isset($user_auth_realm_filenames[basename($filename)])
		&& is_realm_allowed($user_auth_realm_filenames[basename($filename)]);
}

function get_allowed_devices($sql_where = '', $sql_order = 'description', $sql_limit = '', &$total_rows = 0, $user_id = 0, $device_id = 0) {
	return $GLOBALS['__operator_devices'] ?? [];
}

function form_start($action, $id = '', $multipart = false) {
	print '<form method="post" action="' . html_escape($action) . '">';
}

function form_end($ajax = true) {
	print '</form>';
}

if (!function_exists('html_start_box')) {
	function html_start_box($title, $width, $div, $cell_padding, $align, $add_text) {
		print '<table>';
	}
}

if (!function_exists('html_end_box')) {
	function html_end_box($trailing_br = true) {
		print '</table>';
	}
}

function top_header() {
	print '<header>SmokePing</header>';
}

function top_graph_header() {
	print '<header>SmokePing graphs</header>';
}

function bottom_footer() {
	print '<footer>SmokePing</footer>';
}

function get_nfilter_request_var($name) {
	return $_REQUEST[$name] ?? '';
}

function isset_request_var($name) {
	return isset($_REQUEST[$name]);
}

function csrf_check($fatal = true) {
	if ($GLOBALS['__operator_csrf_failure'] ?? false) {
		throw new RuntimeException('sensitive CSRF validation detail');
	}

	return true;
}

function replicate_out($remote_poller_id = 1, $class = 'all') {
}

function api_plugin_register_hook($plugin, $hook, $function, $file, $enable = false) {
	$GLOBALS['__operator_hooks'][$hook] = [$function, $file, $enable];
}

function api_plugin_register_realm($plugin, $file, $display, $admin = true) {
	$GLOBALS['__operator_registered_realms'][$file] = [$display, $admin];
}
