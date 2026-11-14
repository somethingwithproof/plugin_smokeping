<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/*
 * Class loader for lib/. Required only by smokeping_boot() after the PHP
 * version check, so class files may use PHP 8.2 syntax. This file itself
 * stays PHP 8.0 compatible because it sits on the include path of setup.php.
 */

spl_autoload_register(function ($class) {
	$prefix = 'Cacti\\Smokeping\\';

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
