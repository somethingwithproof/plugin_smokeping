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
 * Build the SmokePing data and graph templates in a Cacti 1.2.31 database
 * with Cacti's own save function, and print Cacti's export of them
 * (get_item_xml with dependencies) on stdout. The plugin ships that export,
 * not this script's data.
 *
 *   php plugins/smokeping/tests/fidelity/build-templates.php [export-file]
 *
 * Disposable databases only. Object hashes derive from the object names, so
 * running it twice updates the same rows and exports the same hashes.
 *
 * Why the CDEFs name DEFs by letter: a Cacti CDEF can read only its own
 * item's data source, but SmokePing's smoke bands subtract two pings. Cacti
 * names the DEF for the Nth distinct data source it meets in item order
 * generate_graph_def_name(N), so the item order below fixes each ping's DEF
 * name and a CDEF can name it. The fidelity comparison fails if the order and
 * the CDEFs ever disagree.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/functions.php');
require_once($config['base_path'] . '/lib/export.php');

if (getenv('SMOKEPING_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set SMOKEPING_TEST_DATABASE=disposable\n");
	exit(2);
}

const SP_HEIGHT       = 200;
const SP_WIDTH        = 600;
const SP_UPPER_LIMIT  = '0.05';
const SP_MEDIAN_COLOR = '202020';

/**
 * Stable 32-hex hash for an object, so a rebuild matches the rows it made.
 */
function sp_hash(string $kind, string $name): string {
	return md5('plugin_smokeping:' . $kind . ':' . $name);
}

/**
 * Save a row keyed by its hash: update when one exists, insert otherwise.
 *
 * @param array<string, mixed> $row
 */
function sp_save(string $table, array $row): int {
	$id = (int) db_fetch_cell_prepared("SELECT id FROM `$table` WHERE hash = ?", array($row['hash']));

	if ($id > 0) {
		$row['id'] = $id;
	} else {
		$row['id'] = 0;
	}

	$saved = sql_save($row, $table);

	if (!$saved) {
		fwrite(STDERR, "Saving $table {$row['hash']} failed\n");
		exit(1);
	}

	return (int) $saved;
}

function sp_color(string $hex): int {
	$hex = strtoupper($hex);
	$id  = (int) db_fetch_cell_prepared('SELECT id FROM colors WHERE hex = ?', array($hex));

	if ($id === 0) {
		db_execute_prepared('INSERT INTO colors (hex) VALUES (?)', array($hex));
		$id = (int) db_fetch_cell_prepared('SELECT id FROM colors WHERE hex = ?', array($hex));
	}

	return $id;
}

function sp_cdef(string $name, string $rpn): int {
	$id = sp_save('cdef', array('hash' => sp_hash('cdef', $name), 'name' => $name, 'system' => 0));

	db_execute_prepared('DELETE FROM cdef_items WHERE cdef_id = ?', array($id));

	// cdef_items.value is a short column, so a long expression becomes several
	// custom-string items; Cacti joins the items with commas.
	$tokens = explode(',', $rpn);
	$chunk  = array();
	$part   = 0;

	foreach ($tokens as $i => $token) {
		$chunk[] = $token;

		if (strlen(implode(',', $chunk)) > 60 || $i === count($tokens) - 1) {
			$part++;
			sp_save('cdef_items', array(
				'hash'     => sp_hash('cdef_item', "$name:$part"),
				'cdef_id'  => $id,
				'sequence' => $part,
				'type'     => 6,
				'value'    => implode(',', $chunk),
			));
			$chunk = array();
		}
	}

	return $id;
}

function sp_gprint(string $name, string $text): int {
	return sp_save('graph_templates_gprint', array('hash' => sp_hash('gprint', $name), 'name' => $name, 'gprint_text' => $text));
}

/**
 * SmokePing's loss colors for a ping count (Smokeping.pm, detail graph). The
 * legacy table is 2.7.3's, which 2.8 replaced.
 *
 * @return array<int, array{string, string}> loss => [label, color]
 */
function sp_loss_levels(int $pings, bool $legacy = false): array {
	$range = static function (int $lower, int $upper): string {
		if ($upper < $lower) {
			return (string) $upper;
		}

		return $upper > $lower ? "$lower-$upper" : (string) $lower;
	};

	$p = $pings;

	if ($legacy) {
		return array(
			0                => array('0', '26ff00'),
			1                => array("1/$p", '00b8ff'),
			2                => array("2/$p", '0059ff'),
			3                => array("3/$p", '5e00ff'),
			4                => array("4/$p", '7e00ff'),
			(int) ($p / 2)   => array((int) ($p / 2) . "/$p", 'dd00ff'),
			$p - 1           => array(($p - 1) . "/$p", 'ff0000'),
		);
	}

	$per01 = max((int) (0.01 * $p), 1);
	$per05 = max((int) (0.05 * $p), 2);
	$per10 = max((int) (0.10 * $p), 3);
	$per25 = max((int) (0.25 * $p), 4);
	$per50 = max((int) (0.50 * $p), 5);

	$levels = array(
		0        => array('0', '26ff00'),
		$per01   => array($range(1, $per01), '00b8ff'),
		$per05   => array($range($per01 + 1, $per05), '0059ff'),
		$per10   => array($range($per05 + 1, $per10), '7e00ff'),
		$per25   => array($range($per10 + 1, $per25), 'ff00ff'),
		$per50   => array($range($per25 + 1, $per50), 'ff5500'),
		$p - 1   => array($range($per50 + 1, $p - 1), 'ff0000'),
		$p       => array("$p/$p", 'a00000'),
	);

	ksort($levels);

	return $levels;
}

$gray = static function (int $pings, int $band): string {
	// Smokeping::smokecol: $color = int(190 / $half * ($half - $ibot)) + 50.
	$half = $pings / 2;

	return str_repeat(sprintf('%02x', (int) (190 / $half * ($half - $band)) + 50), 3);
};

/* ---- shared objects ---- */

$gprintMedian = sp_gprint('SmokePing median', '%.1lf %ss');
$gprintLoss   = sp_gprint('SmokePing loss', '%.2lf %%');
$cdefHeight   = SP_HEIGHT;

// Keeps a ping below the graph's upper limit, as cp$i does in Smokeping.pm.
$clip = 'CURRENT_DATA_SOURCE,CURRENT_GRAPH_MAXIMUM_VALUE,LT,CURRENT_DATA_SOURCE,INF,IF';
$cdefClip = sp_cdef('SmokePing smoke clip', $clip);

$exports = array();

// The second pass builds the test-only variant with SmokePing 2.7.3's loss
// colors; it is not part of the export.
foreach (array(10, 20, 30) as $pings) {
foreach (array(false, true) as $legacy) {
	$suffix = $legacy ? ' [2.7 loss levels]' : '';
	$half = intdiv($pings, 2);

	/* ---- data template ---- */

	$dtName = "SmokePing Smoke ($pings pings)$suffix";
	$dtId   = sp_save('data_template', array('hash' => sp_hash('data_template', $dtName), 'name' => $dtName));

	$dtdId = (int) db_fetch_cell_prepared('SELECT id FROM data_template_data WHERE data_template_id = ? AND local_data_id = 0', array($dtId));
	sql_save(array(
		'id'                          => $dtdId,
		'data_template_id'            => $dtId,
		'local_data_id'               => 0,
		'local_data_template_data_id' => 0,
		'data_input_id'               => 0,
		'name'                        => '|host_description| - SmokePing',
		'active'                      => 'on',
		'rrd_step'                    => 300,
		'data_source_profile_id'      => 1,
	), 'data_template_data');

	$items = array('uptime' => 'U', 'loss' => (string) $pings, 'median' => 'U');

	for ($i = 1; $i <= $pings; $i++) {
		$items["ping$i"] = 'U';
	}

	$dsId = array();

	foreach ($items as $name => $maximum) {
		$dsId[$name] = sp_save('data_template_rrd', array(
			'hash'                => sp_hash('data_template_item', "$dtName:$name"),
			'data_template_id'    => $dtId,
			'local_data_id'       => 0,
			'rrd_maximum'         => $maximum,
			'rrd_minimum'         => '0',
			'rrd_heartbeat'       => 600,
			'data_source_type_id' => 1,
			'data_source_name'    => $name,
			'data_input_field_id' => 0,
		));
	}

	/* ---- graph items, in the order that fixes the DEF names ---- */

	$gtName = "SmokePing Smoke ($pings pings)$suffix";
	$gtId   = sp_save('graph_templates', array('hash' => sp_hash('graph_template', $gtName), 'name' => $gtName));

	$gtgId = (int) db_fetch_cell_prepared('SELECT id FROM graph_templates_graph WHERE graph_template_id = ? AND local_graph_id = 0', array($gtId));
	sql_save(array(
		'id'                            => $gtgId,
		'graph_template_id'             => $gtId,
		'local_graph_id'                => 0,
		'local_graph_template_graph_id' => 0,
		'image_format_id'               => 1,
		't_title'                       => '',
		'title'                         => '|host_description| - SmokePing',
		't_height'                      => '',
		'height'                        => SP_HEIGHT,
		'width'                         => SP_WIDTH,
		't_upper_limit'                 => 'on',
		'upper_limit'                   => SP_UPPER_LIMIT,
		'lower_limit'                   => '0',
		'vertical_label'                => 'seconds',
		'auto_scale_rigid'              => 'on',
		'base_value'                    => 1000,
		'slope_mode'                    => '',
	), 'graph_templates_graph');

	db_execute_prepared('DELETE FROM graph_templates_item WHERE graph_template_id = ? AND local_graph_id = 0', array($gtId));

	$defIndex = array();
	$sequence = 0;
	$gi       = array();

	$ref = static function (string $ds) use (&$defIndex): string {
		if (!isset($defIndex[$ds])) {
			$defIndex[$ds] = count($defIndex);
		}

		return generate_graph_def_name((string) $defIndex[$ds]);
	};

	$add = static function (array $item) use (&$gi, &$sequence, $gtId, $gtName): void {
		$sequence++;

		$gi[] = $item + array(
			'hash'                      => sp_hash('graph_template_item', "$gtName:$sequence"),
			'graph_template_id'         => $gtId,
			'local_graph_id'            => 0,
			'task_item_id'              => 0,
			'color_id'                  => 0,
			'alpha'                     => 'FF',
			'graph_type_id'             => GRAPH_ITEM_TYPE_COMMENT,
			'cdef_id'                   => 0,
			'consolidation_function_id' => 1,
			'text_format'               => '',
			'value'                     => '',
			'hard_return'               => '',
			'gprint_id'                 => 0,
			'sequence'                  => $sequence,
		);
	};

	for ($band = 1; $band <= $half; $band++) {
		$top = $pings + 1 - $band;

		// The bottom ping's DEF name, taken when its AREA item references it.
		$bottom = $ref("ping$band");
		$add(array('task_item_id' => $dsId["ping$band"], 'graph_type_id' => GRAPH_ITEM_TYPE_AREA, 'cdef_id' => $cdefClip));

		$clipBottom = "$bottom,CURRENT_GRAPH_MAXIMUM_VALUE,LT,$bottom,INF,IF";
		$smoke      = sp_cdef("SmokePing smoke band $band", "$clipBottom,UN,UNKN,$clip,$clipBottom,-,IF");

		$ref("ping$top");
		$add(array(
			'task_item_id'  => $dsId["ping$top"],
			'graph_type_id' => GRAPH_ITEM_TYPE_STACK,
			'cdef_id'       => $smoke,
			'color_id'      => sp_color($gray($pings, $band)),
		));
	}

	$ref('median');
	$add(array('task_item_id' => $dsId['median'], 'graph_type_id' => GRAPH_ITEM_TYPE_GPRINT_AVERAGE, 'text_format' => 'median rtt:', 'gprint_id' => $gprintMedian));
	$add(array('task_item_id' => $dsId['median'], 'graph_type_id' => GRAPH_ITEM_TYPE_GPRINT_MAX, 'text_format' => ' max', 'gprint_id' => $gprintMedian, 'consolidation_function_id' => 1));
	$add(array('task_item_id' => $dsId['median'], 'graph_type_id' => GRAPH_ITEM_TYPE_GPRINT_MIN, 'text_format' => ' min', 'gprint_id' => $gprintMedian));
	$add(array('task_item_id' => $dsId['median'], 'graph_type_id' => GRAPH_ITEM_TYPE_GPRINT_LAST, 'text_format' => ' now', 'gprint_id' => $gprintMedian, 'hard_return' => 'on'));
	$add(array('task_item_id' => $dsId['median'], 'graph_type_id' => GRAPH_ITEM_TYPE_LINE1, 'color_id' => sp_color(SP_MEDIAN_COLOR)));

	$lossName = $ref('loss');
	$ploss    = sp_cdef("SmokePing loss percent ($pings pings)", "CURRENT_DATA_SOURCE,$pings,/,100,*");

	$add(array('task_item_id' => $dsId['loss'], 'graph_type_id' => GRAPH_ITEM_TYPE_GPRINT_AVERAGE, 'cdef_id' => $ploss, 'text_format' => 'packet loss:', 'gprint_id' => $gprintLoss));
	$add(array('task_item_id' => $dsId['loss'], 'graph_type_id' => GRAPH_ITEM_TYPE_GPRINT_MAX, 'cdef_id' => $ploss, 'text_format' => ' max', 'gprint_id' => $gprintLoss));
	$add(array('task_item_id' => $dsId['loss'], 'graph_type_id' => GRAPH_ITEM_TYPE_GPRINT_MIN, 'cdef_id' => $ploss, 'text_format' => ' min', 'gprint_id' => $gprintLoss));
	$add(array('task_item_id' => $dsId['loss'], 'graph_type_id' => GRAPH_ITEM_TYPE_GPRINT_LAST, 'cdef_id' => $ploss, 'text_format' => ' now', 'gprint_id' => $gprintLoss, 'hard_return' => 'on'));
	$add(array('graph_type_id' => GRAPH_ITEM_TYPE_COMMENT, 'text_format' => 'loss color:'));

	$last = -1;

	foreach (sp_loss_levels($pings, $legacy) as $loss => $level) {
		$me    = "$lossName,$last,GT,$lossName,$loss,LE,*,1,UNKN,IF,CURRENT_DATA_SOURCE,*";
		$width = "CURRENT_GRAPH_MAXIMUM_VALUE,$cdefHeight,/";
		$low   = sp_cdef("SmokePing loss band $pings/$loss low$suffix", "$me,$width,-");
		$high  = sp_cdef("SmokePing loss band $pings/$loss high$suffix", "$me,0,*,$width,2,*,+");

		$add(array('task_item_id' => $dsId['median'], 'graph_type_id' => GRAPH_ITEM_TYPE_AREA, 'cdef_id' => $low));
		$add(array('task_item_id' => $dsId['median'], 'graph_type_id' => GRAPH_ITEM_TYPE_STACK, 'cdef_id' => $high, 'color_id' => sp_color($level[1]), 'text_format' => $level[0]));

		$last = $loss;
	}

	$add(array('graph_type_id' => GRAPH_ITEM_TYPE_HRULE, 'value' => '0', 'color_id' => sp_color('000000')));

	$medianItem = 0;

	foreach ($gi as $item) {
		$id = sp_save('graph_templates_item', $item);

		if ($item['graph_type_id'] === GRAPH_ITEM_TYPE_LINE1) {
			$medianItem = $id;
		}
	}

	// One graph template input, for the median line's color. It is useful to
	// operators, and Cacti 1.2.32 and later refuse to import a graph template
	// whose export has no <inputs> element at all.
	$inputId = sp_save('graph_template_input', array(
		'hash'              => sp_hash('graph_template_input', "$gtName:median color"),
		'graph_template_id' => $gtId,
		'name'              => 'Median Color',
		'description'       => 'The color of the median line',
		'column_name'       => 'color_id',
	));

	db_execute_prepared('DELETE FROM graph_template_input_defs WHERE graph_template_input_id = ?', array($inputId));
	db_execute_prepared('INSERT INTO graph_template_input_defs (graph_template_input_id, graph_template_item_id) VALUES (?, ?)', array($inputId, $medianItem));

	if (!$legacy) {
		$exports[] = $gtId;
	}
}
}

/* ---- summary data input, data template, and graph template ---- */

$inputName = 'SmokePing Summary Script';
$inputId   = sp_save('data_input', array(
	'hash'         => sp_hash('data_input', $inputName),
	'name'         => $inputName,
	'input_string' => '<path_cacti>/plugins/smokeping/scripts/ss_smokeping_summary.php ss_smokeping_summary <series>',
	'type_id'      => DATA_INPUT_TYPE_PHP_SCRIPT_SERVER,
));

$field = static function (string $dataName, string $friendly, string $inOut, int $sequence) use ($inputId, $inputName): int {
	return sp_save('data_input_fields', array(
		'hash'           => sp_hash('data_input_field', "$inputName:$dataName"),
		'data_input_id'  => $inputId,
		'name'           => $friendly,
		'data_name'      => $dataName,
		'input_output'   => $inOut,
		'update_rra'     => $inOut === 'out' ? 'on' : '',
		'sequence'       => $sequence,
		'type_code'      => '',
		'regexp_match'   => '',
		'allow_nulls'    => '',
	));
};

$fieldSeries = $field('series', 'Series (target id~location)', 'in', 0);
$fieldOut    = array(
	'median'   => $field('median', 'Median RTT (ms)', 'out', 1),
	'loss_pct' => $field('loss_pct', 'Packet Loss (%)', 'out', 2),
	'age'      => $field('age', 'Age of the Last Update (s)', 'out', 3),
);

$dtName = 'SmokePing Summary';
$dtId   = sp_save('data_template', array('hash' => sp_hash('data_template', $dtName), 'name' => $dtName));
$dtdId  = (int) db_fetch_cell_prepared('SELECT id FROM data_template_data WHERE data_template_id = ? AND local_data_id = 0', array($dtId));
$dtdId  = (int) sql_save(array(
	'id'                          => $dtdId,
	'data_template_id'            => $dtId,
	'local_data_id'               => 0,
	'local_data_template_data_id' => 0,
	'data_input_id'               => $inputId,
	'name'                        => '|host_description| - SmokePing Summary',
	'active'                      => 'on',
	'rrd_step'                    => 300,
	'data_source_profile_id'      => 1,
), 'data_template_data');

// The series is set per data source, so the template leaves it to the plugin.
db_execute_prepared('REPLACE INTO data_input_data (data_input_field_id, data_template_data_id, t_value, value) VALUES (?, ?, ?, ?)', array($fieldSeries, $dtdId, 'on', ''));

$sumDs = array();

foreach (array('median' => array('0', 'U'), 'loss_pct' => array('0', '100'), 'age' => array('0', 'U')) as $name => $range) {
	$sumDs[$name] = sp_save('data_template_rrd', array(
		'hash'                => sp_hash('data_template_item', "$dtName:$name"),
		'data_template_id'    => $dtId,
		'local_data_id'       => 0,
		'rrd_minimum'         => $range[0],
		'rrd_maximum'         => $range[1],
		'rrd_heartbeat'       => 600,
		'data_source_type_id' => 1,
		'data_source_name'    => $name,
		'data_input_field_id' => $fieldOut[$name],
	));
}

$gtName = 'SmokePing Summary';
$gtId   = sp_save('graph_templates', array('hash' => sp_hash('graph_template', $gtName), 'name' => $gtName));
$gtgId  = (int) db_fetch_cell_prepared('SELECT id FROM graph_templates_graph WHERE graph_template_id = ? AND local_graph_id = 0', array($gtId));

sql_save(array(
	'id'                            => $gtgId,
	'graph_template_id'             => $gtId,
	'local_graph_id'                => 0,
	'local_graph_template_graph_id' => 0,
	'image_format_id'               => 1,
	't_title'                       => '',
	'title'                         => '|host_description| - SmokePing Summary',
	'height'                        => 120,
	'width'                         => 600,
	'upper_limit'                   => '100',
	'lower_limit'                   => '0',
	'vertical_label'                => 'ms, %',
	'auto_scale'                    => 'on',
	'auto_scale_opts'               => 2,
	'auto_scale_rigid'              => '',
	'base_value'                    => 1000,
), 'graph_templates_graph');

db_execute_prepared('DELETE FROM graph_templates_item WHERE graph_template_id = ? AND local_graph_id = 0', array($gtId));

$sequence = 0;
$medianLine = 0;

$addSummary = static function (array $item) use (&$sequence, $gtId, $gtName): int {
	$sequence++;

	return sp_save('graph_templates_item', $item + array(
		'hash'                      => sp_hash('graph_template_item', "$gtName:$sequence"),
		'graph_template_id'         => $gtId,
		'local_graph_id'            => 0,
		'task_item_id'              => 0,
		'color_id'                  => 0,
		'alpha'                     => 'FF',
		'graph_type_id'             => GRAPH_ITEM_TYPE_COMMENT,
		'cdef_id'                   => 0,
		'consolidation_function_id' => 1,
		'text_format'               => '',
		'value'                     => '',
		'hard_return'               => '',
		'gprint_id'                 => 0,
		'sequence'                  => $sequence,
	));
};

$gprintAge  = sp_gprint('SmokePing age', '%.0lf s');
$medianLine = $addSummary(array('task_item_id' => $sumDs['median'], 'graph_type_id' => GRAPH_ITEM_TYPE_LINE1, 'color_id' => sp_color(SP_MEDIAN_COLOR), 'text_format' => 'median rtt (ms)'));
$addSummary(array('task_item_id' => $sumDs['median'], 'graph_type_id' => GRAPH_ITEM_TYPE_GPRINT_LAST, 'text_format' => ' now', 'gprint_id' => sp_gprint('SmokePing median ms', '%.1lf'), 'hard_return' => 'on'));
$addSummary(array('task_item_id' => $sumDs['loss_pct'], 'graph_type_id' => GRAPH_ITEM_TYPE_LINE1, 'color_id' => sp_color('FF0000'), 'text_format' => 'packet loss (%)'));
$addSummary(array('task_item_id' => $sumDs['loss_pct'], 'graph_type_id' => GRAPH_ITEM_TYPE_GPRINT_LAST, 'text_format' => ' now', 'gprint_id' => $gprintLoss, 'hard_return' => 'on'));
$addSummary(array('task_item_id' => $sumDs['age'], 'graph_type_id' => GRAPH_ITEM_TYPE_GPRINT_LAST, 'text_format' => 'age of the last update', 'gprint_id' => $gprintAge, 'hard_return' => 'on'));

$inputSum = sp_save('graph_template_input', array(
	'hash'              => sp_hash('graph_template_input', "$gtName:median color"),
	'graph_template_id' => $gtId,
	'name'              => 'Median Color',
	'description'       => 'The color of the median line',
	'column_name'       => 'color_id',
));

db_execute_prepared('DELETE FROM graph_template_input_defs WHERE graph_template_input_id = ?', array($inputSum));
db_execute_prepared('INSERT INTO graph_template_input_defs (graph_template_input_id, graph_template_item_id) VALUES (?, ?)', array($inputSum, $medianLine));

$exports[] = $gtId;

/* ---- device template ---- */

$htId = sp_save('host_template', array('hash' => sp_hash('host_template', 'SmokePing Target'), 'name' => 'SmokePing Target'));

db_execute_prepared('DELETE FROM host_template_graph WHERE host_template_id = ?', array($htId));

foreach ($exports as $gtId) {
	db_execute_prepared('INSERT INTO host_template_graph (host_template_id, graph_template_id) VALUES (?, ?)', array($htId, $gtId));
}

$xml = get_item_xml('host_template', $htId, true);

// The links only made the export carry the graph templates. The shipped device
// template lists none: Cacti creates every listed graph for a new device, and
// the plugin creates its own graphs, so a listed template would add a graph
// with no data source for each ping count.
db_execute_prepared('DELETE FROM host_template_graph WHERE host_template_id = ?', array($htId));
$xml = preg_replace('#<graph_templates>[^<]*</graph_templates>#', '<graph_templates></graph_templates>', $xml, -1, $unlinked);

if ($unlinked !== 1) {
	fwrite(STDERR, "Expected one graph template list in the export, found $unlinked\n");
	exit(1);
}

// The export follows the data source profile the data templates use. The
// plugin must not overwrite a site's profile, so the shipped file leaves it
// out and import resolves the profile by its hash, which is Cacti's stock one.
$xml = preg_replace('#\s*<hash_2001[0-9a-f]{34}>.*?</hash_2001[0-9a-f]{34}>#s', '', $xml, -1, $removed);

if ($removed !== 1) {
	fwrite(STDERR, "Expected one data source profile in the export, found $removed\n");
	exit(1);
}

if (isset($argv[1])) {
	file_put_contents($argv[1], $xml);
} else {
	print $xml;
}
