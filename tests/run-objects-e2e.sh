#!/bin/sh
# Host-side runner for tests/objects-e2e.sh: builds SmokePing fixture
# directories, then runs the end-to-end script in a Cacti 1.2.31 container
# four times: the mount read-only and writable, each with the plugin and with
# it disabled before the deletion (the control). tests/slowplugin is mounted
# as a plugin whose slow poller_bottom hook runs before this one.
#
#   sh tests/run-objects-e2e.sh <work-dir>
#
# Needs Docker, the `cpt` network with MariaDB (`cpt-db`), a disposable
# database, a Cacti 1.2.31 tree, and the spm2-e2e image (PHP 8.2, rrdtool).
#
# Environment: CACTI_CORE, DB_ENV, SP_DB_NAME as for tests/fidelity/run.sh.
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
PLUGIN=$(cd "$HERE/.." && pwd)
mkdir -p "${1:?usage: run-objects-e2e.sh <work-dir>}"
WORK=$(cd "$1" && pwd)
CACTI_CORE=${CACTI_CORE:-$PLUGIN/../plugin-test-ws/cores/1.2.31}
DB_ENV=${DB_ENV:-$PLUGIN/../plugin-test-ws/docker/db.env}
SP_DB_NAME=${SP_DB_NAME:-smokeping_m3b}
. "$HERE/e2e-env.sh"

# The database is created and loaded when it is missing.
sh "$HERE/ensure-db.sh" "$SP_DB_NAME" "$CACTI_CORE" "$DB_ENV"

# Mount points Docker creates inside the core, and the uninstall export; this
# run removes the ones it made.
made=
for d in rra/smokeping plugins/slowplugin cache/smokeping; do
	[ -e "$CACTI_CORE/$d" ] || made="$made $CACTI_CORE/$d"
done

PRELUDE='ini=$(php -r "echo PHP_CONFIG_FILE_PATH;")/php.ini; [ -f "$ini" ] || { cp "$(dirname "$ini")/php.ini-production" "$ini" 2>/dev/null || touch "$ini"; printf "date.timezone=UTC\nmemory_limit=512M\n" >> "$ini"; };'

run() {
	e2e_docker -e SP_DB_NAME="$SP_DB_NAME" \
		-v "$CACTI_CORE":/cacti -v "$PLUGIN":/cacti/plugins/smokeping -v "$PLUGIN/tests/slowplugin":/cacti/plugins/slowplugin:ro "$@"
}

status=0

for variant in "ro guard" "rw guard" "ro noguard" "rw noguard"; do
	set -- $variant
	mode=$1
	guard=$2
	fx="$WORK/fx-$mode-$guard"
	rm -rf "$fx" "$WORK/outside"
	mkdir -p "$fx" "$WORK/outside"

	# Fixtures: targets with RRDs, a file that is a symlink out of the mount,
	# and a directory that is.
	docker run --rm -v "$PLUGIN":/p:ro -v "$fx":/fx -v "$WORK/outside":/outside "$E2E_IMAGE" sh -c '
		php /p/tests/bin/make-fixture-rrds.php /fx Europe/paris:300:20:normal Europe/rome:300:10:normal Europe/berlin:300:15:normal Europe/oldname:300:20:normal Europe/newname:300:20:normal
		php /p/tests/bin/make-fixture-rrds.php /outside x:300:20:normal
		ln -s /outside/x.rrd /fx/Europe/escape.rrd
		ln -s /outside /fx/Europe/linkdir' >/dev/null 2>&1

	suffix=
	[ "$mode" = ro ] && suffix=:ro

	run -v "$fx":/cacti/rra/smokeping$suffix -v "$WORK/outside":/outside:ro -w /cacti "$E2E_IMAGE" \
		sh -c "$PRELUDE sh plugins/smokeping/tests/objects-e2e.sh $mode $guard" 2>&1 | e2e_check "$WORK/log-objects-$mode-$guard.txt" || status=1
done

for dir in $made; do
	[ "$dir" = "$CACTI_CORE/cache/smokeping" ] && rm -rf "$dir" || rmdir "$dir" 2>/dev/null || true
done

exit $status
