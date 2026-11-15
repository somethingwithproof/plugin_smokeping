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

# The plugin against a running SmokePing, from tests/run-smokeping-e2e.sh: the
# inventory import of SmokePing's configuration and the reader's read of the
# RRD files that SmokePing is writing, mounted read-only at rra/smokeping.
# Runs from the Cacti root on a disposable database. SP_NAME names the
# SmokePing package; SP_STEP and SP_PINGS are its configuration's.
set -u

P=plugins/smokeping
L="php $P/tests/bin/lifecycle.php"
fail=0
result() { echo "RESULT $1 $SP_NAME $2${3:+ ($3)}"; [ "$1" = PASS ] || [ "$1" = INFO ] || fail=1; }
q() { $L query "$1"; }
jcount() { php -r '$r = json_decode(stream_get_contents(STDIN), true); echo is_array($r) ? count($r) : -1;'; }
jcell() { php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r[0] ? implode(" ", array_values($r[0])) : "";'; }

# The files SmokePing's General section names; the plugin's parser checks them
# as SmokePing's does.
mkdir -p /sp/cache /sp/run
touch /sp/smokemail /sp/tmail /sp/basepage.html

$L reset >/dev/null && $L install >/dev/null && $L enable >/dev/null
$L set_setting "path_rrdtool=$(command -v rrdtool)" >/dev/null
$L set_setting "smokeping_mount_path=/cacti/rra/smokeping" >/dev/null
$L set_setting "smokeping_datadir=/cacti/rra/smokeping" >/dev/null
$L set_setting "smokeping_config_file=/sp-config" >/dev/null

php $P/poller_smokeping.php --inventory > /tmp/reader.out 2>&1

rows=$(q "SELECT path, probe, step, pings FROM plugin_smokeping_target WHERE present = 1 ORDER BY path" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo implode(",", array_map(fn ($x) => implode(" ", $x), $r ?: []));')
[ "$rows" = "Local/down FPing $SP_STEP $SP_PINGS,Local/up FPing $SP_STEP $SP_PINGS" ] && result PASS inventory-import "$rows" || result FAIL inventory-import "$rows; $(tail -3 /tmp/reader.out | tr '\n' ' ')"

row=$(q "SELECT o.file_present, o.loss, o.median IS NULL, o.pings FROM plugin_smokeping_observation o JOIN plugin_smokeping_target t ON t.id = o.target_id WHERE t.path = 'Local/up'" | jcell)
[ "$row" = "1 0 0 $SP_PINGS" ] && result PASS answering-target "file, loss 0, a median, $SP_PINGS pings" || result FAIL answering-target "$row"

row=$(q "SELECT o.file_present, o.loss, o.median IS NULL, o.pings FROM plugin_smokeping_observation o JOIN plugin_smokeping_target t ON t.id = o.target_id WHERE t.path = 'Local/down'" | jcell)
[ "$row" = "1 $SP_PINGS 1 $SP_PINGS" ] && result PASS complete-loss "loss $SP_PINGS of $SP_PINGS, no median" || result FAIL complete-loss "$row"

# The median SmokePing wrote, in seconds, is what the reader cached for the
# same round. When SmokePing has written a newer round since the read, the
# reader runs again; a different median for the same round fails.
lastupdate() { rrdtool lastupdate /cacti/rra/smokeping/Local/up.rrd | php -r '$l = array_values(array_filter(explode("\n", stream_get_contents(STDIN)), "strlen")); $names = preg_split("/\s+/", trim($l[0])); [$at, $rest] = explode(":", $l[1], 2); $values = preg_split("/\s+/", trim($rest)); echo trim($at), " ", $values[array_search("median", $names, true)] ?? "";'; }
cached() { q "SELECT o.observed_at, o.median FROM plugin_smokeping_observation o JOIN plugin_smokeping_target t ON t.id = o.target_id WHERE t.path = 'Local/up' AND o.location = ''" | jcell; }
for try in 1 2 3; do
	rrd=$(lastupdate); mine=$(cached)
	[ "${rrd%% *}" = "${mine%% *}" ] && break
	php $P/poller_smokeping.php > /dev/null 2>&1
done
if [ "${rrd%% *}" != "${mine%% *}" ]; then
	result FAIL median-matches-rrd "never the same round: rrd $rrd, cached $mine"
else
	php -r 'exit(is_numeric($argv[1]) && is_numeric($argv[2]) && abs((float) $argv[1] - (float) $argv[2]) <= 1e-9 * max(1.0, abs((float) $argv[2])) ? 0 : 1);' "${mine#* }" "${rrd#* }" \
		&& result PASS median-matches-rrd "round ${rrd%% *}: ${mine#* } s" || result FAIL median-matches-rrd "round ${rrd%% *}: cached ${mine#* }, rrd ${rrd#* }"
fi

states=$(php -r '
chdir("."); include("./include/cli_check.php"); include_once("./plugins/smokeping/setup.php"); smokeping_boot();
$targets = new Somethingwithproof\Smokeping\TargetRepository(false);
$cache   = new Somethingwithproof\Smokeping\ObservationRepository(false);
$eval    = new Somethingwithproof\Smokeping\FreshnessEvaluator(new Somethingwithproof\Smokeping\SystemClock(), 60);
$all     = $targets->forPoller(1);
$obs     = $cache->forTargets(array_map(fn ($t) => $t->id->value, $all));
foreach ($all as $t) { foreach ($obs[$t->id->value] ?? [] as $o) { echo $t->path->value, "=", $eval->freshness($o)->value, "/", $eval->condition($o)->value, " "; } }')
case "$states" in
	*"Local/down=current/complete_loss"*"Local/up=current/within_policy"*|*"Local/up=current/within_policy"*"Local/down=current/complete_loss"*) result PASS measurement-state "$states" ;;
	*) result FAIL measurement-state "$states" ;;
esac

health=$(q "SELECT inventory_status, inventory_targets, series_total, series_read FROM plugin_smokeping_health WHERE poller_id = 1" | jcell)
[ "$health" = "ok 2 2 2" ] && result PASS health-row "$health" || result FAIL health-row "$health"

# The summary data source answer Thold would poll for the target that answers.
id=$(q "SELECT id FROM plugin_smokeping_target WHERE path = 'Local/up'" | jcell)
answer=$(php -r 'chdir("."); include("./include/cli_check.php"); include_once("./plugins/smokeping/setup.php"); smokeping_boot(); echo smokeping_summary_answer($argv[1]);' "$id~" 2>/dev/null)
case "$answer" in
	*"loss_pct:0"*) result PASS summary-answer "$answer" ;;
	*) result FAIL summary-answer "$answer" ;;
esac

exit $fail
