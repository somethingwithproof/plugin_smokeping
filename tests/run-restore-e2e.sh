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
# and recreates each before use.
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
PLUGIN=$(cd "$HERE/.." && pwd)
mkdir -p "${1:?usage: run-restore-e2e.sh <work-dir>}"
WORK=$(cd "$1" && pwd)
CACTI_CORE=${CACTI_CORE:-$PLUGIN/../plugin-test-ws/cores/1.2.31}
DB_ENV=${DB_ENV:-$PLUGIN/../plugin-test-ws/docker/db.env}
. "$HERE/e2e-env.sh"

PRELUDE='ini=$(php -r "echo PHP_CONFIG_FILE_PATH;")/php.ini; [ -f "$ini" ] || { cp "$(dirname "$ini")/php.ini-production" "$ini" 2>/dev/null || touch "$ini"; printf "date.timezone=UTC\nmemory_limit=512M\n" >> "$ini"; };'

R="$WORK/restore"
rm -rf "$R"
mkdir -p "$R/state" "$R/src-data" "$R/src-config" "$R/backup"

made=
for d in rra/smokeping cache/smokeping; do
	[ -e "$CACTI_CORE/$d" ] || made="$made $CACTI_CORE/$d"
done

# Every database this runner uses starts from this core's cacti.sql, whatever an
# earlier run left in it.
fresh_db() { # fresh_db <smokeping_ database>
	e2e_docker -e DB="$1" "$E2E_MARIADB_IMAGE" sh -c 'mariadb -h "$SP_DB_HOST" -uroot -p"$MARIADB_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS \`$DB\`"' >/dev/null
	sh "$HERE/ensure-db.sh" "$1" "$CACTI_CORE" "$DB_ENV"
}

fresh_db smokeping_m4src

# SmokePing's side: its configuration and files, and RRD files in its data directory.
docker run --rm -v "$PLUGIN":/p:ro -v "$R/src-config":/c -v "$R/src-data":/d "$E2E_IMAGE" sh -c '
	mkdir -p /c/cache /c/run
	touch /c/smokemail /c/tmail /c/basepage.html /c/secrets
	printf "#!/bin/sh\n" > /c/fping; cp /c/fping /c/dig; chmod 755 /c/fping /c/dig
	sed "s#@DIR@#/srv/smokeping-config#g; s#/srv/smokeping-config/data#/cacti/rra/smokeping#" /p/tests/Fixtures/inventory.conf > /c/config
	chmod -R a+rX /c; chmod 600 /c/secrets
	php /p/tests/bin/make-fixture-rrds.php /d Europe/paris:300:20:normal Europe/paris~boston:300:20:partial Europe/berlin:60:10:stale Europe/berlin/core:60:10:never Slaves/remote~boston:300:20:delayed' >/dev/null 2>&1

cacti() { # cacti <db> <data dir> <config dir> <data mode> <phase>
	e2e_docker -e SP_DB_NAME="$1" \
		-v "$CACTI_CORE":/cacti -v "$PLUGIN":/cacti/plugins/smokeping -v "$2":/cacti/rra/smokeping:"$4" -v "$3":/srv/smokeping-config:ro -v "$R/state":/state -w /cacti "$E2E_IMAGE" \
		sh -c "$PRELUDE sh plugins/smokeping/tests/restore-e2e.sh $5" 2>&1 | e2e_check "$WORK/log-restore-$5.txt"
}

status=0
run() { out=$(cacti "$@") || true; echo "$out"; echo "$out" | grep -q '^RESULT FAIL' && status=1; [ -n "$out" ] || status=1; }

run smokeping_m4src "$R/src-data" "$R/src-config" ro source

# The backups, taken as an operator would: the database, SmokePing's configuration, its data directory.
e2e_docker -e DB=smokeping_m4src -v "$R/backup":/backup "$E2E_MARIADB_IMAGE" sh -c 'mariadb-dump -h "$SP_DB_HOST" -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction "$DB" > /backup/cacti.sql'
# As root, like an operator's backup: SmokePing's secrets file is mode 600
# and belongs to the container's root, which a non-root host user cannot read.
docker run --rm -v "$R":/r "$E2E_IMAGE" sh -c 'tar -czf /r/backup/smokeping-config.tgz -C /r/src-config . && tar -czf /r/backup/smokeping-datadir.tgz -C /r/src-data .'

# Everything is lost: a fresh database, a fresh container, empty directories.
e2e_docker -e DB=smokeping_m4dst -e RESTORE=1 -v "$R/backup":/backup:ro "$E2E_MARIADB_IMAGE" sh -c '
	mariadb -h "$SP_DB_HOST" -uroot -p"$MARIADB_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB; GRANT ALL ON $DB.* TO \`$MARIADB_USER\`@\`%\`"
	mariadb -h "$SP_DB_HOST" -uroot -p"$MARIADB_ROOT_PASSWORD" "$DB" < /backup/cacti.sql'
mkdir -p "$R/new-data" "$R/new-config"
tar -xzf "$R/backup/smokeping-datadir.tgz" -C "$R/new-data"
tar -xzf "$R/backup/smokeping-config.tgz" -C "$R/new-config"

# SmokePing keeps running while Cacti is being restored: it writes a new round.
docker run --rm -v "$PLUGIN":/p:ro -v "$R/new-data":/d "$E2E_IMAGE" php /p/tests/bin/fixture-update.php /d/Europe/paris.rrd 20 3 >/dev/null 2>&1
run smokeping_m4dst "$R/new-data" "$R/new-config" ro restore

# Restore from the YAML export alone: a fresh Cacti database, nothing from the old database.
# It is dropped first, so ensure-db.sh loads this core's cacti.sql into it even
# when an earlier run, possibly on another core, left the plugin installed.
fresh_db smokeping_m4yml
rm -rf "$R/yaml-data"; mkdir -p "$R/yaml-data"; tar -xzf "$R/backup/smokeping-datadir.tgz" -C "$R/yaml-data"
run smokeping_m4yml "$R/yaml-data" "$R/new-config" ro yaml

for dir in $made; do
	[ "$dir" = "$CACTI_CORE/cache/smokeping" ] && rm -rf "$dir" || rmdir "$dir" 2>/dev/null || true
done

exit $status
