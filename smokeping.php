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

$smokeping_settings = smokeping_settings();
$smokeping_devices  = array();

foreach (get_allowed_devices() as $smokeping_device) {
	$smokeping_devices[(int) $smokeping_device['id']] = $smokeping_device['description'];
}

// The graph button links here with the device whose graph was open. The filter
// only narrows the devices the user may already see.
$smokeping_host_id = 0;

if (isset_request_var('host_id')) {
	$smokeping_only    = (int) get_filter_request_var('host_id');
	$smokeping_host_id = $smokeping_only;

	$smokeping_devices = array_intersect_key($smokeping_devices, array($smokeping_only => true));
}

$smokeping_search = isset_request_var('filter') ? (string) get_nfilter_request_var('filter') : '';
$smokeping_probe  = isset_request_var('probe') ? (string) get_nfilter_request_var('probe') : '';
$smokeping_state  = isset_request_var('state') ? (string) get_nfilter_request_var('state') : 'all';
$smokeping_pnum   = isset_request_var('page') ? (int) get_nfilter_request_var('page') : 1;
$smokeping_action = isset_request_var('action') ? (string) get_nfilter_request_var('action') : '';

$smokeping_filter = new \Somethingwithproof\Smokeping\TargetFilter(
	$smokeping_search,
	$smokeping_probe,
	'all',
	'all',
	$smokeping_state,
	$smokeping_pnum
);

$smokeping_clock = new \Somethingwithproof\Smokeping\SystemClock();
$smokeping_page  = new \Somethingwithproof\Smokeping\ViewPage(
	new \Somethingwithproof\Smokeping\TargetRepository(false),
	new \Somethingwithproof\Smokeping\ObservationRepository(false),
	new \Somethingwithproof\Smokeping\FreshnessEvaluator($smokeping_clock, $smokeping_settings->freshnessAllowance),
	new \Somethingwithproof\Smokeping\MaintMaintenance(),
	$smokeping_clock
);

if ($smokeping_action === 'export_csv') {
	$smokeping_csv = \Somethingwithproof\Smokeping\CsvExporter::exportTargets($smokeping_page->rows($smokeping_devices, $smokeping_filter));
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="smokeping-targets.csv"');
	header('X-Content-Type-Options: nosniff');
	header('Cache-Control: no-store');
	print $smokeping_csv;
	exit;
}

top_graph_header();
if (api_plugin_user_realm_auth('smokeping_operator.php')) {
	print '<p><a href="smokeping_operator.php">' . html_escape(__('SmokePing Operations', 'smokeping')) . '</a></p>';
}
$smokeping_page->render($smokeping_devices, $smokeping_filter, $smokeping_host_id);
bottom_footer();
