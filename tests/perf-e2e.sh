#!/bin/sh
# One performance run at N series, inside the container tests/run-perf.sh
# starts: N SmokePing targets (20 pings, 300 s step) with RRD files on a
# read-only mount, all mapped to one device. Prints one line of numbers.
#
#   SMOKEPING_TEST_DATABASE=disposable sh plugins/smokeping/tests/perf-e2e.sh <N>
set -u

N=${1:?series count}
P=plugins/smokeping
L="php $P/tests/bin/lifecycle.php"
MOUNT=/cacti/rra/smokeping
now() { php -r 'echo microtime(true);'; }
cell() { $L query "$1" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r[0] ? implode(" ", array_values($r[0])) : "";'; }

$L reset >/dev/null && $L install >/dev/null && $L enable >/dev/null
$L set_setting "path_rrdtool=$(command -v rrdtool)" >/dev/null
$L set_setting "smokeping_mount_path=$MOUNT" >/dev/null
$L set_setting "smokeping_datadir=$MOUNT" >/dev/null
$L set_setting "smokeping_config_file=/nonexistent/config" >/dev/null

t0=$(now)
dev=$(php $P/tests/bin/perf-seed.php "$N")
t1=$(now)
seed=$(php -r "printf('%.1f', $t1 - $t0);")

# One full pass, with a budget no run of this size can exhaust.
$L set_setting "smokeping_reader_budget=3600" >/dev/null
php $P/poller_smokeping.php --force > /tmp/reader.out 2>&1
full=$(cell "SELECT CONCAT(ROUND(run_duration, 1), ' ', series_read, ' ', budget_overruns) FROM plugin_smokeping_health WHERE poller_id = 1")

# A second pass over unchanged files, as every later poller cycle does.
php $P/poller_smokeping.php --force > /tmp/reader2.out 2>&1
again=$(cell "SELECT ROUND(run_duration, 1) FROM plugin_smokeping_health WHERE poller_id = 1")

# The default budget (120 s): how many series one cycle covers.
$L set_setting "smokeping_reader_budget=120" >/dev/null
php $P/poller_smokeping.php --force > /tmp/reader3.out 2>&1
budget=$(cell "SELECT CONCAT(series_read, ' ', budget_overruns) FROM plugin_smokeping_health WHERE poller_id = 1")

page=$(php $P/tests/bin/perf-page.php "$dev")
rows=$(cell "SELECT COUNT(*) FROM plugin_smokeping_observation WHERE file_present = 1")

echo "PERF series=$N seed_s=$seed full_run='$full' unchanged_run_s=$again default_budget_cycle='$budget' observed=$rows page=$page"
