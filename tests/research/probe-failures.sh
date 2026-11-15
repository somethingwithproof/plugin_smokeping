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

# What SmokePing writes when a probe fails, as opposed to the target not
# answering (docs/research/probe-failures.md). For each SmokePing package
# (2.7.3, 2.8.2, 2.9.0) and each probe failure, one container runs SmokePing
# with step 20 and 3 pings; after five steps the script prints the daemon's
# state, the target's last update and its last rounds, and the log lines that
# name the failure.
#
#   sh tests/research/probe-failures.sh <work-dir>
#
# Needs Docker and network access to build the images on first use.
set -u

OUT=${1:?usage: probe-failures.sh <work-dir>}
mkdir -p "$OUT"
STEP=20
PINGS=3
. "$(cd "$(dirname "$0")" && pwd)/images.sh"
V6='--sysctl net.ipv6.conf.all.disable_ipv6=0 --sysctl net.ipv6.conf.lo.disable_ipv6=0'
NORAW='--cap-drop NET_RAW --sysctl net.ipv4.ping_group_range=1\ 0'

# probe|failure|host|probe options, ";" between lines|docker options
# @FPING@ becomes the package's fping path inside the container.
SCENARIOS="FPing|control|127.0.0.1|binary = @FPING@|
FPing|missing-binary|127.0.0.1|binary = /usr/bin/fping-missing|
FPing|packetsize-out-of-range|127.0.0.1|binary = @FPING@;packetsize = 70000|
FPing|no-raw-socket|127.0.0.1|binary = @FPING@|$NORAW
FPing|binary-exits-with-error|127.0.0.1|binary = /bin/false|
FPing|source-address-not-local|127.0.0.1|binary = @FPING@;sourceaddress = 192.0.2.55|
FPing|tos-out-of-range|127.0.0.1|binary = @FPING@;tos = 0x1ff|
FPing|host-does-not-resolve|no-such-host.invalid|binary = @FPING@|
FPing6|control|::1|binary = @FPING@|$V6
FPing6|missing-binary|::1|binary = /usr/bin/fping-missing|$V6
FPing6|no-raw-socket|::1|binary = @FPING@|$NORAW $V6
FPing6|binary-exits-with-error|::1|binary = /bin/false|$V6
FPing6|source-address-not-local|::1|binary = @FPING@;sourceaddress = 2001:db8::55|$V6
FPing6|ipv6-disabled|::1|binary = @FPING@|--sysctl net.ipv6.conf.all.disable_ipv6=1
TCPPing|missing-binary|127.0.0.1|binary = /usr/bin/tcpping-missing;forks = 1|
TCPPing|binary-exits-with-error|127.0.0.1|binary = /bin/false;forks = 1|"

research_images

config() { # <probe> <host> <probe options>
	cat <<CONF
*** General ***
owner = test
contact = test@example.invalid
mailhost = 127.0.0.1
sendmail = /bin/true
imgcache = /sp/cache
imgurl = cache
datadir = /sp/data
piddir = /sp/run
cgiurl = http://localhost/smokeping.cgi
smokemail = /sp/smokemail
tmail = /sp/tmail
syslogfacility = local0

*** Database ***
step = $STEP
pings = $PINGS
AVERAGE 0.5 1 1008

*** Presentation ***
template = /sp/basepage.html
+ overview
width = 600
height = 50
+ detail
width = 600
height = 200

*** Probes ***
+ $1
$3

*** Targets ***
probe = $1
menu = Top
title = Top
+ T
menu = T
title = T
++ t
menu = t
title = t
host = $2
CONF
}

container() { echo "spfail-$1-$2-$3"; }

cleanup() {
	echo "$SCENARIOS" | while IFS='|' read -r probe failure _ _ _; do
		for spec in $VERSIONS; do
			docker rm -f "$(container "${spec%%:*}" "$probe" "$failure")" >/dev/null 2>&1
		done
	done
}
trap cleanup EXIT

echo "$SCENARIOS" | while IFS='|' read -r probe failure host options dockeropts; do
	for spec in $VERSIONS; do
		distro=${spec%%:*}
		release=${spec#*:}; release=${release%%:*}
		dir="$OUT/$distro/$probe-$failure"
		mkdir -p "$dir"
		config "$probe" "$host" "$(echo "$options" | tr ';' '\n')" > "$dir/config"
		docker rm -f "$(container "$distro" "$probe" "$failure")" >/dev/null 2>&1
		eval docker run -d --name "$(container "$distro" "$probe" "$failure")" "$dockeropts" -v "$dir:/cfg:ro" "spfail-$distro:$release" \
			sh -c "'mkdir -p /sp/cache /sp/run /sp/data; touch /sp/smokemail /sp/tmail /sp/basepage.html; sed \"s|@FPING@|\$(command -v fping)|\" /cfg/config > /sp/config; exec smokeping --config=/sp/config --nodaemon --debug-daemon'" >/dev/null
	done
done

sleep $((5 * STEP + 10))

echo "$SCENARIOS" | while IFS='|' read -r probe failure _ _ _; do
	for spec in $VERSIONS; do
		distro=${spec%%:*}
		version=${spec##*:}
		name=$(container "$distro" "$probe" "$failure")
		dir="$OUT/$distro/$probe-$failure"
		state=$(docker inspect -f '{{if .State.Running}}running{{else}}exited {{.State.ExitCode}}{{end}}' "$name")
		docker logs "$name" > "$dir/log.txt" 2>&1
		docker exec "$name" sh -c 'f=/sp/data/T/t.rrd; [ -f $f ] || { echo "no file"; exit; }; rrdtool lastupdate $f | tail -1; rrdtool fetch $f AVERAGE -s -100 | grep -v "nan nan nan nan nan nan" | tail -2' > "$dir/rrd.txt" 2>&1 || echo "no file (daemon exited)" > "$dir/rrd.txt"
		echo "== $probe $failure SmokePing $version: $state"
		sed 's/^/   /' "$dir/rrd.txt"
		grep -E "^ERROR|open3|returned with exit code|cannot bind|does currently not resolve|Got fping output: '[^0-9:]" "$dir/log.txt" | sed 's/^/   log: /' | sort -u | head -3
	done
done
