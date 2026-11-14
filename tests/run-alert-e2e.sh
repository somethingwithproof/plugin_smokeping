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

# Host-side runner for tests/alert-e2e.sh: copies plugin_thold and plugin_maint
# from a plugin workspace (the originals are never touched) and runs the
# end-to-end script in a Cacti 1.2.31 container.
#
#   sh tests/run-alert-e2e.sh <work-dir>
#
# Environment: CACTI_CORE, DB_ENV, SP_DB_NAME as for tests/run-objects-e2e.sh;
# PLUGIN_SOURCE is a directory holding thold/ and maint/ (default
# ../plugin-test-ws/cacti/plugins beside the repository).
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
PLUGIN=$(cd "$HERE/.." && pwd)
mkdir -p "${1:?usage: run-alert-e2e.sh <work-dir>}"
WORK=$(cd "$1" && pwd)
CACTI_CORE=${CACTI_CORE:-$PLUGIN/../plugin-test-ws/cores/1.2.31}
DB_ENV=${DB_ENV:-$PLUGIN/../plugin-test-ws/docker/db.env}
SP_DB_NAME=${SP_DB_NAME:-smokeping_m3c}
. "$HERE/e2e-env.sh"
RESULT_FILTER=${RESULT_FILTER:-^(RESULT|LOG|DUMP)}
PLUGIN_SOURCE=${PLUGIN_SOURCE:-$PLUGIN/../plugin-test-ws/cacti/plugins}

# The database is created and loaded when it is missing.
sh "$HERE/ensure-db.sh" "$SP_DB_NAME" "$CACTI_CORE" "$DB_ENV"

# What this run leaves in the core is removed afterwards: the RRD files of the
# devices it creates, the uninstall export, and mount points Docker made.
made=
for d in rra/smokeping plugins/thold plugins/maint cache/smokeping; do
	[ -e "$CACTI_CORE/$d" ] || made="$made $CACTI_CORE/$d"
done

for p in thold maint; do
	rm -rf "$WORK/plugins/$p"
	mkdir -p "$WORK/plugins/$p"
	(cd "$PLUGIN_SOURCE/$p" && tar --exclude=.git --exclude=tests --exclude=.github -cf - .) | (cd "$WORK/plugins/$p" && tar -xf -)
done

# A fresh directory name each run: Docker Desktop can serve stale entries for a
# directory that was just removed and recreated under the same name, which made
# the fixture files fail to appear.
rm -rf "$WORK"/fx-alert-*
FX="$WORK/fx-alert-$$"
mkdir -p "$FX"

PRELUDE='ini=$(php -r "echo PHP_CONFIG_FILE_PATH;")/php.ini; [ -f "$ini" ] || { cp "$(dirname "$ini")/php.ini-production" "$ini" 2>/dev/null || touch "$ini"; printf "date.timezone=UTC\nmemory_limit=512M\n" >> "$ini"; };'

status=0

e2e_docker -e SP_DB_NAME="$SP_DB_NAME" \
	-v "$CACTI_CORE":/cacti -v "$PLUGIN":/cacti/plugins/smokeping -v "$WORK/plugins/thold":/cacti/plugins/thold \
	-v "$WORK/plugins/maint":/cacti/plugins/maint -v "$FX":/cacti/rra/smokeping -w /cacti "$E2E_IMAGE" \
	sh -c "$PRELUDE sh plugins/smokeping/tests/alert-e2e.sh" 2>&1 | e2e_check "$WORK/log-alert.txt" || status=1

rm -f "$CACTI_CORE"/rra/alertprobe_*.rrd

for dir in $made; do
	[ "$dir" = "$CACTI_CORE/cache/smokeping" ] && rm -rf "$dir" || rmdir "$dir" 2>/dev/null || true
done

exit $status
