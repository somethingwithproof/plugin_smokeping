#!/bin/sh
# Host-side runner for tests/failure-e2e.sh.
#
#   sh tests/run-failure-e2e.sh <work-dir>
#
# Environment: CACTI_CORE, DB_ENV, SP_DB_NAME as for tests/run-objects-e2e.sh.
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
PLUGIN=$(cd "$HERE/.." && pwd)
mkdir -p "${1:?usage: run-failure-e2e.sh <work-dir>}"
WORK=$(cd "$1" && pwd)
CACTI_CORE=${CACTI_CORE:-$PLUGIN/../plugin-test-ws/cores/1.2.31}
DB_ENV=${DB_ENV:-$PLUGIN/../plugin-test-ws/docker/db.env}
SP_DB_NAME=${SP_DB_NAME:-smokeping_m4fail}
. "$HERE/e2e-env.sh"

sh "$HERE/ensure-db.sh" "$SP_DB_NAME" "$CACTI_CORE" "$DB_ENV"

PRELUDE='ini=$(php -r "echo PHP_CONFIG_FILE_PATH;")/php.ini; [ -f "$ini" ] || { cp "$(dirname "$ini")/php.ini-production" "$ini" 2>/dev/null || touch "$ini"; printf "date.timezone=UTC\nmemory_limit=512M\n" >> "$ini"; };'

made=
for d in rra/smokeping cache/smokeping; do
	[ -e "$CACTI_CORE/$d" ] || made="$made $CACTI_CORE/$d"
done

rm -rf "$WORK"/fx-fail-*
FX="$WORK/fx-fail-$$"
mkdir -p "$FX"
status=0

e2e_docker -e SP_DB_NAME="$SP_DB_NAME" \
	-v "$CACTI_CORE":/cacti -v "$PLUGIN":/cacti/plugins/smokeping -v "$FX":/cacti/rra/smokeping -w /cacti "$E2E_IMAGE" \
	sh -c "$PRELUDE sh plugins/smokeping/tests/failure-e2e.sh" 2>&1 | e2e_check "$WORK/log-failure.txt" || status=1

for dir in $made; do
	[ "$dir" = "$CACTI_CORE/cache/smokeping" ] && rm -rf "$dir" || rmdir "$dir" 2>/dev/null || true
done

exit $status
