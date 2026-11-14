#!/bin/sh
# +-------------------------------------------------------------------------+
# | Copyright (C) 2004-2026 The Cacti Group                                 |
# |                                                                         |
# | This program is free software; you can redistribute it and/or           |
# | modify it under the terms of the GNU General Public License             |
# | as published by the Free Software Foundation; either version 2          |
# | of the License, or (at your option) any later version.                  |
# |                                                                         |
# | This program is distributed in the hope that it will be useful,         |
# | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
# | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
# | GNU General Public License for more details.                            |
# +-------------------------------------------------------------------------+
# | Cacti: The Complete RRDtool-based Graphing Solution                     |
# +-------------------------------------------------------------------------+
# | This code is designed, written, and maintained by the Cacti Group. See  |
# | about.php and/or the AUTHORS file for specific developer information.   |
# +-------------------------------------------------------------------------+
# | http://www.cacti.net/                                                   |
# +-------------------------------------------------------------------------+
#
# Checks a release tag against the committed tree and extracts the release
# notes. The release workflow runs it; run it by hand before pushing a tag.
#
#   sh tools/release-check.sh check <tag> [ref]     print the version, or say what is wrong
#   sh tools/release-check.sh notes <version> [ref] print that version's CHANGELOG.md notes
#
# ref defaults to HEAD. Everything is read from the committed tree at ref, as
# tools/make-release.sh does, never from the working directory.
#
# `check` fails unless all of these hold:
#   - the tag is v<semantic version>, with an optional -prerelease;
#   - INFO at ref has the same version;
#   - CHANGELOG.md at ref has a section `## [<version>] - YYYY-MM-DD` with a real
#     calendar date (not "Unreleased") and some notes under it.
set -eu

SEMVER='^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.]+)?$'

die() { echo "release-check: $*" >&2; exit 1; }

usage() { die "usage: release-check.sh check <tag> [ref] | notes <version> [ref]"; }

[ $# -ge 2 ] || usage
CMD=$1
ARG=$2
REF=${3:-HEAD}

cd "$(dirname "$0")/.."
git rev-parse --verify --quiet "$REF^{commit}" >/dev/null || die "unknown ref $REF"

# The section for a version: the lines after its `## [version]` heading, up to
# the next `## ` heading or the link references at the end of the file, without
# leading or trailing blank lines.
section() {
	git show "$REF:CHANGELOG.md" | awk -v v="$1" '
		/^## / { if (on) exit; on = ($2 == "[" v "]"); next }
		on && /^\[[^]]+\]: / { exit }
		on { lines[n++] = $0 }
		END {
			s = 0; while (s < n && lines[s] ~ /^[[:space:]]*$/) s++
			e = n - 1; while (e >= s && lines[e] ~ /^[[:space:]]*$/) e--
			for (i = s; i <= e; i++) print lines[i]
		}
	'
}

# The date on a version's heading, or nothing.
heading_date() {
	git show "$REF:CHANGELOG.md" | awk -v v="$1" '/^## / && $2 == "[" v "]" && $3 == "-" { print $4; exit }'
}

# A YYYY-MM-DD date that exists on the calendar.
real_date() {
	printf '%s' "$1" | grep -Eq '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' || return 1
	awk -v d="$1" 'BEGIN {
		split(d, p, "-"); y = p[1] + 0; m = p[2] + 0; day = p[3] + 0
		if (m < 1 || m > 12 || day < 1) exit 1
		dim = (m == 2) ? ((y % 4 == 0 && (y % 100 != 0 || y % 400 == 0)) ? 29 : 28) : ((m == 4 || m == 6 || m == 9 || m == 11) ? 30 : 31)
		exit (day > dim)
	}'
}

case $CMD in
	check)
		TAG=$ARG
		case $TAG in
			v*) ;;
			*) die "tag '$TAG' must be v<version>, for example v1.0.0 or v1.0.0-rc.1" ;;
		esac
		VERSION=${TAG#v}
		printf '%s' "$VERSION" | grep -Eq "$SEMVER" || die "tag '$TAG' is not v<semantic version>"

		INFO_VERSION=$(git show "$REF:INFO" 2>/dev/null | sed -n 's/^version *= *//p') || true
		[ -n "$INFO_VERSION" ] || die "no version in INFO at $REF"
		[ "$INFO_VERSION" = "$VERSION" ] || die "tag $TAG does not match INFO version $INFO_VERSION"

		git cat-file -e "$REF:CHANGELOG.md" 2>/dev/null || die "no CHANGELOG.md at $REF"
		DATE=$(heading_date "$VERSION")
		[ -n "$DATE" ] || die "CHANGELOG.md has no section '## [$VERSION] - YYYY-MM-DD' (an Unreleased or undated heading does not count)"
		real_date "$DATE" || die "CHANGELOG.md dates $VERSION as '$DATE', which is not a calendar date in YYYY-MM-DD form"
		[ -n "$(section "$VERSION")" ] || die "the CHANGELOG.md section for $VERSION is empty"

		echo "$VERSION"
		;;
	notes)
		printf '%s' "$ARG" | grep -Eq "$SEMVER" || die "'$ARG' is not a semantic version"
		NOTES=$(section "$ARG")
		[ -n "$NOTES" ] || die "no CHANGELOG.md notes for $ARG at $REF"
		printf '%s\n' "$NOTES"
		;;
	*)
		usage
		;;
esac
