<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

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
