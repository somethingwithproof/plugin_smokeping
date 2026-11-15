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

# Host-side runner of tests/reader-e2e.sh: the inventory import and the reader
# on fixture RRDs for every measurement state (normal, partial loss, complete
# loss, stopped, late slave data, a changed step), in a container with Cacti.
#
#   sh tests/run-reader-e2e.sh <work-dir>
#
# Environment: CACTI_CORE, DB_ENV, SP_DB_NAME as for tests/run-objects-e2e.sh.
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
PLUGIN=$(cd "$HERE/.." && pwd)
mkdir -p "${1:?usage: run-reader-e2e.sh <work-dir>}"
WORK=$(cd "$1" && pwd)
CACTI_CORE=${CACTI_CORE:-$PLUGIN/../plugin-test-ws/cores/1.2.31}
DB_ENV=${DB_ENV:-$PLUGIN/../plugin-test-ws/docker/db.env}
SP_DB_NAME=${SP_DB_NAME:-smokeping_reader}
. "$HERE/e2e-env.sh"

sh "$HERE/ensure-db.sh" "$SP_DB_NAME" "$CACTI_CORE" "$DB_ENV"

PRELUDE='ini=$(php -r "echo PHP_CONFIG_FILE_PATH;")/php.ini; [ -f "$ini" ] || { cp "$(dirname "$ini")/php.ini-production" "$ini" 2>/dev/null || touch "$ini"; printf "date.timezone=UTC\nmemory_limit=512M\n" >> "$ini"; };'

# The suite writes its fixtures under the core's rra/ and removes them after;
# the container's copy of rra/ keeps them off the checkout.
status=0
e2e_docker -e SP_DB_NAME="$SP_DB_NAME" -v "$CACTI_CORE":/cacti -v "$PLUGIN":/cacti/plugins/smokeping -v "$WORK/rra":/cacti/rra -w /cacti "$E2E_IMAGE" \
	sh -c "$PRELUDE sh plugins/smokeping/tests/reader-e2e.sh" 2>&1 | e2e_check "$WORK/log-reader.txt" || status=1

exit $status
