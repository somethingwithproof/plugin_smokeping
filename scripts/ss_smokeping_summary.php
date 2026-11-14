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
 * Script server input of the "SmokePing Summary" data source. The poller
 * includes this file once and calls ss_smokeping_summary() for every series
 * it polls, so the function reads one row of the reader's cache and nothing
 * else. Run from the command line it prints the same answer, which is how an
 * operator checks a series:
 *
 *   php plugins/smokeping/scripts/ss_smokeping_summary.php <target id>~<location>
 *
 * The file must parse on PHP 8.0, like setup.php: the check below runs before
 * any class is loaded.
 */

if (!isset($called_by_script_server)) {
	include_once(dirname(__FILE__) . '/../../../include/cli_check.php');

	array_shift($_SERVER['argv']);

	print call_user_func_array('ss_smokeping_summary', $_SERVER['argv']);
}

/**
 * @param string $series "<target id>~<location>", the key of the reader's cache
 * @return string "median:<ms> loss_pct:<percent> age:<seconds>", each U when unknown
 */
function ss_smokeping_summary($series = '') {
	if (!function_exists('smokeping_boot')) {
		include_once(dirname(__FILE__) . '/../setup.php');
	}

	if (!smokeping_boot()) {
		return 'median:U loss_pct:U age:U';
	}

	return smokeping_summary_answer((string) $series);
}
