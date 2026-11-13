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

chdir('../../');
include('./include/auth.php');
include_once('./plugins/smokeping/setup.php');

if (!smokeping_page_start()) {
	exit;
}

$smokeping_settings  = smokeping_settings();
$smokeping_clock     = new \Cacti\Smokeping\SystemClock();
$smokeping_mountinfo = is_readable('/proc/self/mountinfo') ? (string) file_get_contents('/proc/self/mountinfo') : '';

$smokeping_page = new \Cacti\Smokeping\HealthPage(
	new \Cacti\Smokeping\HealthCheck($smokeping_clock, max(60, (int) read_config_option('poller_interval'))),
	new \Cacti\Smokeping\MountCheck($smokeping_mountinfo),
	new \Cacti\Smokeping\HealthRepository(false),
	new \Cacti\Smokeping\TargetRepository(false)
);

top_header();
$smokeping_page->render($smokeping_settings, $config['rra_path'], trim((string) read_config_option('path_php_binary')));
bottom_footer();
