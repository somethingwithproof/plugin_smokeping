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

/*
 * Class loader for lib/. Required only by smokeping_boot() after the PHP
 * version check, so class files may use PHP 8.2 syntax. This file itself
 * stays PHP 8.0 compatible because it sits on the include path of setup.php.
 */

spl_autoload_register(function ($class) {
	$prefix = 'Somethingwithproof\\Smokeping\\';

	if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
		return;
	}

	$name = substr($class, strlen($prefix));

	// One flat directory: a namespace separator in the remainder is never ours.
	if (strpos($name, '\\') !== false) {
		return;
	}

	$file = dirname(__DIR__) . '/lib/' . $name . '.php';

	if (is_file($file)) {
		require_once($file);
	}
});
