#!/bin/sh
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
specs="Perm/a:300:20:normal Perm/b:300:20:normal Perm/u:300:20:normal Perm/xss:300:20:normal Perm/old:300:20:normal Perm/new:300:20:normal"
n=1
while [ $n -le $bulk ]; do specs="$specs Bulk/dev$n:300:20:normal"; n=$((n + 1)); done
php $P/tests/bin/make-fixture-rrds.php $FXDIR $specs

for t in "Perm/a:a.example:20:300" "Perm/b:b.example:20:300" "Perm/u:u.example:20:300" "Perm/xss:xss.example:20:300" "Perm/old:old.example:20:300" "Perm/new:old.example:20:300"; do
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
[ -n "$dev_a" ] && [ -n "$a_smoke" ] && [ -n "$b_smoke" ] && [ -n "$dev_x" ] && [ -n "$dev_o" ] && result PASS fixtures "devices a=$dev_a b=$dev_b; smoke graphs $a_smoke/$b_smoke, summary graphs $a_sum/$b_sum" || { result FAIL fixtures "a=$dev_a b=$dev_b x=$dev_x o=$dev_o"; exit 1; }

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

for u in admin restricted scoped revoked broad norealm; do login $u; done
title() { get "$1" index.php | grep -o '<title>[^<]*</title>' | head -1; }
for u in admin restricted scoped revoked broad norealm; do
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
for f in smokeping.php smokeping_inventory.php smokeping_health.php scripts/ss_smokeping_summary.php poller_smokeping.php cli/smokeping.php setup.php lib/Row.php include/autoload.php index.php; do
	body=$(curl -s -b $anon "$BASE/plugins/smokeping/$f?series=1~")
	if echo "$body" | grep -q 'Perm/\|median:\|SmokePing Targets\|SmokePing Inventory\|SmokePing Health'; then leak=1; echo "RESULT FAIL anonymous-$f ($(echo "$body" | head -c 80))"; fi
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
token=$(get scoped $VIEW | grep -o "name='__csrf_magic' value=\"[^\"]*\"" | head -1 | sed 's/.*value="//;s/"$//')
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

# Leave no devices behind.
left=$(q "SELECT id FROM host WHERE description LIKE 'bulk%' OR id IN (SELECT host_id FROM plugin_smokeping_object)" 2>/dev/null | ids_of)
[ -n "$left" ] && $L remove_devices "$left" >/dev/null 2>&1

exit $fail
