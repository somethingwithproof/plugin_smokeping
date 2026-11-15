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

# Runs inside a SmokePing package container for tests/research/smokeping-facts.sh.
set -u

pm=$(find / -name Smokeping.pm -not -path "*/Smokeping/*" 2>/dev/null | head -1)
lib=$(dirname "$pm")/Smokeping
echo "VERSION $(smokeping --version 2>&1 | head -1)"

echo "== section names and menu/title defaults"
grep -n "KEYD_RE *=" "$pm"
grep -n 'menu} ||=\|title} ||=' "$pm"
echo "== RRD create and slave file names"
grep -n '"~"\.\$slave\|DS:uptime:GAUGE\|DS:loss:GAUGE\|DS:median:GAUGE' "$pm"
echo "== slave cache and resend"
grep -n 'retrieve \$store\|push @\$restore, @\$new' "$lib/Slave.pm"
echo "== master applies slave rounds in time order"
grep -n 'sort {\$a->\[1\] <=> \$b->\[1\]}' "$pm"
echo "== master/slave signature and transport"
grep -n 'hmac_md5_hex(' "$lib/Master.pm" "$lib/Slave.pm"
grep -n 'LWP::UserAgent->new\|master_url},' "$lib/Slave.pm"
echo "== parser outside the daemon"
grep -n 'sub get_parser' "$pm"
grep -n 'get_parser' "$lib/Info.pm"

mkdir -p /sp/cache /sp/run /data
touch /sp/smokemail /sp/tmail /sp/basepage.html
printf '++ included\nhost = 127.0.0.1\n' > /sp/extra.conf

config() { # <step> <pings>
	cat <<CONF
*** General ***
owner = t
contact = t@example.invalid
mailhost = 127.0.0.1
sendmail = /bin/true
imgcache = /sp/cache
imgurl = cache
datadir = /data
piddir = /sp/run
cgiurl = http://localhost/smokeping.cgi
smokemail = /sp/smokemail
tmail = /sp/tmail

*** Database ***
step = $1
pings = $2
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
+ FPing
binary = $(command -v fping)

*** Targets ***
probe = FPing
menu = Top
title = Top
+ Section
++ target
host = 127.0.0.1
@include /sp/extra.conf
CONF
}

config 20 3 > /sp/config
echo "== --check with an @include"
smokeping --config=/sp/config --check 2>&1 | tail -1
echo "== --check with piddir missing"
sed 's|^piddir = .*|piddir = /sp/missing|' /sp/config > /sp/config.nopid
smokeping --config=/sp/config.nopid --check 2>&1 | tail -1
echo "== --check with sendmail missing"
sed 's|^sendmail = .*|sendmail = /usr/sbin/missing|' /sp/config > /sp/config.nomail
smokeping --config=/sp/config.nomail --check 2>&1 | tail -1

timeout 50 smokeping --config=/sp/config --nodaemon >/dev/null 2>&1
echo "== RRD files (target and @include target)"
find /data -name '*.rrd' | sort
echo "== RRD layout"
rrdtool info /data/Section/target.rrd | grep -E '^step|^ds\[[a-z0-9]+\]\.(type|minimal_heartbeat|max)'

echo "== pings changed from 3 to 5"
config 20 5 > /sp/config
timeout 15 smokeping --config=/sp/config --nodaemon 2>&1 | grep -E 'Error|ERROR' | head -1
echo "== step changed from 20 to 30"
config 30 3 > /sp/config
timeout 15 smokeping --config=/sp/config --nodaemon 2>&1 | grep -E 'Error|ERROR' | head -1
