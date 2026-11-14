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

use Cacti\Smokeping\Pages;

it('covers every shipped page script with exactly one realm', function () {
	$root    = dirname(__DIR__, 2);
	$scripts = array_map('basename', array_filter(glob($root . '/smokeping*.php'), fn ($f) => $f !== $root . '/setup.php'));
	$covered = array_merge(...array_map(fn ($realm) => $realm['files'], Pages::realms()));

	sort($scripts);
	sort($covered);

	expect($covered)->toBe($scripts)
		->and(array_unique($covered))->toBe($covered)
		->and(array_column(Pages::realms(), 'name'))->toBe(['SmokePing View', 'SmokePing Admin']);
});
