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

# Host-side runner of the live suite: the plugin against a real SmokePing
# network. It cannot run on a GitHub-hosted runner: it needs root, a SmokePing
# 2.8.2 master that is running and writing its files, Docker, and a MariaDB
# container. Run it on the project's runner host.
#
#   sh tests/live/run-live-e2e.sh <work-dir>
#
# For each Cacti tree it: makes a fresh database; bind-mounts SmokePing's data
# directory read-only at the tree's rra/smokeping (mount --bind, then
# remount,ro,bind) and SmokePing's configuration read-only into the container;
# runs tests/live/live-e2e.sh main (install, import, poller cycles, checks);
# then swaps in a writable copy of the data directory and runs live-e2e.sh purge
# (device deletion and RRD cleanup must leave every file byte-identical, which
# live files, still being written, cannot show). It unmounts, drops the
# databases and removes the copy at exit. It never writes to SmokePing's data
# directory or configuration, and never touches the smokeping or apache2
# services; it checks both are as it found them.
#
# Environment:
#   LIVE_CORES    "1.2.31=<dir> 1.2.32=<dir>": the Cacti trees, run in order
#                 (default: the runner's 1.2.31 core and its 1.2.32 copy)
#   SP_DATADIR    SmokePing's datadir (/var/lib/smokeping)
#   SP_ETC        SmokePing's configuration directory (/etc/smokeping)
#   LIVE_SECONDS  how long the poller runs in main (330), and LIVE_CYCLES at least
#                 how many poller cycles (5)
#   WS, DB_ENV, PLUGIN_SOURCE, E2E_*: as for the other runners; PLUGIN_SOURCE
#                 holds thold/ (default $WS/cacti/plugins).
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
PLUGIN=$(cd "$HERE/../.." && pwd)
mkdir -p "${1:?usage: run-live-e2e.sh <work-dir>}"
WORK=$(cd "$1" && pwd)
WS=${WS:-/opt/sp/plugin-test-ws}
LIVE_CORES=${LIVE_CORES:-"1.2.31=$WS/cores/1.2.31 1.2.32=/opt/sp/core-12x"}
CACTI_CORE=${LIVE_CORES%% *}
CACTI_CORE=${CACTI_CORE#*=}
DB_ENV=${DB_ENV:-$WS/docker/db.env}
PLUGIN_SOURCE=${PLUGIN_SOURCE:-$WS/cacti/plugins}
SP_DATADIR=${SP_DATADIR:-/var/lib/smokeping}
SP_ETC=${SP_ETC:-/etc/smokeping}
. "$PLUGIN/tests/e2e-env.sh"

die() { echo "run-live-e2e: $*" >&2; exit 2; }

[ "$(id -u)" = 0 ] || die "run as root: the bind mount needs it"
command -v mount >/dev/null && command -v findmnt >/dev/null || die "mount and findmnt are required"
[ -d "$SP_DATADIR" ] && [ -f "$SP_ETC/config" ] || die "no SmokePing at $SP_DATADIR and $SP_ETC"
[ "$(find "$SP_DATADIR" -name '*.rrd' | wc -l)" -ge 22 ] || die "$SP_DATADIR has fewer than 22 RRD files"
# SmokePing must be running: a live file was written in the last three minutes.
[ -n "$(find "$SP_DATADIR" -name '*.rrd' -mmin -3 | head -1)" ] || die "no RRD file in $SP_DATADIR was written in the last 3 minutes: SmokePing is not running"
[ -n "$(find "$SP_DATADIR" -name .live-e2e-frozen-copy)" ] && die "$SP_DATADIR holds a frozen-copy marker"

# What SmokePing looked like before: its configuration (by checksum), the set
# of RRD files (by name and inode), and the state of its services.
fingerprint() {
	(cd "$SP_ETC" && find . -type f | sort | xargs sha256sum) | sha256sum | cut -c1-16
	(cd "$SP_DATADIR" && find . -type f -name '*.rrd' | sort | xargs stat -c '%n %i') | sha256sum | cut -c1-16
	for s in smokeping apache2; do echo "$s $(systemctl is-active $s 2>/dev/null || true)"; done
}
before=$(fingerprint)

PRELUDE='ini=$(php -r "echo PHP_CONFIG_FILE_PATH;")/php.ini; [ -f "$ini" ] || { cp "$(dirname "$ini")/php.ini-production" "$ini" 2>/dev/null || touch "$ini"; printf "date.timezone=UTC\nmemory_limit=512M\n" >> "$ini"; };'

# Copies of Thold for the container, so the original is never touched.
rm -rf "$WORK/plugins"
mkdir -p "$WORK/plugins/thold"
(cd "$PLUGIN_SOURCE/thold" && tar --exclude=.git --exclude=tests --exclude=.github -cf - .) | (cd "$WORK/plugins/thold" && tar -xf -)

MP=
MP_MADE=
DBS=
FROZEN=

drop_db() { # drop_db <smokeping_ database>
	e2e_docker -e DB="$1" "$E2E_MARIADB_IMAGE" sh -c 'mariadb -h "$SP_DB_HOST" -uroot -p"$MARIADB_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS \`$DB\`"' >/dev/null 2>&1 || echo "run-live-e2e: could not drop database $1" >&2
}

unmount() {
	if [ -n "$MP" ] && mountpoint -q "$MP" 2>/dev/null; then
		umount "$MP" || echo "run-live-e2e: COULD NOT UNMOUNT $MP; unmount it by hand" >&2
	fi
}

live_cleanup() {
	unmount
	if [ -n "$MP_MADE" ] && [ -n "$MP" ] && ! mountpoint -q "$MP" 2>/dev/null; then
		rmdir "$MP" 2>/dev/null || true
	fi
	# Only a directory this run made, and only once nothing is mounted on it.
	if [ -n "$FROZEN" ] && [ -d "$FROZEN" ] && ! mountpoint -q "$MP" 2>/dev/null; then
		rm -rf "$FROZEN"
	fi
	for d in $DBS; do drop_db "$d"; done
	e2e_cleanup
}
trap live_cleanup EXIT
trap 'exit 130' INT TERM

# bind <source directory> <ro|rw>: mount it at the Cacti tree's rra/smokeping.
bind() {
	mount --bind "$1" "$MP"
	[ "$2" = ro ] && mount -o remount,ro,bind "$MP"
	opts=$(findmnt -no OPTIONS "$MP")
	case "$2:$opts" in
		ro:ro,* | ro:*,ro,*) ;;
		rw:ro,* | rw:*,ro,*) die "$MP is read-only but should be writable" ;;
		ro:*) die "$MP is not read-only after remount: $opts" ;;
	esac
}

status=0

run_phase() { # run_phase <core> <database> <phase>
	e2e_docker -e SP_DB_NAME="$2" -e LIVE_SECONDS="${LIVE_SECONDS:-330}" -e LIVE_CYCLES="${LIVE_CYCLES:-5}" \
		-v "$1":/cacti -v "$PLUGIN":/cacti/plugins/smokeping -v "$WORK/plugins/thold":/cacti/plugins/thold \
		-v "$SP_ETC":/etc/smokeping:ro -w /cacti "$E2E_IMAGE" \
		sh -c "$PRELUDE sh plugins/smokeping/tests/live/live-e2e.sh $3" 2>&1 | e2e_check "$WORK/log-live-$tag-$3.txt" || status=1
}

for spec in $LIVE_CORES; do
	tag=${spec%%=*}
	core=${spec#*=}
	[ -f "$core/cacti.sql" ] || die "no Cacti tree at $core"
	db=smokeping_live_$(echo "$tag" | tr -d .)
	echo "== Cacti $tag at $core, database $db"

	drop_db "$db"
	DBS="$DBS $db"
	sh "$PLUGIN/tests/ensure-db.sh" "$db" "$core" "$DB_ENV"

	MP="$core/rra/smokeping"
	if [ -e "$MP" ]; then
		mountpoint -q "$MP" && die "$MP is already a mount point"
		[ -z "$(ls -A "$MP")" ] || die "$MP is not empty"
		MP_MADE=
	else
		mkdir -p "$MP"
		MP_MADE=1
	fi

	bind "$SP_DATADIR" ro
	echo "RESULT PASS $tag mount-bind-readonly ($(findmnt -no SOURCE,OPTIONS "$MP" | cut -c1-80))"
	run_phase "$core" "$db" main
	unmount

	# A writable copy of the live data, for the checks that delete.
	FROZEN="$WORK/frozen-$tag"
	rm -rf "$FROZEN"
	mkdir -p "$FROZEN"
	cp -a "$SP_DATADIR/." "$FROZEN/"
	: > "$FROZEN/.live-e2e-frozen-copy"
	bind "$FROZEN" rw
	[ -f "$MP/.live-e2e-frozen-copy" ] && [ ! -e "$SP_DATADIR/.live-e2e-frozen-copy" ] || die "the writable mount at $MP is not the frozen copy"
	run_phase "$core" "$db" purge
	unmount
	mountpoint -q "$MP" && die "$MP is still mounted"
	rm -rf "$FROZEN"
	FROZEN=
	[ -z "$MP_MADE" ] || { rmdir "$MP"; MP_MADE=; }
	drop_db "$db"
	DBS=
done

after=$(fingerprint)
if [ "$after" = "$before" ]; then
	echo "RESULT PASS smokeping-untouched (configuration checksum, RRD file set and services as found)"
else
	echo "RESULT FAIL smokeping-untouched (before: $(echo "$before" | tr '\n' ' ') after: $(echo "$after" | tr '\n' ' '))"
	status=1
fi

exit $status
