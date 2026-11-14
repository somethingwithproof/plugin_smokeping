#!/bin/sh
# Install a release archive on a clean Cacti 1.2.31 and use it. Run in the
# container tests/run-release-e2e.sh starts: Cacti's PHP extensions and
# rrdtool, the core tree at /core (read-only), the archive at /dist, and a
# disposable installed Cacti database. The core is copied first so the
# installation is clean and the real core tree stays untouched.
#
# Prints one RESULT line per check and exits non-zero on any failure.
set -u

fail=0
result() { echo "RESULT $1 $2${3:+ ($3)}"; [ "$1" = PASS ] || [ "$1" = INFO ] || fail=1; }

archive=$(ls /dist/smokeping-*.tar.gz | head -1)
[ -n "$archive" ] || { result FAIL archive-present "no archive in /dist"; exit 1; }

cp -a /core /work-cacti 2>/dev/null
cd /work-cacti || exit 1
rm -rf plugins/smokeping
tar -xzf "$archive" -C plugins
[ -f plugins/smokeping/INFO ] && result PASS archive-extracts "plugins/smokeping from $(basename "$archive")" || result FAIL archive-extracts "no plugins/smokeping/INFO"

version=$(sed -n 's/^version *= *//p' plugins/smokeping/INFO)

# Start from a Cacti with no trace of the plugin: a re-run of this script finds the database as it left it.
php -r 'chdir("/work-cacti"); include("./include/cli_check.php"); foreach (db_fetch_assoc("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE \"plugin\\_smokeping\\_%\"") as $r) { db_execute("DROP TABLE `" . $r["t"] . "`"); } db_execute("DELETE FROM plugin_config WHERE directory = \"smokeping\""); db_execute("DELETE FROM plugin_hooks WHERE name = \"smokeping\""); db_execute("DELETE FROM plugin_realms WHERE plugin = \"smokeping\""); db_execute("DELETE FROM plugin_db_changes WHERE plugin = \"smokeping\""); db_execute("DELETE FROM settings WHERE name LIKE \"smokeping\\_%\"");' >/dev/null 2>&1

out=$(php cli/plugin_manage.php --plugin=smokeping --install --enable --allperms 2>&1); rc=$?
row=$(php -r 'chdir("/work-cacti"); include("./include/cli_check.php"); $r = db_fetch_row("SELECT status, version FROM plugin_config WHERE directory = \"smokeping\""); echo implode(" ", $r);' 2>/dev/null)
[ $rc -eq 0 ] && [ "$row" = "1 $version" ] && result PASS install-from-archive "enabled, version $version" || result FAIL install-from-archive "rc $rc row '$row': $(echo "$out" | tail -2)"


php -r '
chdir("/work-cacti"); include("./include/cli_check.php");
$tables = db_fetch_cell("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE \"plugin\\_smokeping\\_%\"");
$hooks  = db_fetch_cell("SELECT COUNT(*) FROM plugin_hooks WHERE name = \"smokeping\"");
$realms = db_fetch_cell("SELECT COUNT(*) FROM plugin_realms WHERE plugin = \"smokeping\"");
$host   = db_fetch_cell("SELECT COUNT(*) FROM host_template WHERE name = \"SmokePing Target\"");
$graph  = db_fetch_cell("SELECT COUNT(*) FROM graph_templates WHERE name LIKE \"SmokePing Smoke (%\"");
echo "$tables $hooks $realms $host $graph";' > /tmp/counts 2>/dev/null
[ "$(cat /tmp/counts)" = "6 16 2 1 3" ] && result PASS installed-objects "6 tables, 16 hooks, 2 realms, the host template, 3 smoke graph templates" || result FAIL installed-objects "$(cat /tmp/counts)"

# The shipped files work: the CLI and the reader run, and the translation template and docs are present.
out=$(php plugins/smokeping/cli/smokeping.php export 2>&1 | head -1)
[ "$out" = "schema_version: 1" ] && result PASS cli-runs "export prints the schema version" || result FAIL cli-runs "$out"
out=$(php plugins/smokeping/poller_smokeping.php --force 2>&1); rc=$?
[ $rc -eq 0 ] && result PASS reader-runs "poller_smokeping.php --force exits 0" || result FAIL reader-runs "rc $rc: $out"
[ -f plugins/smokeping/locales/po/cacti.pot ] && [ -f plugins/smokeping/docs/install.md ] && [ -f plugins/smokeping/CHANGELOG.md ] && result PASS pot-and-docs-shipped "the POT, the install guide, and the changelog are in the archive" || result FAIL pot-and-docs-shipped "missing"

# Upgrade over the installed copy is a no-op, and uninstall leaves the export.
php -r 'chdir("/work-cacti"); include("./include/cli_check.php"); include_once("./plugins/smokeping/setup.php"); echo plugin_smokeping_upgrade() ? "ok" : "failed";' > /tmp/upgrade 2>/dev/null
[ "$(cat /tmp/upgrade)" = ok ] && result PASS upgrade-idempotent "plugin_smokeping_upgrade() on the installed archive" || result FAIL upgrade-idempotent "$(cat /tmp/upgrade)"
php cli/plugin_manage.php --plugin=smokeping --disable >/dev/null 2>&1
php cli/plugin_manage.php --plugin=smokeping --uninstall >/dev/null 2>&1
ls cache/smokeping/smokeping-config-*.yaml >/dev/null 2>&1 && result PASS uninstall-exports-configuration "$(ls cache/smokeping | grep yaml | head -1)" || result FAIL uninstall-exports-configuration "no export in cache/smokeping"

exit $fail
