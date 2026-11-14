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
$smokeping_targets  = new \Somethingwithproof\Smokeping\TargetRepository(false);
$smokeping_page     = new \Somethingwithproof\Smokeping\InventoryPage($smokeping_targets);

/* ================= input validation ================= */
$smokeping_action = isset_request_var('action') ? get_nfilter_request_var('action') : '';
$smokeping_id     = isset_request_var('id') ? get_filter_request_var('id') : 0;
/* ==================================================== */

/**
 * Whether the user may re-link this proposal: both targets on the reader's
 * poller, the new target free or on the old target's device, and that device
 * one the user may change.
 */
function smokeping_relink_permitted($proposal, $targets, $pollerId) {
	if ($proposal === null || $proposal['old']->id === null || $proposal['new']->id === null) {
		return false;
	}

	$oldHost = $targets->mappedHost($proposal['old']->id->value);
	$newHost = $targets->mappedHost($proposal['new']->id->value);

	if (\Somethingwithproof\Smokeping\RelinkGuard::refusal($oldHost, $newHost, $proposal['old']->pollerId, $proposal['new']->pollerId, $pollerId) !== null) {
		return false;
	}

	return $oldHost === 0 || is_device_allowed($oldHost);
}

/**
 * Whether the user may see this target: unmapped, or mapped to a device the
 * user may see. The inventory list leaves out every other target, and the add
 * screen and its action, which take a target id from the request, must too.
 */
function smokeping_target_visible($target, $targets) {
	if ($target->id === null) {
		return false;
	}

	$host = $targets->mappedHost($target->id->value);

	return $host === 0 || is_device_allowed($host);
}

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

		if ($smokeping_target === null || !smokeping_target_visible($smokeping_target, $smokeping_targets) || $smokeping_target->pollerId !== $smokeping_settings->pollerId || !in_array($smokeping_mode, array('create', 'map'), true) || ($smokeping_mode === 'map' && ($smokeping_host < 1 || !is_device_allowed($smokeping_host)))) {
			raise_message('smokeping_add', __('Choose a target on this poller and, to map it, a device you may change.', 'smokeping'), MESSAGE_LEVEL_ERROR);
		} else {
			$smokeping_lines = $smokeping_service->add($smokeping_target, $smokeping_host, $smokeping_user)->lines();
		}
	} else {
		// Both targets must be on this poller, the new one free or on the old one's device, and that device one the user may change.
		$smokeping_proposal = $smokeping_targets->openProposal($smokeping_id);

		if (!smokeping_relink_permitted($smokeping_proposal, $smokeping_targets, $smokeping_settings->pollerId)) {
			raise_message('smokeping_action', __('The proposal is not open, or it involves a device or poller you may not change.', 'smokeping'), MESSAGE_LEVEL_ERROR);
		} elseif ($smokeping_action === 'relink_confirm') {
			$smokeping_lines = $smokeping_service->relink($smokeping_id, $smokeping_user, $smokeping_settings->pollerId)->lines();
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

	if ($smokeping_target === null || !smokeping_target_visible($smokeping_target, $smokeping_targets) || $smokeping_target->pollerId !== $smokeping_settings->pollerId) {
		header('Location: smokeping_inventory.php');
		exit;
	}

	$smokeping_devices = array(0 => __('None', 'smokeping'));

	foreach (get_allowed_devices() as $smokeping_device) {
		$smokeping_devices[(int) $smokeping_device['id']] = $smokeping_device['description'];
	}

	top_header();
	\Somethingwithproof\Smokeping\InventoryForms::add($smokeping_target, $smokeping_devices);
	bottom_footer();
	exit;
}

if ($smokeping_action === 'relink') {
	$smokeping_proposal = $smokeping_targets->openProposal($smokeping_id);

	if (!smokeping_relink_permitted($smokeping_proposal, $smokeping_targets, $smokeping_settings->pollerId)) {
		header('Location: smokeping_inventory.php');
		exit;
	}

	top_header();
	\Somethingwithproof\Smokeping\InventoryForms::relink($smokeping_id, $smokeping_proposal['old'], $smokeping_proposal['new'], $smokeping_targets->mappedHost($smokeping_proposal['old']->id->value));
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
