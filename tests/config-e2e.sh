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

# End-to-end check of configuration export and import on a real Cacti: both
# formats, a restore onto devices with new ids, the plan confirmation, the
# refusals, the rollback, and the guarantee that SmokePing's files are never
# touched. Run from the Cacti root, in a container with Cacti's PHP extensions
# and rrdtool, this plugin at plugins/smokeping, a disposable installed Cacti
# database, and SmokePing's fixture directory mounted read-only at
# rra/smokeping (see tests/run-config-e2e.sh):
#
#   SMOKEPING_TEST_DATABASE=disposable sh plugins/smokeping/tests/config-e2e.sh
#
# Prints one RESULT line per check and exits non-zero on any failure.
set -u

P=plugins/smokeping
L="php $P/tests/bin/lifecycle.php"
CLI="php $P/cli/smokeping.php"
fail=0
result() { echo "RESULT $1 $2${3:+ ($3)}"; [ "$1" = PASS ] || [ "$1" = INFO ] || fail=1; }
q() { $L query "$1"; }
jcell() { php -r '$r = json_decode(stream_get_contents(STDIN), true); echo $r[0] ? implode(" ", array_values($r[0])) : "";'; }
cell() { q "$1" | jcell; }
W=$(mktemp -d)

rra=$(php -r 'chdir("."); include("./include/cli_check.php"); echo $config["rra_path"];' 2>/dev/null)
mount="$rra/smokeping"
cache=$(php -r 'chdir("."); include("./include/cli_check.php"); echo $config["base_path"];' 2>/dev/null)/cache/smokeping
rrds() { find "$rra" -name '*.rrd' | sort | xargs sha256sum 2>/dev/null | sha256sum | cut -c1-16; }
devid() { cell "SELECT id FROM host WHERE hostname = '$1'"; }

# ---- A configured installation: three targets, two mapped, a setting changed.
# Devices an earlier run left would make the device names ambiguous.
old=$(cell "SELECT GROUP_CONCAT(id) FROM host WHERE hostname LIKE 'cfg%.example'")
[ -n "$old" ] && [ "$old" != NULL ] && $L remove_devices "$old" >/dev/null 2>&1
$L reset >/dev/null && $L install >/dev/null && $L enable >/dev/null
$L set_setting "smokeping_mount_path=$mount" >/dev/null
$L set_setting "smokeping_datadir=$mount" >/dev/null
$L set_setting "smokeping_reader_budget=90" >/dev/null
$L set_setting "smokeping_uninstall_keep_tables=on" >/dev/null
for t in "Europe/paris:paris.example:20:300" "Europe/rome:rome.example:10:300" "Europe/berlin:berlin.example:15:300"; do
	$L add_target "$t" >/dev/null
done
$L update_target 'Europe/paris|title|Paris: the "gateway" #1 (yes)' >/dev/null
$L update_target 'Europe/paris|slaves|site-b site-c' >/dev/null
$L update_target 'Europe/rome|admin_state|disabled' >/dev/null

make_devices() {
	# Two plain devices whose host names and descriptions are the identity the file carries.
	ids=$($L make_devices "2:cfg" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo implode(" ", $r["result"]);')
	set -- $ids
	dev1=$1
	dev2=$2
}

make_devices
$L map_target "Europe/paris|$dev1" >/dev/null
$L map_target "Europe/rome|$dev2" >/dev/null

rrd_before=$(rrds)
files_before=$(find "$rra" -type f | sort | wc -l)

# ---- Export, both formats.
rm -f "$W/a.yaml" "$W/a.xml"
$CLI export --file="$W/a.yaml" >/dev/null 2>&1; rc_y=$?
$CLI export --format=xml --file="$W/a.xml" >/dev/null 2>&1; rc_x=$?
[ $rc_y -eq 0 ] && [ $rc_x -eq 0 ] && result PASS export-both-formats "yaml $(wc -c < "$W/a.yaml") bytes, xml $(wc -c < "$W/a.xml") bytes" || result FAIL export-both-formats "rc $rc_y $rc_x"

mode=$(stat -c %a "$W/a.yaml")
[ "$mode" = 600 ] && result PASS export-file-private "mode $mode" || result FAIL export-file-private "mode $mode"

$CLI export --file="$W/a.yaml" >/dev/null 2>&1; rc=$?
[ $rc -eq 1 ] && result PASS export-never-overwrites "a second export to the same file is refused" || result FAIL export-never-overwrites "rc $rc"

$CLI export > "$W/stdout.yaml" 2>/dev/null
cmp -s "$W/a.yaml" "$W/stdout.yaml" && result PASS export-stdout-equals-file "same bytes" || result FAIL export-stdout-equals-file "differs"

if grep -q '^schema_version: 1$' "$W/a.yaml" && grep -q 'description: cfg1' "$W/a.yaml" && grep -q "hostname: cfg2.example" "$W/a.yaml" && ! grep -qE 'host_id|target_id|local_data_id|^ *id:' "$W/a.yaml"; then
	result PASS export-by-device-name "mappings carry host name and description, no ids"
else
	result FAIL export-by-device-name "$(grep -n 'mappings' -A8 "$W/a.yaml" | head -12)"
fi

grep -q 'title: .Paris: the "gateway" #1 (yes).' "$W/a.yaml" && result PASS export-quotes-awkward-text "title with colon, quotes, # and a bare word is quoted" || result FAIL export-quotes-awkward-text "$(grep -n 'title' "$W/a.yaml" | head -3)"

for f in a.yaml a.xml; do
	fmt=yaml; [ "$f" = a.xml ] && fmt=xml
	out=$($CLI import --format=$fmt --file="$W/$f" 2>&1); rc=$?
	[ $rc -eq 0 ] && echo "$out" | grep -q ': nothing to do' && result PASS "import-own-export-$fmt" "the installation already matches" || result FAIL "import-own-export-$fmt" "rc $rc: $out"
done

# ---- Uninstall exports the configuration, and keeps it private.
before=$(ls "$cache" 2>/dev/null | wc -l)
$L reset >/dev/null
after=$(ls "$cache" 2>/dev/null | wc -l)
last=$(ls -t "$cache"/smokeping-config-*.yaml 2>/dev/null | head -1)
if [ "$after" -gt "$before" ] && [ -n "$last" ] && [ "$(stat -c %a "$last")" = 600 ] && grep -q 'Europe/paris' "$last"; then
	result PASS uninstall-exports-configuration "$(basename "$last"), mode 600, holds the targets"
else
	result FAIL uninstall-exports-configuration "before $before after $after last=$last"
fi

# The default directory is guarded, and the file name is not predictable.
if [ -f "$cache/.htaccess" ] && grep -q 'Require all denied' "$cache/.htaccess" && [ -f "$cache/index.php" ] && ! grep -q 'Location' "$cache/index.php" && echo "$last" | grep -Eq 'smokeping-config-[0-9]{8}-[0-9]{6}-[0-9a-f]{16}\.yaml$'; then
	result PASS uninstall-export-directory-guarded "deny-all .htaccess, an index.php that serves nothing, a random file name"
else
	result FAIL uninstall-export-directory-guarded "$(ls -a "$cache")"
fi

# A configured backup directory is used instead; an unusable one means no export at all.
mkdir -p "$W/bk"
$L reset >/dev/null; $L install >/dev/null; $L enable >/dev/null
$L set_setting "smokeping_backup_dir=$W/bk" >/dev/null
n_cache=$(ls "$cache" | wc -l)
$L reset >/dev/null
[ "$(ls "$W/bk" | grep -c '^smokeping-config-.*\.yaml$')" = 1 ] && [ "$(ls "$cache" | wc -l)" = "$n_cache" ] && result PASS uninstall-export-to-backup-directory "written to the configured directory only" || result FAIL uninstall-export-to-backup-directory "$(ls "$W/bk") / $(ls "$cache")"
$L install >/dev/null; $L enable >/dev/null
$L set_setting "smokeping_backup_dir=$W/no-such-dir" >/dev/null
$L reset >/dev/null
[ "$(ls "$cache" | wc -l)" = "$n_cache" ] && [ "$(ls "$W/bk" | wc -l)" = 1 ] && ! [ -e "$W/no-such-dir" ] && grep -q 'is not a writable directory; no export was written' log/cacti.log && result PASS uninstall-export-fails-closed "an unusable backup directory writes nothing and does not fall back" || result FAIL uninstall-export-fails-closed "$(ls "$cache")"

# ---- Restore onto a fresh install whose devices have new ids.
restore() {
	fmt=$1
	file=$2
	label=$3

	$L reset >/dev/null; $L install >/dev/null; $L enable >/dev/null
	# Path settings are not imported: the operator sets them on the Settings page of the new installation.
	$L set_setting "smokeping_mount_path=$mount" >/dev/null
	$L set_setting "smokeping_datadir=$mount" >/dev/null
	# The old devices go and new ones with the same names get new ids.
	old=$(cell "SELECT GROUP_CONCAT(id) FROM host WHERE hostname LIKE 'cfg%.example'")
	[ -n "$old" ] && [ "$old" != NULL ] && $L remove_devices "$old" >/dev/null 2>&1
	make_devices

	out=$($CLI import --format=$fmt --file="$file" 2>&1); rc=$?
	plan=$(echo "$out" | sed -n 's/^Plan \([0-9a-f]*\): check only.*/\1/p')
	written=$(cell "SELECT (SELECT COUNT(*) FROM plugin_smokeping_target) + (SELECT COUNT(*) FROM plugin_smokeping_mapping)")
	if [ $rc -eq 0 ] && [ -n "$plan" ] && [ "$written" = 0 ] && echo "$out" | grep -q '^add    target Europe/paris' && echo "$out" | grep -q '^add    mapping Europe/paris'; then
		result PASS "restore-$label-check-writes-nothing" "plan $plan: 3 targets and 2 mappings to add, 0 rows written"
	else
		result FAIL "restore-$label-check-writes-nothing" "rc $rc plan '$plan' rows $written: $out"
		return
	fi

	out=$($CLI import --format=$fmt --file="$file" --apply 2>&1); rc=$?
	[ $rc -eq 1 ] && echo "$out" | grep -q -- '--apply needs --confirm' && result PASS "restore-$label-apply-needs-confirm" "refused without the plan id" || result FAIL "restore-$label-apply-needs-confirm" "rc $rc: $out"

	out=$($CLI import --format=$fmt --file="$file" --apply --confirm=0000000000000000 2>&1); rc=$?
	written=$(cell "SELECT COUNT(*) FROM plugin_smokeping_target")
	[ $rc -eq 1 ] && [ "$written" = 0 ] && result PASS "restore-$label-wrong-confirmation" "a plan id that was not shown is refused, nothing written" || result FAIL "restore-$label-wrong-confirmation" "rc $rc rows $written: $out"

	out=$($CLI import --format=$fmt --file="$file" --apply --confirm="$plan" 2>&1); rc=$?
	[ $rc -eq 0 ] && result PASS "restore-$label-apply" "$out" || result FAIL "restore-$label-apply" "rc $rc: $out"

	d1=$(devid cfg1.example); d2=$(devid cfg2.example)
	row=$(cell "SELECT CONCAT((SELECT COUNT(*) FROM plugin_smokeping_target), ' ', (SELECT host_id FROM plugin_smokeping_mapping m JOIN plugin_smokeping_target t ON t.id = m.target_id WHERE t.path = 'Europe/paris'), ' ', (SELECT host_id FROM plugin_smokeping_mapping m JOIN plugin_smokeping_target t ON t.id = m.target_id WHERE t.path = 'Europe/rome'))")
	[ "$row" = "3 $d1 $d2" ] && result PASS "restore-$label-mappings-follow-device-names" "paris -> device $d1, rome -> device $d2 (new ids)" || result FAIL "restore-$label-mappings-follow-device-names" "$row vs 3 $d1 $d2"

	row=$(cell "SELECT CONCAT(title, '|', slaves) FROM plugin_smokeping_target WHERE path = 'Europe/paris'")
	[ "$row" = 'Paris: the "gateway" #1 (yes)|site-b site-c' ] && result PASS "restore-$label-text-exact" "$row" || result FAIL "restore-$label-text-exact" "$row"

	row=$(cell "SELECT CONCAT((SELECT admin_state FROM plugin_smokeping_target WHERE path = 'Europe/rome'), ' ', (SELECT value FROM settings WHERE name = 'smokeping_reader_budget'), ' ', (SELECT value FROM settings WHERE name = 'smokeping_uninstall_keep_tables'))")
	[ "$row" = "disabled 90 on" ] && result PASS "restore-$label-state-and-settings" "rome disabled, reader budget 90, keep tables on" || result FAIL "restore-$label-state-and-settings" "$row"

	rm -f "$W/b.$fmt"
	$CLI export --format=$fmt --file="$W/b.$fmt" >/dev/null 2>&1
	cmp -s "$W/b.$fmt" "$W/a.$fmt" && result PASS "restore-$label-export-identical" "an export of the restored installation equals the original, byte for byte" || result FAIL "restore-$label-export-identical" "$(diff "$W/a.$fmt" "$W/b.$fmt" | head -6)"

	out=$($CLI import --format=$fmt --file="$file" 2>&1)
	echo "$out" | grep -q ': nothing to do' && result PASS "restore-$label-idempotent" "a second import has nothing to do" || result FAIL "restore-$label-idempotent" "$out"
}

restore yaml "$W/a.yaml" yaml
restore xml "$W/a.xml" xml

# ---- Nothing under SmokePing's directory or Cacti's rra changed.
[ "$(rrds)" = "$rrd_before" ] && [ "$(find "$rra" -type f | sort | wc -l)" = "$files_before" ] && result PASS no-rrd-created-or-changed "$files_before files under rra, checksums unchanged, mount read-only" || result FAIL no-rrd-created-or-changed "files $files_before -> $(find "$rra" -type f | wc -l)"
cell "SELECT COUNT(*) FROM data_local" | grep -q '^[0-9]*$' && [ "$(cell "SELECT COUNT(*) FROM plugin_smokeping_object")" = 0 ] && result PASS no-cacti-objects-created "no data source or graph rows from an import" || result FAIL no-cacti-objects-created "$(cell "SELECT COUNT(*) FROM plugin_smokeping_object")"

# ---- Settings that name a path are never written. The file is shaped like an
# rc.1 export, whose reference settings still list the retired Perl settings.
printf 'schema_version: 1\nreference_settings:\n  smokeping_perl_binary: /tmp/not-perl\n  smokeping_lib_dir: ""\n  smokeping_datadir: /tmp/elsewhere\n  smokeping_config_file: /tmp/evil.conf\n' > "$W/ref.yaml"
out=$($CLI import --file="$W/ref.yaml" 2>&1); rc=$?
plan=$(echo "$out" | sed -n 's/^Plan \([0-9a-f]*\): nothing to do.*/\1/p')
echo "$out" | grep -q 'not applied, change on the Settings page: smokeping_config_file' && echo "$out" | grep -q 'not applied, change on the Settings page: smokeping_datadir' && ! echo "$out" | grep -q 'smokeping_perl_binary\|smokeping_lib_dir' && [ $rc -eq 0 ] && result PASS reference-settings-reported "differences listed as not applied; the retired Perl settings skipped" || result FAIL reference-settings-reported "rc $rc: $out"
$CLI import --file="$W/ref.yaml" --apply --confirm="$plan" >/dev/null 2>&1
[ "$(cell "SELECT COUNT(*) FROM settings WHERE name IN ('smokeping_perl_binary', 'smokeping_datadir', 'smokeping_config_file') AND value LIKE '/tmp/%'")" = 0 ] && result PASS reference-settings-never-written "no path setting changed, even when the plan is applied" || result FAIL reference-settings-never-written "a setting was written"

# ---- Refusals write nothing and say why.
t0=$(cell "SELECT COUNT(*) FROM plugin_smokeping_target")
refuse() {
	name=$1; fmt=$2; want=$3; text=$4
	printf '%s' "$text" > "$W/bad.$fmt"
	out=$($CLI import --format=$fmt --file="$W/bad.$fmt" 2>&1); rc=$?
	if [ $rc -eq 2 ] && echo "$out" | grep -q -- "$want"; then
		result PASS "refuse-$name" "$(echo "$out" | grep -- "$want" | head -1 | cut -c1-110)"
	else
		result FAIL "refuse-$name" "rc $rc: $(echo "$out" | head -3)"
	fi
}
refuse managed-key yaml 'managed_targets is reserved' "schema_version: 1
managed_targets:
  - path: Managed/one
"
refuse probe-profiles xml 'probe_profiles is reserved' '<smokeping-config schema_version="1"><probe_profiles/></smokeping-config>'
refuse yaml-anchor yaml 'line 2: Anchors are not supported' "schema_version: 1
settings: &s
  smokeping_poller_id: 1
"
refuse yaml-flow yaml 'line 2: Flow collections' "schema_version: 1
targets: [a, b]
"
refuse yaml-duplicate-key yaml 'line 3: Duplicate key "schema_version"' "schema_version: 1
settings:
schema_version: 1
"
refuse yaml-second-document yaml 'line 2: Only one document' "schema_version: 1
---
schema_version: 1
"
refuse path-setting-under-settings yaml 'smokeping_config_file is reference only and is never imported' "schema_version: 1
settings:
  smokeping_config_file: /tmp/evil.conf
"
refuse unknown-device yaml 'no device has host name "ghost.example"' "schema_version: 1
targets:
  - path: Europe/paris
mappings:
  - target: Europe/paris
    device:
      hostname: ghost.example
      description: ghost
"
refuse mapping-would-move yaml 'an import never moves a mapping' "schema_version: 1
mappings:
  - target: Europe/paris
    device:
      hostname: cfg2.example
      description: cfg2
"
refuse xml-doctype xml 'DOCTYPE' '<?xml version="1.0"?><!DOCTYPE c [<!ENTITY x SYSTEM "file:///etc/passwd">]><smokeping-config schema_version="1"><settings><setting name="smokeping_config_file">&x;</setting></settings></smokeping-config>'
refuse newer-schema yaml 'newer than this version understands' "schema_version: 2
"
head -c 9000000 /dev/zero | tr '\0' '#' > "$W/huge.yaml"
out=$($CLI import --file="$W/huge.yaml" 2>&1); rc=$?
[ $rc -eq 2 ] && echo "$out" | grep -q 'byte limit' && result PASS refuse-oversize-file "a 9 MB file is refused before it is read" || result FAIL refuse-oversize-file "rc $rc: $out"
[ "$(cell "SELECT COUNT(*) FROM plugin_smokeping_target")" = "$t0" ] && result PASS refusals-wrote-nothing "target count unchanged at $t0" || result FAIL refusals-wrote-nothing "$t0 -> $(cell "SELECT COUNT(*) FROM plugin_smokeping_target")"

# ---- A plan that went stale is refused; a failed apply rolls back whole.
cat > "$W/new.yaml" <<'EOF'
schema_version: 1
settings:
  smokeping_reader_budget: 45
targets:
  - path: Europe/madrid
    host: madrid.example
    step: 300
    pings: 20
  - path: Europe/paris
    title: Paris renamed
EOF
out=$($CLI import --file="$W/new.yaml" 2>&1)
plan=$(echo "$out" | sed -n 's/^Plan \([0-9a-f]*\): check only.*/\1/p')
$L update_target 'Europe/paris|title|Changed by someone else' >/dev/null
out=$($CLI import --file="$W/new.yaml" --apply --confirm="$plan" 2>&1); rc=$?
row=$(cell "SELECT COUNT(*) FROM plugin_smokeping_target WHERE path = 'Europe/madrid'")
[ $rc -eq 1 ] && [ "$row" = 0 ] && echo "$out" | grep -q 'changed since the plan' && result PASS stale-plan-refused "the installation changed after the check; nothing written" || result FAIL stale-plan-refused "rc $rc rows $row: $out"

out=$($CLI import --file="$W/new.yaml" 2>&1)
plan=$(echo "$out" | sed -n 's/^Plan \([0-9a-f]*\): check only.*/\1/p')
cat >> "$W/new.yaml" <<EOF
mappings:
  - target: Europe/madrid
    device:
      hostname: cfg1.example
      description: cfg1
EOF
out=$($CLI import --file="$W/new.yaml" 2>&1)
plan=$(echo "$out" | sed -n 's/^Plan \([0-9a-f]*\): check only.*/\1/p')
$L fail_mapping_inserts >/dev/null
out=$($CLI import --file="$W/new.yaml" --apply --confirm="$plan" 2>&1); rc=$?
row=$(cell "SELECT CONCAT((SELECT COUNT(*) FROM plugin_smokeping_target WHERE path = 'Europe/madrid'), ' ', (SELECT value FROM settings WHERE name = 'smokeping_reader_budget'), ' ', (SELECT title FROM plugin_smokeping_target WHERE path = 'Europe/paris'))")
$L allow_mapping_inserts >/dev/null
[ $rc -eq 1 ] && [ "$row" = "0 90 Changed by someone else" ] && result PASS failed-apply-rolls-back "a refused mapping insert undid the setting, the new target, and the title change" || result FAIL failed-apply-rolls-back "rc $rc row '$row': $out"

out=$($CLI import --file="$W/new.yaml" --apply --confirm="$plan" 2>&1); rc=$?
row=$(cell "SELECT CONCAT((SELECT COUNT(*) FROM plugin_smokeping_target WHERE path = 'Europe/madrid'), ' ', (SELECT value FROM settings WHERE name = 'smokeping_reader_budget'), ' ', (SELECT title FROM plugin_smokeping_target WHERE path = 'Europe/paris'))")
[ $rc -eq 0 ] && [ "$row" = "1 45 Paris renamed" ] && result PASS apply-after-rollback "the same plan applies once the failure is gone" || result FAIL apply-after-rollback "rc $rc row '$row': $out"

[ "$(rrds)" = "$rrd_before" ] && result PASS rrd-unchanged-at-end "checksums of every RRD under rra unchanged" || result FAIL rrd-unchanged-at-end "changed"

old=$(cell "SELECT GROUP_CONCAT(id) FROM host WHERE hostname LIKE 'cfg%.example'")
[ -n "$old" ] && [ "$old" != NULL ] && $L remove_devices "$old" >/dev/null 2>&1
rm -rf "$W"
exit $fail
