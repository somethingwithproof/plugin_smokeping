#!/bin/sh
# SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
# SPDX-License-Identifier: GPL-2.0-or-later
#
# This file is part of plugin_smokeping, a plugin for Cacti.
# https://github.com/somethingwithproof/plugin_smokeping
#
# This program is free software; you can redistribute it and/or modify it
# under the terms of the GNU General Public License as published by the Free
# Software Foundation; either version 2 of the License, or (at your option)
# any later version.
#
# This program is distributed in the hope that it will be useful, but WITHOUT
# ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
# FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
# more details.

# End-to-end check of device and data source provisioning and of SmokePing file
# safety on a real Cacti: real templates, real api_device_save(), real graph
# rendering, real poller_maintenance.php. Run from the Cacti root, in a
# container with Cacti's PHP extensions and rrdtool, this plugin at
# plugins/smokeping, a disposable installed Cacti database, and SmokePing's
# fixture directory mounted at rra/smokeping (see tests/run-objects-e2e.sh):
#
#   SMOKEPING_TEST_DATABASE=disposable sh plugins/smokeping/tests/objects-e2e.sh <ro|rw> <guard|noguard>
#
# ro: the mount is read-only. rw: it is deliberately writable, which is an
# error state the health page reports. noguard disables the plugin before the
# deletion, as a control that shows the protection is what keeps the files.
# Prints one RESULT line per check and exits non-zero on any failure.
set -u

MODE=${1:?ro or rw}
GUARD=${2:?guard or noguard}
P=plugins/smokeping
L="php $P/tests/bin/lifecycle.php"
fail=0
result() { echo "RESULT $1 $MODE/$GUARD $2${3:+ ($3)}"; [ "$1" = PASS ] || [ "$1" = INFO ] || fail=1; }
q() { $L query "$1"; }
res() { php -r '$r = json_decode(stream_get_contents(STDIN), true); echo is_scalar($r["result"] ?? null) ? $r["result"] : json_encode($r["result"] ?? null);'; }
jcount() { php -r '$r = json_decode(stream_get_contents(STDIN), true); echo is_array($r) ? count($r) : -1;'; }
jcell() { php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r[0] ? implode(" ", array_values($r[0])) : "";'; }

rra=$(php -r 'chdir("."); include("./include/cli_check.php"); echo $config["rra_path"];' 2>/dev/null)
mount="$rra/smokeping"
sums() { (cd "$mount" && find . -type f -name '*.rrd' | sort | xargs sha256sum); }
warnings() { grep -c 'RRDfile Maintenance is unable' log/cacti.log 2>/dev/null || true; }
now_log=$(warnings)

$L reset >/dev/null && $L install >/dev/null && $L enable >/dev/null
$L set_setting "path_rrdtool=$(command -v rrdtool)" >/dev/null
$L set_setting "smokeping_mount_path=$mount" >/dev/null
$L set_setting "smokeping_datadir=$mount" >/dev/null
db_trunc() { :; }

for t in "Europe/paris:paris.example:20:300" "Europe/rome:rome.example:10:300" "Europe/berlin:berlin.example:15:300" \
	"Europe/oldname:old.example:20:300" "Europe/newname:new.example:20:300" "Europe/escape:escape.example:20:300" "Europe/linkdir/x:linkdir.example:20:300"; do
	$L add_target "$t" >/dev/null
done

# Prepare the destructive checks' baseline.
before=$(sums)
work_out=$(mktemp)

# 1. Provisioning: device, smoke data source with the mount path, graph.
out=$(php $P/cli/smokeping.php provision Europe/paris 2>&1)
dev=$(echo "$out" | sed -n 's/^Created device \([0-9]*\)$/\1/p')
ds=$(echo "$out" | sed -n 's/^Data source \([0-9]*\) graph \([0-9]*\)$/\1/p')
gr=$(echo "$out" | sed -n 's/^Data source \([0-9]*\) graph \([0-9]*\)$/\2/p')
sds=$(echo "$out" | sed -n 's/^Summary data source \([0-9]*\) graph \([0-9]*\)$/\1/p')
sgr=$(echo "$out" | sed -n 's/^Summary data source \([0-9]*\) graph \([0-9]*\)$/\2/p')
[ -n "$dev" ] && [ -n "$ds" ] && result PASS provision-paris "device $dev data source $ds graph $gr" || result FAIL provision-paris "$out"

row=$(q "SELECT data_source_path, rrd_step, data_input_id FROM data_template_data WHERE local_data_id = ${ds:-0}" | jcell)
[ "$row" = "<path_rra>/smokeping/Europe/paris.rrd 300 0" ] && result PASS data-source-path "$row" || result FAIL data-source-path "$row"

# Cacti creates a graph for each graph template of a new device's template; the
# device template lists none, so the device holds only the plugin's graphs.
row=$(q "SELECT COUNT(*) FROM graph_local WHERE host_id = ${dev:-0} AND id NOT IN (SELECT local_graph_id FROM plugin_smokeping_object)" | jcell)
[ "$row" = "0" ] && result PASS device-only-plugin-graphs "no graph outside the plugin's objects" || result FAIL device-only-plugin-graphs "$row graphs the plugin does not own"

row=$(q "SELECT title_cache FROM graph_templates_graph WHERE local_graph_id = ${gr:-0}" | jcell)
[ "$row" = "Europe/paris - SmokePing" ] && result PASS graph-titled-for-target "$row" || result FAIL graph-titled-for-target "$row"

# SmokePing's probe line: the target's pings, probe description (the probe's
# name while none is known) and step, on the item made from the template's.
probe_line() { q "SELECT gi.text_format FROM graph_templates_item AS gi INNER JOIN graph_templates_item AS ti ON ti.id = gi.local_graph_template_item_id WHERE gi.local_graph_id = ${gr:-0} AND ti.text_format = 'probe:'" | jcell; }
row=$(probe_line)
[ "$row" = "probe:       20 FPing every 300s" ] && result PASS graph-probe-line "$row" || result FAIL graph-probe-line "$row"

# Importing the templates again resets the item; the next poller cycle writes
# it back, with the description an inventory import stored since.
php -r 'include("./include/cli_check.php"); db_execute("UPDATE plugin_smokeping_target SET probe_desc = \"ICMP Echo Pings (56 Bytes)\" WHERE path = \"Europe/paris\"");' >/dev/null 2>&1
$L forget_templates >/dev/null
$L retry_templates >/dev/null
$L sync_probe_lines >/dev/null
row=$(probe_line)
[ "$row" = "probe:       20 ICMP Echo Pings (56 Bytes) every 300s" ] && result PASS probe-line-after-template-import "$row" || result FAIL probe-line-after-template-import "$row"

# A graph made from the templates before #81: no standard deviation, am/s or
# probe line items, no VDEF on the average, the hard return after "now".
# upgrade-graphs brings it to the installed templates, keeping its title and limit.
php -r 'include("./include/cli_check.php"); $g = (int) $argv[1];
	db_execute_prepared("DELETE gi FROM graph_templates_item AS gi INNER JOIN graph_templates_item AS ti ON ti.id = gi.local_graph_template_item_id WHERE gi.local_graph_id = ? AND ti.text_format IN (\" sd\", \" am/s\", \"probe:\")", [$g]);
	db_execute_prepared("UPDATE graph_templates_item SET vdef_id = 0 WHERE local_graph_id = ?", [$g]);
	db_execute_prepared("UPDATE graph_templates_item SET hard_return = \"on\" WHERE local_graph_id = ? AND text_format = \" now\" AND cdef_id = 0", [$g]);
	db_execute_prepared("UPDATE plugin_smokeping_object SET template_version = 0 WHERE local_graph_id = ?", [$g]);' "${gr:-0}" >/dev/null 2>&1
before_items=$(q "SELECT COUNT(*) FROM graph_templates_item WHERE local_graph_id = ${gr:-0}" | jcell)
before_graph=$(q "SELECT title_cache, upper_limit FROM graph_templates_graph WHERE local_graph_id = ${gr:-0}" | jcell)
out=$(php $P/cli/smokeping.php upgrade-graphs 2>&1); rc=$?
items=$(q "SELECT COUNT(*) FROM graph_templates_item WHERE local_graph_id = ${gr:-0}" | jcell)
tpl=$(q "SELECT COUNT(*) FROM graph_templates_item AS gi INNER JOIN graph_local AS gl ON gl.graph_template_id = gi.graph_template_id WHERE gl.id = ${gr:-0} AND gi.local_graph_id = 0" | jcell)
differ=$(q "SELECT COUNT(*) FROM graph_templates_item AS gi INNER JOIN graph_templates_item AS ti ON ti.id = gi.local_graph_template_item_id WHERE gi.local_graph_id = ${gr:-0} AND (gi.vdef_id <> ti.vdef_id OR gi.cdef_id <> ti.cdef_id OR gi.hard_return <> ti.hard_return OR gi.sequence <> ti.sequence)" | jcell)
after_graph=$(q "SELECT title_cache, upper_limit FROM graph_templates_graph WHERE local_graph_id = ${gr:-0}" | jcell)
[ $rc -eq 0 ] && [ "$before_items" = 47 ] && [ "$items" = "$tpl" ] && [ "$differ" = 0 ] && [ "$after_graph" = "$before_graph" ] && [ "$(probe_line)" = "probe:       20 ICMP Echo Pings (56 Bytes) every 300s" ] \
	&& result PASS upgrade-graphs-retemplates "$before_items to $items items; $after_graph; $out" \
	|| result FAIL upgrade-graphs-retemplates "rc $rc, $before_items to $items of $tpl items, $differ differ, graph '$before_graph' to '$after_graph', probe '$(probe_line)': $out"

# The upper limit is sized from the file, or the template's when the last day has no median.
row=$(php $P/tests/bin/scale-check.php "${gr:-0}" | tail -1)
case "$row" in "sized 1 kept 0 wrong 0") result PASS graph-sized-from-data "$row";; *) result FAIL graph-sized-from-data "$row: $(php $P/tests/bin/scale-check.php "${gr:-0}" | head -1)";; esac
out=$(php $P/cli/smokeping.php size-graphs 2>&1); rc=$?
[ $rc -eq 0 ] && echo "$out" | grep -q "^Graph ${gr:-0}: " && result PASS size-graphs-command "$(echo "$out" | grep "^Graph ${gr:-0}: ")" || result FAIL size-graphs-command "rc $rc: $out"

# The plugin records the limit it set, so it can tell a hand-set one (#80).
# Compared here, not in SQL: the plugin's and Cacti's tables may differ in collation.
recorded=$(q "SELECT sized_limit, sized_at > 0 FROM plugin_smokeping_object WHERE local_graph_id = ${gr:-0}" | jcell)
limit=$(q "SELECT upper_limit FROM graph_templates_graph WHERE local_graph_id = ${gr:-0}" | jcell)
[ -n "$limit" ] && [ "$recorded" = "$limit 1" ] && result PASS graph-limit-recorded "recorded $recorded, graph $limit" || result FAIL graph-limit-recorded "recorded '$recorded', graph '$limit'"

# A limit set by hand survives size-graphs and the daily sizing.
$L set_sizing "${gr:-0}|9|$(q "SELECT sized_limit FROM plugin_smokeping_object WHERE local_graph_id = ${gr:-0}" | jcell)|0" >/dev/null
out=$(php $P/cli/smokeping.php size-graphs 2>&1)
row=$(q "SELECT upper_limit FROM graph_templates_graph WHERE local_graph_id = ${gr:-0}" | jcell)
echo "$out" | grep -q "^Graph ${gr:-0}: kept the limit set by hand; the plugin last set " && [ "$row" = "9" ] && result PASS manual-limit-kept-by-size-graphs "left 9" || result FAIL manual-limit-kept-by-size-graphs "limit $row: $out"
$L set_sizing "${gr:-0}|9|$(q "SELECT sized_limit FROM plugin_smokeping_object WHERE local_graph_id = ${gr:-0}" | jcell)|0" >/dev/null
due=$($L resize_due 1:25 | res)
row=$(q "SELECT upper_limit FROM graph_templates_graph WHERE local_graph_id = ${gr:-0}" | jcell)
[ "$due" = '{"sized":0,"kept":0,"manual":1}' ] && [ "$row" = "9" ] && result PASS manual-limit-kept-by-daily-sizing "left 9; $due" || result FAIL manual-limit-kept-by-daily-sizing "limit $row; $due"

# A limit the plugin set follows the data at the next daily run.
$L set_sizing "${gr:-0}|0.001|0.001|0" >/dev/null
due=$($L resize_due 1:25 | res)
row=$(php $P/tests/bin/scale-check.php "${gr:-0}" | tail -1)
[ "$row" = "sized 1 kept 0 wrong 0" ] && result PASS daily-sizing-follows-data "$row; $due" || result FAIL daily-sizing-follows-data "$row: $(php $P/tests/bin/scale-check.php "${gr:-0}" | head -1); $due"

# A graph sized less than a day ago is not due, and a cycle sizes at most its batch.
$L set_sizing "${gr:-0}|0.001|0.001|$(date +%s)" >/dev/null
due=$($L resize_due 1:25 | res)
row=$(q "SELECT upper_limit FROM graph_templates_graph WHERE local_graph_id = ${gr:-0}" | jcell)
[ "$row" = "0.001" ] && result PASS daily-sizing-waits-a-day "limit kept; $due" || result FAIL daily-sizing-waits-a-day "limit $row; $due"
$L set_sizing "${gr:-0}|0.001|0.001|0" >/dev/null
due=$($L resize_due 1:0 | res)
row=$(q "SELECT upper_limit FROM graph_templates_graph WHERE local_graph_id = ${gr:-0}" | jcell)
[ "$due" = '{"sized":0,"kept":0,"manual":0}' ] && [ "$row" = "0.001" ] && result PASS daily-sizing-batch-bound "a due graph waits when the batch is full; $due" || result FAIL daily-sizing-batch-bound "limit $row; $due"

# Leave the graph sized from its data for the checks below.
php $P/cli/smokeping.php size-graphs >/dev/null 2>&1

row=$(q "SELECT availability_method FROM host WHERE id = ${dev:-0}" | jcell)
[ "$row" = "0" ] && result PASS device-availability-none "availability_method=0" || result FAIL device-availability-none "$row"

row=$(q "SELECT COUNT(*) FROM plugin_smokeping_mapping WHERE host_id = ${dev:-0}" | jcell)
[ "$row" = "1" ] && result PASS device-mapped "1 mapping" || result FAIL device-mapped "$row"

rendered=$($L render "${gr:-0}" | res)
[ "$rendered" = image ] && result PASS graph-renders "an image from the plugin-created graph" || result FAIL graph-renders "$rendered"

# The Loss Colors setting moves the graph to the template with SmokePing 2.7's
# loss colors and back (#34), keeping its title, limit and data.
tpl_name() { q "SELECT gt.name FROM graph_local AS gl INNER JOIN graph_templates AS gt ON gt.id = gl.graph_template_id WHERE gl.id = ${gr:-0}" | jcell; }
matches() { q "SELECT (SELECT COUNT(*) FROM graph_templates_item WHERE local_graph_id = ${gr:-0}) - (SELECT COUNT(*) FROM graph_templates_item AS gi INNER JOIN graph_local AS gl ON gl.graph_template_id = gi.graph_template_id WHERE gl.id = ${gr:-0} AND gi.local_graph_id = 0)" | jcell; }
before_graph=$(q "SELECT title_cache, upper_limit FROM graph_templates_graph WHERE local_graph_id = ${gr:-0}" | jcell)
$L set_setting "smokeping_loss_colors=2.7" >/dev/null
out=$(php $P/cli/smokeping.php upgrade-graphs 2>&1); rc=$?
legacy=$(tpl_name); legacy_rendered=$($L render "${gr:-0}" | res)
after_graph=$(q "SELECT title_cache, upper_limit FROM graph_templates_graph WHERE local_graph_id = ${gr:-0}" | jcell)
[ $rc -eq 0 ] && [ "$legacy" = "SmokePing Smoke (20 pings, 2.7 loss colors)" ] && [ "$(matches)" = 0 ] && [ "$after_graph" = "$before_graph" ] && [ "$legacy_rendered" = image ] \
	&& result PASS loss-colors-setting-restyles "$legacy; $after_graph; $out" \
	|| result FAIL loss-colors-setting-restyles "rc $rc, template '$legacy', item difference $(matches), graph '$before_graph' to '$after_graph', render $legacy_rendered: $out"
$L set_setting "smokeping_loss_colors=auto" >/dev/null
out=$(php $P/cli/smokeping.php upgrade-graphs 2>&1); rc=$?
current=$(tpl_name)
[ $rc -eq 0 ] && [ "$current" = "SmokePing Smoke (20 pings)" ] && [ "$(matches)" = 0 ] && [ "$($L render "${gr:-0}" | res)" = image ] \
	&& result PASS loss-colors-auto-restores "$current with no SmokePing found; $out" \
	|| result FAIL loss-colors-auto-restores "rc $rc, template '$current', item difference $(matches): $out"

# A restyle that would leave an item without its data source is refused:
# with the median renamed, change_graph_template() finds no data source of
# that name and leaves the new item unbound. The graph keeps its template.
ds_name() { DS_NAME="$1" DS_ID="${rrd:-0}" php -r 'chdir("/cacti"); include("./include/cli_check.php"); db_execute_prepared("UPDATE data_template_rrd SET data_source_name = ? WHERE id = ?", [getenv("DS_NAME"), (int) getenv("DS_ID")]);'; }
rrd=$(q "SELECT dtr.id FROM graph_templates_item AS gi INNER JOIN data_template_rrd AS dtr ON dtr.id = gi.task_item_id WHERE gi.local_graph_id = ${gr:-0} AND dtr.data_source_name = 'median' LIMIT 1" | jcell)
ds_name median_x
$L set_setting "smokeping_loss_colors=2.7" >/dev/null
out=$(php $P/cli/smokeping.php upgrade-graphs 2>&1); rc=$?
kept=$(tpl_name); unbound=$(q "SELECT COUNT(*) FROM graph_templates_item AS gi INNER JOIN graph_templates_item AS ti ON ti.id = gi.local_graph_template_item_id WHERE gi.local_graph_id = ${gr:-0} AND ti.task_item_id > 0 AND gi.task_item_id = 0" | jcell)
[ -n "$rrd" ] && [ $rc -eq 1 ] && [ "$kept" = "SmokePing Smoke (20 pings)" ] && [ "$unbound" = 0 ] \
	&& result PASS loss-colors-unbound-item-refused "graph keeps $kept with every item bound; $out" \
	|| result FAIL loss-colors-unbound-item-refused "data source item '$rrd', rc $rc, template '$kept', $unbound unbound items: $out"
ds_name median
$L set_setting "smokeping_loss_colors=auto" >/dev/null
php $P/cli/smokeping.php upgrade-graphs >/dev/null 2>&1

# Poller and boost ignore it: input method None means no poller_item rows.
row=$(q "SELECT COUNT(*) FROM poller_item WHERE local_data_id = ${ds:-0}" | jcell)
[ "$row" = "0" ] && result PASS not-polled "no poller_item rows" || result FAIL not-polled "$row rows"

# Provisioning again returns the same objects and creates nothing.
again=$(php $P/cli/smokeping.php provision Europe/paris --device="${dev:-0}" 2>&1)
[ "$again" = "Summary data source ${sds:-0} graph ${sgr:-0}
Data source ${ds:-0} graph ${gr:-0}" ] && result PASS provision-idempotent "$again" || result FAIL provision-idempotent "$again"

# The summary data source: script server input, polled, one series per target location.
row=$(q "SELECT d.data_input_id > 0 AS has_input, i.type_id, v.value FROM data_template_data d JOIN data_input i ON i.id = d.data_input_id JOIN data_input_data v ON v.data_template_data_id = d.id JOIN data_input_fields f ON f.id = v.data_input_field_id AND f.data_name = 'series' WHERE d.local_data_id = ${sds:-0}" | jcell)
[ "$row" = "1 5 $(q "SELECT id FROM plugin_smokeping_target WHERE path = 'Europe/paris'" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r[0]["id"];')~" ] && result PASS summary-data-source "script server input, series $row" || result FAIL summary-data-source "$row"

row=$(q "SELECT action, arg1 FROM poller_item WHERE local_data_id = ${sds:-0}" | jcell)
case "$row" in "2 "*"/plugins/smokeping/scripts/ss_smokeping_summary.php ss_smokeping_summary '"*"~'") result PASS summary-polled "script server poller_item: $row";; *) result FAIL summary-polled "$row";; esac

# 2. Unsupported pings, RRDproxy, traversal, missing file.
out=$(php $P/cli/smokeping.php provision Europe/berlin 2>&1); rc=$?
[ $rc -eq 0 ] && echo "$out" | grep -q 'only for 10, 20, and 30' && echo "$out" | grep -q '^Summary data source' && result PASS unsupported-pings "no smoke graph, with a reason; summary only" || result FAIL unsupported-pings "$out"

$L set_setting "storage_location=1" >/dev/null
out=$(php $P/cli/smokeping.php provision Europe/rome 2>&1); rc=$?
$L set_setting "storage_location=0" >/dev/null
[ $rc -eq 0 ] && echo "$out" | grep -q 'RRDproxy' && echo "$out" | grep -q '^Summary data source' && result PASS rrdproxy-no-smoke "no smoke graph, with a reason; summary only" || result FAIL rrdproxy-no-smoke "$out"

out=$(php $P/cli/smokeping.php provision Europe/escape 2>&1); rc=$?
echo "$out" | grep -q '^SKIPPED: ' && ! echo "$out" | grep -q '^Data source' && result PASS symlinked-file-refused "$(echo "$out" | grep '^SKIPPED: ' | head -1)" || result FAIL symlinked-file-refused "$out"

out=$(php $P/cli/smokeping.php provision Europe/linkdir/x 2>&1); rc=$?
echo "$out" | grep -q '^SKIPPED: ' && ! echo "$out" | grep -q '^Data source' && result PASS symlinked-directory-refused "$(echo "$out" | grep '^SKIPPED: ' | head -1)" || result FAIL symlinked-directory-refused "$out"

objects=$(q "SELECT id FROM plugin_smokeping_object WHERE state IN ('active', 'archived')" | jcount)
[ "$objects" = 1 ] && result PASS refused-targets-have-no-objects "1 object" || result FAIL refused-targets-have-no-objects "$objects objects"

# 3. Re-link keeps both histories graphable.
php $P/cli/smokeping.php provision Europe/oldname >/dev/null 2>&1
out=$(php $P/cli/smokeping.php relink Europe/oldname Europe/newname 2>&1)
live=$(q "SELECT o.local_graph_id, d.data_source_path FROM plugin_smokeping_object o JOIN data_template_data d ON d.local_data_id = o.local_data_id JOIN plugin_smokeping_target t ON t.id = o.target_id WHERE o.state = 'active' AND t.path = 'Europe/newname'" | jcell)
arch=$(q "SELECT o.local_graph_id, d.data_source_path FROM plugin_smokeping_object o JOIN data_template_data d ON d.local_data_id = o.local_data_id JOIN plugin_smokeping_target t ON t.id = o.target_id WHERE o.state = 'archived' AND t.path = 'Europe/oldname'" | jcell)
live_graph=${live%% *}; arch_graph=${arch%% *}
case "$live" in *"<path_rra>/smokeping/Europe/newname.rrd") ok=1;; *) ok=0;; esac
case "$arch" in *"<path_rra>/smokeping/Europe/oldname.rrd") ok2=1;; *) ok2=0;; esac
[ "$ok" = 1 ] && [ "$ok2" = 1 ] && result PASS relink-paths "live -> newname, archived -> oldname" || result FAIL relink-paths "$out / $live / $arch"
[ "$($L render "${live_graph:-0}" | res)" = image ] && [ "$($L render "${arch_graph:-0}" | res)" = image ] && result PASS relink-both-graphable "both graphs render" || result FAIL relink-both-graphable "live $live_graph archived $arch_graph"

# 4. File safety. Delete every plugin-created device and its data sources, then run maintenance.
$L set_setting "rrd_autoclean=on" >/dev/null
devices=$(q "SELECT DISTINCT host_id FROM plugin_smokeping_object ORDER BY host_id" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo implode(",", array_column($r, "host_id"));')

for method in 1 3; do
	# method 1 deletes the file, 3 archives it. Rebuild the plugin's objects for each pass.
	if [ "$method" = 3 ]; then
		$L set_setting "rrd_autoclean_method=3" >/dev/null
		dev=$(php $P/cli/smokeping.php provision Europe/paris 2>&1 | sed -n 's/^Created device \([0-9]*\)$/\1/p')
		devices=$dev
	else
		$L set_setting "rrd_autoclean_method=1" >/dev/null
	fi

	[ "$GUARD" = noguard ] && $L disable >/dev/null
	$L remove_devices "$devices" > "$work_out" 2>&1 || cat "$work_out" >&2
	[ "$GUARD" = noguard ] && $L enable >/dev/null

	left=$(q "SELECT (SELECT COUNT(*) FROM host WHERE id IN ($devices)) + (SELECT COUNT(*) FROM data_local WHERE host_id IN ($devices)) AS n" | jcell)
	[ "$left" = 0 ] && result PASS "devices-deleted-method$method" "devices $devices and their data sources are gone" || result FAIL "devices-deleted-method$method" "$left rows remain"

	queued=$(q "SELECT COUNT(*) FROM data_source_purge_action WHERE name LIKE '%smokeping/%'" | jcell)
	php poller_maintenance.php --force >/dev/null 2>&1
	after=$(sums)

	if [ "$GUARD" = guard ]; then
		[ "$after" = "$before" ] && result PASS "files-unchanged-method$method" "every RRD byte-identical, $queued purge rows under the mount, $(( $(warnings) - now_log )) maintenance warnings" || result FAIL "files-unchanged-method$method" "files differ"
	else
		[ "$after" != "$before" ] && result PASS "control-files-lost-method$method" "without the plugin the writable mount loses files" || result INFO "control-files-lost-method$method" "files survived without the plugin"
	fi

	[ "$MODE" = ro ] && [ "$GUARD" = noguard ] && { [ "$after" = "$before" ] && result PASS "readonly-mount-alone-protects-method$method" "no plugin, ro mount: byte-identical" || result FAIL "readonly-mount-alone-protects-method$method" "files differ"; }

	[ "$GUARD" = guard ] || break
done

# 5. The RRD cleaner: an administrator queues a SmokePing file by hand.
$L queue_purge "smokeping/Europe/rome.rrd|1" >/dev/null
php poller_maintenance.php --force >/dev/null 2>&1
if [ "$MODE" = ro ]; then
	[ "$(sums)" = "$before" ] && result PASS rrdcleaner-queued-file-unchanged "ro mount: byte-identical" || result FAIL rrdcleaner-queued-file-unchanged "files differ"
else
	[ "$(sums)" != "$before" ] && result PASS rrdcleaner-on-writable-mount-is-unprotected "writable mount: the file was deleted, which is why the mount must be read-only" || result INFO rrdcleaner-on-writable-mount-is-unprotected "file survived"
fi

# 5b. The purge in a real poller run. poller.php starts poller_maintenance.php
# in the background before the poller_bottom hooks; with a slow plugin ordered
# first (tests/slowplugin), a sweep must not depend on winning that race.
if [ "$GUARD" = guard ] && [ -f plugins/slowplugin/setup.php ]; then
	php -r 'include("./include/cli_check.php");
		db_execute("DELETE FROM plugin_config WHERE directory = \"slowplugin\""); db_execute("DELETE FROM plugin_hooks WHERE name = \"slowplugin\"");
		db_execute("INSERT INTO plugin_config (directory, name, status, author, webpage, version) VALUES (\"slowplugin\", \"slowplugin\", 1, \"\", \"\", \"1.0\")");
		db_execute_prepared("UPDATE plugin_config SET id = ? WHERE directory = \"smokeping\"", array(db_fetch_cell("SELECT MAX(id) FROM plugin_config") + 1));
		db_execute("INSERT INTO plugin_hooks (name, hook, file, `function`, status) VALUES (\"slowplugin\", \"poller_bottom\", \"setup.php\", \"slowplugin_poller_bottom\", 1)");' >/dev/null 2>&1
	$L set_setting "path_php_binary=$(command -v php)" >/dev/null
	$L set_setting "poller_enabled=on" >/dev/null
	$L set_setting "rrd_autoclean_method=1" >/dev/null
	before3=$(sums)
	swept=$(grep -c 'queued purge entries under the SmokePing mount' log/cacti.log 2>/dev/null || true)
	# The RRD cleaner page's spelling: rra_path stripped, leading slash kept.
	$L queue_purge "/smokeping/Europe/berlin.rrd|1" >/dev/null
	php poller.php --force >/dev/null 2>&1
	n=0; while grep -qs 'poller_mainten[a]nce' /proc/[0-9]*/cmdline && [ $n -lt 30 ]; do sleep 1; n=$((n + 1)); done
	left=$(q "SELECT COUNT(*) FROM data_source_purge_action WHERE name LIKE '%smokeping/%'" | jcell)
	swept=$(( $(grep -c 'queued purge entries under the SmokePing mount' log/cacti.log 2>/dev/null || true) - swept ))
	[ "$(sums)" = "$before3" ] && [ "$left" = 0 ] && [ "$swept" -ge 1 ] && result PASS poller-run-sweeps-before-purge "a row queued before the run was swept before poller_maintenance.php reached it; every RRD byte-identical" || result FAIL poller-run-sweeps-before-purge "files $( [ "$(sums)" = "$before3" ] && echo unchanged || echo changed), $left rows left, $swept sweeps"
	php -r 'include("./include/cli_check.php"); db_execute("DELETE FROM plugin_hooks WHERE name = \"slowplugin\""); db_execute("DELETE FROM plugin_config WHERE directory = \"slowplugin\"");' >/dev/null 2>&1
fi

# 6. rrdcheck reads RRDs and writes nothing.
$L set_setting "rrdcheck_enable=on" >/dev/null
before2=$(sums)
php poller_rrdcheck.php --force >/dev/null 2>&1
[ "$(sums)" = "$before2" ] && result PASS rrdcheck-unchanged "rrdcheck run leaves the files as they were" || result FAIL rrdcheck-unchanged "files differ"

exit $fail
