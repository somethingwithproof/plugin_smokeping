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

# Tests of tools/release-check.sh, the release workflow's gate, against
# throwaway repositories: a good tag, and every way a tag can disagree with
# INFO or CHANGELOG.md. No Docker and no Cacti are needed.
#
#   sh tests/release-tools-test.sh
#
# Prints one RESULT line per case and exits non-zero on any failure.
set -u

PLUGIN=$(cd "$(dirname "$0")/.." && pwd)
WORK=$(mktemp -d "${TMPDIR:-/tmp}/release-tools-test.XXXXXX")
trap 'rm -rf "$WORK"' EXIT
fail=0
result() { echo "RESULT $1 $2${3:+ ($3)}"; [ "$1" = PASS ] || [ "$1" = INFO ] || fail=1; }

# repo <name> <INFO version> <CHANGELOG body> -> a repository with the script committed
repo() {
	d="$WORK/$1"
	mkdir -p "$d/tools"
	cp "$PLUGIN/tools/release-check.sh" "$d/tools/"
	printf '[info]\nname = smokeping\nversion = %s\n' "$2" > "$d/INFO"
	printf '%s\n' "$3" > "$d/CHANGELOG.md"
	(cd "$d" && git init -q && git add INFO CHANGELOG.md tools && git -c core.hooksPath=/dev/null -c user.name=t -c user.email=t@example.invalid commit -q -m init)
}

# expect_ok|expect_fail <repo> <label> <expected output or message fragment> <args...>
expect_ok() {
	d=$1; label=$2; want=$3; shift 3
	out=$(cd "$d" && sh tools/release-check.sh "$@" 2>&1) && [ "$out" = "$want" ] && result PASS "$label" "$out" || result FAIL "$label" "wanted '$want', got '$out'"
}
expect_fail() {
	d=$1; label=$2; want=$3; shift 3
	out=$(cd "$d" && sh tools/release-check.sh "$@" 2>&1) && { result FAIL "$label" "accepted: $out"; return; }
	case $out in
		*"$want"*) result PASS "$label" "$out" ;;
		*) result FAIL "$label" "wanted a message with '$want', got '$out'" ;;
	esac
}

GOOD='# Changelog

## [Unreleased]

## [1.0.0-rc.1] - 2026-11-20

First candidate.

### Added

- Something (#1).

## [0.9.0] - 2026-10-01

Older notes.

[Unreleased]: https://example.invalid/compare/v1.0.0-rc.1...HEAD
[1.0.0-rc.1]: https://example.invalid/releases/tag/v1.0.0-rc.1'

repo good 1.0.0-rc.1 "$GOOD"
expect_ok "$WORK/good" tag-matches-info-and-changelog 1.0.0-rc.1 check v1.0.0-rc.1
expect_fail "$WORK/good" tag-without-v "must be v<version>" check 1.0.0-rc.1
expect_fail "$WORK/good" tag-not-semver "not v<semantic version>" check v1.0
expect_fail "$WORK/good" tag-leading-zero "not v<semantic version>" check v01.0.0
expect_fail "$WORK/good" tag-differs-from-info "does not match INFO version 1.0.0-rc.1" check v1.0.0

# The last section of the file is followed by link references, not a heading.
notes=$(cd "$WORK/good" && sh tools/release-check.sh notes 1.0.0-rc.1 2>&1)
want='First candidate.

### Added

- Something (#1).'
[ "$notes" = "$want" ] && result PASS notes-stop-at-next-heading "the section only" || result FAIL notes-stop-at-next-heading "got: $notes"
notes=$(cd "$WORK/good" && sh tools/release-check.sh notes 0.9.0 2>&1)
[ "$notes" = "Older notes." ] && result PASS notes-stop-at-link-references "the last section has no references" || result FAIL notes-stop-at-link-references "got: $notes"
expect_fail "$WORK/good" notes-for-unknown-version "no CHANGELOG.md notes" notes 7.7.7

repo unreleased 1.0.0 '## [Unreleased]

## [1.0.0] - Unreleased

Notes.'
expect_fail "$WORK/unreleased" changelog-section-unreleased "not a calendar date" check v1.0.0

repo nosection 3.0.0 '## [1.0.0] - 2026-11-20

Notes.'
expect_fail "$WORK/nosection" changelog-has-no-section-for-version "no section" check v3.0.0

repo baddate 1.0.0 '## [1.0.0] - 2026-02-30

Notes.'
expect_fail "$WORK/baddate" changelog-date-not-on-calendar "not a calendar date" check v1.0.0

repo leapday 1.0.0 '## [1.0.0] - 2028-02-29

Notes.'
expect_ok "$WORK/leapday" changelog-leap-day 1.0.0 check v1.0.0

repo emptysection 1.0.0 '## [1.0.0] - 2026-11-20

## [0.9.0] - 2026-10-01

Older.'
expect_fail "$WORK/emptysection" changelog-section-empty "is empty" check v1.0.0

repo plain 1.2.3 '## [1.2.3] - 2026-11-20

Notes.'
expect_ok "$WORK/plain" release-without-prerelease 1.2.3 check v1.2.3

repo noinfo 1.0.0 '## [1.0.0] - 2026-11-20

Notes.'
(cd "$WORK/noinfo" && printf '[info]\nname = smokeping\n' > INFO && git add INFO && git -c core.hooksPath=/dev/null -c user.name=t -c user.email=t@example.invalid commit -q -m "no version")
expect_fail "$WORK/noinfo" info-without-version "no version in INFO" check v1.0.0

# Only the committed tree counts: an edit that was never committed does not
# turn a bad tag into a good one, and does not break a good one.
printf '[info]\nversion = 9.9.9\n' > "$WORK/good/INFO"
expect_ok "$WORK/good" uncommitted-info-is-ignored 1.0.0-rc.1 check v1.0.0-rc.1
(cd "$WORK/good" && git checkout -q INFO)

# A ref other than HEAD.
(cd "$WORK/good" && printf '[info]\nversion = 2.0.0\n' > INFO && git add INFO && git -c core.hooksPath=/dev/null -c user.name=t -c user.email=t@example.invalid commit -q -m bump)
expect_fail "$WORK/good" head-moved-on "does not match INFO version 2.0.0" check v1.0.0-rc.1
expect_ok "$WORK/good" earlier-ref 1.0.0-rc.1 check v1.0.0-rc.1 HEAD~1
expect_fail "$WORK/good" unknown-ref "unknown ref" check v1.0.0-rc.1 no-such-ref

exit $fail
