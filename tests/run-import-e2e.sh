#!/bin/sh
# Host-side runner for tests/import-e2e.sh: importing an existing SmokePing
# configuration into a real Cacti, with the configuration read-only at
# /etc/smokeping and SmokePing's fixture files on a read-only mount.
#
#   sh tests/run-import-e2e.sh <work-dir>
#
# Environment: CACTI_CORE, DB_ENV, SP_DB_NAME as for tests/run-objects-e2e.sh
# (the database is created and loaded when missing).
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
PLUGIN=$(cd "$HERE/.." && pwd)
mkdir -p "${1:?usage: run-import-e2e.sh <work-dir>}"
WORK=$(cd "$1" && pwd)
CACTI_CORE=${CACTI_CORE:-$PLUGIN/../plugin-test-ws/cores/1.2.31}
DB_ENV=${DB_ENV:-$PLUGIN/../plugin-test-ws/docker/db.env}
SP_DB_NAME=${SP_DB_NAME:-smokeping_m4imp}

sh "$HERE/ensure-db.sh" "$SP_DB_NAME" "$CACTI_CORE" "$DB_ENV"

PRELUDE='ini=$(php -r "echo PHP_CONFIG_FILE_PATH;")/php.ini; [ -f "$ini" ] || { cp "$(dirname "$ini")/php.ini-production" "$ini" 2>/dev/null || touch "$ini"; printf "date.timezone=UTC\nmemory_limit=512M\n" >> "$ini"; };'

# A fresh directory name each run: Docker Desktop can serve stale entries for a
# directory that was just removed and recreated under the same name.
rm -rf "$WORK"/fx-imp-*
FX="$WORK/fx-imp-$$"
mkdir -p "$FX/data" "$FX/etc"
cp -R "$HERE/Fixtures/import/." "$FX/etc/"

made=
[ -e "$CACTI_CORE/rra/smokeping" ] || made="$made $CACTI_CORE/rra/smokeping"

docker run --rm -v "$PLUGIN":/p:ro -v "$FX/data":/fx spm2-e2e sh -c '
	php /p/tests/bin/make-fixture-rrds.php /fx Europe/paris:300:20:normal Europe/paris~boston:300:20:normal Europe/paris_10:300:10:normal Europe/Nordic/oslo:300:20:normal Lab/disman:300:15:normal' >/dev/null 2>&1

status=0

docker run --rm --network cpt --env-file "$DB_ENV" -e SP_DB_NAME="$SP_DB_NAME" -e SMOKEPING_TEST_DATABASE=disposable \
	-v "$CACTI_CORE":/cacti -v "$PLUGIN":/cacti/plugins/smokeping -v "$FX/data":/cacti/rra/smokeping:ro -v "$FX/etc":/etc/smokeping:ro -w /cacti spm2-e2e \
	sh -c "$PRELUDE sh plugins/smokeping/tests/import-e2e.sh" 2>&1 | grep -E "${RESULT_FILTER:-^RESULT}" || status=1

for dir in $made; do
	rmdir "$dir" 2>/dev/null || true
done

rm -rf "$FX"

exit $status
