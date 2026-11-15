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

# The graph fidelity comparison under the name the e2e workflow job runs
# (tests/run-<runner>-e2e.sh). It compares against Cacti 1.2.31 only.
#
#   sh tests/run-fidelity-e2e.sh <work-dir>
exec sh "$(cd "$(dirname "$0")" && pwd)/fidelity/run.sh" "$@"
