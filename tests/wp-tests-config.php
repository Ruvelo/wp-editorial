<?php

declare(strict_types=1);
/**
 * Test configuration. Locally: SQLite (through the drop-in in .wp/), in a
 * temporary file. In CI: MySQL, from WP_DB_* environment variables.
 */
define('ABSPATH', rtrim((string) (getenv('WP_CORE_DIR') ?: dirname(__DIR__).'/.wp'), '/').'/');

if (getenv('WP_DB_ENGINE') !== 'mysql') {
    define('DB_PATH', sys_get_temp_dir().'/ruvelo-editorial-tests.sqlite');
}

define('DB_NAME', getenv('WP_DB_NAME') ?: 'wordpress_test');
define('DB_USER', getenv('WP_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('WP_DB_PASS') ?: '');
define('DB_HOST', getenv('WP_DB_HOST') ?: '127.0.0.1');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Test Blog');
define('WP_PHP_BINARY', 'php');
define('WP_DEBUG', true);
define('WPLANG', '');
