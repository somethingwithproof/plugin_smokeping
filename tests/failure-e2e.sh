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

# Failure qualification of observe mode (REQ-TEST-010, #46): each scenario
# the plugin can meet in observe mode, scripted, with the documented state
# and a check that no data was lost. Run in the container
# tests/run-failure-e2e.sh starts, with SmokePing's data directory (fixture
# RRD files, writable here as SmokePing's own user would be) at
# rra/smokeping and a disposable installed Cacti database.
#
# Scenarios scripted elsewhere: maintenance start and expiry
# (tests/alert-e2e.sh: maint-suppresses-notification and alert-after-maint)
# and an interrupted plugin upgrade (tests/Integration/LifecycleTest.php).
# An interrupted upgrade of managed configuration needs managed mode (M5).
#
# Prints one RESULT line per check and exits non-zero on any failure.
set -u

P=plugins/smokeping
L="php $P/tests/bin/lifecycle.php"
M=/cacti/rra/smokeping
fail=0
result() { echo "RESULT $1 $2${3:+ ($3)}"; [ "$1" = PASS ] || [ "$1" = INFO ] || fail=1; }
cell() { $L query "$1" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r[0] ? implode(" ", array_values($r[0])) : "";'; }
tid() { cell "SELECT id FROM plugin_smokeping_target WHERE path = '$1'"; }
answer() { php $P/scripts/ss_smokeping_summary.php "$(tid "$1")~$2" 2>/dev/null; }
obs() { cell "SELECT CONCAT(observed_at, ' ', IFNULL(loss, 'NULL'), ' ', IFNULL(median, 'NULL'), ' ', file_present) FROM plugin_smokeping_observation WHERE target_id = $(tid "$1") AND location = ''"; }
read_all() { php $P/poller_smokeping.php --force >/tmp/reader.out 2>&1; }
now=$(date +%s)

$L reset >/dev/null && $L install >/dev/null && $L enable >/dev/null
$L set_setting "path_rrdtool=$(command -v rrdtool)" >/dev/null
$L set_setting "smokeping_mount_path=$M" >/dev/null
$L set_setting "smokeping_datadir=$M" >/dev/null
$L set_setting "smokeping_config_file=/nonexistent/config" >/dev/null
$L set_setting "smokeping_reader_budget=60" >/dev/null
$L set_setting "log_verbosity=3" >/dev/null

php $P/tests/bin/make-fixture-rrds.php "$M" Fail/ok:300:20:normal Fail/dns:300:20:loss Fail/stopped:300:20:stale Fail/never:300:20:never Fail/skew-ahead:300:20:normal Fail/skew-behind:300:20:normal Fail/corrupt:300:20:normal Fail/unreadable:300:20:normal
for t in ok dns stopped never skew-ahead skew-behind corrupt unreadable; do $L add_target "Fail/$t:$t.example:20:300" >/dev/null; done
# Files the reader cannot use, from the start: garbage, and a directory where a file should be.
printf 'not an rrd' > "$M/Fail/corrupt.rrd"
rm -f "$M/Fail/unreadable.rrd"; mkdir "$M/Fail/unreadable.rrd"
untouched() { (cd "$M/Fail" && sha256sum dns.rrd stopped.rrd never.rrd skew-behind.rrd | sha256sum | cut -c1-16); }
before_files=$(untouched)

# ---- SmokePing's configuration is unreadable: the inventory fails, the targets stay.
read_all
row=$(cell "SELECT CONCAT(inventory_status, ' ', (SELECT COUNT(*) FROM plugin_smokeping_target WHERE present = 1)) FROM plugin_smokeping_health WHERE poller_id = 1")
[ "$row" = "error 8" ] && result PASS config-unreadable-keeps-inventory "inventory status error, all 8 targets still present" || result FAIL config-unreadable-keeps-inventory "$row"

# ---- DNS failure: SmokePing's probes fail and it writes complete loss. The plugin reports loss, not staleness, and does no lookups.
dns=$(answer Fail/dns '')
case "$dns" in
	"median:U loss_pct:100 age:"[0-9]*) result PASS dns-failure-reads-complete-loss "$dns" ;;
	*) result FAIL dns-failure-reads-complete-loss "$dns" ;;
esac
cond=$(php -r 'chdir("/cacti"); include("./include/cli_check.php"); include_once("./plugins/smokeping/setup.php"); smokeping_boot();
$o = (new Somethingwithproof\Smokeping\ObservationRepository(false))->forTargets(array((int) $argv[1]));
$e = new Somethingwithproof\Smokeping\FreshnessEvaluator(new Somethingwithproof\Smokeping\SystemClock(), 60);
foreach ($o[(int) $argv[1]] ?? array() as $x) { echo $e->freshness($x)->value, "/", $e->condition($x)->value; }' "$(tid Fail/dns)" 2>/dev/null)
[ "$cond" = "current/complete_loss" ] && result PASS dns-failure-state "freshness current, condition complete_loss" || result FAIL dns-failure-state "$cond"
grep -rqE 'gethostbyname|dns_get_record|checkdnsrr|getaddrinfo' $P/lib $P/scripts $P/*.php && result FAIL plugin-does-no-name-lookups "a lookup call is in the plugin" || result PASS plugin-does-no-name-lookups "no resolver call in lib, scripts, or pages"

# ---- Stopped collector: the file stops updating. Unknown, never zero loss, and the age keeps growing.
a=$(answer Fail/stopped '')
echo "$a" | grep -Eq '^median:U loss_pct:U age:[0-9]+$' && result PASS stopped-collector-is-unknown "$a" || result FAIL stopped-collector-is-unknown "$a"
a=$(answer Fail/never '')
echo "$a" | grep -Eq '^median:U loss_pct:U age:' && result PASS never-updated-is-unknown "$a" || result FAIL never-updated-is-unknown "$a"

# ---- Clock skew. The plugin compares an RRD's own update time with this host's clock.
# SmokePing's host 10 minutes ahead: a round stamped in the future.
php $P/tests/bin/fixture-update.php "$M/Fail/skew-ahead.rrd" 20 0 $((now + 600)) >/dev/null 2>&1
# SmokePing's host 30 minutes behind: the newest round is 30 minutes old.
php -r 'passthru("rrdtool lastupdate " . escapeshellarg("'$M'/Fail/skew-behind.rrd"));' >/dev/null
read_all
a=$(answer Fail/skew-ahead '')
echo "$a" | grep -Eq '^median:[0-9.]+ loss_pct:0' && result PASS skew-ahead-reads-current "$a: a future timestamp is current; a stopped collector hides for as long as the skew" || result FAIL skew-ahead-reads-current "$a"
cond=$(php -r 'chdir("/cacti"); include("./include/cli_check.php"); include_once("./plugins/smokeping/setup.php"); smokeping_boot();
$o = (new Somethingwithproof\Smokeping\ObservationRepository(false))->forTargets(array((int) $argv[1]));
$e = new Somethingwithproof\Smokeping\FreshnessEvaluator(new Somethingwithproof\Smokeping\SystemClock(), 60);
foreach ($o[(int) $argv[1]] ?? array() as $x) { echo $e->freshness($x)->value, " age ", $e->age($x); }' "$(tid Fail/skew-ahead)" 2>/dev/null)
case "$cond" in current*) result PASS skew-ahead-state "$cond";; *) result FAIL skew-ahead-state "$cond";; esac
# The same file read with a clock 30 minutes ahead of SmokePing's: stale, with a growing age.
cond=$(php -r 'chdir("/cacti"); include("./include/cli_check.php"); include_once("./plugins/smokeping/setup.php"); smokeping_boot();
$o = (new Somethingwithproof\Smokeping\ObservationRepository(false))->forTargets(array((int) $argv[1]));
$e = new Somethingwithproof\Smokeping\FreshnessEvaluator(new Somethingwithproof\Smokeping\FixedClock(time() + 1800), 60);
foreach ($o[(int) $argv[1]] ?? array() as $x) { echo $e->freshness($x)->value; }' "$(tid Fail/skew-behind)" 2>/dev/null)
[ "$cond" = "stale" ] && result PASS skew-behind-reads-stale "a reader clock 30 minutes ahead of SmokePing's sees stale, so skew shows as age" || result FAIL skew-behind-reads-stale "$cond"

# ---- Unreadable storage: one file is garbage and one is a directory where a file should be (both made above, before the first read).
before=$(obs Fail/ok)
read_all; rc=$?
after=$(obs Fail/ok)
row=$(cell "SELECT CONCAT(series_total, ' ', series_read) FROM plugin_smokeping_health WHERE poller_id = 1")
[ -n "$after" ] && [ "$(echo "$after" | cut -d' ' -f4)" = 1 ] && result PASS unreadable-files-do-not-stop-the-reader "the readable series were read ($row) and Fail/ok is cached" || result FAIL unreadable-files-do-not-stop-the-reader "$row; $(tail -3 /tmp/reader.out)"
a=$(answer Fail/corrupt ''); echo "$a" | grep -Eq '^median:U loss_pct:U' && result PASS corrupt-file-is-unknown "$a" || result FAIL corrupt-file-is-unknown "$a"
# A previously good reading is kept, not replaced by zero, when the file becomes unreadable.
cp "$M/Fail/ok.rrd" /tmp/ok.rrd.keep
printf 'broken' > "$M/Fail/ok.rrd"
read_all
kept=$(obs Fail/ok)
[ "$kept" = "$after" ] && result PASS unreadable-file-keeps-last-reading "the cached row is unchanged: $kept" || result FAIL unreadable-file-keeps-last-reading "$after -> $kept"
cp /tmp/ok.rrd.keep "$M/Fail/ok.rrd"

# ---- Full storage: writes to the reader's cache are refused (as a full disk refuses them).
php $P/tests/bin/fixture-update.php "$M/Fail/ok.rrd" 20 4 >/dev/null 2>&1
$L fail_observation_writes >/dev/null
read_all; rc=$?
row=$(obs Fail/ok)
$L allow_observation_writes >/dev/null
[ $rc -eq 0 ] && [ "$row" = "$after" ] && result PASS full-storage-leaves-cache-intact "the reader exited 0, the cached rows are unchanged, nothing half written" || result FAIL full-storage-leaves-cache-intact "rc $rc: $row vs $after"
read_all
row=$(obs Fail/ok)
echo "$row" | grep -q ' 4 ' && result PASS full-storage-recovers "the next cycle after the storage came back caches the new round (loss 4)" || result FAIL full-storage-recovers "$row"

# ---- Duplicate reader start: the second exits at once and the first is not disturbed.
i=0; specs=""
while [ $i -lt 1500 ]; do specs="$specs Dup/t$i:300:20:normal"; i=$((i + 1)); done
php $P/tests/bin/make-fixture-rrds.php "$M" $specs
php -r 'chdir("/cacti"); include("./include/cli_check.php"); for ($i = 0; $i < 1500; $i++) { db_execute_prepared("INSERT INTO plugin_smokeping_target (poller_id, path, host, probe, step, pings, present, first_seen, last_seen) VALUES (1, ?, ?, \"FPing\", 300, 20, 1, 1, 1)", array("Dup/t$i", "dup.example")); }' >/dev/null 2>&1
: > log/cacti.log
# A holder takes the reader's slot in Cacti's process table, as a running
# reader does, and keeps it until released. Starting a real reader first and
# the duplicate a moment later raced: on a fast host the first had finished.
rm -f /tmp/release-reader
php -r 'chdir("/cacti"); include("./include/cli_check.php"); include_once("./lib/poller.php"); if (!register_process_start("smokeping", "reader", 1, 600)) { exit(1); } while (!file_exists("/tmp/release-reader")) { usleep(100000); } unregister_process("smokeping", "reader", 1);' >/dev/null 2>&1 &
first=$!
n=0
until [ "$(cell "SELECT COUNT(*) FROM processes WHERE tasktype = 'smokeping' AND taskname = 'reader'")" = 1 ] || [ $n -ge 100 ]; do sleep 0.1; n=$((n + 1)); done
php $P/poller_smokeping.php --force >/tmp/second.out 2>&1; rc2=$?
held=$(cell "SELECT pid FROM processes WHERE tasktype = 'smokeping' AND taskname = 'reader'")
touch /tmp/release-reader
wait $first; rc1=$?
php $P/poller_smokeping.php --force >/tmp/first.out 2>&1; rc3=$?
runs=$(grep -c 'SMOKEPING STATS' log/cacti.log)
grep -q 'A SmokePing reader is already running' log/cacti.log && [ "$held" = "$first" ] && [ $rc1 -eq 0 ] && [ $rc2 -eq 0 ] && [ $rc3 -eq 0 ] && result PASS duplicate-start-exits "the second start exited 0 and logged a note; the running reader kept its slot" || result FAIL duplicate-start-exits "rc $rc1 $rc2 $rc3, slot held by '$held' not $first; $(grep SMOKEPING log/cacti.log | tail -3)"
[ "$runs" = 1 ] && [ "$(cell "SELECT COUNT(*) FROM plugin_smokeping_observation WHERE target_id IN (SELECT id FROM plugin_smokeping_target WHERE path LIKE 'Dup/%')")" = 1500 ] && result PASS duplicate-start-one-run "one run recorded, 1,500 cache rows, no duplicates" || result FAIL duplicate-start-one-run "runs $runs"

# ---- Interrupted reader: killed in the middle of a run.
$L set_setting "smokeping_reader_budget=600" >/dev/null
php -r 'chdir("/cacti"); include("./include/cli_check.php"); db_execute("DELETE FROM plugin_smokeping_observation WHERE target_id IN (SELECT id FROM plugin_smokeping_target WHERE path LIKE \"Dup/%\")");' >/dev/null 2>&1
php $P/poller_smokeping.php --force >/tmp/killed.out 2>&1 &
pid=$!
n=0; while [ "$(cell "SELECT COUNT(*) FROM plugin_smokeping_observation WHERE target_id IN (SELECT id FROM plugin_smokeping_target WHERE path LIKE 'Dup/%')")" -lt 100 ] && [ $n -lt 100 ]; do sleep 0.1; n=$((n + 1)); done
kill -9 $pid 2>/dev/null; wait $pid 2>/dev/null
partial=$(cell "SELECT COUNT(*) FROM plugin_smokeping_observation WHERE target_id IN (SELECT id FROM plugin_smokeping_target WHERE path LIKE 'Dup/%')")
bad=$(cell "SELECT COUNT(*) FROM plugin_smokeping_observation WHERE observed_at = 0 AND file_present = 1 AND target_id IN (SELECT id FROM plugin_smokeping_target WHERE path LIKE 'Dup/%')")
[ "$partial" -gt 0 ] && [ "$partial" -lt 1500 ] && [ "$bad" = 0 ] && result PASS killed-reader-leaves-whole-rows "$partial of 1500 series cached when it died; every row is a complete reading" || result FAIL killed-reader-leaves-whole-rows "partial $partial bad $bad"
start=$(date +%s)
php $P/poller_smokeping.php --force >/tmp/resume.out 2>&1; rc=$?
[ $rc -eq 0 ] && [ "$(cell "SELECT COUNT(*) FROM plugin_smokeping_observation WHERE target_id IN (SELECT id FROM plugin_smokeping_target WHERE path LIKE 'Dup/%')")" = 1500 ] && result PASS killed-reader-resumes "the next run started at once (the dead process was detected) and completed all 1,500 series" || result FAIL killed-reader-resumes "rc $rc: $(tail -3 /tmp/resume.out)"

# ---- No data lost: the plugin never wrote a SmokePing file. The files these scenarios did not touch are byte for byte what they were.
[ "$(untouched)" = "$before_files" ] && result PASS smokeping-files-untouched "dns, stopped, never, and skew-behind are unchanged after every scenario" || result FAIL smokeping-files-untouched "a file changed"

exit $fail
