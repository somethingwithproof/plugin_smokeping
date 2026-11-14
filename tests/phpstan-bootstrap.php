<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_smokeping, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_smokeping
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

// Constants core defines at runtime, so PHPStan can resolve them without a database.
$cacti_root = rtrim((string) getenv('CACTI_PATH'), '/');

if ($cacti_root === '' || !is_file($cacti_root . '/include/global_constants.php')) {
	fwrite(STDERR, "Set CACTI_PATH to the Cacti root.\n");
	exit(2);
}

require_once $cacti_root . '/include/global_constants.php';
