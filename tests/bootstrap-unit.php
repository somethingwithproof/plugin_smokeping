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
	// Core's __() (include/global_languages.php) passes every argument to
	// sprintf(), the trailing text domain included, when the text has a
	// placeholder. A placeholder therefore takes the domain unless the caller
	// supplied values before it: __('%d s', $n, 'smokeping'), never
	// sprintf(__('%d s', 'smokeping'), $n).
	function __() {
		$args = func_get_args();

		if (count($args) === 1 || preg_match('/%(%|c|[-0-9.]*[bdeEfFgGhHosuxX])/', $args[0]) !== 1) {
			return $args[0];
		}

		return call_user_func_array('sprintf', $args);
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

// The purge sweep reads the purge queue; unit tests have no database. A test
// fills $GLOBALS['__smokeping_purge_queue'] with queue rows and reads the
// statements the code ran from $GLOBALS['__smokeping_db_exec'].
$GLOBALS['__smokeping_purge_queue'] = [];
$GLOBALS['__smokeping_db_exec']     = [];

// Rows for any other SELECT, the queries asked, and the row a single-row
// SELECT returns.
$GLOBALS['__smokeping_db_assoc']   = [];
$GLOBALS['__smokeping_db_queries'] = [];
$GLOBALS['__smokeping_db_row']     = [];

if (!function_exists('db_fetch_cell_prepared')) {
	function db_fetch_cell_prepared($sql, $params = [], $col_name = '', $log = true, $db_conn = false, $log_function = null) {
		return 0;
	}
}

if (!function_exists('db_fetch_assoc_prepared')) {
	function db_fetch_assoc_prepared($sql, $params = [], $log = true, $db_conn = false, $log_function = null) {
		$GLOBALS['__smokeping_db_queries'][] = ['sql' => $sql, 'params' => $params];

		if (str_contains($sql, 'data_source_purge_action')) {
			return $GLOBALS['__smokeping_purge_queue'];
		}

		// A closure answers each query from its SQL and parameters.
		return $GLOBALS['__smokeping_db_assoc'] instanceof Closure ? ($GLOBALS['__smokeping_db_assoc'])($sql, $params) : $GLOBALS['__smokeping_db_assoc'];
	}
}

if (!function_exists('db_fetch_row_prepared')) {
	function db_fetch_row_prepared($sql, $params = [], $log = true, $db_conn = false, $log_function = null) {
		return $GLOBALS['__smokeping_db_row'];
	}
}

// A statement containing $GLOBALS['__smokeping_db_fail'] fails, and the
// last statement matched $GLOBALS['__smokeping_db_affected'] rows. The
// transaction calls are recorded as statements named BEGIN, COMMIT, ROLLBACK.
$GLOBALS['__smokeping_db_fail']     = null;
$GLOBALS['__smokeping_db_affected'] = 1;

if (!function_exists('db_execute_prepared')) {
	function db_execute_prepared($sql, $params = [], $log = true, $db_conn = false, $log_function = null) {
		$GLOBALS['__smokeping_db_exec'][] = ['sql' => $sql, 'params' => $params];

		return $GLOBALS['__smokeping_db_fail'] === null || !str_contains($sql, $GLOBALS['__smokeping_db_fail']);
	}
}

if (!function_exists('db_affected_rows')) {
	function db_affected_rows($db_conn = false) {
		return $GLOBALS['__smokeping_db_affected'];
	}
}

function smokeping_test_transaction(string $statement): bool {
	$GLOBALS['__smokeping_db_exec'][] = ['sql' => $statement, 'params' => []];

	return true;
}

if (!function_exists('db_begin_transaction')) {
	function db_begin_transaction($db_conn = false) {
		return smokeping_test_transaction('BEGIN');
	}
}

if (!function_exists('db_commit_transaction')) {
	function db_commit_transaction($db_conn = false) {
		return smokeping_test_transaction('COMMIT');
	}
}

if (!function_exists('db_rollback_transaction')) {
	function db_rollback_transaction($db_conn = false) {
		return smokeping_test_transaction('ROLLBACK');
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
