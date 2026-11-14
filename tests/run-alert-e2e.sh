#!/bin/sh
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
PLUGIN_SOURCE=${PLUGIN_SOURCE:-$PLUGIN/../plugin-test-ws/cacti/plugins}

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

docker run --rm --network cpt --env-file "$DB_ENV" -e SP_DB_NAME="$SP_DB_NAME" -e SMOKEPING_TEST_DATABASE=disposable \
	-v "$CACTI_CORE":/cacti -v "$PLUGIN":/cacti/plugins/smokeping -v "$WORK/plugins/thold":/cacti/plugins/thold \
	-v "$WORK/plugins/maint":/cacti/plugins/maint -v "$FX":/cacti/rra/smokeping -w /cacti spm2-e2e \
	sh -c "$PRELUDE sh plugins/smokeping/tests/alert-e2e.sh" 2>&1 | grep -E "${RESULT_FILTER:-^(RESULT|LOG|DUMP)}"
