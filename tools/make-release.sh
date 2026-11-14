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
#
# Build the release archive from a git ref (default HEAD): smokeping-<version>.tar.gz
# and its SHA256SUMS, in the output directory (default ./dist).
#
#   sh tools/make-release.sh [ref] [output-dir]
#
# The archive holds only what a site needs: the runtime PHP and template
# files, the translation template, and the documentation. It is built
# from the committed tree with `git archive`, never from the working directory,
# so an untracked or modified file cannot enter it. The allowlist below is the
# whole policy: a new top-level file or directory is left out until it is added
# here. The archive is reproducible: files carry the commit time, owners are
# not recorded, and gzip stores no name or time.
set -eu

REF=${1:-HEAD}
OUT=${2:-dist}

cd "$(dirname "$0")/.."
git rev-parse --verify --quiet "$REF^{commit}" >/dev/null || { echo "make-release: unknown ref $REF" >&2; exit 1; }

VERSION=$(git show "$REF:INFO" | sed -n 's/^version *= *//p')
[ -n "$VERSION" ] || { echo "make-release: no version in INFO at $REF" >&2; exit 1; }

# Semantic version: MAJOR.MINOR.PATCH with an optional -prerelease.
printf '%s' "$VERSION" | grep -Eq '^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.]+)?$' || { echo "make-release: version '$VERSION' is not a semantic version" >&2; exit 1; }

git show "$REF:CHANGELOG.md" | grep -Eq "^## \[$VERSION\]" || { echo "make-release: CHANGELOG.md has no entry for $VERSION" >&2; exit 1; }

# Top-level runtime files (every PHP entry point), documents, and directories.
paths=$(
	git ls-tree --name-only "$REF" | grep -E '^[A-Za-z0-9_]+\.php$'
	for f in INFO LICENSE README.md CHANGELOG.md SECURITY.md; do git ls-tree --name-only "$REF" -- "$f"; done
	for d in include lib cli scripts templates images docs; do git ls-tree --name-only -d "$REF" -- "$d"; done
	git ls-tree --name-only "$REF" -- locales/index.php locales/po locales/LC_MESSAGES
)

mkdir -p "$OUT"

ARCHIVE="$OUT/smokeping-$VERSION.tar.gz"

# shellcheck disable=SC2086
git archive --format=tar --prefix=smokeping/ "$REF" -- $paths | gzip -n -9 > "$ARCHIVE"

(cd "$OUT" && { sha256sum "smokeping-$VERSION.tar.gz" 2>/dev/null || shasum -a 256 "smokeping-$VERSION.tar.gz"; } > SHA256SUMS)

echo "$ARCHIVE"
