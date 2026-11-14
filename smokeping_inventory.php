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
$smokeping_page     = new \Somethingwithproof\Smokeping\InventoryPage(new \Somethingwithproof\Smokeping\TargetRepository(false));

top_header();
$smokeping_page->render($smokeping_settings->pollerId);
bottom_footer();
