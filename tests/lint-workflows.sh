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

# Lint the GitHub Actions workflows with actionlint (syntax, expressions,
# shellcheck on run: steps) and zizmor (security: script injection, excessive
# permissions, unpinned actions, credential persistence). CI and a developer
# machine run this same file, so the pinned tool versions live only here.
#
#   sh tests/lint-workflows.sh
#
# Needs Docker. zizmor's online audits (impostor commits, known-vulnerable
# actions) run when GH_TOKEN is set in the environment and are skipped, with a
# note, when it is not. Exits non-zero on any finding.
set -eu

ACTIONLINT=rhysd/actionlint:1.7.12@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667
ZIZMOR=ghcr.io/zizmorcore/zizmor:1.32.0@sha256:3014cc8f68f663935f69b3c3e0e41f5ce102b268d97db13db0f62208747f860d

PLUGIN=$(cd "$(dirname "$0")/.." && pwd)
status=0

echo "== actionlint"
docker run --rm -v "$PLUGIN":/repo:ro -w /repo "$ACTIONLINT" -color || status=1

echo "== zizmor"
if [ -n "${GH_TOKEN:-}" ]; then
	# The token goes in by name, so its value is never on a command line.
	docker run --rm -e GH_TOKEN -v "$PLUGIN":/repo:ro -w /repo "$ZIZMOR" --no-progress --color=always .github || status=1
else
	echo "GH_TOKEN is not set: running zizmor's offline audits only"
	docker run --rm -v "$PLUGIN":/repo:ro -w /repo "$ZIZMOR" --offline --no-progress --color=always .github || status=1
fi

exit $status
