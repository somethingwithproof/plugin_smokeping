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

// Test-only Cacti configuration for the end-to-end runners: the database comes
// from the environment. tests/e2e-env.sh installs it in a Cacti checkout that
// has no include/config.php.
$database_type     = 'mysql';
$database_default  = getenv('SP_DB_NAME') ?: 'smokeping_it';
$database_hostname = getenv('SP_DB_HOST') ?: 'cpt-db';
$database_username = getenv('MARIADB_USER');
$database_password = getenv('MARIADB_PASSWORD');
$database_port     = '3306';
$database_retries  = 5;
$database_ssl      = false;
$database_ssl_key  = '';
$database_ssl_cert = '';
$database_ssl_ca   = '';
$database_persist  = false;
$poller_id = 1;
$url_path = '/cacti/';
$cacti_session_name = 'Cacti';
$cacti_db_session = false;
$disable_log_rotation = false;
