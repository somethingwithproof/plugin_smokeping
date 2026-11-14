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

# A fresh directory name each run: Docker Desktop can serve stale entries for a
# directory that was just removed and recreated under the same name.
rm -rf "$WORK"/fx-perm-*
FX="$WORK/fx-perm-$$"
mkdir -p "$FX"

PRELUDE='ini=$(php -r "echo PHP_CONFIG_FILE_PATH;")/php.ini; [ -f "$ini" ] || { cp "$(dirname "$ini")/php.ini-production" "$ini" 2>/dev/null || touch "$ini"; printf "date.timezone=UTC\nmemory_limit=512M\n" >> "$ini"; };'

docker run --rm --network cpt --env-file "$DB_ENV" -e SP_DB_NAME="$SP_DB_NAME" -e SMOKEPING_TEST_DATABASE=disposable \
	-v "$CACTI_CORE":/cacti -v "$PLUGIN":/cacti/plugins/smokeping -v "$FX":/cacti/rra/smokeping -w /cacti spm2-e2e \
	sh -c "$PRELUDE sh plugins/smokeping/tests/permissions-e2e.sh" 2>&1 | grep -E "${RESULT_FILTER:-^RESULT}"
