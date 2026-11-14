<?php
/*
 * Print the rrdtool graph arguments Cacti 1.2.31 generates for a graph made
 * from the "SmokePing Smoke (N pings)" template over a fixture RRD, one
 * argument per line. Cacti runs real code: the device and graph are created
 * with api_device_save() and create_complete_graph_from_template(), the data
 * source path is `<path_rra>/smokeping/<file>`, and rrdtool_function_graph()
 * renders through a wrapper that records the command it sends rrdtool.
 *
 *   php plugins/smokeping/tests/fidelity/cacti-args.php <pings> <rra-relative-rrd> <start> <end> <upper-limit>
 *
 * Disposable databases only.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/functions.php');
require_once($config['base_path'] . '/lib/api_device.php');
require_once($config['base_path'] . '/lib/template.php');
require_once($config['base_path'] . '/lib/rrd.php');

if (getenv('SMOKEPING_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set SMOKEPING_TEST_DATABASE=disposable\n");
	exit(2);
}

list(, $pings, $rrdFile, $start, $end, $upper) = $argv;

// SMOKEPING_TEMPLATE_SUFFIX selects the test-only 2.7 loss level variant.
$suffix     = (string) getenv('SMOKEPING_TEMPLATE_SUFFIX');
$templateId = (int) db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE name = ?', array("SmokePing Smoke ($pings pings)$suffix"));
$deviceTpl  = (int) db_fetch_cell_prepared("SELECT id FROM host_template WHERE name = 'SmokePing Target'");

if ($templateId === 0 || $deviceTpl === 0) {
	fwrite(STDERR, "Templates are not installed\n");
	exit(1);
}

$description = 'fidelity-' . $pings . ($suffix === '' ? '' : '-legacy');
$hostId      = (int) db_fetch_cell_prepared('SELECT id FROM host WHERE description = ?', array($description));

ob_start();

if ($hostId === 0) {
	// Availability 0 is AVAIL_NONE: Cacti never pings or polls these devices.
	$hostId = api_device_save(0, $deviceTpl, $description, '127.0.0.1', '', 0, '', '', 161, 500, 'on', 0, 0, 23, 400, 1, '', 'MD5', '', 'DES', '', '');
}

$localGraphId = (int) db_fetch_cell_prepared('SELECT id FROM graph_local WHERE host_id = ? AND graph_template_id = ?', array($hostId, $templateId));

if ($localGraphId === 0) {
	$suggested = array();
	$null      = array();
	$created      = create_complete_graph_from_template($templateId, $hostId, $null, $suggested);
	$localGraphId = is_array($created) ? (int) $created['local_graph_id'] : 0;
}

ob_end_clean();

if ($localGraphId === 0) {
	fwrite(STDERR, "Graph creation failed\n");
	exit(1);
}

db_execute_prepared("UPDATE data_template_data SET data_source_path = ? WHERE local_data_id IN (SELECT DISTINCT dtr.local_data_id FROM graph_templates_item gti INNER JOIN data_template_rrd dtr ON dtr.id = gti.task_item_id WHERE gti.local_graph_id = ?)", array('<path_rra>/smokeping/' . $rrdFile, $localGraphId));
db_execute_prepared('UPDATE graph_templates_graph SET upper_limit = ?, t_upper_limit = ? WHERE local_graph_id = ?', array($upper, 'on', $localGraphId));

// Record what Cacti sends rrdtool: path_rrdtool points at a wrapper that tees stdin.
if (read_config_option('path_rrdtool') === '' || read_config_option('path_rrdtool') === false) {
	set_config_option('path_rrdtool', trim((string) shell_exec('command -v rrdtool')));
}

$log = sys_get_temp_dir() . '/rrd-stdin-' . getmypid() . '.log';
$bin = sys_get_temp_dir() . '/rrdwrap-' . getmypid() . '.sh';

file_put_contents($bin, "#!/bin/sh\ntee -a " . escapeshellarg($log) . " | exec " . escapeshellarg((string) read_config_option('path_rrdtool')) . " \"\$@\"\n");
chmod($bin, 0755);

$real = (string) read_config_option('path_rrdtool');
set_config_option('path_rrdtool', $bin);

$image = rrdtool_function_graph($localGraphId, 0, array('graph_start' => $start, 'graph_end' => $end, 'output_flag' => RRDTOOL_OUTPUT_STDOUT));

set_config_option('path_rrdtool', $real);

$command = is_file($log) ? (string) file_get_contents($log) : '';

if ($command === '') {
	fwrite(STDERR, "Cacti sent rrdtool no command (" . (is_string($image) ? strlen($image) . ' bytes' : gettype($image)) . ")\n");
	exit(1);
}

// Split the command the way a shell does; Cacti quotes its arguments.
if (!preg_match('/(?:^|\n)graph\s+-\s+(.*?)(?:\nquit\b|\z)/s', $command, $found)) {
	fwrite(STDERR, "No graph command in what Cacti sent rrdtool\n");
	exit(1);
}

$command = $found[1];
$script  = 'set -- ' . str_replace(array("\r"), '', $command) . "\nprintf '%s\\n' \"\$@\"\n";
$proc    = proc_open(array('sh', '-c', $script), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
$args    = stream_get_contents($pipes[1]);
$err     = stream_get_contents($pipes[2]);

proc_close($proc);
unlink($log);
unlink($bin);

if ($err !== '') {
	fwrite(STDERR, $err);
}

print $args;
