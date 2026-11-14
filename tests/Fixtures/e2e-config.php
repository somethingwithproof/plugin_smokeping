<?php
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
