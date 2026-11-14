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
# Regenerate locales/po/cacti.pot from every PHP file at depth 2 or less, the
# scan the Cacti plugin gettext builds use. With --check, fail instead when
# the committed template is out of date. Per-language catalogs are managed
# through Weblate and are not touched here.

set -eu

BASE_PATH=$(cd "$(dirname "$0")/.." && pwd)
POT=locales/po/cacti.pot

cd "$BASE_PATH"
mkdir -p locales/po

command -v xgettext >/dev/null 2>&1 || { echo "ERROR: Unable to locate xgettext (install GNU gettext)" >&2; exit 1; }

VERSION=$(sed -n 's/^version *= *//p' INFO)

generate() {
	# shellcheck disable=SC2046
	xgettext --no-wrap --add-location=file --from-code=UTF-8 --copyright-holder="The Cacti Group" \
		--package-name="Cacti" --package-version="$VERSION" --msgid-bugs-address="developers@cacti.net" \
		-F -L PHP -k__gettext -k__ -k__n:1,2 -k__x:1c,2 -k__xn:1c,2,3 -k__esc -k__esc_n:1,2 -k__esc_x:1c,2 -k__esc_xn:1c,2,3 -k__date \
		-o "$1" $(find . -maxdepth 2 -name '*.php' -not -path './tests/*' | sort)

	sed -e 's/FULL NAME <EMAIL@ADDRESS>/Cacti Developers <developers@cacti.net>/' \
		-e 's/LANGUAGE <LL@li.org>/Cacti Developers <developers@cacti.net>/' \
		-e 's/CHARSET/UTF-8/' \
		-e '/^"POT-Creation-Date:/d' "$1" > "$1.tmp" && mv "$1.tmp" "$1"
}

if [ "${1:-}" = "--check" ]; then
	tmp=$(mktemp)
	generate "$tmp"

	if ! diff -u "$POT" "$tmp"; then
		rm -f "$tmp"
		echo "ERROR: $POT is out of date; run locales/build_gettext.sh and commit the result" >&2
		exit 1
	fi

	rm -f "$tmp"
	echo "$POT is up to date"
	exit 0
fi

generate "$POT"
echo "Updated $POT"
