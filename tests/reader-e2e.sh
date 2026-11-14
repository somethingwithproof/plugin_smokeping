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

# End-to-end check of inventory import and the reader on a real Cacti: the
# plugin's configuration parser, real rrdtool, real database. Run from the
# Cacti root in a container that has Cacti's PHP extensions and rrdtool,
# with this plugin at plugins/smokeping and a
# disposable, installed Cacti database:
#
#   SMOKEPING_TEST_DATABASE=disposable sh plugins/smokeping/tests/reader-e2e.sh
#
# Prints one RESULT line per check and exits non-zero on any failure.
set -u

P=plugins/smokeping
L="php $P/tests/bin/lifecycle.php"
fail=0
result() { echo "RESULT $1 $2${3:+ ($3)}"; [ "$1" = PASS ] || [ "$1" = INFO ] || fail=1; }
q() { $L query "$1"; }
jcount() { php -r '$r = json_decode(stream_get_contents(STDIN), true); echo is_array($r) ? count($r) : -1;'; }
jcell() { php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r[0] ? implode(" ", array_values($r[0])) : "";'; }

rra=$(php -r 'chdir("."); include("./include/cli_check.php"); echo $config["rra_path"];' 2>/dev/null)
mount="$rra/smokeping-e2e"
work=$(mktemp -d)
trash_dir() { rm -rf "$1"; }

$L reset >/dev/null && $L install >/dev/null && $L enable >/dev/null
$L set_setting "path_rrdtool=$(command -v rrdtool)" >/dev/null
$L set_setting "smokeping_mount_path=$mount" >/dev/null
$L set_setting "smokeping_datadir=$mount" >/dev/null
$L set_setting "smokeping_config_file=$work/config" >/dev/null
$L set_setting "smokeping_reader_budget=60" >/dev/null

# SmokePing configuration from the inventory fixture, datadir = the mount.
mkdir -p "$work/cache" "$work/run"
touch "$work/smokemail" "$work/tmail" "$work/basepage.html" "$work/secrets"
printf '#!/bin/sh\n' > "$work/fping"; cp "$work/fping" "$work/dig"; chmod 755 "$work/fping" "$work/dig"
sed "s#@DIR@#$work#g; s#$work/data#$mount#" "$P/tests/Fixtures/inventory.conf" > "$work/config"
chmod 600 "$work/secrets"

trash_dir "$mount"
php $P/tests/bin/make-fixture-rrds.php "$mount" \
	"Europe/paris:300:20:normal" "Europe/paris~boston:300:20:partial" "Europe/paris~sydney.au:300:20:loss" \
	"Europe/berlin:60:10:stale" "Europe/berlin/core:60:10:never" "Slaves/remote~boston:300:20:delayed"
before=$(find "$mount" -type f -exec sha256sum {} + | sort | sha256sum)

# 1. First run: inventory import plus a read of every series.
php $P/poller_smokeping.php --inventory > "$work/out" 2>&1
targets=$(q "SELECT path FROM plugin_smokeping_target WHERE present = 1" | jcount)
[ "$targets" = 5 ] && result PASS inventory-import "5 targets" || result FAIL inventory-import "$targets targets"

series=$(q "SELECT target_id FROM plugin_smokeping_observation" | jcount)
[ "$series" = 7 ] && result PASS reader-series "7 target locations cached" || result FAIL reader-series "$series rows"

row=$(q "SELECT o.loss, o.median IS NULL, o.pings, o.file_present FROM plugin_smokeping_observation o JOIN plugin_smokeping_target t ON t.id = o.target_id WHERE t.path = 'Europe/paris' AND o.location = 'sydney.au'" | jcell)
[ "$row" = "20 1 20 1" ] && result PASS complete-loss-row "loss=20 median=NULL" || result FAIL complete-loss-row "$row"

row=$(q "SELECT o.file_present, o.observed_at FROM plugin_smokeping_observation o JOIN plugin_smokeping_target t ON t.id = o.target_id WHERE t.path = 'DNS/resolver'" | jcell)
[ "$row" = "0 0" ] && result PASS missing-file-row "absent RRD cached as never observed" || result FAIL missing-file-row "$row"

row=$(q "SELECT o.observed_at FROM plugin_smokeping_observation o JOIN plugin_smokeping_target t ON t.id = o.target_id WHERE t.path = 'Europe/berlin/core'" | jcell)
[ "$row" = "0" ] && result PASS never-updated-row "created but never updated" || result FAIL never-updated-row "$row"

health=$(q "SELECT inventory_status, inventory_targets, series_total, series_read FROM plugin_smokeping_health WHERE poller_id = 1" | jcell)
[ "$health" = "ok 5 7 7" ] && result PASS health-row "$health" || result FAIL health-row "$health"

# Measurement state derived from the fixture RRDs through the cache.
states=$(php -r '
chdir("."); include("./include/cli_check.php"); include_once("./plugins/smokeping/setup.php"); smokeping_boot();
$targets = new Somethingwithproof\Smokeping\TargetRepository(false);
$cache   = new Somethingwithproof\Smokeping\ObservationRepository(false);
$eval    = new Somethingwithproof\Smokeping\FreshnessEvaluator(new Somethingwithproof\Smokeping\SystemClock(), 60);
$all     = $targets->forPoller(1);
$obs     = $cache->forTargets(array_map(fn ($t) => $t->id->value, $all));
foreach ($all as $t) { foreach ($obs[$t->id->value] ?? [] as $o) { echo $t->path->value, ($o->location === "" ? "" : "~" . $o->location), "=", $eval->freshness($o)->value, "/", $eval->condition($o)->value, " "; } }')
expected="DNS/resolver=never/unknown Europe/berlin=stale/unknown Europe/berlin/core=never/unknown Europe/paris=current/within_policy Europe/paris~boston=current/degraded Europe/paris~sydney.au=current/complete_loss Slaves/remote~boston=delayed/within_policy "
[ "$states" = "$expected" ] && result PASS fixture-states "$states" || result FAIL fixture-states "$states"

# 2. A second run is idempotent.
php $P/poller_smokeping.php --inventory > /dev/null 2>&1
targets=$(q "SELECT id FROM plugin_smokeping_target" | jcount); series=$(q "SELECT target_id FROM plugin_smokeping_observation" | jcount)
[ "$targets/$series" = "5/7" ] && result PASS rerun-idempotent "5 targets, 7 series" || result FAIL rerun-idempotent "$targets/$series"

# 3. Duplicate start is refused while another reader holds the process slot.
sleep 30 & holder=$!
php -r 'chdir("."); include("./include/cli_check.php"); include_once("./lib/poller.php"); db_execute_prepared("INSERT INTO processes (pid, tasktype, taskname, taskid, timeout, started, last_update) VALUES (?, \"smokeping\", \"reader\", 1, 600, NOW(), NOW())", array((int) $argv[1]));' "$holder"
stamp=$(q "SELECT run_finished FROM plugin_smokeping_health" | jcell)
sleep 1
php $P/poller_smokeping.php > /dev/null 2>&1
after=$(q "SELECT run_finished FROM plugin_smokeping_health" | jcell)
[ "$stamp" = "$after" ] && result PASS duplicate-start "second reader exited without running" || result FAIL duplicate-start "$stamp -> $after"
kill $holder 2>/dev/null; wait $holder 2>/dev/null

# A holder that died without unregistering does not block the next run.
php $P/poller_smokeping.php > /dev/null 2>&1
after2=$(q "SELECT run_finished FROM plugin_smokeping_health" | jcell)
[ "$after2" != "$after" ] && result PASS stale-process-slot "dead holder is replaced" || result FAIL stale-process-slot "did not run"

# 4. A broken configuration leaves the inventory unchanged and records an error.
cp "$work/config" "$work/config.good"
printf '\n+ bad.section\nhost = 192.0.2.9\n' >> "$work/config"
php $P/poller_smokeping.php --inventory > /dev/null 2>&1
health=$(q "SELECT inventory_status, inventory_targets FROM plugin_smokeping_health" | jcell)
targets=$(q "SELECT id FROM plugin_smokeping_target WHERE present = 1" | jcount)
[ "$health" = "error 5" ] && [ "$targets" = 5 ] && result PASS broken-config "inventory kept, status error" || result FAIL broken-config "$health / $targets"
cp "$work/config.good" "$work/config"

# 5. A rename produces a re-link proposal and leaves the mapping alone.
php -r 'chdir("."); include("./include/cli_check.php"); db_execute("INSERT INTO plugin_smokeping_mapping (target_id, host_id) SELECT id, 1 FROM plugin_smokeping_target WHERE path = \"Europe/paris\"");'
sed 's/^++ paris$/++ paris_moved/' "$work/config.good" > "$work/config"
php $P/poller_smokeping.php --inventory > /dev/null 2>&1
proposal=$(q "SELECT o.path AS old_path, n.path AS new_path, r.status FROM plugin_smokeping_relink r JOIN plugin_smokeping_target o ON o.id = r.old_target_id JOIN plugin_smokeping_target n ON n.id = r.new_target_id" | jcell)
mapping=$(q "SELECT t.path FROM plugin_smokeping_mapping m JOIN plugin_smokeping_target t ON t.id = m.target_id" | jcell)
[ "$proposal" = "Europe/paris Europe/paris_moved proposed" ] && [ "$mapping" = "Europe/paris" ] && result PASS rename-proposal "$proposal; mapping unchanged" || result FAIL rename-proposal "$proposal / $mapping"
cp "$work/config.good" "$work/config"

# 6. 10,000 series: run time against the budget, and a resume after an overrun.
php $P/tests/bin/make-fixture-rrds.php "$mount" $(i=0; while [ $i -lt 10000 ]; do echo "Big/s$((i / 100))/t$((i % 100)):300:20:normal"; i=$((i + 1)); done) 
{
	sed '/^\*\*\* Targets/,$d' "$work/config.good"
	printf '*** Targets ***\nprobe = FPingNormal\nmenu = Top\ntitle = Big\n+ Big\n'
	s=0; while [ $s -lt 100 ]; do printf '++ s%d\n' $s; t=0; while [ $t -lt 100 ]; do printf '+++ t%d\nhost = 192.0.2.%d\n' $t $((t + 1)); t=$((t + 1)); done; s=$((s + 1)); done
} > "$work/config"
php $P/poller_smokeping.php --inventory > /dev/null 2>&1
row=$(q "SELECT series_total, series_read, run_duration, budget_overruns FROM plugin_smokeping_health" | jcell)
result INFO 10k-run "total read duration overruns: $row"
set -- $row
[ "$1" = 10000 ] && [ "$2" = 10000 ] && result PASS 10k-within-budget "10000 series in $3 s with a 60 s budget" || result FAIL 10k-within-budget "$row"

$L set_setting "smokeping_reader_budget=1" >/dev/null
php $P/poller_smokeping.php > /dev/null 2>&1
row=$(q "SELECT series_read, budget_overruns FROM plugin_smokeping_health" | jcell)
set -- $row
first=$1
php $P/poller_smokeping.php > /dev/null 2>&1
fresh=$(q "SELECT COUNT(*) AS c FROM plugin_smokeping_observation WHERE ingested_at >= UNIX_TIMESTAMP() - 5" | jcell)
[ "$first" -lt 10000 ] && [ "$2" -ge 1 ] && result PASS budget-overrun "stopped after $first series, overrun recorded" || result FAIL budget-overrun "$row"
[ "$fresh" -gt "$first" ] && result PASS budget-resume "second run continued with the oldest series ($fresh read in the last 5 s)" || result FAIL budget-resume "$fresh"

# 7. Killing a run mid-batch leaves every cached row complete.
$L set_setting "smokeping_reader_budget=60" >/dev/null
php $P/poller_smokeping.php > /dev/null 2>&1 & reader=$!
sleep 1; kill -9 $reader 2>/dev/null; wait $reader 2>/dev/null
bad=$(q "SELECT target_id FROM plugin_smokeping_observation WHERE file_present = 1 AND (observed_at = 0 OR loss IS NULL) AND target_id IN (SELECT id FROM plugin_smokeping_target WHERE path LIKE 'Big/%')" | jcount)
php $P/poller_smokeping.php > /dev/null 2>&1
read=$(q "SELECT series_read FROM plugin_smokeping_health" | jcell)
[ "$bad" = 0 ] && [ "$read" = 10000 ] && result PASS kill-mid-batch "no partial rows; next run read all 10000" || result FAIL kill-mid-batch "bad=$bad read=$read"

after=$(find "$mount" -path "$mount/Big" -prune -o -type f -print0 | xargs -0 sha256sum | sort | sha256sum)
[ "$before" = "$after" ] && result PASS rrd-untouched "fixture RRDs byte-identical" || result FAIL rrd-untouched

trash_dir "$mount"; trash_dir "$work"
exit $fail
