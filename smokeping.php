<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

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
if (isset_request_var('host_id')) {
	$smokeping_only = get_filter_request_var('host_id');

	$smokeping_devices = array_intersect_key($smokeping_devices, array((int) $smokeping_only => true));
}

$smokeping_page = new \Somethingwithproof\Smokeping\ViewPage(
	new \Somethingwithproof\Smokeping\TargetRepository(false),
	new \Somethingwithproof\Smokeping\ObservationRepository(false),
	new \Somethingwithproof\Smokeping\FreshnessEvaluator(new \Somethingwithproof\Smokeping\SystemClock(), $smokeping_settings->freshnessAllowance)
);

top_graph_header();
$smokeping_page->render($smokeping_devices);
bottom_footer();
