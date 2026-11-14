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

# The release archive's allowlist (tools/make-release.sh) and .gitattributes
# export-ignore must agree, so GitHub's automatic "Source code" archives hold
# the same files as the release archive. Compares the file lists of
# `git archive` (which honours export-ignore) and of the release archive, and
# proves the comparison can fail by breaking each side in a throwaway clone.
#
#   sh tests/archive-policy-test.sh [ref]     (default HEAD)
#
# No Docker and no Cacti are needed. Prints one RESULT line per check and exits
# non-zero on any failure.
set -u

PLUGIN=$(cd "$(dirname "$0")/.." && pwd)
REF=${1:-HEAD}
WORK=$(mktemp -d "${TMPDIR:-/tmp}/archive-policy-test.XXXXXX")
trap 'rm -rf "$WORK"' EXIT
fail=0
result() { echo "RESULT $1 $2${3:+ ($3)}"; [ "$1" = PASS ] || [ "$1" = INFO ] || fail=1; }

GIT_QUIET="-c core.hooksPath=/dev/null -c user.name=t -c user.email=t@example.invalid"

# lists <repo> <ref> <tag>: the files in GitHub's archive and in the release archive
lists() {
	(cd "$1" && git archive --format=tar "$2" | tar -t | grep -v '/$' | sort > "$WORK/$3-github.txt")
	(cd "$1" && sh tools/make-release.sh "$2" "$WORK/$3-dist" >/dev/null 2>&1) || return 1
	tar -tzf "$WORK/$3-dist"/smokeping-*.tar.gz | sed 's#^smokeping/##' | grep -v '/$' | grep -v '^$' | sort > "$WORK/$3-release.txt"
}

lists "$PLUGIN" "$REF" real || { result FAIL release-archive-builds "tools/make-release.sh failed at $REF"; exit 1; }

if diff "$WORK/real-github.txt" "$WORK/real-release.txt" > "$WORK/real.diff"; then
	result PASS source-archive-equals-release-archive "$(wc -l < "$WORK/real-release.txt" | tr -d ' ') files in both"
else
	result FAIL source-archive-equals-release-archive "$(head -6 "$WORK/real.diff" | tr '\n' ' ')"
fi

# The documentation is part of both.
grep -q '^docs/install\.md$' "$WORK/real-github.txt" && grep -q '^docs/install\.md$' "$WORK/real-release.txt" && result PASS docs-stay-in-both "docs/install.md" || result FAIL docs-stay-in-both "docs/install.md missing"

# Nothing that is development-only reaches GitHub's archive.
dev=$(grep -E '^(tests|tools|\.github)/|^phpunit|^phpstan|^\.editorconfig$|^\.gitattributes$|^CONTRIBUTING|^CODE_OF_CONDUCT|build_gettext' "$WORK/real-github.txt" || true)
[ -z "$dev" ] && result PASS source-archive-has-no-dev-files || result FAIL source-archive-has-no-dev-files "$(echo "$dev" | head -3 | tr '\n' ' ')"

# The comparison can fail. In a clone, first drop an export-ignore line, then
# add a file to the tree that the allowlist does not name.
rm -rf "$WORK/clone"
git clone -q "$PLUGIN" "$WORK/clone"
(cd "$WORK/clone" && git checkout -q "$(cd "$PLUGIN" && git rev-parse "$REF^{commit}")")

(cd "$WORK/clone" && grep -v '^/tools export-ignore$' .gitattributes > .gitattributes.new && mv .gitattributes.new .gitattributes && git add .gitattributes && git $GIT_QUIET commit -q -m "drop an export-ignore")
lists "$WORK/clone" HEAD dropped || { result FAIL detects-missing-export-ignore "release build failed in the clone"; exit 1; }
diff -q "$WORK/dropped-github.txt" "$WORK/dropped-release.txt" >/dev/null && result FAIL detects-missing-export-ignore "tools/ in the source archive went unnoticed" || result PASS detects-missing-export-ignore "tools/ in the source archive is a difference"

(cd "$WORK/clone" && git reset -q --hard "$(cd "$PLUGIN" && git rev-parse "$REF^{commit}")" && echo "<?php" > NEWTOP.md && git add NEWTOP.md && git $GIT_QUIET commit -q -m "a new top-level file")
lists "$WORK/clone" HEAD added || { result FAIL detects-unlisted-file "release build failed in the clone"; exit 1; }
diff -q "$WORK/added-github.txt" "$WORK/added-release.txt" >/dev/null && result FAIL detects-unlisted-file "a new top-level file went unnoticed" || result PASS detects-unlisted-file "a new top-level file is a difference until it is allowed or ignored"

exit $fail
