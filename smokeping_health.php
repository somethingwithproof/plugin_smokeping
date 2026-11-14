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

chdir('../../');
include('./include/auth.php');
include_once('./plugins/smokeping/setup.php');

if (!smokeping_page_start()) {
	exit;
}

$smokeping_settings  = smokeping_settings();
$smokeping_clock     = new \Somethingwithproof\Smokeping\SystemClock();
$smokeping_mountinfo = is_readable('/proc/self/mountinfo') ? (string) file_get_contents('/proc/self/mountinfo') : '';

$smokeping_page = new \Somethingwithproof\Smokeping\HealthPage(
	new \Somethingwithproof\Smokeping\HealthCheck($smokeping_clock, max(60, (int) read_config_option('poller_interval'))),
	new \Somethingwithproof\Smokeping\MountCheck($smokeping_mountinfo),
	new \Somethingwithproof\Smokeping\HealthRepository(false),
	new \Somethingwithproof\Smokeping\TargetRepository(false)
);

top_header();
$smokeping_page->render($smokeping_settings, $config['rra_path'], trim((string) read_config_option('path_php_binary')));
bottom_footer();
