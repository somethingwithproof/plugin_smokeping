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

# Graph fidelity comparison: SmokePing's smoke graph definition against the
# Cacti graph templates, on each SmokePing package in the distribution matrix.
#
#   sh tests/fidelity/run.sh <work-dir>
#
# Needs Docker, the `cpt` network with a running MariaDB (`cpt-db`), and a
# Cacti 1.2.31 tree. The image of each SmokePing package, spfid-ubuntu (22.04,
# 2.7.3), spfid-debian (13, 2.8.2) and spfid-fedora (45, 2.9.0), is built on
# first use, which needs network access. The Cacti image is spm2-e2e (PHP
# 8.2, rrdtool).
# Disposable database only. Prints RESULT lines; exits non-zero on any FAIL.
#
# Environment:
#   CACTI_CORE   Cacti 1.2.31 tree (default: ../plugin-test-ws/cores/1.2.31 beside the repository)
#   DB_ENV       env file with MARIADB_USER and MARIADB_PASSWORD
#   SP_DB_NAME   database name (default smokeping_m3)
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
PLUGIN=$(cd "$HERE/../.." && pwd)
WORK=$(cd "${1:?usage: run.sh <work-dir>}" 2>/dev/null && pwd || { mkdir -p "$1" && cd "$1" && pwd; })
CACTI_CORE=${CACTI_CORE:-$PLUGIN/../plugin-test-ws/cores/1.2.31}
DB_ENV=${DB_ENV:-$PLUGIN/../plugin-test-ws/docker/db.env}
SP_DB_NAME=${SP_DB_NAME:-smokeping_m3}
. "$PLUGIN/tests/e2e-env.sh"

# One image per SmokePing package, with rrdtool.
for spec in ubuntu:22.04 debian:13 fedora:45; do
	image=spfid-${spec%%:*}
	docker image inspect "$image" >/dev/null 2>&1 && continue
	case $spec in
		fedora*) install='dnf install -y -q smokeping rrdtool && dnf clean all' ;;
		*) install='apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends smokeping rrdtool >/dev/null && rm -rf /var/lib/apt/lists/*' ;;
	esac
	printf 'FROM %s\nRUN %s\n' "$spec" "$install" | docker build -q -t "$image" - >/dev/null
done

# The database is created and loaded when it is missing.
sh "$PLUGIN/tests/ensure-db.sh" "$SP_DB_NAME" "$CACTI_CORE" "$DB_ENV"

mkdir -p "$WORK/fx" "$WORK/cacti" "$WORK/cacti-legacy"

cacti() {
	e2e_docker -e SP_DB_NAME="$SP_DB_NAME" -e SMOKEPING_TEMPLATE_SUFFIX \
		-v "$CACTI_CORE":/cacti -v "$PLUGIN":/cacti/plugins/smokeping -v "$WORK/fx":/cacti/rra/smokeping -v "$WORK":/work -w /cacti "$E2E_IMAGE" sh -c "$*"
}

# Fixtures, ranges, templates, and the Cacti argument lists.
cacti 'php plugins/smokeping/tests/fidelity/make-fixtures.php /cacti/rra/smokeping && perl plugins/smokeping/tests/fidelity/ranges.pl rrdtool /cacti/rra/smokeping > /work/ranges.txt && php plugins/smokeping/tests/fidelity/build-templates.php /work/templates.xml' >/dev/null 2>&1

while read -r pings name start end max; do
	cacti "php plugins/smokeping/tests/fidelity/cacti-args.php $pings p$pings.rrd $start $end $max > /work/cacti/p$pings-$name.args" 2>/dev/null
done < "$WORK/ranges.txt"

# SmokePing 2.7.3 uses different loss colors (see docs/research/graph-fidelity.md),
# so its gating comparison is against the test-only variant with those levels.
while read -r pings name start end max; do
	(
		export SMOKEPING_TEMPLATE_SUFFIX=' [2.7 loss levels]'
		cacti "php plugins/smokeping/tests/fidelity/cacti-args.php $pings p$pings.rrd $start $end $max > /work/cacti-legacy/p$pings-$name.args" 2>/dev/null
	)
done < "$WORK/ranges.txt"

# One run per packaged SmokePing. ubuntu runs twice: against the shipped
# template (recorded, expected to differ in the loss overlay only) and against
# the 2.7 variant (gating).
status=0

for run in ubuntu-legacy ubuntu debian fedora; do
	distro=${run%-legacy}
	cdir=cacti
	[ "$run" = ubuntu-legacy ] && cdir=cacti-legacy
	docker run --rm -v "$HERE":/f:ro -v "$WORK":/work -v "$WORK/fx":/cacti/rra/smokeping:ro "spfid-$distro" sh -c '
		inc=""; [ -f /usr/share/smokeping/Smokeping.pm ] && inc="-I/usr/share/smokeping"
		rrd=$(rrdtool --version | head -1 | cut -d" " -f2)
		perl $inc /f/smokeping-args.pl /cacti/rra/smokeping/p20.rrd 20 300 0 1 1 200 600 2>&1 >/dev/null | sed "s/^/'"$distro"' rrdtool $rrd /" > /work/blocks-'"$distro"'.txt
		while read -r pings name start end max; do
			perl $inc /f/smokeping-args.pl /cacti/rra/smokeping/p$pings.rrd $pings 300 $start $end $max 200 600 2>/dev/null > /work/ref-'"$distro"'-p$pings-$name.args
			perl /f/compare.pl rrdtool "'"$run"' p$pings $name" /work/'"$cdir"'/p$pings-$name.args /work/ref-'"$distro"'-p$pings-$name.args $start $end $max 200 600
		done < /work/ranges.txt' > "$WORK/results-$run.txt" 2>&1 || { [ "$run" = ubuntu ] || status=1; }
done

cat "$WORK"/blocks-*.txt "$WORK"/results-*.txt
for f in "$WORK"/results-*.txt; do
	[ "$f" = "$WORK/results-ubuntu.txt" ] && continue
	grep -q '^RESULT FAIL' "$f" && status=1
done

exit $status
