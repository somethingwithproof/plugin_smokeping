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

/*
 * Test setup for tests/alert-e2e.sh: create Thold thresholds on the summary
 * data source of one target, the way an operator does from a Thold template.
 * Disposable databases only.
 *
 *   php plugins/smokeping/tests/bin/alert-setup.php <local data id> <loss hi> <loss trigger> <age hi> <age trigger>
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/plugins/thold/includes/functions.php');

if (getenv('SMOKEPING_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set SMOKEPING_TEST_DATABASE=disposable\n");
	exit(2);
}

list(, $localDataId, $lossHi, $lossTrigger, $ageHi, $ageTrigger) = $argv;

$localGraphId = (int) db_fetch_cell_prepared('SELECT local_graph_id FROM plugin_smokeping_object WHERE local_data_id = ?', array((int) $localDataId));
$dataTemplate = db_fetch_row_prepared('SELECT id, name FROM data_template WHERE name = ?', array('SmokePing Summary'));

foreach (array('loss_pct' => array($lossHi, $lossTrigger), 'age' => array($ageHi, $ageTrigger)) as $name => $limits) {
	$templateRrd = (int) db_fetch_cell_prepared('SELECT id FROM data_template_rrd WHERE data_template_id = ? AND local_data_id = 0 AND data_source_name = ?', array($dataTemplate['id'], $name));
	$rrd         = (int) db_fetch_cell_prepared('SELECT id FROM data_template_rrd WHERE local_data_id = ? AND data_source_name = ?', array((int) $localDataId, $name));

	db_execute_prepared('DELETE FROM thold_data WHERE local_data_id = ? AND data_source_name = ?', array((int) $localDataId, $name));
	db_execute_prepared('DELETE FROM thold_template WHERE hash = ?', array(md5('smokeping-test:' . $name)));

	db_execute_prepared('INSERT INTO thold_template
		(hash, name, suggested_name, data_template_id, data_template_hash, data_template_name, data_source_id, data_source_name, data_source_friendly,
		thold_hi, thold_fail_trigger, thold_enabled, thold_type, notify_extra, restored_alert)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
		array(md5('smokeping-test:' . $name), "SmokePing $name", '|host_description| - SmokePing ' . $name, $dataTemplate['id'], '', $dataTemplate['name'], $templateRrd, $name, $name,
			$limits[0], (int) $limits[1], 'on', 0, 'ops@example.test', ''));

	$templateId = (int) db_fetch_cell_prepared('SELECT id FROM thold_template WHERE hash = ?', array(md5('smokeping-test:' . $name)));
	$message    = '';

	if (!thold_create_from_template((int) $localDataId, $localGraphId, $rrd, $templateId, $message)) {
		fwrite(STDERR, "Could not create the $name threshold\n");
		exit(1);
	}

	print $name . ' threshold ' . (int) db_fetch_cell_prepared('SELECT id FROM thold_data WHERE local_data_id = ? AND data_source_name = ?', array((int) $localDataId, $name)) . PHP_EOL;
}
