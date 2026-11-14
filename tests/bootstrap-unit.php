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
 * Unit test bootstrap. The classes under lib/ call a handful of Cacti helpers;
 * each is declared here with core's signature and a recording body, so unit
 * tests need neither a database nor a Cacti checkout.
 */

if (!defined('POLLER_VERBOSITY_MEDIUM')) {
	define('POLLER_VERBOSITY_NONE', 1);
	define('POLLER_VERBOSITY_LOW', 2);
	define('POLLER_VERBOSITY_MEDIUM', 3);
	define('POLLER_VERBOSITY_HIGH', 4);
	define('POLLER_VERBOSITY_DEBUG', 5);
}

$GLOBALS['__smokeping_log'] = [];

if (!function_exists('__')) {
	// Core's __() treats a trailing registered text domain as the domain and
	// passes the remaining arguments to sprintf().
	function __() {
		$args = func_get_args();

		if (count($args) > 1 && $args[count($args) - 1] === 'smokeping') {
			array_pop($args);
		}

		return count($args) > 1 ? vsprintf(array_shift($args), $args) : $args[0];
	}
}

if (!function_exists('cacti_log')) {
	function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '') {
		$GLOBALS['__smokeping_log'][] = ['message' => $string, 'environ' => $environ, 'level' => $level];

		return true;
	}
}

if (!function_exists('html_escape')) {
	function html_escape($string) {
		return htmlspecialchars((string) $string, ENT_QUOTES, 'UTF-8', false);
	}
}

$GLOBALS['__smokeping_options'] = [];

if (!function_exists('read_config_option')) {
	function read_config_option($config_name, $force = false) {
		return $GLOBALS['__smokeping_options'][$config_name] ?? '';
	}
}

$GLOBALS['__smokeping_exec'] = [];

if (!function_exists('exec_background')) {
	function exec_background($filename, $args = '', $redirect_args = '') {
		$GLOBALS['__smokeping_exec'][] = ['filename' => $filename, 'args' => $args];
	}
}

$GLOBALS['config'] = [
	'base_path'    => '/var/www/cacti',
	'library_path' => '/var/www/cacti/lib',
	'rra_path'     => '/var/www/cacti/rra',
	'poller_id'    => 1,
	'is_web'       => false,
];

require_once dirname(__DIR__) . '/setup.php';
require_once dirname(__DIR__) . '/include/autoload.php';
