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

# One Cacti-objects performance run at N series, inside the container
# tests/run-perf-objects.sh starts: N SmokePing targets (20 pings, 300 s
# step), each on its own device, provisioned through the plugin. It times
# Cacti's device, data source, and graph management pages and the plugin's
# overview over HTTP with the devices alone (the baseline) and again with
# every smoke and summary object, then two poller cycles. Prints PERF lines.
# With PERF_OBJECTS=stock each device gets two graphs from Cacti's own Local
# Linux Machine templates instead, the same number of graphs per series as the
# plugin's, and the poller is not run: the comparison for REQ-PERF-002.
#   SMOKEPING_TEST_DATABASE=disposable [PERF_OBJECTS=stock] sh plugins/smokeping/tests/perf-objects-e2e.sh <N>
set -u

N=${1:?series count}
REQS=${PERF_REQUESTS:-20}
MODE=${PERF_OBJECTS:-plugin}
P=plugins/smokeping
L="php $P/tests/bin/lifecycle.php"
MOUNT=/cacti/rra/smokeping
BASE=http://127.0.0.1:8099/cacti
WORK=$(mktemp -d)
cell() { $L query "$1" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r[0] ? implode(" ", array_values($r[0])) : "";'; }
now() { php -r 'echo microtime(true);'; }
since() { php -r "printf('%.1f', microtime(true) - $1);"; }

# The disposable database may come from a newer 1.2 release than the tree.
php -r 'chdir("/cacti"); include("./include/cli_check.php"); db_execute_prepared("UPDATE version SET cacti = ?", array($config["cacti_version"]));'

old=$(cell "SELECT GROUP_CONCAT(host_id) FROM plugin_smokeping_mapping" 2>/dev/null)
[ -n "$old" ] && $L remove_devices "$old" >/dev/null 2>&1
$L reset >/dev/null && $L install >/dev/null && $L enable >/dev/null
for kv in "path_rrdtool=$(command -v rrdtool)" "path_php_binary=$(command -v php)" "smokeping_mount_path=$MOUNT" "smokeping_datadir=$MOUNT" "smokeping_config_file=/nonexistent/config"; do
	$L set_setting "$kv" >/dev/null
done

PASSWORD=$(php -r 'echo bin2hex(random_bytes(12));')
php -r 'chdir("/cacti"); include("./include/cli_check.php"); db_execute_prepared("UPDATE user_auth SET password = ?, must_change_password = \"\", password_change = \"\", enabled = \"on\", locked = \"\", failed_attempts = 0 WHERE id = 1", array(password_hash($argv[1], PASSWORD_DEFAULT)));' "$PASSWORD"

mkdir -p /var/www && ln -sfn /cacti /var/www/cacti
(cd /var/www && php -S 127.0.0.1:8099 -t /var/www > "$WORK/web.log" 2>&1 &)
n=0; until curl -s -o /dev/null "$BASE/index.php" || [ $n -ge 30 ]; do sleep 1; n=$((n + 1)); done

JAR=$WORK/admin.jar
login() {
	: > "$JAR"
	token=$(curl -s -c "$JAR" "$BASE/index.php" | grep -o "name='__csrf_magic' value=\"[^\"]*\"" | head -1 | sed 's/.*value="//;s/"$//')
	curl -s -b "$JAR" -c "$JAR" -o /dev/null --data-urlencode "__csrf_magic=$token" --data "action=login&login_username=admin" --data-urlencode "login_password=$PASSWORD" "$BASE/index.php"
}

# time_page <label> <page>: one warm-up, then REQS timed requests. Prints
# "<label>=p50/p95" in milliseconds, or "<label>=FAIL" when an answer was not
# the page (an error, or the login form).
time_page() {
	curl -s -b "$JAR" -o /dev/null "$BASE/$2"
	: > "$WORK/t"
	i=0
	while [ $i -lt "$REQS" ]; do
		out=$(curl -s -b "$JAR" -o "$WORK/body" -w '%{http_code} %{time_total}' "$BASE/$2")
		if [ "${out%% *}" != 200 ] || grep -q 'login_username' "$WORK/body" || ! grep -q 'logout' "$WORK/body"; then
			echo "$1=FAIL"
			return
		fi
		echo "${out#* }" >> "$WORK/t"
		i=$((i + 1))
	done
	sort -n "$WORK/t" | php -r '$t = array_map("floatval", file("php://stdin")); $n = count($t); printf("%s=%d/%d", $argv[1], round(1000 * $t[intdiv($n - 1, 2)]), round(1000 * $t[(int) ceil(0.95 * $n) - 1]));' "$1"
	echo
}

# stop <stage> <output>: a provisioning step failed, so the pages would be
# timed on a partial population.
stop() {
	echo "PERF series=$N stage=$1 FAILED $2"
	exit 1
}

pages() {
	login
	echo "$(time_page devices host.php) $(time_page data_sources data_sources.php) $(time_page graphs graphs.php) $(time_page overview plugins/smokeping/smokeping.php)"
}

t0=$(now)
dev=$(php $P/tests/bin/perf-provision.php devices "$N") || stop devices "$dev"
echo "PERF series=$N stage=devices $dev wall_s=$(since "$t0")"
echo "PERF series=$N stage=baseline $(pages)"

if [ "$MODE" = stock ]; then
	# Stock graphs from a previous stock run are on devices reset removed; the
	# templates stay, so the import is skipped when they exist.
	[ -n "$(cell "SELECT id FROM graph_templates WHERE name = 'Unix - Load Average'")" ] || php cli/import_package.php --filename=install/templates/Local_Linux_Machine.xml.gz > "$WORK/import.out" 2>&1
	t0=$(now)
	obj=$(php $P/tests/bin/perf-provision.php stock 'Unix - Load Average' 'Linux - Memory Usage') || stop stock_objects "$obj"
	echo "PERF series=$N stage=stock_objects $obj wall_s=$(since "$t0") rows='$(cell "SELECT CONCAT((SELECT COUNT(*) FROM data_template_rrd WHERE local_data_id > 0), ' data_template_rrd, ', (SELECT COUNT(*) FROM graph_templates_item WHERE local_graph_id > 0), ' graph items, ', (SELECT COUNT(*) FROM graph_local), ' graphs')")'"
	echo "PERF series=$N stage=stock $(pages)"
	exit 0
fi

t0=$(now)
obj=$(php $P/tests/bin/perf-provision.php objects) || stop objects "$obj"
echo "PERF series=$N stage=objects $obj wall_s=$(since "$t0") rows='$(cell "SELECT CONCAT((SELECT COUNT(*) FROM data_template_rrd WHERE local_data_id > 0), ' data_template_rrd, ', (SELECT COUNT(*) FROM graph_templates_item WHERE local_graph_id > 0), ' graph items, ', (SELECT COUNT(*) FROM poller_item), ' poller items')")'"
echo "PERF series=$N stage=provisioned $(pages)"

# Two cycles: the first creates the RRD files, the second is a steady cycle.
# poller_bottom starts the reader in the background, so each cycle waits for
# it to finish: the next cycle must not overlap it, as 300 s apart they never
# do. The reader's own time is its health row.
for c in first steady; do
	t0=$(now)
	php poller.php --force > "$WORK/poller-$c.out" 2>&1
	poller=$(since "$t0")
	n=0
	while pgrep -f poller_smokeping.php > /dev/null && [ $n -lt 600 ]; do sleep 1; n=$((n + 1)); done
	echo "PERF series=$N stage=poller_$c wall_s=$poller reader_s=$(cell "SELECT ROUND(run_duration, 1) FROM plugin_smokeping_health WHERE poller_id = 1") stats='$(grep -o 'SYSTEM STATS:.*' log/cacti.log 2>/dev/null | tail -1 | cut -c1-200)'"
done
