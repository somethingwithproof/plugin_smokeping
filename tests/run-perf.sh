#!/bin/sh
# Host-side runner for tests/perf-e2e.sh: local smoke measurements of the
# reader and the overview page at 100, 1,000, and 10,000 series, in
# containers. The numbers are for this machine only; the release numbers come
# from the project's runner (docs/performance.md).
#
#   sh tests/run-perf.sh <work-dir> [series...]
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
PLUGIN=$(cd "$HERE/.." && pwd)
mkdir -p "${1:?usage: run-perf.sh <work-dir> [series...]}"
WORK=$(cd "$1" && pwd)
shift
CACTI_CORE=${CACTI_CORE:-$PLUGIN/../plugin-test-ws/cores/1.2.31}
DB_ENV=${DB_ENV:-$PLUGIN/../plugin-test-ws/docker/db.env}
SP_DB_NAME=${SP_DB_NAME:-smokeping_m4perf}
. "$HERE/e2e-env.sh"

sh "$HERE/ensure-db.sh" "$SP_DB_NAME" "$CACTI_CORE" "$DB_ENV"

PRELUDE='ini=$(php -r "echo PHP_CONFIG_FILE_PATH;")/php.ini; [ -f "$ini" ] || { cp "$(dirname "$ini")/php.ini-production" "$ini" 2>/dev/null || touch "$ini"; printf "date.timezone=UTC\nmemory_limit=1G\n" >> "$ini"; };'

made=
for d in rra/smokeping cache/smokeping; do
	[ -e "$CACTI_CORE/$d" ] || made="$made $CACTI_CORE/$d"
done

for n in ${@:-100 1000 10000}; do
	fx="$WORK/perf-$n"
	rm -rf "$fx"
	mkdir -p "$fx"

	docker run --rm -v "$PLUGIN":/p:ro -v "$fx":/fx "$E2E_IMAGE" sh -c '
		i=0; specs=""
		while [ $i -lt '"$n"' ]; do specs="$specs Perf/g$((i / 100))/t$i:300:20:normal"; i=$((i + 1)); done
		php /p/tests/bin/make-fixture-rrds.php /fx $specs' >/dev/null 2>&1

	e2e_docker -e SP_DB_NAME="$SP_DB_NAME" \
		-v "$CACTI_CORE":/cacti -v "$PLUGIN":/cacti/plugins/smokeping -v "$fx":/cacti/rra/smokeping:ro -w /cacti "$E2E_IMAGE" \
		sh -c "$PRELUDE sh plugins/smokeping/tests/perf-e2e.sh $n" 2>&1 | grep -E '^PERF'

	rm -rf "$fx"
done

for dir in $made; do
	[ "$dir" = "$CACTI_CORE/cache/smokeping" ] && rm -rf "$dir" || rmdir "$dir" 2>/dev/null || true
done
