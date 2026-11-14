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

// An import creates devices and maps targets across the whole SmokePing tree,
// so it needs the SmokePing Admin realm (checked by auth.php) and device
// permissions that cover every device, as the configuration page does.
if (!smokeping_user_sees_every_device(new \Somethingwithproof\Smokeping\ConfigRepository(false, $config['rra_path']))) {
	top_header();
	\Somethingwithproof\Smokeping\ImportPage::refused(__('Your device permissions do not cover every device, so you cannot import a SmokePing configuration. Ask an administrator with access to all devices.', 'smokeping'));
	bottom_footer();
	exit;
}

$smokeping_service    = smokeping_import_service(true);
$smokeping_repository = new \Somethingwithproof\Smokeping\SmokepingImportRepository(false);
$smokeping_settings   = smokeping_settings();
$smokeping_user       = isset($_SESSION['sess_user_id']) && is_numeric($_SESSION['sess_user_id']) ? (int) $_SESSION['sess_user_id'] : 0;

/** The largest uploaded configuration accepted; it is kept in the session between the steps. */
define('SMOKEPING_IMPORT_MAX_UPLOAD', 4194304);

/* ================= input validation ================= */
$smokeping_action = $_SERVER['REQUEST_METHOD'] === 'POST' && isset_request_var('action') ? get_nfilter_request_var('action') : '';

$smokeping_values = array(
	'source'     => 'server',
	'device_per' => \Somethingwithproof\Smokeping\SmokepingImportOptions::PER_HOST,
	'template'   => (string) $smokeping_service->defaultTemplateId(),
	'poller'     => (string) $smokeping_settings->pollerId,
	'site'       => '0',
	'tree'       => '0',
	'tree_name'  => '',
);

foreach (array('source' => '/^(server|upload)$/', 'device_per' => '/^(host|target)$/', 'template' => '/^[0-9]{1,9}$/', 'poller' => '/^[0-9]{1,9}$/', 'site' => '/^[0-9]{1,9}$/', 'tree' => '/^-?[0-9]{1,9}$/') as $smokeping_name => $smokeping_pattern) {
	if (isset_request_var($smokeping_name)) {
		$smokeping_value = get_nfilter_request_var($smokeping_name);

		if (is_string($smokeping_value) && preg_match($smokeping_pattern, $smokeping_value)) {
			$smokeping_values[$smokeping_name] = $smokeping_value;
		}
	}
}

if (isset_request_var('tree_name') && is_string(get_nfilter_request_var('tree_name'))) {
	$smokeping_values['tree_name'] = mb_substr(trim(get_nfilter_request_var('tree_name')), 0, 255);
}

$smokeping_sections = array();
$smokeping_posted   = isset_request_var('sections') ? get_nfilter_request_var('sections') : array();

if (is_array($smokeping_posted)) {
	foreach ($smokeping_posted as $smokeping_section) {
		if (is_string($smokeping_section) && preg_match('/^[-_0-9a-zA-Z]+(\/[-_0-9a-zA-Z]+)*$/', $smokeping_section)) {
			$smokeping_sections[] = $smokeping_section;
		}
	}
}

$smokeping_plan_id = isset_request_var('plan') && is_string(get_nfilter_request_var('plan')) ? get_nfilter_request_var('plan') : '';
/* ==================================================== */

$smokeping_tree    = (int) $smokeping_values['tree'];
$smokeping_options = new \Somethingwithproof\Smokeping\SmokepingImportOptions(
	$smokeping_values['device_per'],
	(int) $smokeping_values['template'],
	(int) $smokeping_values['poller'],
	(int) $smokeping_values['site'],
	array_values(array_unique($smokeping_sections)),
	max(0, $smokeping_tree),
	$smokeping_tree === -1 ? $smokeping_values['tree_name'] : ''
);
$smokeping_upload  = $smokeping_values['source'] === 'upload';

/**
 * Show a problem that stopped the preview, with a way back.
 */
function smokeping_import_problem($message) {
	top_header();
	\Somethingwithproof\Smokeping\ImportPage::refused($message);
	print '<a class="linkEditMain" href="smokeping_import.php">' . html_escape(__('Back', 'smokeping')) . '</a>';
	bottom_footer();
	exit;
}

/**
 * Read the configuration of the pending import: the server's file, or the
 * uploaded text kept in the session.
 *
 * @return array{0: \Somethingwithproof\Smokeping\Inventory, 1: string}
 */
function smokeping_import_read($service, $pending) {
	if (($pending['source'] ?? '') === 'upload') {
		return $service->read((string) ($pending['text'] ?? ''), (string) ($pending['name'] ?? 'uploaded file'));
	}

	return $service->read(null);
}

if ($smokeping_action === 'preview') {
	$smokeping_pending = $_SESSION['smokeping_import_pending'] ?? null;
	$smokeping_pending = is_array($smokeping_pending) && ($smokeping_pending['user'] ?? -1) === $smokeping_user ? $smokeping_pending : array();

	if ($smokeping_tree === -1 && $smokeping_values['tree_name'] === '') {
		smokeping_import_problem(__('Name the new graph tree, or choose an existing tree or no tree.', 'smokeping'));
	}

	if ($smokeping_upload) {
		$smokeping_file = isset($_FILES['smokeping_file']) && is_array($_FILES['smokeping_file']) ? $_FILES['smokeping_file'] : array();

		if (($smokeping_file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_string($smokeping_file['tmp_name'] ?? null) && is_uploaded_file($smokeping_file['tmp_name'])) {
			$smokeping_text = file_get_contents($smokeping_file['tmp_name'], false, null, 0, SMOKEPING_IMPORT_MAX_UPLOAD + 1);

			if (!is_string($smokeping_text) || strlen($smokeping_text) > SMOKEPING_IMPORT_MAX_UPLOAD) {
				smokeping_import_problem(__('The uploaded file is larger than 4 MiB.', 'smokeping'));
			}

			$smokeping_name    = preg_replace('/[^-_.0-9a-zA-Z]/', '_', basename((string) ($smokeping_file['name'] ?? 'upload')));
			$smokeping_pending = array('user' => $smokeping_user, 'source' => 'upload', 'text' => $smokeping_text, 'name' => $smokeping_name === '' ? 'upload' : $smokeping_name);
		} elseif (($smokeping_pending['source'] ?? '') !== 'upload') {
			smokeping_import_problem(__('No file was uploaded, or it is larger than the server accepts.', 'smokeping'));
		}
	} else {
		$smokeping_pending = array('user' => $smokeping_user, 'source' => 'server');
	}

	try {
		list($smokeping_inventory, $smokeping_digest) = smokeping_import_read($smokeping_service, $smokeping_pending);
	} catch (\Somethingwithproof\Smokeping\InventoryError $smokeping_error) {
		unset($_SESSION['smokeping_import_pending']);
		smokeping_import_problem(__('The SmokePing configuration cannot be read, so nothing was imported: %s', $smokeping_error->getMessage(), 'smokeping'));
	}

	$smokeping_plan = $smokeping_service->plan($smokeping_inventory, $smokeping_digest, $smokeping_options, $smokeping_upload);

	$smokeping_pending['plan']            = $smokeping_plan->id();
	$_SESSION['smokeping_import_pending'] = $smokeping_pending;

	$smokeping_hidden = $smokeping_values;
	unset($smokeping_hidden['tree_name']);
	$smokeping_hidden['tree_name'] = $smokeping_values['tree_name'];

	top_header();
	\Somethingwithproof\Smokeping\ImportPage::preview($smokeping_plan, $smokeping_hidden);
	bottom_footer();
	exit;
}

if ($smokeping_action === 'apply') {
	$smokeping_pending = $_SESSION['smokeping_import_pending'] ?? null;

	unset($_SESSION['smokeping_import_pending']);

	if (!is_array($smokeping_pending) || ($smokeping_pending['user'] ?? -1) !== $smokeping_user || !hash_equals((string) ($smokeping_pending['plan'] ?? ''), $smokeping_plan_id) || ($smokeping_pending['source'] ?? '') !== $smokeping_values['source']) {
		raise_message('smokeping_import', __('There is no previewed import to apply. Preview it again.', 'smokeping'), MESSAGE_LEVEL_ERROR);
	} else {
		try {
			list($smokeping_inventory, $smokeping_digest) = smokeping_import_read($smokeping_service, $smokeping_pending);
			list($smokeping_done, $smokeping_refusals) = $smokeping_service->apply($smokeping_inventory, $smokeping_digest, $smokeping_options, $smokeping_upload, $smokeping_plan_id, $smokeping_user, api_plugin_user_realm_auth('tree.php'));
		} catch (\Somethingwithproof\Smokeping\InventoryError $smokeping_error) {
			$smokeping_done     = array();
			$smokeping_refusals = array($smokeping_error->getMessage());
		}

		if ($smokeping_refusals !== array()) {
			raise_message('smokeping_import', implode('<br>', array_map('html_escape', $smokeping_refusals)), MESSAGE_LEVEL_ERROR);
		} else {
			$smokeping_shown = array_slice($smokeping_done, 0, 40);

			if (count($smokeping_done) > count($smokeping_shown)) {
				$smokeping_shown[] = __('and %d more; see the Cacti log', count($smokeping_done) - count($smokeping_shown), 'smokeping');
			}

			raise_message('smokeping_import', __('The SmokePing configuration was imported.', 'smokeping') . '<br>' . implode('<br>', array_map('html_escape', $smokeping_shown)), MESSAGE_LEVEL_INFO);
		}
	}

	header('Location: smokeping_import.php');
	exit;
}

top_header();
$smokeping_choices = $smokeping_repository->choices();
\Somethingwithproof\Smokeping\ImportPage::form($smokeping_values, $smokeping_settings->configFile, $smokeping_choices['pollers'], $smokeping_choices['templates'], $smokeping_choices['sites'], $smokeping_choices['trees']);
bottom_footer();
