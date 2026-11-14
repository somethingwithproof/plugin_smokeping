#!/bin/sh
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

row=$(q "SELECT availability_method FROM host WHERE id = ${dev:-0}" | jcell)
[ "$row" = "0" ] && result PASS device-availability-none "availability_method=0" || result FAIL device-availability-none "$row"

row=$(q "SELECT COUNT(*) FROM plugin_smokeping_mapping WHERE host_id = ${dev:-0}" | jcell)
[ "$row" = "1" ] && result PASS device-mapped "1 mapping" || result FAIL device-mapped "$row"

rendered=$($L render "${gr:-0}" | res)
[ "$rendered" = image ] && result PASS graph-renders "an image from the plugin-created graph" || result FAIL graph-renders "$rendered"

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
