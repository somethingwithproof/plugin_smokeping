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

# Permission suite over HTTP through Cacti's real auth.php. Every plugin page,
# smoke and summary graph, and data source is requested as an administrator, a
# scoped user, a user whose device access is revoked between two requests, a
# user with a broad device policy, and a user without the SmokePing realms; the
# unmapped target must be visible to administrators only. The same run drives
# the administrator's confirm screens and the bulk device action over HTTP.
#
# Run from the Cacti root in the spm2-e2e container with this plugin at
# plugins/smokeping, a writable fixture directory at rra/smokeping, and a
# disposable installed Cacti database (see tests/run-permissions-e2e.sh):
#
#   SMOKEPING_TEST_DATABASE=disposable sh plugins/smokeping/tests/permissions-e2e.sh
#
# Prints one RESULT line per check and exits non-zero on any failure.
set -u

P=plugins/smokeping
L="php $P/tests/bin/lifecycle.php"
FXDIR=/cacti/rra/smokeping
BASE=http://127.0.0.1:8099/cacti
WORK=$(mktemp -d)
fail=0
result() {
	echo "RESULT $1 $2${3:+ ($3)}"
	[ "$1" = PASS ] || [ "$1" = INFO ] || fail=1
}
q() { $L query "$1"; }
jcell() { php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r[0] ? implode(" ", array_values($r[0])) : "";'; }
check() { # check <name> <detail> <command...>: PASS when the command succeeds
	name=$1; detail=$2; shift 2
	if "$@"; then result PASS "$name" "$detail"; else result FAIL "$name" "$detail"; fi
}

SP_USER_PASSWORD=$(php -r 'echo bin2hex(random_bytes(12));')
export SP_USER_PASSWORD

# The disposable database may have been installed by a newer 1.2 release than
# the tree under test; the web installer redirects on any difference.
php -r 'chdir("/cacti"); include("./include/cli_check.php"); db_execute_prepared("UPDATE version SET cacti = ?", array($config["cacti_version"]));'

# ---- fixtures and objects ----
ids_of() { php -r '$r = json_decode(stream_get_contents(STDIN), true); echo is_array($r) ? implode(",", array_column($r, "id")) : "";'; }
# Devices an earlier run left, the plugin's and the plain bulk ones.
old=$(q "SELECT id FROM host WHERE description LIKE 'bulk%' OR id IN (SELECT host_id FROM plugin_smokeping_object)" 2>/dev/null | ids_of)
[ -n "$old" ] && $L remove_devices "$old" >/dev/null 2>&1
$L reset >/dev/null && $L install >/dev/null && $L enable >/dev/null
for kv in "path_rrdtool=$(command -v rrdtool)" "path_php_binary=$(command -v php)" "smokeping_mount_path=$FXDIR" "smokeping_datadir=$FXDIR"; do
	$L set_setting "$kv" >/dev/null
done

bulk=100
specs="Perm/a:300:20:normal Perm/b:300:20:normal Perm/u:300:20:normal Perm/xss:300:20:normal Perm/old:300:20:normal Perm/new:300:20:normal Perm/ro:300:20:normal Perm/rn:300:20:normal Perm/plain:300:20:normal Imp/web/one:300:20:normal Imp/web/two:300:10:normal"
n=1
while [ $n -le $bulk ]; do specs="$specs Bulk/dev$n:300:20:normal"; n=$((n + 1)); done
php $P/tests/bin/make-fixture-rrds.php $FXDIR $specs

for t in "Perm/a:a.example:20:300" "Perm/b:b.example:20:300" "Perm/u:u.example:20:300" "Perm/xss:xss.example:20:300" "Perm/old:old.example:20:300" "Perm/new:old.example:20:300" "Perm/ro:ro.example:20:300" "Perm/rn:ro.example:20:300" "Perm/plain:plain.example:20:300"; do
	$L add_target "$t" >/dev/null
done

provision() { php $P/cli/smokeping.php provision "$1" 2>&1; }
out=$(provision Perm/a);   dev_a=$(echo "$out" | sed -n 's/^Created device \([0-9]*\)$/\1/p')
a_sum=$(echo "$out" | sed -n 's/^Summary data source [0-9]* graph \([0-9]*\)$/\1/p'); a_smoke=$(echo "$out" | sed -n 's/^Data source [0-9]* graph \([0-9]*\)$/\1/p')
a_ds=$(echo "$out" | sed -n 's/^Data source \([0-9]*\) graph [0-9]*$/\1/p')
out=$(provision Perm/b);   dev_b=$(echo "$out" | sed -n 's/^Created device \([0-9]*\)$/\1/p')
b_sum=$(echo "$out" | sed -n 's/^Summary data source [0-9]* graph \([0-9]*\)$/\1/p'); b_smoke=$(echo "$out" | sed -n 's/^Data source [0-9]* graph \([0-9]*\)$/\1/p')
b_ds=$(echo "$out" | sed -n 's/^Data source \([0-9]*\) graph [0-9]*$/\1/p')
out=$(provision Perm/xss); dev_x=$(echo "$out" | sed -n 's/^Created device \([0-9]*\)$/\1/p')
out=$(provision Perm/old); dev_o=$(echo "$out" | sed -n 's/^Created device \([0-9]*\)$/\1/p')
# A device with graphs but no mapping, for the graph-button check: provision a
# target, then drop only its mapping row.
out=$(provision Perm/plain); dev_p=$(echo "$out" | sed -n 's/^Created device \([0-9]*\)$/\1/p')
[ -n "$dev_p" ] && php -r 'chdir("/cacti"); include("./include/cli_check.php"); db_execute_prepared("DELETE FROM plugin_smokeping_mapping WHERE host_id = ?", array((int) $argv[1]));' "$dev_p"
[ -n "$dev_a" ] && [ -n "$a_smoke" ] && [ -n "$b_smoke" ] && [ -n "$dev_x" ] && [ -n "$dev_o" ] && [ -n "$dev_p" ] && result PASS fixtures "devices a=$dev_a b=$dev_b; smoke graphs $a_smoke/$b_smoke, summary graphs $a_sum/$b_sum" || { result FAIL fixtures "a=$dev_a b=$dev_b x=$dev_x o=$dev_o p=$dev_p"; exit 1; }

# A hostile name that must come out escaped, on a device the scoped user may see.
$L update_target 'Perm/xss|title|<img src=x onerror=alert(1)>' >/dev/null
$L make_devices "$bulk:bulk" >/dev/null
n=1
while [ $n -le $bulk ]; do
	$L add_target "Bulk/dev$n:bulk$n.example:20:300" >/dev/null
	n=$((n + 1))
done
bulk_ids=$(q "SELECT id FROM host WHERE description LIKE 'bulk%' ORDER BY id" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo implode(",", array_column($r, "id"));')

SP_USER_PASSWORD=$SP_USER_PASSWORD php $P/tests/bin/permission-setup.php "$dev_a" "$dev_b" >/dev/null || { result FAIL users "could not create users"; exit 1; }
# scoped and revoked may see A and the hostile device.
for u in scoped revoked; do
	php -r 'chdir("/cacti"); include("./include/cli_check.php"); db_execute_prepared("INSERT INTO user_auth_perms (user_id, item_id, type) SELECT id, ?, 3 FROM user_auth WHERE username = ?", array((int) $argv[1], $argv[2]));' "$dev_x" "$u"
done

# ---- web server ----
mkdir -p /var/www && ln -sfn /cacti /var/www/cacti
(cd /var/www && php -S 127.0.0.1:8099 -t /var/www > "$WORK/web.log" 2>&1 &)
n=0; until curl -s -o /dev/null "$BASE/index.php" || [ $n -ge 30 ]; do sleep 1; n=$((n + 1)); done

login() { # login <user> -> cookie jar $WORK/<user>.jar
	jar=$WORK/$1.jar
	: > "$jar"
	token=$(curl -s -c "$jar" "$BASE/index.php" | grep -o "name='__csrf_magic' value=\"[^\"]*\"" | head -1 | sed 's/.*value="//;s/"$//')
	curl -s -b "$jar" -c "$jar" -o /dev/null --data-urlencode "__csrf_magic=$token" --data "action=login&login_username=$1" --data-urlencode "login_password=$SP_USER_PASSWORD" "$BASE/index.php"
}
get() { curl -s -b "$WORK/$1.jar" -c "$WORK/$1.jar" "$BASE/$2"; }
code() { curl -s -o /dev/null -w '%{http_code}' -b "$WORK/$1.jar" -c "$WORK/$1.jar" "$BASE/$2"; }
# A graph request answers with an image either way; a denied one is a small "GRAPH ACCESS DENIED" image.
cat > "$WORK/graph_ok.sh" <<'X'
#!/bin/sh
f=$(mktemp)
curl -s -b "$1" -o "$f" "$2"
size=$(wc -c < "$f")
if [ "$size" -gt 1500 ] && { head -c 400 "$f" | grep -q '<svg' || head -c 4 "$f" | tail -c 3 | grep -q PNG; }; then rm -f "$f"; exit 0; fi
rm -f "$f"
exit 1
X
is_graph() { sh "$WORK/graph_ok.sh" "$WORK/$1.jar" "$BASE/$2"; }
not_graph() { ! is_graph "$@"; }
has() { grep -q -- "$1"; }
# The session's CSRF token from the page script block, which every page has; a
# page without a form has no hidden token field, and a post without a valid
# token is turned away before the plugin's code runs.
csrf() { get "$1" "$2" | sed -n "s/.*var csrfMagicToken='\([^']*\)'.*/\1/p" | head -1; }

for u in admin restricted scoped revoked broad norealm nodisabled; do login $u; done
title() { get "$1" index.php | grep -o '<title>[^<]*</title>' | head -1; }
for u in admin restricted scoped revoked broad norealm nodisabled; do
	t=$(title $u)
	# Users without console access land on a permission page after login; what matters is that the session exists.
	case "$t" in *Login*) result FAIL "login-$u" "$t";; *) result PASS "login-$u" "$t";; esac
done

VIEW=plugins/smokeping/smokeping.php
INV=plugins/smokeping/smokeping_inventory.php
HEALTH=plugins/smokeping/smokeping_health.php

# ---- unauthenticated ----
anon=$WORK/anon.jar; : > $anon
leak=0
for f in smokeping.php smokeping_inventory.php smokeping_health.php smokeping_config.php scripts/ss_smokeping_summary.php poller_smokeping.php cli/smokeping.php setup.php lib/Row.php include/autoload.php index.php; do
	body=$(curl -s -b $anon "$BASE/plugins/smokeping/$f?series=1~")
	if echo "$body" | grep -q 'Perm/\|median:\|SmokePing Targets\|SmokePing Inventory\|SmokePing Health\|Import a SmokePing'; then leak=1; echo "RESULT FAIL anonymous-$f ($(echo "$body" | head -c 80))"; fi
done
[ $leak = 0 ] && result PASS anonymous-gets-nothing "every plugin file returns no SmokePing data without a login"

# ---- administrator ----
body=$(get admin $VIEW)
check admin-view-lists-mapped "Perm/a and Perm/b" sh -c 'echo "$1" | grep -q "Perm/a" && echo "$1" | grep -q "Perm/b"' x "$body"
check admin-view-hides-unmapped "Perm/u is not on the user page" sh -c '! echo "$1" | grep -q "Perm/u"' x "$body"
body=$(get admin $INV)
check admin-inventory-lists-unmapped "Perm/u with an Add to Cacti link" sh -c 'echo "$1" | grep -q "Perm/u" && echo "$1" | grep -q "Add to Cacti"' x "$body"
check admin-health-page "health table" sh -c 'echo "$1" | grep -q "SmokePing Health"' x "$(get admin $HEALTH)"
check admin-smoke-graph-image "graph $a_smoke" is_graph admin "graph_image.php?local_graph_id=$a_smoke&graph_start=-3600&graph_end=0"
check admin-summary-graph-image "graph $a_sum" is_graph admin "graph_image.php?local_graph_id=$a_sum&graph_start=-3600&graph_end=0"
check admin-data-source-page "data source $a_ds" sh -c 'echo "$1" | grep -q "Perm/a"' x "$(get admin "data_sources.php?action=ds_edit&id=$a_ds")"

# ---- administrator whose device permissions are limited ----
body=$(get restricted $INV)
check restricted-admin-sees-own-and-unmapped "Perm/a and the unmapped Perm/u" sh -c 'echo "$1" | grep -q "Perm/a" && echo "$1" | grep -q "Perm/u"' x "$body"
check restricted-admin-not-other-device "no Perm/b, which is mapped to a device the user may not see" sh -c '! echo "$1" | grep -q "Perm/b"' x "$body"

# A user allowed on device A cannot re-link A's target onto a target that belongs to device B.
provision_on() { $L map_target "$1|$2" >/dev/null; php $P/cli/smokeping.php provision "$1" --device="$2" >/dev/null 2>&1; }
provision_on Perm/ro "$dev_a"
provision_on Perm/rn "$dev_b"
bad=$($L propose_relink "Perm/ro|Perm/rn" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"];')
token=$(csrf restricted $INV)
check restricted-relink-screen-refused "the confirm screen is not offered for a target on another device" sh -c '! echo "$1" | grep -q "Confirm Re-link"' x "$(get restricted "$INV?action=relink&id=$bad")"
curl -s -b $WORK/restricted.jar -c $WORK/restricted.jar -o /dev/null --data-urlencode "__csrf_magic=$token" --data "action=relink_confirm&id=$bad" "$BASE/$INV"
check restricted-relink-post-reached-page "the page answered the post with its own refusal, not a CSRF failure" sh -c 'echo "$1" | grep -q "proposal is not open" && ! echo "$1" | grep -q "CSRF"' x "$(get restricted $INV)"
row=$(q "SELECT (SELECT status FROM plugin_smokeping_relink WHERE id = $bad) AS s, (SELECT host_id FROM plugin_smokeping_mapping m JOIN plugin_smokeping_target t ON t.id = m.target_id WHERE t.path = 'Perm/ro') AS a, (SELECT host_id FROM plugin_smokeping_mapping m JOIN plugin_smokeping_target t ON t.id = m.target_id WHERE t.path = 'Perm/rn') AS b" | jcell)
[ "$row" = "proposed $dev_a $dev_b" ] && result PASS restricted-relink-onto-other-device-refused "proposal still open; Perm/ro stays on device $dev_a and Perm/rn on device $dev_b" || result FAIL restricted-relink-onto-other-device-refused "$row"

# The add screen and its action, opened by id for a target mapped to a device the user may not see.
b_id=$(q "SELECT id FROM plugin_smokeping_target WHERE path = 'Perm/b'" | jcell)
check restricted-add-screen-other-device "no add screen for Perm/b, mapped to device $dev_b" sh -c '! echo "$1" | grep -q "Perm/b\|b\.example"' x "$(get restricted "$INV?action=add&id=$b_id")"
token=$(csrf restricted $INV)
curl -s -b $WORK/restricted.jar -c $WORK/restricted.jar -o /dev/null --data-urlencode "__csrf_magic=$token" --data "action=add_confirm&id=$b_id&mode=map&host_id=$dev_a" "$BASE/$INV"
body=$(get restricted $INV)
row=$(q "SELECT (SELECT host_id FROM plugin_smokeping_mapping WHERE target_id = $b_id) AS m, (SELECT COUNT(*) FROM plugin_smokeping_object WHERE target_id = $b_id AND host_id <> $dev_b) AS o" | jcell)
# The page's own refusal message shows the post got past the CSRF check; it names neither the target nor its device.
[ "$row" = "$dev_b 0" ] && echo "$body" | grep -q "Choose a target on this poller" && ! echo "$body" | grep -q "Perm\\\\/b\|Perm/b\|device $dev_b" && result PASS restricted-add-other-device-refused "refused by the page: mapping and objects unchanged, and the message names neither the target nor its device" || result FAIL restricted-add-other-device-refused "$row; $(echo "$body" | grep -o 'sessionMessage=.\{0,160\}')"

# ---- scoped user (device A and the hostile device) ----
body=$(get scoped $VIEW)
check scoped-sees-own-device "Perm/a" sh -c 'echo "$1" | grep -q "Perm/a"' x "$body"
check scoped-not-other-device "no Perm/b" sh -c '! echo "$1" | grep -q "Perm/b"' x "$body"
check scoped-not-unmapped "no Perm/u" sh -c '! echo "$1" | grep -q "Perm/u"' x "$body"
body=$(get scoped "$VIEW?host_id=$dev_b")
check scoped-host-filter-cannot-widen "host_id of an unseen device lists nothing" sh -c '! echo "$1" | grep -q "Perm/b"' x "$body"
get scoped $VIEW > $WORK/xss.html
check scoped-xss-escaped "hostile title is escaped" sh -c 'grep -q "&lt;img src=x" "$1" && ! grep -q "<img src=x" "$1"' x "$WORK/xss.html"
check scoped-inventory-denied "admin realm required" sh -c '! echo "$1" | grep -q "Perm/u\|Add to Cacti"' x "$(get scoped $INV)"
check scoped-health-denied "admin realm required" sh -c '! echo "$1" | grep -q "SmokePing Health for"' x "$(get scoped $HEALTH)"
check scoped-own-smoke-graph "graph $a_smoke" is_graph scoped "graph_image.php?local_graph_id=$a_smoke&graph_start=-3600&graph_end=0"
check scoped-own-summary-graph "graph $a_sum" is_graph scoped "graph_image.php?local_graph_id=$a_sum&graph_start=-3600&graph_end=0"
check scoped-other-smoke-graph-denied "graph $b_smoke" not_graph scoped "graph_image.php?local_graph_id=$b_smoke&graph_start=-3600&graph_end=0"
check scoped-other-summary-graph-denied "graph $b_sum" not_graph scoped "graph_image.php?local_graph_id=$b_sum&graph_start=-3600&graph_end=0"
check scoped-other-graph-data-denied "graph_xport of graph $b_smoke" sh -c '! curl -s -b "$1" "$2" | grep -q "ping1"' x "$WORK/scoped.jar" "$BASE/graph_xport.php?local_graph_id=$b_smoke&graph_start=-3600&graph_end=0"
check scoped-data-source-page-denied "no console access" sh -c '! echo "$1" | grep -q "Perm/a"' x "$(get scoped "data_sources.php?action=ds_edit&id=$a_ds")"
check scoped-other-data-source-page-denied "data source $b_ds" sh -c '! echo "$1" | grep -q "Perm/b"' x "$(get scoped "data_sources.php?action=ds_edit&id=$b_ds")"

# A scoped user cannot add targets or run the device action.
token=$(csrf scoped $VIEW)
u_id=$(q "SELECT id FROM plugin_smokeping_target WHERE path = 'Perm/u'" | jcell)
curl -s -b $WORK/scoped.jar -o /dev/null --data-urlencode "__csrf_magic=$token" --data "action=add_confirm&id=$u_id&mode=create" "$BASE/$INV"
curl -s -b $WORK/scoped.jar -o /dev/null --data-urlencode "__csrf_magic=$token" --data "action=actions&drp_action=smokeping_add&selected_items=$(php -r 'echo urlencode(serialize(array("'"$dev_b"'")));')" "$BASE/host.php"
mapped_u=$(q "SELECT COUNT(*) FROM plugin_smokeping_mapping WHERE target_id = $u_id" | jcell)
[ "$mapped_u" = 0 ] && result PASS scoped-cannot-add-targets "the unmapped target stayed unmapped after posts to the add action and the device action" || result FAIL scoped-cannot-add-targets "mapped $mapped_u"

# ---- revoked user: the next request after the change is already denied ----
body=$(get revoked $VIEW)
check revoked-before "Perm/a visible before the revocation" sh -c 'echo "$1" | grep -q "Perm/a"' x "$body"
check revoked-graph-before "graph $a_smoke renders before" is_graph revoked "graph_image.php?local_graph_id=$a_smoke&graph_start=-3600&graph_end=0"
$L revoke_device "revoked|$dev_a" >/dev/null
body=$(get revoked $VIEW)
check revoked-view-after "Perm/a gone on the very next request" sh -c '! echo "$1" | grep -q "Perm/a"' x "$body"
check revoked-graph-after "graph $a_smoke denied on the very next request" not_graph revoked "graph_image.php?local_graph_id=$a_smoke&graph_start=-3600&graph_end=0"
check revoked-summary-graph-after "graph $a_sum denied" not_graph revoked "graph_image.php?local_graph_id=$a_sum&graph_start=-3600&graph_end=0"

# ---- broad device policy: sees mapped targets, never unmapped ones ----
body=$(get broad $VIEW)
check broad-sees-mapped "Perm/a and Perm/b" sh -c 'echo "$1" | grep -q "Perm/a" && echo "$1" | grep -q "Perm/b"' x "$body"
check broad-not-unmapped "no Perm/u" sh -c '! echo "$1" | grep -q "Perm/u"' x "$body"
check broad-inventory-denied "admin realm required" sh -c '! echo "$1" | grep -q "Perm/u\|Add to Cacti"' x "$(get broad $INV)"
check broad-health-denied "admin realm required" sh -c '! echo "$1" | grep -q "SmokePing Health for"' x "$(get broad $HEALTH)"
is_graph broad "graph_image.php?local_graph_id=$a_smoke&graph_start=-3600&graph_end=0" && is_graph broad "graph_image.php?local_graph_id=$b_smoke&graph_start=-3600&graph_end=0" && result PASS broad-graph-images "graphs $a_smoke and $b_smoke render" || result FAIL broad-graph-images "graphs did not render"

# ---- no SmokePing realm ----
for page in $VIEW $INV $HEALTH; do
	check "norealm-denied-$(basename $page .php)" "no SmokePing realm" sh -c '! echo "$1" | grep -q "Perm/a\|SmokePing Targets\|SmokePing Inventory\|SmokePing Health for"' x "$(get norealm $page)"
done

# ---- configuration page: admin realm only, posts only, confirmed in two steps ----
CONF=plugins/smokeping/smokeping_config.php
for u in scoped revoked broad norealm; do
	check "config-page-denied-$u" "no SmokePing admin realm" sh -c '! echo "$1" | grep -q "Import a SmokePing Configuration\|Export the SmokePing"' x "$(get $u $CONF)"
done
check config-page-admin "export and import forms" sh -c 'echo "$1" | grep -q "Export the SmokePing Configuration" && echo "$1" | grep -q "Import a SmokePing Configuration"' x "$(get admin $CONF)"

conf_token() { get "$1" "$CONF" | grep -o "name='__csrf_magic' value=\"[^\"]*\"" | head -1 | sed 's/.*value="//;s/"$//'; }
token=$(conf_token admin)
export_body=$(curl -s -b $WORK/admin.jar -c $WORK/admin.jar --data-urlencode "__csrf_magic=$token" --data "action=export&format=yaml" "$BASE/$CONF")
check config-export-yaml "a POST with a token downloads YAML" sh -c 'echo "$1" | grep -q "^schema_version: 1$" && echo "$1" | grep -q "Perm/a"' x "$export_body"
export_xml=$(curl -s -b $WORK/admin.jar -c $WORK/admin.jar --data-urlencode "__csrf_magic=$token" --data "action=export&format=xml" "$BASE/$CONF")
check config-export-xml "a POST with a token downloads XML" sh -c 'echo "$1" | grep -q "<smokeping-config schema_version=\"1\">"' x "$export_xml"
# A refused post regenerates the session id, and from 1.2.32 deletes the old
# session; keep the jar current, as a browser keeps its cookie, so the
# administrator stays logged in for the checks that follow.
check config-export-needs-token "a POST without a token is turned away" sh -c '! curl -s -b "$1" -c "$1" --data "action=export&format=yaml" "$2" | grep -q "^schema_version"' x "$WORK/admin.jar" "$BASE/$CONF"
check config-export-refusal-keeps-session "the administrator is still logged in after the refused post" sh -c '! echo "$1" | grep -q "<title>Login to Cacti"' x "$(get admin $CONF)"
check config-export-get-ignored "a GET with action=export shows the page, not a download" sh -c '! echo "$1" | grep -q "^schema_version"' x "$(get admin "$CONF?action=export&format=yaml")"

# An administrator whose device permissions are limited holds the realm and is still refused: the
# export and the import cover every target and every mapping with the names of its devices.
body=$(get restricted $CONF)
check config-page-refused-restricted-admin "no forms, no device named" sh -c '! echo "$1" | grep -q "Import a SmokePing Configuration\|Export the SmokePing" && echo "$1" | grep -q "do not cover every device" && ! echo "$1" | grep -q "Perm/\|a.example\|b.example"' x "$body"
token=$(csrf restricted $INV)
export_r=$(curl -s -b $WORK/restricted.jar -c $WORK/restricted.jar --data-urlencode "__csrf_magic=$token" --data "action=export&format=yaml" "$BASE/$CONF")
check config-export-refused-restricted-admin "a POST to export returns no configuration" sh -c '! echo "$1" | grep -q "schema_version\|Perm/\|hostname" && echo "$1" | grep -q "do not cover every device"' x "$export_r"
export_rx=$(curl -s -b $WORK/restricted.jar -c $WORK/restricted.jar --data-urlencode "__csrf_magic=$token" --data "action=export&format=xml" "$BASE/$CONF")
check config-export-xml-refused-restricted-admin "xml too" sh -c '! echo "$1" | grep -q "smokeping-config\|Perm/"' x "$export_rx"

printf 'schema_version: 1\ntargets:\n  - path: Conf/restricted\n    host: restricted.example\n' > $WORK/conf-r.yaml
token=$(csrf restricted $INV)
page_r=$(curl -s -b $WORK/restricted.jar -c $WORK/restricted.jar -F "__csrf_magic=$token" -F action=check -F format=yaml -F "config_file=@$WORK/conf-r.yaml" "$BASE/$CONF")
check config-plan-refused-restricted-admin "an upload to check returns no plan" sh -c '! echo "$1" | grep -q "add    target\|Apply Import\|Plan " && echo "$1" | grep -q "do not cover every device"' x "$page_r"
token=$(csrf restricted $INV)
curl -s -b $WORK/restricted.jar -c $WORK/restricted.jar -o /dev/null --data-urlencode "__csrf_magic=$token" --data "action=apply&plan=0000000000000000" "$BASE/$CONF"
[ "$(q "SELECT COUNT(*) FROM plugin_smokeping_target WHERE path = 'Conf/restricted'" | jcell)" = 0 ] && result PASS config-apply-refused-restricted-admin "an apply post changes nothing" || result FAIL config-apply-refused-restricted-admin "a target was added"

printf 'schema_version: 1\ntargets:\n  - path: Conf/new\n    host: conf.example\n' > $WORK/conf-new.yaml
n0=$(q "SELECT COUNT(*) FROM plugin_smokeping_target" | jcell)
# A scoped user's check and apply reach no code: the realm is missing.
token=$(conf_token scoped)
curl -s -b $WORK/scoped.jar -o /dev/null --data-urlencode "__csrf_magic=$token" --data "action=apply&plan=0000000000000000" "$BASE/$CONF"
token=$(conf_token admin)
page=$(curl -s -b $WORK/admin.jar -c $WORK/admin.jar -F "__csrf_magic=$token" -F action=check -F format=yaml -F "config_file=@$WORK/conf-new.yaml" "$BASE/$CONF")
plan=$(echo "$page" | sed -n 's/.*name="plan" value="\([0-9a-f]*\)".*/\1/p' | head -1)
check config-check-shows-plan-writes-nothing "the plan lists the add and nothing is stored" sh -c 'echo "$1" | grep -q "add    target Conf/new" && [ "$2" = "$3" ]' x "$page" "$(q "SELECT COUNT(*) FROM plugin_smokeping_target" | jcell)" "$n0"
token=$(conf_token admin)
curl -s -b $WORK/admin.jar -c $WORK/admin.jar -o /dev/null --data-urlencode "__csrf_magic=$token" --data "action=apply&plan=0000000000000000" "$BASE/$CONF"
[ "$(q "SELECT COUNT(*) FROM plugin_smokeping_target WHERE path = 'Conf/new'" | jcell)" = 0 ] && result PASS config-apply-wrong-plan-refused "a plan id that was not shown applies nothing" || result FAIL config-apply-wrong-plan-refused "target was added"
# A refused apply consumes the checked import, so the administrator checks again before the real apply.
token=$(conf_token admin)
page=$(curl -s -b $WORK/admin.jar -c $WORK/admin.jar -F "__csrf_magic=$token" -F action=check -F format=yaml -F "config_file=@$WORK/conf-new.yaml" "$BASE/$CONF")
plan=$(echo "$page" | sed -n 's/.*name="plan" value="\([0-9a-f]*\)".*/\1/p' | head -1)
token=$(conf_token admin)
curl -s -b $WORK/admin.jar -c $WORK/admin.jar -o /dev/null --data-urlencode "__csrf_magic=$token" --data "action=apply&plan=$plan" "$BASE/$CONF"
[ -n "$plan" ] && [ "$(q "SELECT COUNT(*) FROM plugin_smokeping_target WHERE path = 'Conf/new'" | jcell)" = 1 ] && result PASS config-apply-confirmed "the shown plan applied once confirmed" || result FAIL config-apply-confirmed "plan '$plan'"

# ---- import of a SmokePing configuration: admin realm and every device, posts only, two steps ----
IMP=plugins/smokeping/smokeping_import.php
for u in scoped revoked broad norealm; do
	check "import-page-denied-$u" "no SmokePing admin realm" sh -c '! echo "$1" | grep -q "Preview reads the configuration"' x "$(get $u $IMP)"
done
body=$(get admin $IMP)
check import-page-admin "the options form, and the entry under Import/Export" sh -c 'echo "$1" | grep -q "Preview reads the configuration" && echo "$1" | grep -q "plugins/smokeping/smokeping_import.php"' x "$body"
body=$(get restricted $IMP)
check import-page-refused-restricted-admin "no form, no device named" sh -c '! echo "$1" | grep -q "Preview reads the configuration" && echo "$1" | grep -q "do not cover every device"' x "$body"

# What an earlier run imported: the plugin's tables were reset, Cacti's were not.
old=$(q "SELECT id FROM host WHERE hostname = 'imp.example'" | ids_of)
[ -n "$old" ] && $L remove_devices "$old" >/dev/null 2>&1
php -r 'chdir("/cacti"); include("./include/cli_check.php"); foreach (db_fetch_assoc("SELECT id FROM graph_tree WHERE name = \"SmokePing Web\"") as $t) { db_execute_prepared("DELETE FROM graph_tree_items WHERE graph_tree_id = ?", array($t["id"])); db_execute_prepared("DELETE FROM graph_tree WHERE id = ?", array($t["id"])); }'

imp_conf() {
	printf '*** General ***\nowner = o\ncontact = o@example.net\ncgiurl = http://localhost/\nimgcache = /c\nimgurl = c\ndatadir = /var/lib/smokeping\npiddir = /run\nsmokemail = /m\n'
	printf '*** Database ***\nstep = 300\npings = 20\n*** Presentation ***\ntemplate = /t\n+ overview\nwidth = 600\nheight = 50\n+ detail\nwidth = 600\nheight = 200\n'
	printf '*** Probes ***\n+ FPing\nbinary = /usr/bin/fping\n++ FPing20\n++ FPing10\npings = 10\n*** Targets ***\nprobe = FPing20\nmenu = Top\ntitle = Top\n'
	printf '+ Imp\nmenu = Imp\ntitle = Imp\n++ web\nmenu = Web\ntitle = Web\n+++ one\nhost = imp.example\n+++ two\nprobe = FPing10\nhost = imp.example\n%s' "${1:-}"
}
imp_conf > $WORK/imp.conf
imp_conf '@include more.conf
' > $WORK/imp-include.conf
imp_rows() { q "SELECT (SELECT COUNT(*) FROM plugin_smokeping_target WHERE path LIKE 'Imp/%') AS t, (SELECT COUNT(*) FROM host WHERE hostname = 'imp.example') AS h, (SELECT COUNT(*) FROM plugin_smokeping_object o JOIN plugin_smokeping_target t ON t.id = o.target_id WHERE t.path LIKE 'Imp/%') AS o, (SELECT COUNT(*) FROM graph_tree WHERE name = 'SmokePing Web') AS tree" | jcell; }
imp_preview() { # imp_preview <user> <file> -> the preview page
	token=$(csrf "$1" $IMP)
	curl -s -b "$WORK/$1.jar" -c "$WORK/$1.jar" -F "__csrf_magic=$token" -F action=preview -F source=upload -F device_per=host -F poller=1 -F site=0 -F tree=-1 -F "tree_name=SmokePing Web" -F "smokeping_file=@$2" "$BASE/$IMP"
}
imp_apply() { # imp_apply <user> <plan> [no-token]
	token=$(csrf "$1" $IMP)
	[ "${3:-}" = no-token ] && token=
	curl -s -b "$WORK/$1.jar" -c "$WORK/$1.jar" -o /dev/null --data-urlencode "__csrf_magic=$token" --data "action=apply&plan=$2&source=upload&device_per=host&poller=1&site=0&tree=-1" --data-urlencode "tree_name=SmokePing Web" "$BASE/$IMP"
}

page=$(imp_preview admin $WORK/imp.conf)
plan=$(echo "$page" | sed -n 's/.*name=.plan. value=.\([0-9a-f]\{16\}\).*/\1/p' | head -1)
check import-preview-writes-nothing "the preview lists one device and four graphs and stores nothing" sh -c 'echo "$1" | grep -q "Devices: 1 to create" && echo "$1" | grep -q "Graphs: 4 to create" && echo "$1" | grep -q "SmokePing Sections" && [ -n "$2" ] && [ "$3" = "0 0 0 0" ]' x "$page" "$plan" "$(imp_rows)"
page=$(imp_preview admin $WORK/imp-include.conf)
check import-upload-refuses-include "an uploaded @include is refused with its line" sh -c 'echo "$1" | grep -q "@include is not allowed here: an uploaded file is read on its own"' x "$page"
page=$(imp_preview restricted $WORK/imp.conf)
check import-preview-refused-restricted-admin "no plan for a limited administrator" sh -c '! echo "$1" | grep -q "Devices: \|imp.example" && echo "$1" | grep -q "do not cover every device"' x "$page"

page=$(imp_preview admin $WORK/imp.conf)
plan=$(echo "$page" | sed -n 's/.*name=.plan. value=.\([0-9a-f]\{16\}\).*/\1/p' | head -1)
imp_apply admin "$plan" no-token
imp_apply admin 0000000000000000
imp_apply scoped "$plan"
imp_apply restricted "$plan"
[ "$(imp_rows)" = "0 0 0 0" ] && result PASS import-apply-refusals "no token, a plan never shown, no realm, and limited devices all write nothing" || result FAIL import-apply-refusals "$(imp_rows)"
page=$(imp_preview admin $WORK/imp.conf)
plan=$(echo "$page" | sed -n 's/.*name=.plan. value=.\([0-9a-f]\{16\}\).*/\1/p' | head -1)
imp_apply admin "$plan"
[ -n "$plan" ] && [ "$(imp_rows)" = "2 1 4 1" ] && result PASS import-apply-confirmed "two targets on one device, four graphs, the new tree" || result FAIL import-apply-confirmed "plan '$plan': $(imp_rows)"
page=$(imp_preview admin $WORK/imp.conf)
check import-rerun-nothing-to-do "a second preview creates nothing" sh -c 'echo "$1" | grep -q "Devices: 0 to create, 0 existing to use, 1 already mapped" && echo "$1" | grep -q "Graphs: 0 to create, 4 already exist"' x "$page"

# ---- administrator workflows over HTTP ----
admin_token() { get admin "$1" | grep -o "name='__csrf_magic' value=\"[^\"]*\"" | head -1 | sed 's/.*value="//;s/"$//'; }

# Confirm and add the unmapped target to a new device.
check admin-add-confirm-screen "the confirm screen names both choices" sh -c 'echo "$1" | grep -q "Create a new device" && echo "$1" | grep -q "Map it to an existing device"' x "$(get admin "$INV?action=add&id=$u_id")"
token=$(admin_token "$INV?action=add&id=$u_id")
get admin "$INV?action=add_confirm&id=$u_id&mode=create" >/dev/null
[ "$(q "SELECT COUNT(*) FROM plugin_smokeping_mapping WHERE target_id = $u_id" | jcell)" = 0 ] && result PASS admin-get-does-not-add "a GET never adds" || result FAIL admin-get-does-not-add "the GET mapped the target"
curl -s -b $WORK/admin.jar -c $WORK/admin.jar -o /dev/null --data-urlencode "__csrf_magic=$token" --data "action=add_confirm&id=$u_id&mode=create" "$BASE/$INV"
row=$(q "SELECT (SELECT COUNT(*) FROM plugin_smokeping_mapping WHERE target_id = $u_id) AS m, (SELECT COUNT(*) FROM plugin_smokeping_object WHERE target_id = $u_id AND state = 'summary') AS s, (SELECT COUNT(*) FROM plugin_smokeping_object WHERE target_id = $u_id AND state = 'active') AS a" | jcell)
[ "$row" = "1 1 1" ] && result PASS admin-add-creates-device "mapped, one summary and one smoke data source" || result FAIL admin-add-creates-device "$row"
body=$(get admin $VIEW)
check admin-view-now-lists-added "Perm/u appears once mapped" sh -c 'echo "$1" | grep -q "Perm/u"' x "$body"

# Re-link confirmation: the old target disappeared, the new one has the same host.
prop=$($L propose_relink "Perm/old|Perm/new" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r["result"];')
check admin-relink-confirm-screen "names both paths and what happens" sh -c 'echo "$1" | grep -q "Perm/old" && echo "$1" | grep -q "Perm/new" && echo "$1" | grep -q "archived graph"' x "$(get admin "$INV?action=relink&id=$prop")"
token=$(admin_token "$INV?action=relink&id=$prop")
curl -s -b $WORK/admin.jar -c $WORK/admin.jar -o /dev/null --data-urlencode "__csrf_magic=$token" --data "action=relink_confirm&id=$prop" "$BASE/$INV"
row=$(q "SELECT (SELECT status FROM plugin_smokeping_relink WHERE id = $prop) AS s, (SELECT COUNT(*) FROM plugin_smokeping_object o JOIN plugin_smokeping_target t ON t.id = o.target_id WHERE t.path = 'Perm/new' AND o.state IN ('active', 'summary')) AS live, (SELECT COUNT(*) FROM plugin_smokeping_object o JOIN plugin_smokeping_target t ON t.id = o.target_id WHERE t.path = 'Perm/old' AND o.state = 'archived') AS arch, (SELECT COUNT(*) FROM plugin_smokeping_mapping m JOIN plugin_smokeping_target t ON t.id = m.target_id WHERE t.path = 'Perm/new') AS mapped" | jcell)
[ "$row" = "accepted 2 1 1" ] && result PASS admin-relink-applies "proposal accepted; live smoke and summary on the new target, archived smoke on the old, mapping moved" || result FAIL admin-relink-applies "$row"

# Bulk device action on 100 devices in one request.
ids=$(echo "$bulk_ids" | tr ',' ' ')
fields=""
for id in $ids; do fields="$fields --data chk_$id=on"; done
token=$(admin_token "host.php")
prep=$(curl -s -b $WORK/admin.jar -c $WORK/admin.jar --data-urlencode "__csrf_magic=$token" --data "action=actions&drp_action=smokeping_add" $fields "$BASE/host.php")
check bulk-confirm-screen "preview reports 100 matching targets" sh -c 'echo "$1" | grep -q "100 targets match"' x "$prep"
selected=$(php -r '$ids = explode(",", $argv[1]); echo urlencode(serialize(array_map("strval", $ids)));' "$bulk_ids")
token=$(admin_token "host.php")
res=$(curl -s -b $WORK/admin.jar -c $WORK/admin.jar -o /dev/null -w '%{http_code} %{time_total}' --data-urlencode "__csrf_magic=$token" --data "action=actions&drp_action=smokeping_add&selected_items=$selected" "$BASE/host.php")
row=$(q "SELECT (SELECT COUNT(*) FROM plugin_smokeping_mapping m JOIN plugin_smokeping_target t ON t.id = m.target_id WHERE t.path LIKE 'Bulk/%') AS mapped, (SELECT COUNT(*) FROM plugin_smokeping_object o JOIN plugin_smokeping_target t ON t.id = o.target_id WHERE t.path LIKE 'Bulk/%' AND o.state = 'summary') AS summaries, (SELECT COUNT(*) FROM plugin_smokeping_object o JOIN plugin_smokeping_target t ON t.id = o.target_id WHERE t.path LIKE 'Bulk/%' AND o.state = 'active') AS smoke" | jcell)
[ "$row" = "$bulk $bulk $bulk" ] && result PASS bulk-action-100-devices "one request, HTTP/time: $res s; $row mappings, summary and smoke data sources" || result FAIL bulk-action-100-devices "HTTP/time $res; mapped/summary/smoke $row"

# Deleting a mapped device removes its mapping and leaves the target.
first=$(echo "$bulk_ids" | cut -d, -f1)
$L remove_devices "$first" >/dev/null 2>&1
row=$(q "SELECT (SELECT COUNT(*) FROM plugin_smokeping_mapping WHERE host_id = $first) AS m, (SELECT COUNT(*) FROM plugin_smokeping_target WHERE path = 'Bulk/dev1') AS t, (SELECT COUNT(*) FROM plugin_smokeping_object WHERE host_id = $first) AS o" | jcell)
[ "$row" = "0 1 0" ] && result PASS device-delete-unmaps "mapping and objects gone, target kept (unmapped)" || result FAIL device-delete-unmaps "$row"

# Graph button: a link to the device's targets, only on graphs of devices that have some.
mapped_graph=$a_smoke
plain_graph=$(q "SELECT g.id FROM graph_local g WHERE g.host_id NOT IN (SELECT host_id FROM plugin_smokeping_mapping) AND g.host_id > 0 ORDER BY g.id LIMIT 1" | jcell)
if [ -n "$plain_graph" ]; then
	check graph-button-on-mapped-device "link to smokeping.php?host_id=$dev_a" sh -c 'grep -q "smokeping.php?host_id='"$dev_a"'" "$1"' x "$(get admin "graph.php?action=view&local_graph_id=$mapped_graph&rra_id=0" > $WORK/g1.html; echo $WORK/g1.html)"
	check graph-button-absent-on-other-device "no link on graph $plain_graph" sh -c '! grep -q "smokeping.php?host_id=" "$1"' x "$(get admin "graph.php?action=view&local_graph_id=$plain_graph&rra_id=0" > $WORK/g2.html; echo $WORK/g2.html)"
else
	result FAIL graph-button-absent-on-other-device "no graph of an unmapped device to check"
fi

# A plugin graph goes in a tree like any Cacti graph: a graph leaf under a
# branch, saved through Cacti's tree API, shows on the tree page, which
# writes each graph as a wrapper_<id> element its script fills.
leaf=$(php -r 'chdir("/cacti"); include("./include/cli_check.php"); include_once("./lib/api_tree.php");
	db_execute("INSERT INTO graph_tree (enabled, name, sort_type, sequence, user_id, last_modified, modified_by) VALUES (\"on\", \"SmokePing Leaf\", 1, 99, 1, NOW(), 1)");
	$tree = db_fetch_insert_id();
	$branch = api_tree_item_save(0, $tree, TREE_ITEM_TYPE_HEADER, 0, "Smoke", 0, 0, 0, 1, 1, false);
	$item = api_tree_item_save(0, $tree, TREE_ITEM_TYPE_GRAPH, $branch, "", (int) $argv[1], 0, 0, 1, 1, false);
	echo $branch . " " . $item;' "$mapped_graph" 2>/dev/null)
branch=${leaf%% *}
page=$(get admin "graph_view.php?action=tree_content&node=tbranch-$branch&host_id=-1&site_id=-1&host_template_id=-1&hgd=")
check graph-in-tree "graph $mapped_graph as a leaf of branch $branch" sh -c 'echo "$1" | grep -q "id=.wrapper_$2."' x "$page" "$mapped_graph"
php -r 'chdir("/cacti"); include("./include/cli_check.php"); foreach (db_fetch_assoc("SELECT id FROM graph_tree WHERE name = \"SmokePing Leaf\"") as $t) { db_execute_prepared("DELETE FROM graph_tree_items WHERE graph_tree_id = ?", array($t["id"])); db_execute_prepared("DELETE FROM graph_tree WHERE id = ?", array($t["id"])); }'

# Translations: with language support on, each locale Cacti 1.2 offers shows
# the plugin's own catalog. Cacti has no Latin American Spanish locale, so
# es-419 ships for when it does and is not requested here.
$L set_setting "i18n_language_support=1" >/dev/null
for loc in de-DE es-ES fr-FR ja-JP zh-CN; do
	get admin "$HEALTH?language=$loc" > "$WORK/i18n-$loc.html"
	out=$(php $P/tests/bin/i18n-check.php "$P/locales/po/$loc.po" 'SmokePing Health for Poller %d' "$WORK/i18n-$loc.html") && result PASS "translation-$loc" "$out" || result FAIL "translation-$loc" "$out"
done
get admin "$HEALTH?language=en-US" > /dev/null
$L set_setting "i18n_language_support=0" >/dev/null

# ---- a disabled device: Hide Disabled must not decide who sees every device ----
# Cacti's get_allowed_devices() leaves disabled devices out while the user's
# Hide Disabled setting is on, its default, and reads that setting from
# settings_user. The administrator, who may see every device, keeps export and
# import with a disabled device present; a user granted every device but the
# disabled one is refused them.
$L make_devices "1:bulkoff" >/dev/null
dev_off=$(q "SELECT id FROM host WHERE description = 'bulkoff1'" | ids_of)
php -r 'chdir("/cacti"); include("./include/cli_check.php");
	$off = (int) $argv[1];
	db_execute_prepared("UPDATE host SET disabled = \"on\" WHERE id = ?", array($off));
	db_execute("DELETE s FROM settings_user AS s INNER JOIN user_auth AS u ON u.id = s.user_id WHERE s.name = \"hide_disabled\" AND u.username IN (\"admin\", \"nodisabled\")");
	db_execute_prepared("DELETE p FROM user_auth_perms AS p INNER JOIN user_auth AS u ON u.id = p.user_id WHERE u.username = \"nodisabled\"");
	db_execute_prepared("INSERT INTO user_auth_perms (user_id, item_id, type) SELECT u.id, h.id, 3 FROM user_auth AS u, host AS h WHERE u.username = \"nodisabled\" AND h.id > 0 AND h.deleted = \"\" AND h.id <> ?", array($off));' "$dev_off"
hide=$(php -r 'chdir("/cacti"); include("./include/cli_check.php"); echo read_user_setting("hide_disabled", false, false, 1);')
[ -n "$dev_off" ] && [ "$hide" = on ] && [ "$(q "SELECT disabled FROM host WHERE id = $dev_off" | jcell)" = on ] && result PASS disabled-fixture "device $dev_off disabled, administrator's Hide Disabled on by default" || result FAIL disabled-fixture "device '$dev_off', hide_disabled '$hide'"

check disabled-config-page-admin "export and import forms with a disabled device present" sh -c 'echo "$1" | grep -q "Export the SmokePing Configuration" && ! echo "$1" | grep -q "do not cover every device"' x "$(get admin $CONF)"
token=$(conf_token admin)
check disabled-config-export-admin "the export downloads" sh -c 'echo "$1" | grep -q "^schema_version: 1$"' x "$(curl -s -b $WORK/admin.jar -c $WORK/admin.jar --data-urlencode "__csrf_magic=$token" --data "action=export&format=yaml" "$BASE/$CONF")"
check disabled-import-page-admin "the import form" sh -c 'echo "$1" | grep -q "Preview reads the configuration" && ! echo "$1" | grep -q "do not cover every device"' x "$(get admin $IMP)"

check disabled-config-page-refused "no forms for a user denied only the disabled device" sh -c '! echo "$1" | grep -q "Import a SmokePing Configuration\|Export the SmokePing" && echo "$1" | grep -q "do not cover every device"' x "$(get nodisabled $CONF)"
token=$(csrf nodisabled $INV)
check disabled-config-export-refused "a POST to export returns no configuration" sh -c '! echo "$1" | grep -q "schema_version\|Perm/\|hostname" && echo "$1" | grep -q "do not cover every device"' x "$(curl -s -b $WORK/nodisabled.jar -c $WORK/nodisabled.jar --data-urlencode "__csrf_magic=$token" --data "action=export&format=yaml" "$BASE/$CONF")"
printf 'schema_version: 1\ntargets:\n  - path: Conf/nodisabled\n    host: nodisabled.example\n' > $WORK/conf-nd.yaml
token=$(csrf nodisabled $INV)
check disabled-config-plan-refused "an upload to check returns no plan" sh -c '! echo "$1" | grep -q "add    target\|Apply Import\|Plan " && echo "$1" | grep -q "do not cover every device"' x "$(curl -s -b $WORK/nodisabled.jar -c $WORK/nodisabled.jar -F "__csrf_magic=$token" -F action=check -F format=yaml -F "config_file=@$WORK/conf-nd.yaml" "$BASE/$CONF")"
token=$(csrf nodisabled $INV)
apply_nd=$(curl -s -b $WORK/nodisabled.jar -c $WORK/nodisabled.jar --data-urlencode "__csrf_magic=$token" --data "action=apply&plan=0000000000000000" "$BASE/$CONF")
check disabled-config-apply-refused "an apply post is refused for device coverage" sh -c 'echo "$1" | grep -q "do not cover every device"' x "$apply_nd"
[ "$(q "SELECT COUNT(*) FROM plugin_smokeping_target WHERE path = 'Conf/nodisabled'" | jcell)" = 0 ] && result PASS disabled-config-apply-writes-nothing "an apply post changes nothing" || result FAIL disabled-config-apply-writes-nothing "a target was added"
check disabled-import-page-refused "no import form" sh -c '! echo "$1" | grep -q "Preview reads the configuration" && echo "$1" | grep -q "do not cover every device"' x "$(get nodisabled $IMP)"
check disabled-import-preview-refused "no import plan" sh -c '! echo "$1" | grep -q "Devices: \|imp.example" && echo "$1" | grep -q "do not cover every device"' x "$(imp_preview nodisabled $WORK/imp.conf)"
[ -n "$dev_off" ] && $L remove_devices "$dev_off" >/dev/null 2>&1

# Leave no devices behind.
left=$(q "SELECT id FROM host WHERE description LIKE 'bulk%' OR id IN (SELECT host_id FROM plugin_smokeping_object)" 2>/dev/null | ids_of)
[ -n "$left" ] && $L remove_devices "$left" >/dev/null 2>&1

exit $fail
