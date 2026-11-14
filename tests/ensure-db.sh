#!/bin/sh
# Create a disposable test database loaded from a Cacti core's cacti.sql when it
# does not exist yet, and grant the test user on it. Credentials come from the
# env file only and are never printed.
#
#   sh tests/ensure-db.sh <database> <core dir> <db.env>
#
# The database name must start with smokeping_, so a runner can never touch
# another database. Needs the `cpt` network and the `cpt-db` container.
set -eu

DB=${1:?usage: ensure-db.sh <database> <core dir> <db.env>}
CORE=${2:?core dir}
DBENV=${3:?env file}

case "$DB" in
	smokeping_*[!A-Za-z0-9_]* | smokeping_) echo "ensure-db: refusing database name $DB" >&2; exit 2;;
	smokeping_*) ;;
	*) echo "ensure-db: the database name must start with smokeping_" >&2; exit 2;;
esac

[ -f "$CORE/cacti.sql" ] || { echo "ensure-db: $CORE/cacti.sql not found" >&2; exit 2; }

docker run --rm -i --network cpt --env-file "$DBENV" -e DB="$DB" -v "$CORE/cacti.sql":/cacti.sql:ro mariadb:10.11 sh -s <<'EOS'
set -eu
root() { mariadb -h cpt-db -uroot -p"$MARIADB_ROOT_PASSWORD" "$@"; }
tables=$(root -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME = 'settings'")
if [ "$tables" = 0 ]; then
	root -e "CREATE DATABASE IF NOT EXISTS \`$DB\`; GRANT ALL ON \`$DB\`.* TO \`$MARIADB_USER\`@\`%\`"
	root "$DB" < /cacti.sql
	echo "ensure-db: loaded $DB"
fi
EOS
