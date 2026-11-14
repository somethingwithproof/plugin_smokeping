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

# Settings shared by the host-side end-to-end runners (tests/run-*.sh). Source
# it after the runner has set PLUGIN, CACTI_CORE and DB_ENV; it is not run alone.
#
#   E2E_IMAGE          test image with PHP, Cacti's extensions, rrdtool (spm2-e2e)
#   E2E_NETWORK        Docker network the containers join (cpt)
#   E2E_DB_HOST        MariaDB host on that network (cpt-db)
#   E2E_MARIADB_IMAGE  image whose client administers the database (mariadb:10.11)
#
# The defaults are the local setup. CI sets all four for its MariaDB service
# container. The database credentials come from the env file DB_ENV when it
# exists, otherwise from MARIADB_ROOT_PASSWORD, MARIADB_USER and
# MARIADB_PASSWORD in the environment; they are handed to containers through a
# private env file, never on a command line.

E2E_IMAGE=${E2E_IMAGE:-spm2-e2e}
E2E_NETWORK=${E2E_NETWORK:-cpt}
E2E_DB_HOST=${E2E_DB_HOST:-cpt-db}
E2E_MARIADB_IMAGE=${E2E_MARIADB_IMAGE:-mariadb:10.11}
export E2E_IMAGE E2E_NETWORK E2E_DB_HOST E2E_MARIADB_IMAGE

E2E_MADE=

e2e_cleanup() {
	for f in $E2E_MADE; do
		rm -f "$f"
	done
}
trap e2e_cleanup EXIT

if [ ! -f "${DB_ENV:-}" ]; then
	: "${MARIADB_ROOT_PASSWORD:?no env file at DB_ENV: set MARIADB_ROOT_PASSWORD, MARIADB_USER and MARIADB_PASSWORD}"
	: "${MARIADB_USER:?set MARIADB_USER}"
	: "${MARIADB_PASSWORD:?set MARIADB_PASSWORD}"
	DB_ENV=$(umask 077 && mktemp "${TMPDIR:-/tmp}/smokeping-e2e-env.XXXXXX")
	E2E_MADE="$E2E_MADE $DB_ENV"
	{
		echo "MARIADB_ROOT_PASSWORD=$MARIADB_ROOT_PASSWORD"
		echo "MARIADB_USER=$MARIADB_USER"
		echo "MARIADB_PASSWORD=$MARIADB_PASSWORD"
	} > "$DB_ENV"
fi
export DB_ENV

# A Cacti checkout has no include/config.php; the local core carries one that
# reads the database from the environment. Install it when the core has none,
# and remove it at exit.
if [ -n "${CACTI_CORE:-}" ] && [ ! -e "$CACTI_CORE/include/config.php" ]; then
	cp "$PLUGIN/tests/Fixtures/e2e-config.php" "$CACTI_CORE/include/config.php"
	E2E_MADE="$E2E_MADE $CACTI_CORE/include/config.php"
fi

# e2e_docker <docker run arguments>: a throwaway container on the database
# network with the database credentials and host in its environment.
e2e_docker() {
	docker run --rm --network "$E2E_NETWORK" --env-file "$DB_ENV" -e SP_DB_HOST="$E2E_DB_HOST" -e SMOKEPING_TEST_DATABASE=disposable "$@"
}

# e2e_rm <path>...: rm -rf, and for what a container created as root and the
# calling user may not delete (on Linux), the same from a container.
e2e_rm() {
	for p in "$@"; do
		rm -rf "$p" 2>/dev/null && continue
		docker run --rm -v "$(dirname "$p")":/e2e-rm "$E2E_IMAGE" rm -rf "/e2e-rm/$(basename "$p")"
	done
}

# e2e_check <log>: reads a container's output. Saves all of it to <log>, shows
# the lines that match RESULT_FILTER (default: the RESULT lines), and fails
# unless there is at least one RESULT line and none is a FAIL.
e2e_check() {
	tee "$1" | grep -E "${RESULT_FILTER:-^RESULT}" || true
	grep -q '^RESULT ' "$1" || return 1
	! grep -q '^RESULT FAIL' "$1"
}
