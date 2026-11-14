#!/bin/sh
# Host-side runner for tests/permissions-e2e.sh.
#
#   sh tests/run-permissions-e2e.sh <work-dir>
#
# Environment: CACTI_CORE, DB_ENV, SP_DB_NAME as for tests/run-objects-e2e.sh.
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
PLUGIN=$(cd "$HERE/.." && pwd)
mkdir -p "${1:?usage: run-permissions-e2e.sh <work-dir>}"
WORK=$(cd "$1" && pwd)
CACTI_CORE=${CACTI_CORE:-$PLUGIN/../plugin-test-ws/cores/1.2.31}
DB_ENV=${DB_ENV:-$PLUGIN/../plugin-test-ws/docker/db.env}
SP_DB_NAME=${SP_DB_NAME:-smokeping_m3c}
. "$HERE/e2e-env.sh"

# The database is created and loaded when it is missing.
sh "$HERE/ensure-db.sh" "$SP_DB_NAME" "$CACTI_CORE" "$DB_ENV"

made=
for d in rra/smokeping cache/smokeping; do
	[ -e "$CACTI_CORE/$d" ] || made="$made $CACTI_CORE/$d"
done

# A fresh directory name each run: Docker Desktop can serve stale entries for a
# directory that was just removed and recreated under the same name.
rm -rf "$WORK"/fx-perm-*
FX="$WORK/fx-perm-$$"
mkdir -p "$FX"

PRELUDE='ini=$(php -r "echo PHP_CONFIG_FILE_PATH;")/php.ini; [ -f "$ini" ] || { cp "$(dirname "$ini")/php.ini-production" "$ini" 2>/dev/null || touch "$ini"; printf "date.timezone=UTC\nmemory_limit=512M\n" >> "$ini"; };'

status=0

e2e_docker -e SP_DB_NAME="$SP_DB_NAME" \
	-v "$CACTI_CORE":/cacti -v "$PLUGIN":/cacti/plugins/smokeping -v "$FX":/cacti/rra/smokeping -w /cacti "$E2E_IMAGE" \
	sh -c "$PRELUDE sh plugins/smokeping/tests/permissions-e2e.sh" 2>&1 | e2e_check "$WORK/log-permissions.txt" || status=1

for dir in $made; do
	[ "$dir" = "$CACTI_CORE/cache/smokeping" ] && rm -rf "$dir" || rmdir "$dir" 2>/dev/null || true
done

exit $status
