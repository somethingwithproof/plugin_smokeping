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

# Regenerate lib/ProbeCatalog.php from the probe modules of every supported
# SmokePing release:
#
#   sh tools/make-probe-catalog.sh
#
# Runs tools/probe-catalog.pl in the oracle images tests/oracle/run.sh builds
# (2.7.3, 2.8.2, 2.9.0) without a network, then merges the results with
# tools/make-probe-catalog.php. Review the diff, and rerun tests/oracle/run.sh.
set -eu

ROOT=$(cd "$(dirname "$0")/.." && pwd)
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

for spec in ubuntu:2.7.3 debian:2.8.2 fedora:2.9.0; do
	image=smokeping-oracle-${spec%%:*}:${spec#*:}
	docker image inspect "$image" >/dev/null 2>&1 || { echo "make-probe-catalog: $image is missing; run tests/oracle/run.sh first" >&2; exit 1; }
	docker run --rm --network none -v "$ROOT":/p:ro "$image" perl /p/tools/probe-catalog.pl > "$WORK/${spec#*:}.json"
done

docker run --rm -v "$ROOT":/p -v "$WORK":/w -w /p php:8.2-cli \
	php tools/make-probe-catalog.php lib/ProbeCatalog.php 2.7.3=/w/2.7.3.json 2.8.2=/w/2.8.2.json 2.9.0=/w/2.9.0.json
