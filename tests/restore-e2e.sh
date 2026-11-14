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

# Phases of the backup and restore qualification (#45), run inside the Cacti
# container tests/run-restore-e2e.sh starts for each phase:
#
#   source   a working installation: inventory, a mapped device with a smoke and
#            a summary data source, a reader run. Records what a restore must reproduce.
#   restore  a fresh container, with the database, SmokePing's configuration and
#            its data directory restored from backups: the mappings resolve to the
#            same RRD files and the reader resumes with no repair.
#   yaml     a fresh Cacti and database with only the YAML export restored: the
#            targets and mappings come back and the data sources resolve to the same files.
#
# Prints one RESULT line per check and exits non-zero on any failure.
set -u

PHASE=${1:?phase}
P=plugins/smokeping
L="php $P/tests/bin/lifecycle.php"
CLI="php $P/cli/smokeping.php"
STATE=/state
CONF=/srv/smokeping-config
MOUNT=/cacti/rra/smokeping
fail=0
result() { echo "RESULT $1 $PHASE-$2${3:+ ($3)}"; [ "$1" = PASS ] || [ "$1" = INFO ] || fail=1; }
q() { $L query "$1"; }
jcell() { php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r[0] ? implode(" ", array_values($r[0])) : "";'; }
cell() { q "$1" | jcell; }
lines() { php -r '$r = json_decode(stream_get_contents(STDIN), true); foreach ($r as $row) { echo implode("|", array_values($row)), PHP_EOL; }'; }

# What identifies a mapped target's files: target, device identity, data source and the file it reads.
snapshot() {
	q "SELECT t.path, h.hostname, h.description, o.state, d.data_source_path FROM plugin_smokeping_object o JOIN plugin_smokeping_target t ON t.id = o.target_id JOIN host h ON h.id = o.host_id JOIN data_template_data d ON d.local_data_id = o.local_data_id ORDER BY t.path, o.state" | lines
}

settings() {
	$L set_setting "path_rrdtool=$(command -v rrdtool)" >/dev/null
	$L set_setting "smokeping_mount_path=$MOUNT" >/dev/null
	$L set_setting "smokeping_datadir=$MOUNT" >/dev/null
	$L set_setting "smokeping_config_file=$CONF/config" >/dev/null
}

sums() { (cd "$MOUNT" && find . -type f -name '*.rrd' | sort | xargs sha256sum); }

case "$PHASE" in
source)
	$L reset >/dev/null && $L install >/dev/null && $L enable >/dev/null
	settings
	php $P/poller_smokeping.php --inventory >/dev/null 2>&1
	targets=$(cell "SELECT COUNT(*) FROM plugin_smokeping_target WHERE present = 1")
	[ "$targets" = 5 ] && result PASS inventory "5 targets from SmokePing's configuration" || result FAIL inventory "$targets"

	out=$($CLI provision Europe/paris 2>&1)
	echo "$out" | grep -q '^Data source' && result PASS provision "$(echo "$out" | tr '\n' ' ')" || result FAIL provision "$out"
	php $P/poller_smokeping.php >/dev/null 2>&1

	snapshot > $STATE/objects.txt
	sums > $STATE/sums.txt
	cell "SELECT COUNT(*) FROM plugin_smokeping_observation WHERE file_present = 1" > $STATE/observations.txt
	gr=$(cell "SELECT local_graph_id FROM plugin_smokeping_object WHERE state = 'active' LIMIT 1")
	[ "$($L render "${gr:-0}" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"];')" = image ] && result PASS graph-renders "graph $gr" || result FAIL graph-renders "graph $gr"
	rm -f $STATE/config.yaml; $CLI export --file=$STATE/config.yaml >/dev/null 2>&1
	[ "$(wc -l < $STATE/objects.txt | tr -d ' ')" -ge 2 ] && result PASS recorded "$(wc -l < $STATE/objects.txt | tr -d ' ') data sources recorded, YAML export written" || result FAIL recorded "$(cat $STATE/objects.txt)"
	;;

restore)
	# Nothing is installed or repaired here: the database came back from its dump.
	row=$(cell "SELECT status FROM plugin_config WHERE directory = 'smokeping'")
	[ "$row" = 1 ] && result PASS plugin-enabled-from-backup "plugin_config status 1" || result FAIL plugin-enabled-from-backup "$row"

	php -r 'chdir("/cacti"); include("./include/cli_check.php"); include_once("./plugins/smokeping/setup.php"); echo plugin_smokeping_upgrade() ? "ok" : "failed";' > /tmp/up 2>/dev/null
	[ "$(cat /tmp/up)" = ok ] && result PASS upgrade-is-a-no-op "plugin_smokeping_upgrade() on the restored database" || result FAIL upgrade-is-a-no-op "$(cat /tmp/up)"

	snapshot > /tmp/objects.txt
	cmp -s /tmp/objects.txt $STATE/objects.txt && result PASS mappings-and-paths-same "$(wc -l < /tmp/objects.txt | tr -d ' ') data sources, same targets, devices, and paths" || result FAIL mappings-and-paths-same "$(diff $STATE/objects.txt /tmp/objects.txt | head -4)"

	# Each data source's path resolves, through Cacti, to a file that exists and is the restored one.
	php -r '
	chdir("/cacti"); include("./include/cli_check.php"); include_once("./lib/rrd.php");
	$bad = 0; $n = 0;
	foreach (db_fetch_assoc("SELECT local_data_id FROM plugin_smokeping_object WHERE state = \"active\"") as $r) {
		$n++; $file = get_data_source_path((int) $r["local_data_id"], true);
		if (!is_string($file) || !is_file($file) || strpos($file, "/cacti/rra/smokeping/") !== 0) { $bad++; }
	}
	echo $n > 0 && $bad === 0 ? "ok $n" : "bad $bad of $n";' > /tmp/resolve 2>/dev/null
	case "$(cat /tmp/resolve)" in ok*) result PASS data-sources-resolve-to-restored-files "$(cat /tmp/resolve) resolve under the mount to existing files";; *) result FAIL data-sources-resolve-to-restored-files "$(cat /tmp/resolve)";; esac

	# SmokePing wrote a new round to Europe/paris.rrd after the restore; every other file must be the one that was backed up.
	sums | grep -v ' ./Europe/paris.rrd$' > /tmp/sums.txt
	grep -v ' ./Europe/paris.rrd$' $STATE/sums.txt > /tmp/sums-before.txt
	cmp -s /tmp/sums.txt /tmp/sums-before.txt && result PASS datadir-restored-identical "$(wc -l < /tmp/sums.txt | tr -d ' ') RRD files other than the one SmokePing kept writing have the checksums recorded before the loss" || result FAIL datadir-restored-identical "$(diff /tmp/sums-before.txt /tmp/sums.txt | head -3)"

	gr=$(cell "SELECT local_graph_id FROM plugin_smokeping_object WHERE state = 'active' LIMIT 1")
	[ "$($L render "${gr:-0}" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"];')" = image ] && result PASS graph-renders-after-restore "graph $gr" || result FAIL graph-renders-after-restore "graph $gr"

	# A new round was written to the restored file by SmokePing (the runner did that before this phase).
	last=$(rrdtool lastupdate "$MOUNT/Europe/paris.rrd" | tail -1 | cut -d: -f1)
	php $P/poller_smokeping.php > /tmp/reader.out 2>&1; rc=$?
	row=$(cell "SELECT CONCAT(o.observed_at, ' ', o.loss) FROM plugin_smokeping_observation o JOIN plugin_smokeping_target t ON t.id = o.target_id WHERE t.path = 'Europe/paris' AND o.location = ''")
	[ $rc -eq 0 ] && [ "$row" = "$last 3" ] && result PASS reader-resumes "one poller cycle later the cache holds the round SmokePing wrote after the restore (loss 3 at $last)" || result FAIL reader-resumes "rc $rc row '$row' want '$last 3': $(tail -2 /tmp/reader.out)"

	row=$(cell "SELECT inventory_status FROM plugin_smokeping_health WHERE poller_id = 1")
	[ "$row" = ok ] && result PASS health-ok-after-restore "inventory status ok" || result INFO health-after-restore "inventory status '$row'"
	;;

yaml)
	$L reset >/dev/null && $L install >/dev/null && $L enable >/dev/null
	settings

	# The devices exist again under the names the export carries, with ids this installation chose.
	php -r '
	chdir("/cacti"); include("./include/cli_check.php");
	include_once("./plugins/smokeping/setup.php"); smokeping_boot();
	$doc = Somethingwithproof\Smokeping\ConfigCodec::parse("yaml", Somethingwithproof\Smokeping\ConfigCodec::readFile("'$STATE'/config.yaml"));
	foreach ($doc->mappings as $m) { echo $m->hostname, "|", $m->description, PHP_EOL; }' > /tmp/devices.txt
	while IFS= read -r device; do $L make_device "$device" >/dev/null; done < /tmp/devices.txt
	[ -s /tmp/devices.txt ] && result PASS devices-recreated "$(wc -l < /tmp/devices.txt | tr -d ' ') device recreated by name" || result FAIL devices-recreated "the export has no mappings"

	out=$($CLI import --file=$STATE/config.yaml 2>&1); plan=$(echo "$out" | sed -n 's/^Plan \([0-9a-f]*\): check only.*/\1/p')
	out2=$($CLI import --file=$STATE/config.yaml --apply --confirm="$plan" 2>&1); rc=$?
	[ $rc -eq 0 ] && result PASS import-applied "plan $plan: $out2" || result FAIL import-applied "rc $rc: $out2 / $out"

	hostid=$(cell "SELECT m.host_id FROM plugin_smokeping_mapping m JOIN plugin_smokeping_target t ON t.id = m.target_id WHERE t.path = 'Europe/paris'")
	named=$(cell "SELECT id FROM host WHERE hostname = '$(cut -d'|' -f1 /tmp/devices.txt | head -1)'")
	[ -n "$hostid" ] && [ "$hostid" = "$named" ] && result PASS mapping-follows-device-name "Europe/paris -> device $hostid, found by host name and description" || result FAIL mapping-follows-device-name "mapped $hostid, device $named"

	# SmokePing's configuration is read again; the targets are the same ones, so nothing is duplicated and the mapping stays.
	php $P/poller_smokeping.php --inventory >/dev/null 2>&1
	row=$(cell "SELECT CONCAT((SELECT COUNT(*) FROM plugin_smokeping_target), ' ', (SELECT COUNT(*) FROM plugin_smokeping_mapping), ' ', (SELECT host_id FROM plugin_smokeping_mapping m JOIN plugin_smokeping_target t ON t.id = m.target_id WHERE t.path = 'Europe/paris'))")
	[ "$row" = "5 1 $hostid" ] && result PASS inventory-after-import-keeps-mapping "5 targets, 1 mapping, unchanged device" || result FAIL inventory-after-import-keeps-mapping "$row"

	# The data sources are not in the export: provisioning recreates them on the same files.
	out=$($CLI provision Europe/paris --device="$hostid" 2>&1)
	snapshot > /tmp/objects.txt
	# Only the smoke data source reads SmokePing's file; the summary data source is Cacti's own RRD and its name carries a new id.
	expected=$(grep '|active|' $STATE/objects.txt)
	actual=$(grep '|active|' /tmp/objects.txt)
	[ "$expected" = "$actual" ] && result PASS provision-recreates-same-paths "data sources point at the same files as before: $(cut -d'|' -f5 /tmp/objects.txt | tr '\n' ' ')" || result FAIL provision-recreates-same-paths "$out / $(diff $STATE/objects.txt /tmp/objects.txt | head -4)"

	php $P/poller_smokeping.php >/dev/null 2>&1
	row=$(cell "SELECT COUNT(*) FROM plugin_smokeping_observation WHERE file_present = 1")
	[ "$row" = "$(cat $STATE/observations.txt)" ] && result PASS reader-resumes "$row observations cached" || result FAIL reader-resumes "$row vs $(cat $STATE/observations.txt)"
	;;
esac

exit $fail
