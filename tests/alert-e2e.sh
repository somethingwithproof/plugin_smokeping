#!/bin/sh
# End-to-end check of alerting on SmokePing data: the summary data source is
# polled by Cacti's poller through the script server, Thold evaluates it, and
# Maint suppresses the notification. Real poller.php, real script server,
# real plugin_thold and plugin_maint, real rrdtool; the SmokePing collector is
# a fixture that appends rounds to an RRD the way smokeping does.
#
# Run from the Cacti root in the spm2-e2e container with this plugin at
# plugins/smokeping, plugin_thold at plugins/thold, plugin_maint at
# plugins/maint, SmokePing's fixture directory (writable) at rra/smokeping,
# and a disposable installed Cacti database (see tests/run-alert-e2e.sh):
#
#   SMOKEPING_TEST_DATABASE=disposable sh plugins/smokeping/tests/alert-e2e.sh
#
# Takes about three minutes: the collector stops for longer than the age
# threshold. Prints one RESULT line per check and exits non-zero on failure.
set -u

P=plugins/smokeping
L="php $P/tests/bin/lifecycle.php"
FX=/cacti/rra/smokeping/Alert/probe.rrd
fail=0
result() {
	echo "RESULT $1 $2${3:+ ($3)}"

	if [ "$1" != PASS ] && [ "$1" != INFO ]; then
		fail=1
		# What Cacti logged just before the failure, without the noise of unrelated plugins.
		$L query "SELECT id, local_data_id, data_source_name, lastread, thold_alert, thold_fail_count FROM thold_data" | sed 's/^/DUMP /'
		tail -25 log/cacti.log | grep -v 'AUTOM8\|snmpagent\|Backtrace' | cut -c1-220 | sed 's/^/LOG /'
	fi
}
q() { $L query "$1"; }
jcell() { php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r[0] ? implode(" ", array_values($r[0])) : "";'; }

# Start from nothing: devices an earlier run created, then the plugins themselves.
old=$(q "SELECT DISTINCT host_id FROM plugin_smokeping_object" 2>/dev/null | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo is_array($r) ? implode(",", array_column($r, "host_id")) : "";')
[ -n "$old" ] && $L remove_devices "$old" >/dev/null 2>&1
for p in thold maint; do php cli/plugin_manage.php --plugin=$p --uninstall >/dev/null 2>&1; done
$L reset >/dev/null
php cli/plugin_manage.php --plugin=thold --install --enable >/dev/null 2>&1
php cli/plugin_manage.php --plugin=maint --install --enable >/dev/null 2>&1
$L install >/dev/null && $L enable >/dev/null
for kv in "path_rrdtool=$(command -v rrdtool)" "path_php_binary=$(command -v php)" "smokeping_mount_path=/cacti/rra/smokeping" "smokeping_datadir=/cacti/rra/smokeping" "poller_enabled=on"; do
	$L set_setting "$kv" >/dev/null
done

result INFO versions "thold $(sed -n 's/^version = //p' plugins/thold/INFO), maint $(sed -n 's/^version = //p' plugins/maint/INFO), Cacti $(q "SELECT cacti AS v FROM version" | jcell)"

php $P/tests/bin/make-fixture-rrds.php /cacti/rra/smokeping Alert/probe:300:20:normal Alert/stale:300:20:stale
$L add_target "Alert/probe:probe.example:20:300" >/dev/null
$L add_target "Alert/stale:stale.example:20:300" >/dev/null

out=$(php $P/cli/smokeping.php provision Alert/probe 2>&1)
dev=$(echo "$out" | sed -n 's/^Created device \([0-9]*\)$/\1/p')
sds=$(echo "$out" | sed -n 's/^Summary data source \([0-9]*\) graph \([0-9]*\)$/\1/p')
[ -n "$dev" ] && [ -n "$sds" ] && result PASS provisioned "device $dev, summary data source $sds" || { result FAIL provisioned "$out"; exit 1; }

php $P/tests/bin/alert-setup.php "$sds" 5 2 90 1 >/dev/null || { result FAIL thold-setup "could not create thresholds"; exit 1; }
tholds=$(q "SELECT id, data_source_name FROM thold_data WHERE local_data_id = $sds ORDER BY id")
loss_id=$(echo "$tholds" | php -r '$r = json_decode(stream_get_contents(STDIN), true); foreach ($r as $x) if ($x["data_source_name"] === "loss_pct") echo $x["id"];')
age_id=$(echo "$tholds" | php -r '$r = json_decode(stream_get_contents(STDIN), true); foreach ($r as $x) if ($x["data_source_name"] === "age") echo $x["id"];')

# A second series whose last round is three steps old: median and loss are
# unknown, age is numeric.
out=$(php $P/cli/smokeping.php provision Alert/stale --device="$dev" 2>&1)
sds2=$(echo "$out" | sed -n 's/^Summary data source \([0-9]*\) graph \([0-9]*\)$/\1/p')
php $P/tests/bin/alert-setup.php "$sds2" 5 1 90 1 >/dev/null || { result FAIL thold-setup-stale "could not create thresholds"; exit 1; }
loss2_id=$(q "SELECT id FROM thold_data WHERE local_data_id = $sds2 AND data_source_name = 'loss_pct'" | jcell)
age2_id=$(q "SELECT id FROM thold_data WHERE local_data_id = $sds2 AND data_source_name = 'age'" | jcell)

thold_stats() { grep -c 'SYSTEM THOLD STATS' log/cacti.log 2>/dev/null || true; }

# One collector round and one Cacti poll: the fixture appends a round, the
# reader caches it, and the poller runs the script server and Thold.
cycle() {
	[ "${2:-write}" = write ] && php $P/tests/bin/fixture-update.php "$FX" 20 "$1"
	php $P/poller_smokeping.php >/dev/null 2>&1
	before=$(thold_stats)
	php poller.php --force >/dev/null 2>&1
	n=0
	while [ "$(thold_stats)" = "$before" ] && [ $n -lt 40 ]; do sleep 1; n=$((n + 1)); done
	sleep 2
}
thold_row() { q "SELECT lastread, thold_alert, thold_fail_count FROM thold_data WHERE id = $1" | jcell; }
notices() { q "SELECT COUNT(*) FROM plugin_thold_log WHERE threshold_id = $1 AND status = $2" | jcell; }
ST_NOTIFYAL=4
ST_NOTIFYRS=5

# 1. Healthy: no alert, values reach Thold through poller_output.
cycle 0
row=$(thold_row "$loss_id")
[ "$row" = "0 0 0" ] && result PASS thold-receives-script-server-values "loss_pct lastread 0 from the script server, no alert" || result FAIL thold-receives-script-server-values "$row"
row=$(q "SELECT arg1 FROM poller_item WHERE local_data_id = $sds" | jcell)
echo "$row" | grep -q 'ss_smokeping_summary' && result PASS summary-polled-by-script-server "$row" || result FAIL summary-polled-by-script-server "$row"

# A stale series raises no loss alert (its loss is unknown) and does raise the age alert.
[ "$(notices "$loss2_id" $ST_NOTIFYAL)" = 0 ] && result PASS stale-loss-unknown-no-alert "loss_pct is U for a stale round, so no loss alert ($(thold_row "$loss2_id"))" || result FAIL stale-loss-unknown-no-alert "$(notices "$loss2_id" $ST_NOTIFYAL) alerts"
[ "$(notices "$age2_id" $ST_NOTIFYAL)" = 1 ] && result PASS stale-age-alerts "age $(thold_row "$age2_id" | cut -d' ' -f1) s on the stale series fires the age threshold" || result FAIL stale-age-alerts "$(notices "$age2_id" $ST_NOTIFYAL) alerts, row $(thold_row "$age2_id")"

# 2. Loss: 8 of 20 pings lost is 40 percent. The trigger is two polls.
cycle 8
row=$(thold_row "$loss_id")
[ "$row" = "40 0 0" ] || [ "$row" = "40 2 1" ] && result PASS loss-value-reaches-thold "lastread 40 ($row)" || result FAIL loss-value-reaches-thold "$row"
[ "$(notices "$loss_id" $ST_NOTIFYAL)" = 0 ] && result PASS loss-not-yet-triggered "one breach is below the trigger of 2" || result FAIL loss-not-yet-triggered "$(notices "$loss_id" $ST_NOTIFYAL)"
cycle 8
[ "$(notices "$loss_id" $ST_NOTIFYAL)" = 1 ] && result PASS thold-triggers-on-loss "one alert notification after two breaching polls" || result FAIL thold-triggers-on-loss "$(notices "$loss_id" $ST_NOTIFYAL) notifications, row $(thold_row "$loss_id")"
cycle 8
[ "$(notices "$loss_id" $ST_NOTIFYAL)" = 1 ] && result PASS no-repeat-alert "still one notification" || result FAIL no-repeat-alert "$(notices "$loss_id" $ST_NOTIFYAL)"

# 3. Recovery.
cycle 0
row=$(thold_row "$loss_id")
[ "$(notices "$loss_id" $ST_NOTIFYRS)" = 1 ] && result PASS thold-recovers-on-loss "restoral notification sent, state $row" || result FAIL thold-recovers-on-loss "$(notices "$loss_id" $ST_NOTIFYRS) restorals, row $row"

# 4. Maint: a device schedule suppresses the notification while the
# measurements continue.
now=$(date +%s)
php -r '
	chdir("/cacti"); include("./include/cli_check.php");
	db_execute_prepared("INSERT INTO plugin_maint_schedules (enabled, name, mtype, stime, etime, minterval) VALUES (\"on\", \"smokeping-test\", 1, ?, ?, 0)", array(time() - 600, time() + 7200));
	$id = db_fetch_cell("SELECT MAX(id) FROM plugin_maint_schedules");
	db_execute_prepared("INSERT INTO plugin_maint_hosts (type, host, schedule) VALUES (1, ?, ?)", array((int) $argv[1], $id));
' "$dev"
alerts_before=$(notices "$loss_id" $ST_NOTIFYAL)
cycle 8; cycle 8; cycle 8
row=$(thold_row "$loss_id")
[ "$(notices "$loss_id" $ST_NOTIFYAL)" = "$alerts_before" ] && result PASS maint-suppresses-notification "no new notification in three breaching polls during maintenance" || result FAIL maint-suppresses-notification "$(notices "$loss_id" $ST_NOTIFYAL) vs $alerts_before"
lastread=${row%% *}
[ "$lastread" = 40 ] && result PASS measurements-continue-in-maint "loss_pct lastread still 40; the summary data source keeps being polled" || result FAIL measurements-continue-in-maint "$row"
rrd=$(ls rra/*median_${sds}.rrd 2>/dev/null | head -1)
last=$(rrdtool lastupdate "$rrd" 2>/dev/null | tail -1 | cut -d: -f1)
[ -n "$last" ] && [ $(( $(date +%s) - last )) -lt 60 ] && result PASS summary-rrd-updated-in-maint "the Cacti RRD was updated $(( $(date +%s) - last )) s ago" || result FAIL summary-rrd-updated-in-maint "last update $last"

# Maintenance ends while loss continues: the alert follows the trigger.
php -r 'chdir("/cacti"); include("./include/cli_check.php"); db_execute("DELETE FROM plugin_maint_hosts"); db_execute("DELETE FROM plugin_maint_schedules");'
cycle 8; cycle 8
[ "$(notices "$loss_id" $ST_NOTIFYAL)" = $((alerts_before + 1)) ] && result PASS alert-after-maint "the alert fires once maintenance has ended" || result FAIL alert-after-maint "$(notices "$loss_id" $ST_NOTIFYAL) vs $alerts_before"
cycle 0

# 5. Age: the collector stops. The threshold is 90 seconds; the fixture
# collector wrote a round at the last cycle above and writes no more.
age_alerts=$(notices "$age_id" $ST_NOTIFYAL)
[ "$age_alerts" = 0 ] && result PASS age-quiet-while-collecting "no age alert while rounds arrive" || result FAIL age-quiet-while-collecting "$age_alerts"
sleep 100
cycle 0 nowrite
row=$(thold_row "$age_id")
[ "$(notices "$age_id" $ST_NOTIFYAL)" = 1 ] && result PASS thold-triggers-on-age "age ${row%% *} s exceeded 90 s after the collector stopped" || result FAIL thold-triggers-on-age "$(notices "$age_id" $ST_NOTIFYAL) notifications, row $row"
row=$(thold_row "$loss_id")
result INFO loss-after-collector-stops "loss_pct state while the round is delayed: $row"

# The collector resumes.
cycle 0
[ "$(notices "$age_id" $ST_NOTIFYRS)" = 1 ] && result PASS thold-recovers-on-age "restoral notification once rounds arrive again" || result FAIL thold-recovers-on-age "$(notices "$age_id" $ST_NOTIFYRS) restorals, row $(thold_row "$age_id")"

exit $fail
