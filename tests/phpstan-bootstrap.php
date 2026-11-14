<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

// Constants core defines at runtime, so PHPStan can resolve them without a database.
$cacti_root = rtrim((string) getenv('CACTI_PATH'), '/');

if ($cacti_root === '' || !is_file($cacti_root . '/include/global_constants.php')) {
	fwrite(STDERR, "Set CACTI_PATH to the Cacti root.\n");
	exit(2);
}

require_once $cacti_root . '/include/global_constants.php';
