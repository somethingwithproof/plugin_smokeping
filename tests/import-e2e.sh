#!/bin/sh
# End-to-end check of importing an existing SmokePing configuration into a
# real Cacti from the command line: the dry run, the confirmation, the devices,
# graphs, and graph tree it creates, that the graphs render, that a second run
# creates nothing, the refusals, and that SmokePing's files are never written.
# Run from the Cacti root in the spm2-e2e container with this plugin at
# plugins/smokeping, tests/Fixtures/import read-only at /etc/smokeping, the
# fixture RRD files read-only at rra/smokeping, and a disposable installed Cacti
# database (see tests/run-import-e2e.sh):
#
#   SMOKEPING_TEST_DATABASE=disposable sh plugins/smokeping/tests/import-e2e.sh
#
# Prints one RESULT line per check and exits non-zero on any failure.
set -u

P=plugins/smokeping
L="php $P/tests/bin/lifecycle.php"
CLI="php $P/cli/smokeping.php"
fail=0
result() { echo "RESULT $1 $2${3:+ ($3)}"; [ "$1" = PASS ] || [ "$1" = INFO ] || fail=1; }
q() { $L query "$1"; }
jcell() { php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r[0] ? implode(" ", array_values($r[0])) : "";'; }
cell() { q "$1" | jcell; }
W=$(mktemp -d)

rra=$(php -r 'chdir("."); include("./include/cli_check.php"); echo $config["rra_path"];' 2>/dev/null)
mount="$rra/smokeping"
files() { (find /etc/smokeping "$mount" -type f | sort | xargs sha256sum) 2>/dev/null | sha256sum | cut -c1-16; }

# Devices and trees an earlier run left.
old=$(cell "SELECT GROUP_CONCAT(id) FROM host WHERE hostname LIKE '%.example.net'")
[ -n "$old" ] && [ "$old" != NULL ] && $L remove_devices "$old" >/dev/null 2>&1
php -r 'chdir("."); include("./include/cli_check.php"); foreach (db_fetch_assoc("SELECT id FROM graph_tree WHERE name LIKE \"SmokePing%\"") as $t) { db_execute_prepared("DELETE FROM graph_tree_items WHERE graph_tree_id = ?", array($t["id"])); db_execute_prepared("DELETE FROM graph_tree WHERE id = ?", array($t["id"])); }'
$L reset >/dev/null && $L install >/dev/null && $L enable >/dev/null
$L set_setting "path_rrdtool=$(command -v rrdtool)" >/dev/null
$L set_setting "smokeping_config_file=/etc/smokeping/config" >/dev/null
$L set_setting "smokeping_mount_path=$mount" >/dev/null
$L set_setting "smokeping_datadir=/var/lib/smokeping" >/dev/null

before=$(files)
hosts0=$(cell "SELECT COUNT(*) FROM host")
counts() { cell "SELECT (SELECT COUNT(*) FROM host WHERE hostname LIKE '%.example.net') AS d, (SELECT COUNT(*) FROM plugin_smokeping_object WHERE state = 'summary') AS s, (SELECT COUNT(*) FROM plugin_smokeping_object WHERE state = 'active') AS a, (SELECT COUNT(*) FROM plugin_smokeping_mapping) AS m, (SELECT COUNT(*) FROM graph_tree_items i JOIN graph_tree t ON t.id = i.graph_tree_id WHERE t.name = 'SmokePing' AND i.host_id = 0) AS b, (SELECT COUNT(*) FROM graph_tree_items i JOIN graph_tree t ON t.id = i.graph_tree_id WHERE t.name = 'SmokePing' AND i.host_id > 0) AS l, (SELECT COUNT(*) FROM graph_tree WHERE name = 'SmokePing') AS t"; }

# ---- Dry run of the configured file, its includes resolved.
out=$($CLI import-smokeping --dry-run --tree-name=SmokePing 2>&1); rc=$?
plan=$(echo "$out" | sed -n 's/^Plan \([0-9a-f]*\): dry run.*/\1/p')
if [ $rc -eq 0 ] && [ -n "$plan" ] && echo "$out" | grep -q '^section Europe/Nordic: 1 targets$' && echo "$out" | grep -q 'create device "paris.example.net" (paris.example.net) for Europe/paris, Europe/paris_10' && echo "$out" | grep -q '^summary only: Lab/disman: it sends 15 pings'; then
	result PASS dry-run-plan "plan $plan: sections, one device for the host of two targets, the 15-ping target summary only"
else
	result FAIL dry-run-plan "rc $rc: $(echo "$out" | head -20 | tr '\n' '|')"
fi
echo "$out" | grep -q '^load: 5 series' && result PASS dry-run-load "$(echo "$out" | grep '^load:' | head -1)" || result FAIL dry-run-load "$(echo "$out" | grep '^load:')"
[ "$(cell "SELECT COUNT(*) FROM host")" = "$hosts0" ] && [ "$(counts)" = "0 0 0 0 0 0 0" ] && result PASS dry-run-writes-nothing "no device, data source, mapping, or tree" || result FAIL dry-run-writes-nothing "$(counts)"

# ---- Refusals.
$CLI import-smokeping --apply --tree-name=SmokePing >/dev/null 2>&1; rc=$?
out=$($CLI import-smokeping --apply --confirm=0000000000000000 --tree-name=SmokePing 2>&1); rc2=$?
[ $rc -eq 1 ] && [ $rc2 -eq 1 ] && echo "$out" | grep -q 'changed since the check' && [ "$(counts)" = "0 0 0 0 0 0 0" ] && result PASS apply-needs-shown-plan "no --confirm and a plan id never shown both write nothing" || result FAIL apply-needs-shown-plan "rc $rc $rc2: $out"
out=$($CLI import-smokeping --dry-run --poller=2 2>&1); rc=$?
[ $rc -eq 2 ] && echo "$out" | grep -q 'which runs the SmokePing reader' && result PASS other-poller-refused "devices must be on the reader's poller" || result FAIL other-poller-refused "rc $rc: $out"
out=$($CLI import-smokeping --dry-run --file=/etc/smokeping/config 2>&1); rc=$?
[ $rc -eq 2 ] && echo "$out" | grep -q '/etc/smokeping/config, line 5: @include is not allowed here' && result PASS upload-refuses-include "a file read on its own names the @include line" || result FAIL upload-refuses-include "rc $rc: $out"
cat /etc/smokeping/config.d/* > "$W/whole.conf"
out=$($CLI import-smokeping --dry-run --file="$W/whole.conf" --device-per=target 2>&1); rc=$?
[ $rc -eq 0 ] && echo "$out" | grep -q 'read on its own, without @include' && [ "$(echo "$out" | grep -c '^create device')" = 4 ] && result PASS upload-one-device-per-target "a whole file, four devices for four targets" || result FAIL upload-one-device-per-target "rc $rc: $(echo "$out" | grep '^create device\|^note' | tr '\n' '|')"

# ---- Apply the plan that was shown.
out=$($CLI import-smokeping --apply --confirm="$plan" --tree-name=SmokePing 2>&1); rc=$?
row=$(counts)
[ $rc -eq 0 ] && [ "$row" = "3 5 4 4 3 3 1" ] && result PASS apply-creates "3 devices, 5 summary and 4 smoke data sources, 4 mappings, 3 branches, 3 device leaves, 1 tree" || result FAIL apply-creates "rc $rc, counts $row: $(echo "$out" | tail -5 | tr '\n' '|')"

paris=$(cell "SELECT id FROM host WHERE hostname = 'paris.example.net'")
row=$(cell "SELECT COUNT(DISTINCT m.host_id), MIN(h.poller_id), MIN(h.host_template_id = (SELECT id FROM host_template WHERE name = 'SmokePing Target')) FROM plugin_smokeping_mapping m JOIN plugin_smokeping_target t ON t.id = m.target_id JOIN host h ON h.id = m.host_id WHERE t.path IN ('Europe/paris', 'Europe/paris_10')")
[ "$row" = "1 1 1" ] && result PASS one-device-per-host "both Paris targets on device $paris, poller 1, SmokePing Target template" || result FAIL one-device-per-host "$row"
row=$(cell "SELECT GROUP_CONCAT(i.title ORDER BY i.id SEPARATOR ',') FROM graph_tree_items i JOIN graph_tree t ON t.id = i.graph_tree_id WHERE t.name = 'SmokePing' AND i.host_id = 0")
nordic=$(cell "SELECT p.title FROM graph_tree_items i JOIN graph_tree_items p ON p.id = i.parent JOIN graph_tree t ON t.id = i.graph_tree_id WHERE t.name = 'SmokePing' AND i.title = 'Nordic'")
oslo=$(cell "SELECT p.title FROM graph_tree_items i JOIN graph_tree_items p ON p.id = i.parent JOIN host h ON h.id = i.host_id WHERE h.hostname = 'oslo.example.net'")
[ "$row" = "Europe,Nordic,Lab" ] && [ "$nordic" = Europe ] && [ "$oslo" = Nordic ] && result PASS tree-mirrors-sections "branches $row; Nordic under Europe; oslo under Nordic" || result FAIL tree-mirrors-sections "$row / $nordic / $oslo"
[ "$(cell "SELECT COUNT(*) FROM plugin_smokeping_object o JOIN plugin_smokeping_target t ON t.id = o.target_id WHERE t.path = 'Lab/disman' AND o.state = 'active'")" = 0 ] && result PASS summary-only-target "the 15-ping target has a summary graph and no smoke graph" || result FAIL summary-only-target "a smoke graph exists"

smoke=$(cell "SELECT o.local_graph_id FROM plugin_smokeping_object o JOIN plugin_smokeping_target t ON t.id = o.target_id WHERE t.path = 'Europe/paris' AND o.location = 'boston' AND o.state = 'active'")
summary=$(cell "SELECT o.local_graph_id FROM plugin_smokeping_object o JOIN plugin_smokeping_target t ON t.id = o.target_id WHERE t.path = 'Europe/paris_10' AND o.state = 'summary'")
r1=$($L render "$smoke" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"] ?? "";')
r2=$($L render "$summary" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"] ?? "";')
[ "$r1" = image ] && [ "$r2" = image ] && result PASS graphs-render "smoke graph $smoke (slave location) and summary graph $summary render" || result FAIL graphs-render "$r1 $r2"

# ---- A second run creates nothing new and says what exists.
first=$(counts)
out=$($CLI import-smokeping --dry-run --tree-name=SmokePing 2>&1)
plan2=$(echo "$out" | sed -n 's/^Plan \([0-9a-f]*\):.*/\1/p')
echo "$out" | grep -q '^create' && result FAIL rerun-plans-nothing "$(echo "$out" | grep '^create' | head -3)" || result PASS rerun-plans-nothing "every device already mapped and every graph existing"
out=$($CLI import-smokeping --apply --confirm="$plan2" --tree-name=SmokePing 2>&1); rc=$?
[ $rc -eq 0 ] && [ "$(counts)" = "$first" ] && [ "$(echo "$out" | grep -c 'already in Cacti on device')" = 4 ] && result PASS rerun-idempotent "counts unchanged ($first); four targets reported already in Cacti" || result FAIL rerun-idempotent "rc $rc $(counts): $(echo "$out" | head -5 | tr '\n' '|')"

# ---- A mapped device is used, not duplicated, when the target is new to the plugin.
$L reset >/dev/null && $L install >/dev/null && $L enable >/dev/null
$L set_setting "smokeping_config_file=/etc/smokeping/config" >/dev/null
$L set_setting "smokeping_mount_path=$mount" >/dev/null
out=$($CLI import-smokeping --dry-run --sections=Europe/Nordic 2>&1)
echo "$out" | grep -q "^map Europe/Nordic/oslo to device $(cell "SELECT id FROM host WHERE hostname = 'oslo.example.net'")" && ! echo "$out" | grep -q 'Europe/paris' && result PASS existing-device-matched "with the plugin's tables reset, oslo maps to its existing device by host name and poller; only the chosen section" || result FAIL existing-device-matched "$(echo "$out" | head -8 | tr '\n' '|')"

[ "$(files)" = "$before" ] && result PASS smokeping-files-untouched "configuration and RRD files byte-identical" || result FAIL smokeping-files-untouched "a file changed"

rm -rf "$W"
exit $fail
