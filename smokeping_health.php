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

$smokeping_cacti     = new \Somethingwithproof\Smokeping\CactiObjectRepository(false);
$smokeping_targets   = new \Somethingwithproof\Smokeping\TargetRepository(false);
$smokeping_objects   = new \Somethingwithproof\Smokeping\ObjectRepository(false);
$smokeping_templates = new \Somethingwithproof\Smokeping\TemplateInstaller(__DIR__ . '/templates/smokeping-templates.xml', $config['library_path'], $smokeping_cacti);
$smokeping_action    = isset_request_var('action') ? (string) get_nfilter_request_var('action') : '';

// An administrator's explicit retry of the template import. POST only: csrf-magic
// has already validated the token by the time this page script runs.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $smokeping_action === 'retry_templates') {
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
	$smokeping_targets,
	$smokeping_templates,
	$smokeping_cacti
);

// Convert SmokePing alert patterns into Thold thresholds: GET renders a
// side-by-side preview only; POST creates supported thresholds after checking
// Admin realm and device permissions.
if ($smokeping_action === 'thold_preview' || $smokeping_action === 'thold_create') {
	$smokeping_target_path = isset_request_var('target') ? trim((string) get_nfilter_request_var('target')) : '';
	$smokeping_target      = $smokeping_target_path !== '' ? $smokeping_targets->find($smokeping_settings->pollerId, $smokeping_target_path) : null;
	$smokeping_host        = ($smokeping_target !== null && $smokeping_target->id !== null) ? $smokeping_targets->mappedHost($smokeping_target->id->value) : 0;

	if ($smokeping_target === null || ($smokeping_host > 0 && !is_device_allowed($smokeping_host))) {
		header('Location: smokeping_health.php');
		exit;
	}

	$smokeping_alerts = array();

	try {
		$smokeping_root   = (new \Somethingwithproof\Smokeping\SmokepingConfigParser())->parseFile($smokeping_settings->configFile);
		$smokeping_alerts = \Somethingwithproof\Smokeping\TholdTranslator::parseAlerts($smokeping_root);
	} catch (\Somethingwithproof\Smokeping\SmokepingConfigError $e) {
		$smokeping_alerts = array();
	}

	$smokeping_generator = new \Somethingwithproof\Smokeping\TholdGenerator($smokeping_targets, $smokeping_objects, $smokeping_cacti);

	if ($smokeping_action === 'thold_create') {
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			header('Location: smokeping_health.php');
			exit;
		}

		$smokeping_result = $smokeping_generator->apply($smokeping_target, $smokeping_alerts);
		$smokeping_msgs   = array();

		foreach ($smokeping_result['created'] as $smokeping_item) {
			$smokeping_msgs[] = sprintf('Created Thold #%d (%s)', $smokeping_item['thold_id'], $smokeping_item['name']);
		}

		foreach ($smokeping_result['skipped'] as $smokeping_skip) {
			$smokeping_msgs[] = 'Skipped: ' . $smokeping_skip;
		}

		if ($smokeping_msgs !== array()) {
			raise_message('smokeping_thold', implode('<br>', array_map('html_escape', $smokeping_msgs)), $smokeping_result['created'] !== array() ? MESSAGE_LEVEL_INFO : MESSAGE_LEVEL_WARN);
		}

		header('Location: smokeping_health.php');
		exit;
	}

	top_header();
	$smokeping_page->renderTholdPreview($smokeping_generator->preview($smokeping_target, $smokeping_alerts));
	bottom_footer();
	exit;
}

top_header();
$smokeping_page->render($smokeping_settings, $config['rra_path'], trim((string) read_config_option('path_php_binary')), (int) $config['poller_id'] === $smokeping_settings->pollerId);
bottom_footer();
