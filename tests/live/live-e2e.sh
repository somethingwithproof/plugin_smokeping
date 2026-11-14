#!/bin/sh
# The live end-to-end suite, inside the Cacti container: the plugin against a
# real SmokePing 2.8.2 network (tests/live/run-live-e2e.sh starts this). Run
# from the Cacti root with this plugin at plugins/smokeping, plugin_thold at
# plugins/thold, SmokePing's configuration read-only at /etc/smokeping, SmokePing's
# data directory at rra/smokeping, and a fresh Cacti database.
#
#   SMOKEPING_TEST_DATABASE=disposable sh plugins/smokeping/tests/live/live-e2e.sh <main|purge>
#
# LIVE_SECONDS (330) and LIVE_CYCLES (5) set the shortest poller run; shorten them
# only to debug the suite, as the run then no longer meets the five minutes.
#
# main: the data directory is SmokePing's own, on a read-only bind mount. The
#   plugin is installed, the configuration imported (dry run, then apply), the
#   poller runs for at least LIVE_SECONDS (330), and the results are checked.
# purge: the data directory is a writable copy of it. Devices are deleted and
#   Cacti's RRD cleanup runs; every file must stay byte-identical. SmokePing
#   keeps writing its live files, so byte-identity can only be asserted on a
#   frozen copy; the live mount is covered by the read-only mount itself.
#
# The network it expects (see docs/testing.md): targets Europe/London,
# Europe/Frankfurt (10 pings), Asia/Singapore, Asia/Bangalore (30 pings),
# Oceania/Sydney, Web/SydneyHTTP (the same host as Sydney) and Faults/Unreachable
# (192.0.2.1, always lost), each at the master and at the slaves lon1 and sgp1,
# and an RRD file Local/LocalMachine.rrd that no target owns.
set -u

PHASE=${1:?main or purge}
P=plugins/smokeping
L="php $P/tests/bin/lifecycle.php"
CLI="php $P/cli/smokeping.php"
SECONDS_TO_POLL=${LIVE_SECONDS:-330}
MIN_CYCLES=${LIVE_CYCLES:-5}
BASE=http://127.0.0.1:8099/cacti
fail=0
result() {
	echo "RESULT $1 $PHASE $2${3:+ ($3)}"
	[ "$1" = PASS ] || [ "$1" = INFO ] || fail=1
}
q() { $L query "$1"; }
jcell() { php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r[0] ? implode(" ", array_values($r[0])) : "";'; }
cell() { q "$1" | jcell; }
rowsof() { php -r '$r = json_decode(stream_get_contents(STDIN), true); foreach (is_array($r) ? $r : array() as $row) { echo implode(" ", array_values($row)), "\n"; }'; }
W=$(mktemp -d)

rra=$(php -r 'chdir("."); include("./include/cli_check.php"); echo $config["rra_path"];' 2>/dev/null)
MOUNT="$rra/smokeping"
sums() { (cd "$MOUNT" && find . -type f -name '*.rrd' | sort | xargs sha256sum) | sha256sum | cut -c1-16; }
# A file's identity and size, which a delete, a replace or a truncate changes and SmokePing's in-place updates do not.
ident() { (cd "$MOUNT" && find . -type f -name '*.rrd' | sort | xargs stat -c '%n %i %s'); }

# The database was loaded from cacti.sql, which records a new install; record
# the version of the tree under test, as a finished install does.
php -r 'chdir("/cacti"); include("./include/cli_check.php"); db_execute_prepared("UPDATE version SET cacti = ?", array($config["cacti_version"]));'

if [ "$PHASE" = main ]; then
	# ---- Install on a fresh database.
	php cli/plugin_manage.php --plugin=thold --install --enable >/dev/null 2>&1
	$L install >/dev/null && $L enable >/dev/null
	for kv in "path_rrdtool=$(command -v rrdtool)" "path_php_binary=$(command -v php)" "poller_enabled=on" "smokeping_config_file=/etc/smokeping/config" "smokeping_mount_path=$MOUNT" "smokeping_datadir=/var/lib/smokeping" "rrd_autoclean=on" "rrd_autoclean_method=1"; do
		$L set_setting "$kv" >/dev/null
	done
	[ "$(cell "SELECT COUNT(*) FROM plugin_config WHERE directory = 'smokeping' AND status = 1")" = 1 ] && result PASS installed "plugin installed and enabled on Cacti $(cell "SELECT cacti FROM version")" || result FAIL installed "$(cell "SELECT status FROM plugin_config WHERE directory = 'smokeping'")"

	# ---- The mounts.
	(touch "$MOUNT/.live-e2e-probe") 2>/dev/null && { rm -f "$MOUNT/.live-e2e-probe"; result FAIL data-mount-read-only "the container could write to SmokePing's data directory"; exit 1; } || result PASS data-mount-read-only "SmokePing's data directory is read-only in the container"
	(touch /etc/smokeping/.live-e2e-probe) 2>/dev/null && { rm -f /etc/smokeping/.live-e2e-probe; result FAIL config-mount-read-only "the container could write to /etc/smokeping"; exit 1; } || result PASS config-mount-read-only "/etc/smokeping is read-only in the container"
	rrds=$(ident | wc -l | tr -d ' ')
	[ "$rrds" -ge 22 ] && result PASS live-data-visible "$rrds RRD files visible through the mount" || { result FAIL live-data-visible "$rrds RRD files"; exit 1; }

	hosts0=$(cell "SELECT COUNT(*) FROM host")

	# ---- Dry run.
	out=$($CLI import-smokeping --dry-run --tree-name=SmokePing 2>&1); rc=$?
	plan=$(echo "$out" | sed -n 's/^Plan \([0-9a-f]*\): dry run.*/\1/p')
	echo "$out" > "$W/dry-run.txt"
	devices_planned=$(echo "$out" | grep -c '^create device')
	smoke_planned=$(echo "$out" | grep -c '^create smoke graph')
	summary_planned=$(echo "$out" | grep -c '^create summary graph')
	if [ $rc -eq 0 ] && [ -n "$plan" ] && [ "$devices_planned" = 6 ] && [ "$smoke_planned" = 21 ] && [ "$summary_planned" = 21 ] && echo "$out" | grep -q '^create device "170.64.201.43" (170.64.201.43) for Oceania/Sydney, Web/SydneyHTTP$'; then
		result PASS dry-run-plan "plan $plan: 6 devices (Sydney and SydneyHTTP on one), 21 smoke and 21 summary graphs"
	else
		result FAIL dry-run-plan "rc $rc, $devices_planned devices, $smoke_planned smoke, $summary_planned summary: $(echo "$out" | head -8 | tr '\n' '|')"
	fi
	for section in Europe Asia Oceania Web Faults; do echo "$out" | grep -q "^tree \"SmokePing\": branch $section$" || result FAIL dry-run-tree "no branch $section"; done
	[ "$(cell "SELECT COUNT(*) FROM host")" = "$hosts0" ] && [ "$(cell "SELECT COUNT(*) FROM plugin_smokeping_mapping")" = 0 ] && [ "$(cell "SELECT COUNT(*) FROM graph_tree WHERE name = 'SmokePing'")" = 0 ] && result PASS dry-run-writes-nothing "no device, mapping, or tree" || result FAIL dry-run-writes-nothing "the dry run changed the database"
	echo "$out" | grep -q '^load:' && result INFO dry-run-load "$(echo "$out" | grep '^load:' | head -1)"

	# ---- Apply the plan that was shown.
	out=$($CLI import-smokeping --apply --confirm="$plan" --tree-name=SmokePing 2>&1); rc=$?
	echo "$out" > "$W/apply.txt"
	[ $rc -eq 0 ] && result PASS apply "rc 0" || result FAIL apply "rc $rc: $(echo "$out" | tail -5 | tr '\n' '|')"

	# ---- Devices: one per host; the two Sydney targets share one.
	mapped=$(cell "SELECT COUNT(DISTINCT host_id) FROM plugin_smokeping_mapping")
	names=$(q "SELECT h.hostname FROM host h WHERE h.id IN (SELECT host_id FROM plugin_smokeping_mapping) ORDER BY h.hostname" | rowsof | tr '\n' ' ')
	want="152.42.216.172 164.92.252.179 168.144.20.91 170.64.201.43 188.166.158.18 192.0.2.1 "
	[ "$mapped" = 6 ] && [ "$names" = "$want" ] && result PASS one-device-per-host "6 devices: $names" || result FAIL one-device-per-host "$mapped devices: $names"
	row=$(cell "SELECT COUNT(DISTINCT m.host_id), COUNT(*) FROM plugin_smokeping_mapping m JOIN plugin_smokeping_target t ON t.id = m.target_id WHERE t.path IN ('Oceania/Sydney', 'Web/SydneyHTTP')")
	[ "$row" = "1 2" ] && result PASS sydney-targets-share-a-device "Oceania/Sydney and Web/SydneyHTTP map to one device" || result FAIL sydney-targets-share-a-device "$row"
	row=$(cell "SELECT COUNT(*) FROM plugin_smokeping_target WHERE present = 1")
	[ "$row" = 7 ] && result PASS inventory-seven-targets "7 targets, none for Local or __cgi" || result FAIL inventory-seven-targets "$row targets"
	[ "$(cell "SELECT COUNT(*) FROM plugin_smokeping_target WHERE path LIKE 'Local%' OR path LIKE '%cgi%' OR path LIKE '\\_\\_%'")" = 0 ] && result PASS orphan-and-cgi-not-targets "no target for Local/LocalMachine.rrd, __cgi or __sortercache" || result FAIL orphan-and-cgi-not-targets "a target was made from a file that no target owns"

	# ---- Graphs: smoke graphs at the master and both slaves, and the template for the ping count.
	row=$(cell "SELECT COUNT(*) FROM plugin_smokeping_object WHERE state = 'active'")
	by_loc=$(q "SELECT IF(location = '', 'master', location) AS l, COUNT(*) AS n FROM plugin_smokeping_object WHERE state = 'active' GROUP BY location ORDER BY location" | rowsof | tr '\n' ' ')
	[ "$row" = 21 ] && [ "$by_loc" = "master 7 lon1 7 sgp1 7 " ] && result PASS smoke-graphs-at-master-and-slaves "21 smoke graphs: $by_loc" || result FAIL smoke-graphs-at-master-and-slaves "$row: $by_loc"
	row=$(cell "SELECT COUNT(*) FROM plugin_smokeping_object WHERE state = 'summary'")
	[ "$row" = 21 ] && result PASS summary-graphs "21 summary data sources and graphs" || result FAIL summary-graphs "$row"
	tpl=$(q "SELECT gt.name AS t, COUNT(*) AS n FROM plugin_smokeping_object o JOIN graph_local gl ON gl.id = o.local_graph_id JOIN graph_templates gt ON gt.id = gl.graph_template_id WHERE o.state = 'active' GROUP BY gt.name ORDER BY gt.name" | rowsof | tr '\n' '|')
	[ "$tpl" = "SmokePing Smoke (10 pings) 3|SmokePing Smoke (20 pings) 15|SmokePing Smoke (30 pings) 3|" ] && result PASS ping-count-templates "$tpl" || result FAIL ping-count-templates "$tpl"
	bad=$(cell "SELECT COUNT(*) FROM plugin_smokeping_object o JOIN plugin_smokeping_target t ON t.id = o.target_id JOIN graph_local gl ON gl.id = o.local_graph_id JOIN graph_templates gt ON gt.id = gl.graph_template_id WHERE o.state = 'active' AND gt.name <> CONCAT('SmokePing Smoke (', t.pings, ' pings)')")
	frank=$(cell "SELECT GROUP_CONCAT(DISTINCT t.pings) FROM plugin_smokeping_target t WHERE t.path = 'Europe/Frankfurt'")
	bang=$(cell "SELECT GROUP_CONCAT(DISTINCT t.pings) FROM plugin_smokeping_target t WHERE t.path = 'Asia/Bangalore'")
	[ "$bad" = 0 ] && [ "$frank" = 10 ] && [ "$bang" = 30 ] && result PASS template-matches-target-pings "every smoke graph uses the template of its target's ping count (Frankfurt $frank, Bangalore $bang)" || result FAIL template-matches-target-pings "$bad mismatches, Frankfurt $frank, Bangalore $bang"

	row=$(cell "SELECT COUNT(*) FROM graph_local WHERE host_id IN (SELECT host_id FROM plugin_smokeping_mapping) AND id NOT IN (SELECT local_graph_id FROM plugin_smokeping_object)")
	[ "$row" = 0 ] && result PASS devices-hold-only-plugin-graphs "no graph on an imported device outside the plugin's objects" || result FAIL devices-hold-only-plugin-graphs "$row graphs the plugin does not own"
	row=$(cell "SELECT title_cache FROM graph_templates_graph g JOIN plugin_smokeping_object o ON o.local_graph_id = g.local_graph_id JOIN plugin_smokeping_target t ON t.id = o.target_id WHERE t.path = 'Asia/Singapore' AND o.location = '' AND o.state = 'active'")
	[ "$row" = "Singapore - SmokePing" ] && result PASS graph-titled-for-target "$row" || result FAIL graph-titled-for-target "$row"

	# ---- Scale: every smoke graph sized from its file, as SmokePing sizes its graphs.
	# A target that lost every ping for a day has no median and keeps the
	# template's limit, so only a wrong limit or no sized graph at all fails.
	row=$(php $P/tests/bin/scale-check.php | tail -1)
	case "$row" in "sized 0 "*) result FAIL graphs-sized-from-data "no graph sized: $row";; "sized "[0-9]*" kept "[0-9]*" wrong 0") result PASS graphs-sized-from-data "$row";; *) result FAIL graphs-sized-from-data "$row: $(php $P/tests/bin/scale-check.php | tr '\n' '|')";; esac

	# ---- Tree: branches by section, each device under its sections.
	branches=$(q "SELECT i.title FROM graph_tree_items i JOIN graph_tree t ON t.id = i.graph_tree_id WHERE t.name = 'SmokePing' AND i.host_id = 0 ORDER BY i.title" | rowsof | tr '\n' ' ')
	[ "$branches" = "Asia Europe Faults Oceania Web " ] && result PASS tree-branches-by-section "branches: $branches" || result FAIL tree-branches-by-section "$branches"
	# Each device sits under the branch of the section of each of its targets.
	want=$(q "SELECT DISTINCT m.host_id AS h, SUBSTRING_INDEX(t.path, '/', 1) AS s FROM plugin_smokeping_mapping m JOIN plugin_smokeping_target t ON t.id = m.target_id ORDER BY h, s" | rowsof | tr '\n' ',')
	have=$(q "SELECT DISTINCT leaf.host_id AS h, br.title AS s FROM graph_tree_items leaf JOIN graph_tree_items br ON br.id = leaf.parent JOIN graph_tree gt ON gt.id = leaf.graph_tree_id WHERE gt.name = 'SmokePing' AND leaf.host_id > 0 ORDER BY h, s" | rowsof | tr '\n' ',')
	[ -n "$want" ] && [ "$want" = "$have" ] && result PASS tree-device-under-its-section "each device is under the branch of every section it has a target in (host id and section: $have)" || result FAIL tree-device-under-its-section "wanted $want, the tree has $have"

	# ---- Thold on the always-lost target, and on London as the control.
	unreach=$(cell "SELECT o.local_data_id FROM plugin_smokeping_object o JOIN plugin_smokeping_target t ON t.id = o.target_id WHERE t.path = 'Faults/Unreachable' AND o.location = '' AND o.state = 'summary'")
	london=$(cell "SELECT o.local_data_id FROM plugin_smokeping_object o JOIN plugin_smokeping_target t ON t.id = o.target_id WHERE t.path = 'Europe/London' AND o.location = '' AND o.state = 'summary'")
	for ds in "$unreach" "$london"; do
		php $P/tests/bin/alert-setup.php "$ds" 90 2 900 1 >/dev/null || result FAIL thold-setup "could not create thresholds for data source $ds"
	done
	loss_u=$(cell "SELECT id FROM thold_data WHERE local_data_id = ${unreach:-0} AND data_source_name = 'loss_pct'")
	loss_l=$(cell "SELECT id FROM thold_data WHERE local_data_id = ${london:-0} AND data_source_name = 'loss_pct'")
	[ -n "$loss_u" ] && [ -n "$loss_l" ] && result PASS thold-thresholds "loss_pct above 90 for 2 polls on Unreachable (threshold $loss_u) and London (threshold $loss_l)" || result FAIL thold-thresholds "unreachable=$unreach london=$london"

	# ---- Real poller cycles, one a minute, for at least SECONDS_TO_POLL seconds.
	thold_stats() { grep -c 'SYSTEM THOLD STATS' log/cacti.log 2>/dev/null || true; }
	start=$(date +%s); cycles=0
	while :; do
		before=$(thold_stats)
		php poller.php --force >/dev/null 2>&1
		n=0
		while [ "$(thold_stats)" = "$before" ] && [ $n -lt 60 ]; do sleep 1; n=$((n + 1)); done
		cycles=$((cycles + 1))
		now=$(date +%s)
		[ $((now - start)) -ge "$SECONDS_TO_POLL" ] && [ $cycles -ge "$MIN_CYCLES" ] && break
		next=$((start + cycles * 60))
		[ "$next" -gt "$now" ] && sleep $((next - now))
	done
	elapsed=$(( $(date +%s) - start ))
	sleep 5
	[ "$elapsed" -ge 300 ] && [ $cycles -ge 5 ] && result PASS poller-cycles "$cycles real poller.php cycles over $elapsed s" || result FAIL poller-cycles "$cycles cycles over $elapsed s"
	row=$(cell "SELECT COUNT(*) FROM plugin_smokeping_observation WHERE file_present = 1 AND ingested_at > $start")
	[ "$row" = 21 ] && result PASS reader-cached-every-series "21 of 21 series cached by the reader during the cycles" || result FAIL reader-cached-every-series "$row of 21 series"
	[ "$(cell "SELECT COUNT(*) FROM poller_item WHERE local_data_id IN (SELECT local_data_id FROM plugin_smokeping_object WHERE state = 'summary')")" -ge 21 ] && result PASS summary-polled-by-script-server "21 summary data sources are in the poller's item table" || result FAIL summary-polled-by-script-server "poller_item has too few rows"

	# ---- Values: the plugin's cache and summaries against rrdtool on the same files.
	php $P/tests/live/check-values.php > "$W/values.txt" 2>&1
	cat "$W/values.txt"
	grep -q '^RESULT FAIL' "$W/values.txt" && fail=1
	grep -q '^RESULT PASS values-compared' "$W/values.txt" || { result FAIL values-compared "no summary line"; }

	# ---- Unreachable: complete loss, and the threshold fired.
	sumtext=$(php $P/scripts/ss_smokeping_summary.php "$(cell "SELECT id FROM plugin_smokeping_target WHERE path = 'Faults/Unreachable'")~" 2>&1)
	echo "$sumtext" | grep -q 'loss_pct:100 ' && echo "$sumtext" | grep -q 'median:U' && result PASS unreachable-reports-total-loss "$sumtext" || result FAIL unreachable-reports-total-loss "$sumtext"
	row=$(cell "SELECT CONCAT(thold_alert, ' ', thold_fail_count, ' ', lastread) FROM thold_data WHERE id = ${loss_u:-0}")
	fired=$(cell "SELECT COUNT(*) FROM plugin_thold_log WHERE threshold_id = ${loss_u:-0} AND status = 4")
	[ "${fired:-0}" -ge 1 ] && result PASS thold-fires-on-unreachable "the loss_pct threshold on Unreachable fired $fired time(s) (alert/fail/lastread: $row)" || result FAIL thold-fires-on-unreachable "no alert logged (alert/fail/lastread: $row)"
	fired=$(cell "SELECT COUNT(*) FROM plugin_thold_log WHERE threshold_id = ${loss_l:-0} AND status = 4")
	[ "${fired:-1}" = 0 ] && result PASS thold-quiet-on-london "the same threshold never fired on London (a host that answers)" || result FAIL thold-quiet-on-london "$fired alerts on London: $(cell "SELECT CONCAT(thold_alert, ' ', lastread) FROM thold_data WHERE id = ${loss_l:-0}")"

	# ---- Pages over HTTP: every graph, and the health page.
	mkdir -p /var/www && ln -sfn /cacti /var/www/cacti
	(cd /var/www && php -S 127.0.0.1:8099 -t /var/www > "$W/web.log" 2>&1 &)
	n=0; until curl -s -o /dev/null "$BASE/index.php" || [ $n -ge 30 ]; do sleep 1; n=$((n + 1)); done
	pw=$(php -r 'echo bin2hex(random_bytes(12));')
	PW=$pw php -r 'chdir("/cacti"); include("./include/cli_check.php"); db_execute_prepared("UPDATE user_auth SET password = ?, must_change_password = \"\" WHERE id = 1", array(password_hash(getenv("PW"), PASSWORD_DEFAULT)));'
	jar="$W/admin.jar"; : > "$jar"
	token=$(curl -s -c "$jar" "$BASE/index.php" | grep -o "name='__csrf_magic' value=\"[^\"]*\"" | head -1 | sed 's/.*value="//;s/"$//')
	curl -s -b "$jar" -c "$jar" -o /dev/null --data-urlencode "__csrf_magic=$token" --data "action=login&login_username=admin" --data-urlencode "login_password=$pw" "$BASE/index.php"
	unset pw

	graphs=$(q "SELECT local_graph_id FROM plugin_smokeping_object ORDER BY local_graph_id" | rowsof)
	ok=0; bad=0; badlist=
	for g in $graphs; do
		curl -s -b "$jar" -o "$W/g.png" "$BASE/graph_image.php?local_graph_id=$g&graph_start=-10800&graph_end=0&image_format=png"
		size=$(wc -c < "$W/g.png" | tr -d ' ')
		if [ "$size" -gt 2000 ] && [ "$(head -c 4 "$W/g.png" | tail -c 3)" = PNG ]; then ok=$((ok + 1)); else bad=$((bad + 1)); badlist="$badlist $g($size)"; fi
	done
	formats=$(cell "SELECT GROUP_CONCAT(DISTINCT image_format_id) FROM graph_templates_graph WHERE local_graph_id IN (SELECT local_graph_id FROM plugin_smokeping_object)")
	result INFO graph-image-format "the graphs' own image_format_id is $formats (1 PNG, 3 SVG): graph_image.php answers in that format unless image_format=png is asked for, which is what is checked here"
	[ "$bad" = 0 ] && [ "$ok" = 42 ] && result PASS graphs-render-png "all $ok graphs (21 smoke, 21 summary) render a non-empty PNG through graph_image.php" || result FAIL graphs-render-png "$ok rendered, $bad did not:$badlist"

	curl -s -b "$jar" -c "$jar" "$BASE/plugins/smokeping/smokeping_health.php" > "$W/health.html"
	php -r '$h = file_get_contents($argv[1]); $t = html_entity_decode(strip_tags(preg_replace("/<(script|style)\b.*?<\/\1>/si", "", $h))); file_put_contents($argv[2], preg_replace("/\s+/", " ", $t));' "$W/health.html" "$W/health.txt"
	grep -q 'is on a read-only mount' "$W/health.txt" && result PASS health-mount-read-only "$(grep -o '/cacti/rra/smokeping is on a read-only mount' "$W/health.txt" | head -1)" || result FAIL health-mount-read-only "$(head -c 400 "$W/health.txt")"
	grep -q 'All 21 expected RRD files are visible through the mount' "$W/health.txt" && result PASS health-expected-files-visible "All 21 expected RRD files are visible through the mount" || result FAIL health-expected-files-visible "$(grep -o 'expected RRD files[^.]*' "$W/health.txt" | head -1)"
	# The page's Status column: no row says Error, and nothing names __cgi.
	errors=$(php -r '$h = file_get_contents($argv[1]); echo preg_match_all("/<td[^>]*>\s*Error\s*<\/td>/i", $h);' "$W/health.html")
	[ "$errors" = 0 ] && ! grep -q '__cgi\|__sortercache' "$W/health.txt" && result PASS health-no-error-for-cgi "no Error status on the health page, and __cgi is not mentioned" || result FAIL health-no-error-for-cgi "$errors Error rows"
	# The plugin never walks the data directory, so a file no target owns is neither listed nor an error.
	if grep -q 'LocalMachine' "$W/health.txt"; then result INFO health-orphan-rrd "the health page names Local/LocalMachine.rrd"; else result INFO health-orphan-rrd "Local/LocalMachine.rrd belongs to no target and the health page does not list it: the plugin reads only the files its inventory names and has no orphan report"; fi
	(cd $P && grep -rnE 'scandir|glob\(|DirectoryIterator|opendir|readdir|FilesystemIterator' lib setup.php poller_smokeping.php cli scripts smokeping*.php 2>/dev/null) | grep -q . && result FAIL plugin-never-walks-datadir "the plugin code lists a directory" || result PASS plugin-never-walks-datadir "no directory listing anywhere in the plugin, so __cgi and files no target owns are never read"

	# ---- A second import creates nothing.
	counts() { cell "SELECT (SELECT COUNT(*) FROM host), (SELECT COUNT(*) FROM plugin_smokeping_mapping), (SELECT COUNT(*) FROM plugin_smokeping_object), (SELECT COUNT(*) FROM graph_local), (SELECT COUNT(*) FROM data_local), (SELECT COUNT(*) FROM graph_tree_items)"; }
	first=$(counts)
	out=$($CLI import-smokeping --dry-run --tree-name=SmokePing 2>&1)
	plan2=$(echo "$out" | sed -n 's/^Plan \([0-9a-f]*\):.*/\1/p')
	echo "$out" | grep -q '^create' && result FAIL reimport-plans-nothing "$(echo "$out" | grep '^create' | head -3 | tr '\n' '|')" || result PASS reimport-plans-nothing "no device, graph or tree item to create"
	out=$($CLI import-smokeping --apply --confirm="$plan2" --tree-name=SmokePing 2>&1); rc=$?
	[ $rc -eq 0 ] && [ "$(counts)" = "$first" ] && result PASS reimport-idempotent "counts unchanged ($first)" || result FAIL reimport-idempotent "rc $rc: before $first, after $(counts)"

	# ---- Deleting a device leaves every SmokePing file where it was (live data, so by identity and size).
	before=$(ident)
	sydney=$(cell "SELECT m.host_id FROM plugin_smokeping_mapping m JOIN plugin_smokeping_target t ON t.id = m.target_id WHERE t.path = 'Oceania/Sydney'")
	$L remove_devices "$sydney" >/dev/null 2>&1
	left=$(cell "SELECT (SELECT COUNT(*) FROM host WHERE id = $sydney) + (SELECT COUNT(*) FROM data_local WHERE host_id = $sydney)")
	php poller_maintenance.php --force >/dev/null 2>&1
	after=$(ident)
	[ "$left" = 0 ] && [ -n "$before" ] && [ "$after" = "$before" ] && result PASS live-files-survive-device-delete "device $sydney and its data sources deleted, poller_maintenance.php ran, all $(echo "$before" | wc -l | tr -d ' ') RRD files still there with the same inode and size" || { echo "$before" > "$W/before.txt"; echo "$after" > "$W/after.txt"; result FAIL live-files-survive-device-delete "left=$left; $(diff "$W/before.txt" "$W/after.txt" | head -4 | tr '\n' '|')"; }
fi

if [ "$PHASE" = purge ]; then
	# The destructive checks run only on the frozen copy the runner made.
	[ -f "$MOUNT/.live-e2e-frozen-copy" ] || { result FAIL frozen-copy "no marker file: refusing to run destructive checks on $MOUNT"; exit 2; }
	[ -f "$MOUNT/Local/LocalMachine.rrd" ] && [ -f "$MOUNT/Europe/London.rrd" ] || { result FAIL frozen-copy "the copy has no RRD files"; exit 2; }
	result PASS frozen-copy "a writable copy of SmokePing's data directory, $(ident | wc -l | tr -d ' ') RRD files"

	before=$(sums)
	london=$(cell "SELECT m.host_id FROM plugin_smokeping_mapping m JOIN plugin_smokeping_target t ON t.id = m.target_id WHERE t.path = 'Europe/London'")
	$L remove_devices "$london" >/dev/null 2>&1
	left=$(cell "SELECT (SELECT COUNT(*) FROM host WHERE id = $london) + (SELECT COUNT(*) FROM data_local WHERE host_id = $london)")
	queued=$(cell "SELECT COUNT(*) FROM data_source_purge_action WHERE name LIKE '%smokeping/%'")
	php poller_maintenance.php --force >/dev/null 2>&1
	[ "$left" = 0 ] && [ "$(sums)" = "$before" ] && result PASS purge-device-delete-byte-identical "London deleted with its data sources, $queued purge rows queued under the mount, poller_maintenance.php ran: every RRD byte-identical" || result FAIL purge-device-delete-byte-identical "left=$left, files $( [ "$(sums)" = "$before" ] && echo unchanged || echo changed)"

	# The RRD cleaner: an administrator selects a file nobody owns and one that is a
	# target's (the cleaner page's spelling: rra_path stripped, leading slash kept),
	# and the next poller run, which starts poller_maintenance.php, sweeps them out
	# of the queue first.
	swept=$(grep -c 'queued purge entries under the SmokePing mount' log/cacti.log 2>/dev/null || true)
	$L queue_purge "/smokeping/Local/LocalMachine.rrd|1" >/dev/null
	$L queue_purge "/smokeping/Europe/Frankfurt.rrd|1" >/dev/null
	php poller.php --force >/dev/null 2>&1
	n=0; while grep -qs 'poller_mainten[a]nce' /proc/[0-9]*/cmdline && [ $n -lt 60 ]; do sleep 1; n=$((n + 1)); done
	left=$(cell "SELECT COUNT(*) FROM data_source_purge_action WHERE name LIKE '%smokeping/%'")
	swept=$(( $(grep -c 'queued purge entries under the SmokePing mount' log/cacti.log 2>/dev/null || true) - swept ))
	[ "$(sums)" = "$before" ] && [ -f "$MOUNT/Local/LocalMachine.rrd" ] && [ "$left" = 0 ] && [ "$swept" -ge 1 ] && result PASS purge-rrdcleaner-selection-byte-identical "Local/LocalMachine.rrd and Europe/Frankfurt.rrd queued for deletion: swept from the queue before poller_maintenance.php ran, every RRD byte-identical" || result FAIL purge-rrdcleaner-selection-byte-identical "files $( [ "$(sums)" = "$before" ] && echo unchanged || echo changed), $left rows left, $swept sweeps"

	# Control: without the plugin, the writable copy loses its files, so the checks above can fail.
	frank=$(cell "SELECT m.host_id FROM plugin_smokeping_mapping m JOIN plugin_smokeping_target t ON t.id = m.target_id WHERE t.path = 'Europe/Frankfurt'")
	$L disable >/dev/null
	$L remove_devices "$frank" >/dev/null 2>&1
	php poller_maintenance.php --force >/dev/null 2>&1
	$L enable >/dev/null
	[ "$(sums)" != "$before" ] && result PASS control-files-lost-without-plugin "with the plugin disabled the writable copy lost files, which is what the plugin prevents" || result INFO control-files-lost-without-plugin "files survived without the plugin"
fi

rm -rf "$W"
exit $fail
