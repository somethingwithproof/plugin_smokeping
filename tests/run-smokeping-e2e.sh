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

# The plugin against SmokePing itself, one packaged version at a time:
# Ubuntu 22.04 (2.7.3), Debian 13 (2.8.2) and Fedora 45 (2.9.0). Each
# SmokePing daemon runs in its own container and measures two targets, one
# that answers (127.0.0.1) and one that cannot (192.0.2.1, TEST-NET-1), every
# 60 seconds. While it runs, tests/smokeping-e2e.sh imports that SmokePing's
# configuration into Cacti and reads the RRD files it writes.
#
#   sh tests/run-smokeping-e2e.sh <work-dir>
#
# Needs Docker, the network and MariaDB of the other runners, and network
# access to build the SmokePing images on the first run. Environment:
# CACTI_CORE, DB_ENV, SP_DB_NAME, E2E_* as for tests/run-objects-e2e.sh.
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
PLUGIN=$(cd "$HERE/.." && pwd)
mkdir -p "${1:?usage: run-smokeping-e2e.sh <work-dir>}"
WORK=$(cd "$1" && pwd)
CACTI_CORE=${CACTI_CORE:-$PLUGIN/../plugin-test-ws/cores/1.2.31}
DB_ENV=${DB_ENV:-$PLUGIN/../plugin-test-ws/docker/db.env}
SP_DB_NAME=${SP_DB_NAME:-smokeping_live}
. "$HERE/e2e-env.sh"

SPECS="ubuntu:22.04:2.7.3 debian:13:2.8.2 fedora:45:2.9.0"
STEP=60
PINGS=5

image_of() { echo "smokeping-e2e-${1%%:*}:${1##*:}"; }

# One image per package: SmokePing, fping and rrdtool from the distribution.
for spec in $SPECS; do
	base=${spec%:*}
	image=$(image_of "$spec")
	docker image inspect "$image" >/dev/null 2>&1 && continue
	case $base in
		fedora*) install='dnf install -y -q smokeping fping rrdtool && dnf clean all' ;;
		*) install='apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends smokeping fping rrdtool >/dev/null && rm -rf /var/lib/apt/lists/*' ;;
	esac
	printf 'FROM %s\nRUN %s\n' "$base" "$install" | docker build -q -t "$image" - >/dev/null
done

# SmokePing's configuration with its datadir at $1. The plugin reads the same
# file with the datadir where Cacti sees the files.
config() { # config <datadir> <fping binary>
	cat <<CONF
*** General ***
owner    = e2e
contact  = e2e@example.net
mailhost = localhost
cgiurl   = http://localhost/smokeping.cgi
imgcache = /sp/cache
imgurl   = cache
datadir  = $1
piddir   = /sp/run
smokemail = /sp/smokemail
tmail    = /sp/tmail

*** Database ***
step     = $STEP
pings    = $PINGS
AVERAGE  0.5   1  1008
MIN  0.5  12  1008
MAX  0.5  12  1008

*** Presentation ***
template = /sp/basepage.html

+ overview
width  = 600
height = 50

+ detail
width  = 600
height = 200

*** Probes ***
+ FPing
binary = $2

*** Targets ***
probe = FPing
menu  = Top
title = Top

+ Local
menu  = Local
title = Local

++ up
host = 127.0.0.1

++ down
host = 192.0.2.1
CONF
}

names=
cleanup() {
	for n in $names; do docker rm -f "smokeping-e2e-$n" >/dev/null 2>&1 || true; done
	e2e_cleanup
}
trap cleanup EXIT

# Start every SmokePing at once; each needs a few steps of data.
for spec in $SPECS; do
	name=${spec%%:*}
	dir="$WORK/$name"
	e2e_rm "$dir"
	mkdir -p "$dir/sp/cache" "$dir/sp/run" "$dir/data"
	touch "$dir/sp/smokemail" "$dir/sp/tmail" "$dir/sp/basepage.html"
	fping=$(docker run --rm "$(image_of "$spec")" sh -c 'command -v fping || ls /usr/sbin/fping /usr/bin/fping 2>/dev/null' | head -1)
	config /data "$fping" > "$dir/sp/config"
	config /cacti/rra/smokeping "$fping" > "$dir/cacti-config"
	docker rm -f "smokeping-e2e-$name" >/dev/null 2>&1 || true
	docker run -d --name "smokeping-e2e-$name" --cap-add NET_RAW -v "$dir/sp":/sp -v "$dir/data":/data "$(image_of "$spec")" \
		sh -c 'mkdir -p /var/lib/smokeping /run/smokeping; exec smokeping --config=/sp/config --nodaemon' >/dev/null
	names="$names $name"
done

# Wait until each SmokePing has written both files and at least two rounds
# with a median for the target that answers.
rounds='for f in up down; do [ -f /data/Local/$f.rrd ] || exit 1; done
rrdtool fetch /data/Local/up.rrd AVERAGE -s -'"$((4 * STEP))"' | awk '"'"'NR == 1 { for (i = 1; i <= NF; i++) if ($i == "median") c = i + 1; next } c && $c ~ /^[0-9]/ { n++ } END { exit !(n >= 2) }'"'"
deadline=$(( $(date +%s) + 8 * STEP ))
for spec in $SPECS; do
	name=${spec%%:*}
	until docker exec "smokeping-e2e-$name" sh -c "$rounds" 2>/dev/null; do
		running=$(docker inspect -f '{{.State.Running}}' "smokeping-e2e-$name" 2>/dev/null || echo false)
		if [ "$running" != true ] || [ "$(date +%s)" -ge "$deadline" ]; then
			echo "RESULT FAIL $name smokeping-writes (running=$running; no two rounds in $((8 * STEP)) s)"
			docker logs "smokeping-e2e-$name" 2>&1 | tail -20
			exit 1
		fi
		sleep 5
	done
done

PRELUDE='ini=$(php -r "echo PHP_CONFIG_FILE_PATH;")/php.ini; [ -f "$ini" ] || { cp "$(dirname "$ini")/php.ini-production" "$ini" 2>/dev/null || touch "$ini"; printf "date.timezone=UTC\nmemory_limit=512M\n" >> "$ini"; };'

status=0

for spec in $SPECS; do
	name=${spec%%:*}
	release=${spec##*:}
	dir="$WORK/$name"
	db="${SP_DB_NAME}_$name"
	sh "$HERE/ensure-db.sh" "$db" "$CACTI_CORE" "$DB_ENV"
	e2e_docker -e SP_DB_NAME="$db" -e SP_NAME="$name $release" -e SP_STEP="$STEP" -e SP_PINGS="$PINGS" \
		-v "$CACTI_CORE":/cacti -v "$PLUGIN":/cacti/plugins/smokeping -v "$dir/data":/cacti/rra/smokeping:ro -v "$dir/cacti-config":/sp-config:ro -w /cacti "$E2E_IMAGE" \
		sh -c "$PRELUDE sh plugins/smokeping/tests/smokeping-e2e.sh" 2>&1 | e2e_check "$WORK/log-smokeping-$name.txt" || status=1
done

exit $status
