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
 * Render the SmokePing overview page for one device in a CLI process and
 * print the time, memory, and size, as JSON. The mount path is pointed at a
 * directory that does not exist: the page reads the database only, so it
 * renders the same without any RRD file (REQ-PERF-003).
 *
 *   php perf-page.php <device id>
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/plugins/smokeping/setup.php');

smokeping_boot();

db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('smokeping_mount_path', '/nonexistent/mount')");

$devices = array((int) $argv[1] => 'perf device');
$page    = new \Cacti\Smokeping\ViewPage(
	new \Cacti\Smokeping\TargetRepository(false),
	new \Cacti\Smokeping\ObservationRepository(false),
	new \Cacti\Smokeping\FreshnessEvaluator(new \Cacti\Smokeping\SystemClock(), 60)
);

$start = microtime(true);

ob_start();
$page->render($devices);
$html = ob_get_clean();

print json_encode(array('seconds' => round(microtime(true) - $start, 3), 'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1), 'rows' => substr_count((string) $html, 'tableRow'), 'html_mb' => round(strlen((string) $html) / 1048576, 2))) . PHP_EOL;
