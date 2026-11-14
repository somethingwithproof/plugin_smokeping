#!/bin/sh
# Host-side runner for tests/restore-e2e.sh: the backup and restore
# qualification. Backs up a working installation (database dump, SmokePing's
# configuration, SmokePing's data directory), restores the backups into fresh
# containers and fresh databases, and checks the result. Also restores from
# the YAML export alone.
#
#   sh tests/run-restore-e2e.sh <work-dir>
#
# Environment: CACTI_CORE, DB_ENV as for tests/run-objects-e2e.sh. Uses the
# databases smokeping_m4src, smokeping_m4dst, and smokeping_m4yml, and drops
# the last two before it starts.
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
PLUGIN=$(cd "$HERE/.." && pwd)
mkdir -p "${1:?usage: run-restore-e2e.sh <work-dir>}"
WORK=$(cd "$1" && pwd)
CACTI_CORE=${CACTI_CORE:-$PLUGIN/../plugin-test-ws/cores/1.2.31}
DB_ENV=${DB_ENV:-$PLUGIN/../plugin-test-ws/docker/db.env}

PRELUDE='ini=$(php -r "echo PHP_CONFIG_FILE_PATH;")/php.ini; [ -f "$ini" ] || { cp "$(dirname "$ini")/php.ini-production" "$ini" 2>/dev/null || touch "$ini"; printf "date.timezone=UTC\nmemory_limit=512M\n" >> "$ini"; };'

R="$WORK/restore"
rm -rf "$R"
mkdir -p "$R/state" "$R/src-data" "$R/src-config" "$R/backup"

made=
for d in rra/smokeping cache/smokeping; do
	[ -e "$CACTI_CORE/$d" ] || made="$made $CACTI_CORE/$d"
done

sh "$HERE/ensure-db.sh" smokeping_m4src "$CACTI_CORE" "$DB_ENV"

# SmokePing's side: its configuration and files, and RRD files in its data directory.
docker run --rm -v "$PLUGIN":/p:ro -v "$R/src-config":/c -v "$R/src-data":/d spm2-e2e sh -c '
	mkdir -p /c/cache /c/run
	touch /c/smokemail /c/tmail /c/basepage.html /c/secrets
	printf "#!/bin/sh\n" > /c/fping; cp /c/fping /c/dig; chmod 755 /c/fping /c/dig
	sed "s#@DIR@#/srv/smokeping-config#g; s#/srv/smokeping-config/data#/cacti/rra/smokeping#" /p/tests/Fixtures/inventory.conf > /c/config
	chmod -R a+rX /c; chmod 600 /c/secrets
	php /p/tests/bin/make-fixture-rrds.php /d Europe/paris:300:20:normal Europe/paris~boston:300:20:partial Europe/berlin:60:10:stale Europe/berlin/core:60:10:never Slaves/remote~boston:300:20:delayed' >/dev/null 2>&1

cacti() { # cacti <db> <data dir> <config dir> <data mode> <phase>
	docker run --rm --network cpt --env-file "$DB_ENV" -e SP_DB_NAME="$1" -e SMOKEPING_TEST_DATABASE=disposable \
		-v "$CACTI_CORE":/cacti -v "$PLUGIN":/cacti/plugins/smokeping -v "$2":/cacti/rra/smokeping:"$4" -v "$3":/srv/smokeping-config:ro -v "$R/state":/state -w /cacti spm2-e2e \
		sh -c "$PRELUDE sh plugins/smokeping/tests/restore-e2e.sh $5" 2>&1 | grep -E '^RESULT'
}

status=0
run() { out=$(cacti "$@") || true; echo "$out"; echo "$out" | grep -q '^RESULT FAIL' && status=1; [ -n "$out" ] || status=1; }

run smokeping_m4src "$R/src-data" "$R/src-config" ro source

# The backups, taken as an operator would: the database, SmokePing's configuration, its data directory.
docker run --rm --network cpt --env-file "$DB_ENV" -e DB=smokeping_m4src -v "$R/backup":/backup mariadb:10.11 sh -c 'mariadb-dump -h cpt-db -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction "$DB" > /backup/cacti.sql'
tar -czf "$R/backup/smokeping-config.tgz" -C "$R/src-config" .
tar -czf "$R/backup/smokeping-datadir.tgz" -C "$R/src-data" .

# Everything is lost: a fresh database, a fresh container, empty directories.
docker run --rm --network cpt --env-file "$DB_ENV" -e DB=smokeping_m4dst -e RESTORE=1 -v "$R/backup":/backup:ro mariadb:10.11 sh -c '
	mariadb -h cpt-db -uroot -p"$MARIADB_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB; GRANT ALL ON $DB.* TO \`$MARIADB_USER\`@\`%\`"
	mariadb -h cpt-db -uroot -p"$MARIADB_ROOT_PASSWORD" "$DB" < /backup/cacti.sql'
mkdir -p "$R/new-data" "$R/new-config"
tar -xzf "$R/backup/smokeping-datadir.tgz" -C "$R/new-data"
tar -xzf "$R/backup/smokeping-config.tgz" -C "$R/new-config"

# SmokePing keeps running while Cacti is being restored: it writes a new round.
docker run --rm -v "$PLUGIN":/p:ro -v "$R/new-data":/d spm2-e2e php /p/tests/bin/fixture-update.php /d/Europe/paris.rrd 20 3 >/dev/null 2>&1
run smokeping_m4dst "$R/new-data" "$R/new-config" ro restore

# Restore from the YAML export alone: a fresh Cacti database, nothing from the old database.
sh "$HERE/ensure-db.sh" smokeping_m4yml "$CACTI_CORE" "$DB_ENV"
docker run --rm --network cpt --env-file "$DB_ENV" -e DB=smokeping_m4yml mariadb:10.11 sh -c 'mariadb -h cpt-db -uroot -p"$MARIADB_ROOT_PASSWORD" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = \"$DB\" AND TABLE_NAME LIKE \"plugin_smokeping_%\"" | grep -qx 0 || mariadb -h cpt-db -uroot -p"$MARIADB_ROOT_PASSWORD" -e "DROP DATABASE $DB; CREATE DATABASE $DB; GRANT ALL ON $DB.* TO \`$MARIADB_USER\`@\`%\`"' >/dev/null 2>&1 || true
rm -rf "$R/yaml-data"; mkdir -p "$R/yaml-data"; tar -xzf "$R/backup/smokeping-datadir.tgz" -C "$R/yaml-data"
run smokeping_m4yml "$R/yaml-data" "$R/new-config" ro yaml

for dir in $made; do
	[ "$dir" = "$CACTI_CORE/cache/smokeping" ] && rm -rf "$dir" || rmdir "$dir" 2>/dev/null || true
done

exit $status
