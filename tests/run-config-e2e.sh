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

# Host-side runner for tests/config-e2e.sh: configuration export and import on
# a real Cacti, with SmokePing fixture files on a read-only mount.
#
#   sh tests/run-config-e2e.sh <work-dir>
#
# Environment: CACTI_CORE, DB_ENV, SP_DB_NAME as for tests/run-objects-e2e.sh
# (the database is created and loaded when missing).
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
PLUGIN=$(cd "$HERE/.." && pwd)
mkdir -p "${1:?usage: run-config-e2e.sh <work-dir>}"
WORK=$(cd "$1" && pwd)
CACTI_CORE=${CACTI_CORE:-$PLUGIN/../plugin-test-ws/cores/1.2.31}
DB_ENV=${DB_ENV:-$PLUGIN/../plugin-test-ws/docker/db.env}
SP_DB_NAME=${SP_DB_NAME:-smokeping_m4cfg}
. "$HERE/e2e-env.sh"

sh "$HERE/ensure-db.sh" "$SP_DB_NAME" "$CACTI_CORE" "$DB_ENV"

PRELUDE='ini=$(php -r "echo PHP_CONFIG_FILE_PATH;")/php.ini; [ -f "$ini" ] || { cp "$(dirname "$ini")/php.ini-production" "$ini" 2>/dev/null || touch "$ini"; printf "date.timezone=UTC\nmemory_limit=512M\n" >> "$ini"; };'

rm -rf "$WORK"/fx-cfg-*
FX="$WORK/fx-cfg-$$"
mkdir -p "$FX"

# Mount points Docker creates inside the core; remove the ones this run made.
made=
[ -e "$CACTI_CORE/rra/smokeping" ] || made="$made $CACTI_CORE/rra/smokeping"

docker run --rm -v "$PLUGIN":/p:ro -v "$FX":/fx "$E2E_IMAGE" sh -c '
	php /p/tests/bin/make-fixture-rrds.php /fx Europe/paris:300:20:normal Europe/rome:300:10:normal Europe/berlin:300:15:normal' >/dev/null 2>&1

status=0

e2e_docker -e SP_DB_NAME="$SP_DB_NAME" \
	-v "$CACTI_CORE":/cacti -v "$PLUGIN":/cacti/plugins/smokeping -v "$FX":/cacti/rra/smokeping:ro -w /cacti "$E2E_IMAGE" \
	sh -c "$PRELUDE sh plugins/smokeping/tests/config-e2e.sh" 2>&1 | e2e_check "$WORK/log-config.txt" || status=1

for dir in $made; do
	rmdir "$dir" 2>/dev/null || true
done

exit $status
