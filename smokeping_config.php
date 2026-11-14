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

// The export and the import cover every target and mapping with the names of
// their devices, so a user must hold the SmokePing Admin realm (checked by
// auth.php) and be able to see every device. The page names no device.
if (!smokeping_user_sees_every_device(new \Cacti\Smokeping\ConfigRepository(false, $config['rra_path']))) {
	top_header();
	\Cacti\Smokeping\ConfigPage::refused();
	bottom_footer();
	exit;
}

$smokeping_service = smokeping_config_service(true);
$smokeping_post    = $_SERVER['REQUEST_METHOD'] === 'POST';

/* ================= input validation ================= */
$smokeping_action = $smokeping_post && isset_request_var('action') ? get_nfilter_request_var('action') : '';
$smokeping_format = isset_request_var('format') ? get_nfilter_request_var('format') : \Cacti\Smokeping\ConfigCodec::YAML;
$smokeping_plan   = isset_request_var('plan') ? get_nfilter_request_var('plan') : '';

if (!\Cacti\Smokeping\ConfigCodec::isFormat($smokeping_format)) {
	$smokeping_format = \Cacti\Smokeping\ConfigCodec::YAML;
}
/* ==================================================== */

$smokeping_user = isset($_SESSION['sess_user_id']) && is_numeric($_SESSION['sess_user_id']) ? (int) $_SESSION['sess_user_id'] : 0;

// Changes and the download need a posted form; csrf-magic has already checked its token.
if ($smokeping_action === 'export') {
	try {
		$smokeping_text = $smokeping_service->export($smokeping_format);
	} catch (\Cacti\Smokeping\ConfigException $smokeping_error) {
		top_header();
		\Cacti\Smokeping\ConfigPage::refused();
		bottom_footer();
		exit;
	}

	header('Content-Type: ' . ($smokeping_format === 'xml' ? 'application/xml' : 'application/yaml') . '; charset=UTF-8');
	header('Content-Disposition: attachment; filename="smokeping-config.' . $smokeping_format . '"');
	header('X-Content-Type-Options: nosniff');
	header('Content-Length: ' . strlen($smokeping_text));

	print $smokeping_text;
	exit;
}

if ($smokeping_action === 'check') {
	$smokeping_problems = array();
	$smokeping_result   = null;
	$smokeping_file     = isset($_FILES['config_file']) && is_array($_FILES['config_file']) ? $_FILES['config_file'] : array();

	unset($_SESSION['smokeping_config_pending']);

	if (($smokeping_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($smokeping_file['tmp_name'] ?? null) || !is_uploaded_file($smokeping_file['tmp_name'])) {
		$smokeping_problems[] = __('No file was uploaded, or it is larger than the server accepts.', 'smokeping');
	} else {
		try {
			$smokeping_text     = \Cacti\Smokeping\ConfigCodec::readFile($smokeping_file['tmp_name']);
			$smokeping_document = \Cacti\Smokeping\ConfigCodec::parse($smokeping_format, $smokeping_text);
			$smokeping_result   = $smokeping_service->plan($smokeping_document);

			if (!$smokeping_result->hasErrors() && !$smokeping_result->isEmpty()) {
				$_SESSION['smokeping_config_pending'] = array('format' => $smokeping_format, 'text' => $smokeping_text, 'plan' => $smokeping_result->id(), 'user' => $smokeping_user);
			}
		} catch (\Cacti\Smokeping\ConfigException $smokeping_error) {
			$smokeping_problems = $smokeping_error->lines();
		}
	}

	top_header();
	\Cacti\Smokeping\ConfigPage::plan($smokeping_result, $smokeping_problems);
	bottom_footer();
	exit;
}

if ($smokeping_action === 'apply') {
	$smokeping_pending = $_SESSION['smokeping_config_pending'] ?? null;

	unset($_SESSION['smokeping_config_pending']);

	if (!is_array($smokeping_pending) || ($smokeping_pending['user'] ?? 0) !== $smokeping_user || !hash_equals((string) ($smokeping_pending['plan'] ?? ''), $smokeping_plan)) {
		raise_message('smokeping_config', __('There is no checked import to apply. Check the file again.', 'smokeping'), MESSAGE_LEVEL_ERROR);
	} else {
		try {
			$smokeping_document = \Cacti\Smokeping\ConfigCodec::parse((string) $smokeping_pending['format'], (string) $smokeping_pending['text']);
			$smokeping_refusals = $smokeping_service->apply($smokeping_document, $smokeping_plan, $smokeping_user, time());
		} catch (\Cacti\Smokeping\ConfigException $smokeping_error) {
			$smokeping_refusals = $smokeping_error->lines();
		}

		if ($smokeping_refusals === array()) {
			raise_message('smokeping_config', __('The configuration was imported.', 'smokeping'), MESSAGE_LEVEL_INFO);
		} else {
			raise_message('smokeping_config', implode('<br>', array_map('html_escape', $smokeping_refusals)), MESSAGE_LEVEL_ERROR);
		}
	}

	header('Location: smokeping_config.php');
	exit;
}

top_header();
\Cacti\Smokeping\ConfigPage::main();
bottom_footer();
