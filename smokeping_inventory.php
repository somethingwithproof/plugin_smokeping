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

$smokeping_settings = smokeping_settings();
$smokeping_targets  = new \Cacti\Smokeping\TargetRepository(false);
$smokeping_page     = new \Cacti\Smokeping\InventoryPage($smokeping_targets);

/* ================= input validation ================= */
$smokeping_action = isset_request_var('action') ? get_nfilter_request_var('action') : '';
$smokeping_id     = isset_request_var('id') ? get_filter_request_var('id') : 0;
/* ==================================================== */

$smokeping_user = isset($_SESSION['sess_user_id']) && is_numeric($_SESSION['sess_user_id']) ? (int) $_SESSION['sess_user_id'] : 0;

if (in_array($smokeping_action, array('add_confirm', 'relink_confirm', 'relink_dismiss'), true)) {
	// Changes need a posted form; csrf-magic has already checked its token.
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		header('Location: smokeping_inventory.php');
		exit;
	}

	$smokeping_service = smokeping_target_actions();
	$smokeping_lines   = array();

	if ($smokeping_action === 'add_confirm') {
		$smokeping_target = $smokeping_targets->byId($smokeping_id);
		$smokeping_mode   = get_nfilter_request_var('mode');
		$smokeping_host   = $smokeping_mode === 'map' ? (int) get_filter_request_var('host_id') : 0;

		if ($smokeping_target === null || $smokeping_target->pollerId !== $smokeping_settings->pollerId || !in_array($smokeping_mode, array('create', 'map'), true) || ($smokeping_mode === 'map' && ($smokeping_host < 1 || !is_device_allowed($smokeping_host)))) {
			raise_message('smokeping_add', __('Choose a target on this poller and, to map it, a device you may change.', 'smokeping'), MESSAGE_LEVEL_ERROR);
		} else {
			$smokeping_lines = $smokeping_service->add($smokeping_target, $smokeping_host, $smokeping_user)->lines();
		}
	} else {
		// The proposal's device must be one the user may change.
		$smokeping_proposal = $smokeping_targets->openProposal($smokeping_id);
		$smokeping_old      = $smokeping_proposal === null ? null : $smokeping_proposal['old']->id;
		$smokeping_host     = $smokeping_old === null ? 0 : $smokeping_targets->mappedHost($smokeping_old->value);

		if ($smokeping_proposal === null || ($smokeping_host > 0 && !is_device_allowed($smokeping_host))) {
			raise_message('smokeping_action', __('The proposal is not open or its device is not one you may change.', 'smokeping'), MESSAGE_LEVEL_ERROR);
		} elseif ($smokeping_action === 'relink_confirm') {
			$smokeping_lines = $smokeping_service->relink($smokeping_id, $smokeping_user)->lines();
		} else {
			$smokeping_service->dismiss($smokeping_id, $smokeping_user);
			$smokeping_lines = array(__('The proposal was dismissed.', 'smokeping'));
		}
	}

	if ($smokeping_lines !== array()) {
		raise_message('smokeping_action', implode('<br>', array_map('html_escape', $smokeping_lines)), MESSAGE_LEVEL_INFO);
	}

	header('Location: smokeping_inventory.php');
	exit;
}

if ($smokeping_action === 'add') {
	$smokeping_target = $smokeping_targets->byId($smokeping_id);

	if ($smokeping_target === null || $smokeping_target->pollerId !== $smokeping_settings->pollerId) {
		header('Location: smokeping_inventory.php');
		exit;
	}

	$smokeping_devices = array(0 => __('None', 'smokeping'));

	foreach (get_allowed_devices() as $smokeping_device) {
		$smokeping_devices[(int) $smokeping_device['id']] = $smokeping_device['description'];
	}

	top_header();
	\Cacti\Smokeping\InventoryForms::add($smokeping_target, $smokeping_devices);
	bottom_footer();
	exit;
}

if ($smokeping_action === 'relink') {
	$smokeping_proposal = $smokeping_targets->openProposal($smokeping_id);

	if ($smokeping_proposal === null || $smokeping_proposal['old']->id === null || ($smokeping_targets->mappedHost($smokeping_proposal['old']->id->value) > 0 && !is_device_allowed($smokeping_targets->mappedHost($smokeping_proposal['old']->id->value)))) {
		header('Location: smokeping_inventory.php');
		exit;
	}

	top_header();
	\Cacti\Smokeping\InventoryForms::relink($smokeping_id, $smokeping_proposal['old'], $smokeping_proposal['new'], $smokeping_targets->mappedHost($smokeping_proposal['old']->id->value));
	bottom_footer();
	exit;
}

$smokeping_visible = array();

foreach (get_allowed_devices() as $smokeping_device) {
	$smokeping_visible[(int) $smokeping_device['id']] = $smokeping_device['description'];
}

top_header();
$smokeping_page->render($smokeping_settings->pollerId, $smokeping_visible);
bottom_footer();
