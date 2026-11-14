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

$smokeping_templates = new \Somethingwithproof\Smokeping\TemplateInstaller(__DIR__ . '/templates/smokeping-templates.xml', $config['library_path'], new \Somethingwithproof\Smokeping\CactiObjectRepository(false));

// An administrator's explicit retry of the template import. POST only: csrf-magic
// has already validated the token by the time this page script runs.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && get_nfilter_request_var('action') === 'retry_templates') {
	if (plugin_smokeping_upgrade(true) && $smokeping_templates->present()) {
		raise_message('smokeping_templates', __('The SmokePing templates are imported.', 'smokeping'), MESSAGE_LEVEL_INFO);
	} else {
		raise_message('smokeping_templates', __('The SmokePing templates failed to import. See the health table.', 'smokeping'), MESSAGE_LEVEL_ERROR);
	}

	header('Location: smokeping_health.php');
	exit;
}

$smokeping_page = new \Somethingwithproof\Smokeping\HealthPage(
	new \Somethingwithproof\Smokeping\HealthCheck($smokeping_clock, max(60, (int) read_config_option('poller_interval'))),
	new \Somethingwithproof\Smokeping\MountCheck($smokeping_mountinfo),
	new \Somethingwithproof\Smokeping\HealthRepository(false),
	new \Somethingwithproof\Smokeping\TargetRepository(false),
	$smokeping_templates,
	new \Somethingwithproof\Smokeping\CactiObjectRepository(false)
);

top_header();
$smokeping_page->render($smokeping_settings, $config['rra_path'], trim((string) read_config_option('path_php_binary')));
bottom_footer();
