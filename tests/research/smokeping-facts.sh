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

# Evidence for the SmokePing rows of docs/PLAN.md ("Upstream behavior the
# design depends on"), for each SmokePing package. In each container it runs
# SmokePing for two rounds and records the RRD layout it creates, the source
# lines behind each row, and what --check and a changed step or pings do.
#
#   sh tests/research/smokeping-facts.sh <work-dir>
#
# Writes facts-<version>.txt per package. Needs Docker and network access to
# build the images on first use.
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
OUT=${1:?usage: smokeping-facts.sh <work-dir>}
mkdir -p "$OUT"
. "$HERE/images.sh"
research_images

for spec in $VERSIONS; do
	distro=${spec%%:*}
	release=${spec#*:}; release=${release%%:*}
	version=${spec##*:}
	docker run --rm --cap-add NET_RAW -v "$HERE:/research:ro" "spfail-$distro:$release" sh /research/smokeping-facts-inside.sh > "$OUT/facts-$version.txt" 2>&1 &
done
wait

for spec in $VERSIONS; do
	version=${spec##*:}
	echo "== SmokePing $version"
	grep -E "^(== |VERSION|([^ ]*:)?[0-9]+:|step = |ds\[(loss|uptime)\]\.(minimal_heartbeat|max)|/data/|Error: RRD|ERROR:|Configuration file)" "$OUT/facts-$version.txt" | sed 's/^/   /'
done
