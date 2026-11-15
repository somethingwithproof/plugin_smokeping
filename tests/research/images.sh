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

# Sourced by the research scripts: the SmokePing packages they compare, and
# one image per package with SmokePing, fping and rrdtool, built on first use.
VERSIONS="ubuntu:22.04:2.7.3 debian:13:2.8.2 fedora:45:2.9.0"

research_images() {
	for spec in $VERSIONS; do
		distro=${spec%%:*}
		release=${spec#*:}; release=${release%%:*}
		image=spfail-$distro:$release
		docker image inspect "$image" >/dev/null 2>&1 && continue
		case $distro in
			fedora) install='dnf install -y -q smokeping fping rrdtool && dnf clean all' ;;
			*) install='apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends smokeping fping rrdtool >/dev/null && rm -rf /var/lib/apt/lists/*' ;;
		esac
		printf 'FROM %s:%s\nRUN %s\n' "$distro" "$release" "$install" | docker build -q -t "$image" - >/dev/null
	done
}
