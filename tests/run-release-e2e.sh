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

# Host-side runner for tests/release-e2e.sh: builds the release archive from a
# git ref, checks its contents and that it is reproducible, lints its entry
# points on PHP 8.0, and installs it on a clean copy of Cacti 1.2.31.
#
#   sh tests/run-release-e2e.sh <work-dir> [ref]
#
# Environment: CACTI_CORE, DB_ENV, SP_DB_NAME as for tests/run-objects-e2e.sh.
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
PLUGIN=$(cd "$HERE/.." && pwd)
mkdir -p "${1:?usage: run-release-e2e.sh <work-dir> [ref]}"
WORK=$(cd "$1" && pwd)
REF=${2:-HEAD}
CACTI_CORE=${CACTI_CORE:-$PLUGIN/../plugin-test-ws/cores/1.2.31}
DB_ENV=${DB_ENV:-$PLUGIN/../plugin-test-ws/docker/db.env}
SP_DB_NAME=${SP_DB_NAME:-smokeping_m4rel}
. "$HERE/e2e-env.sh"

fail=0
result() { echo "RESULT $1 $2${3:+ ($3)}"; [ "$1" = PASS ] || [ "$1" = INFO ] || fail=1; }

rm -rf "$WORK/dist" "$WORK/dist2" "$WORK/unpacked"
mkdir -p "$WORK/unpacked"

archive=$(cd "$PLUGIN" && sh tools/make-release.sh "$REF" "$WORK/dist")
(cd "$PLUGIN" && sh tools/make-release.sh "$REF" "$WORK/dist2" >/dev/null)

[ "$(shasum -a 256 "$archive" | cut -d' ' -f1)" = "$(shasum -a 256 "$WORK/dist2/$(basename "$archive")" | cut -d' ' -f1)" ] && result PASS archive-reproducible "two builds are byte for byte equal" || result FAIL archive-reproducible "builds differ"

tar -tzf "$archive" | sed 's#^smokeping/##' | grep -v '/$' | sort > "$WORK/files.txt"
tar -xzf "$archive" -C "$WORK/unpacked"

# Only runtime files, the POT, and the documentation.
unexpected=$(grep -Ev '^(INFO|LICENSE|LICENSES/GPL-2\.0-or-later\.txt|REUSE\.toml|README\.md|CHANGELOG\.md|SECURITY\.md|[A-Za-z0-9_]+\.php|include/.+|lib/[A-Za-z0-9]+\.php|cli/[a-z_]+\.php|scripts/[a-z_]+\.php|templates/[a-z-]+\.xml|images/[a-z_]+\.(gif|php)|docs/.+\.md|docs/i18n/manifest\.json|docs/research/.+|docs/images/[a-z_-]+\.(png|svg)|locales/index\.php|locales/po/cacti\.pot|locales/po/[a-z]{2,3}-[A-Z0-9]{2,3}\.po|locales/LC_MESSAGES/[a-z]{2,3}-[A-Z0-9]{2,3}\.mo)$' "$WORK/files.txt" || true)
[ -z "$unexpected" ] && result PASS archive-only-runtime-pot-docs "$(wc -l < "$WORK/files.txt" | tr -d ' ') files, every one runtime, POT, or documentation" || result FAIL archive-only-runtime-pot-docs "$(echo "$unexpected" | head -5 | tr '\n' ' ')"

missing=
for f in INFO setup.php smokeping.php smokeping_config.php lib/YamlReader.php cli/smokeping.php scripts/ss_smokeping_summary.php templates/smokeping-templates.xml locales/po/cacti.pot docs/install.md docs/configuration-format.md CHANGELOG.md LICENSE; do
	grep -qx "$f" "$WORK/files.txt" || missing="$missing $f"
done
[ -z "$missing" ] && result PASS archive-has-runtime-files "entry points, lib, templates, POT, install guide, changelog, license" || result FAIL archive-has-runtime-files "missing:$missing"

grep -Eq '^(tests|\.github|tools)/|phpunit|phpstan|CONTRIBUTING|CODE_OF_CONDUCT|build_gettext|\.git' "$WORK/files.txt" && result FAIL archive-excludes-dev-files "$(grep -E '^(tests|\.github|tools)/|phpunit|phpstan|CONTRIBUTING|CODE_OF_CONDUCT|build_gettext|\.git' "$WORK/files.txt" | head -3 | tr '\n' ' ')" || result PASS archive-excludes-dev-files "no tests, CI, tooling, or contributor files"

# The entry points Cacti includes before the PHP check parse on PHP 8.0.
# Pull first, so the pull's progress is not read as lint output.
docker pull -q php:8.0-cli >/dev/null
lint=$(docker run --rm -v "$WORK/unpacked/smokeping":/p:ro -w /p php:8.0-cli sh -c 'for f in setup.php include/autoload.php *.php cli/*.php scripts/*.php; do php -l "$f" | grep -v "^No syntax"; done; echo done' 2>&1)
[ "$lint" = done ] && result PASS archive-entry-points-lint-php80 "setup.php, page scripts, cli, scripts" || result FAIL archive-entry-points-lint-php80 "$lint"

# Version and changelog agree.
version=$(sed -n 's/^version *= *//p' "$WORK/unpacked/smokeping/INFO")
grep -q "^## \[$version\]" "$WORK/unpacked/smokeping/CHANGELOG.md" && result PASS changelog-entry-for-version "CHANGELOG.md has [$version]" || result FAIL changelog-entry-for-version "no entry for $version"

sh "$HERE/ensure-db.sh" "$SP_DB_NAME" "$CACTI_CORE" "$DB_ENV"

PRELUDE='ini=$(php -r "echo PHP_CONFIG_FILE_PATH;")/php.ini; [ -f "$ini" ] || { cp "$(dirname "$ini")/php.ini-production" "$ini" 2>/dev/null || touch "$ini"; printf "date.timezone=UTC\nmemory_limit=512M\n" >> "$ini"; };'

e2e_docker -e SP_DB_NAME="$SP_DB_NAME" \
	-v "$CACTI_CORE":/core:ro -v "$WORK/dist":/dist:ro -v "$PLUGIN/tests/release-e2e.sh":/release-e2e.sh:ro -w / "$E2E_IMAGE" \
	sh -c "$PRELUDE sh /release-e2e.sh" 2>&1 | e2e_check "$WORK/log-release.txt" > "$WORK/inside.txt" || fail=1

cat "$WORK/inside.txt"
grep -q '^RESULT FAIL' "$WORK/inside.txt" && fail=1

exit $fail
