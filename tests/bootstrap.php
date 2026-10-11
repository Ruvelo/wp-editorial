<?php

declare(strict_types=1);
use Ruvelo\WP\Editorial\Install;

require dirname(__DIR__).'/vendor/autoload.php';

putenv('WP_PHPUNIT__TESTS_CONFIG='.__DIR__.'/wp-tests-config.php');
define('WP_TESTS_CONFIG_FILE_PATH', __DIR__.'/wp-tests-config.php');
define('RUVELO_EDITORIAL_TESTS', true);

$ruvelo_tests_dir = getenv('WP_PHPUNIT__DIR') ?: dirname(__DIR__).'/vendor/wp-phpunit/wp-phpunit';

require $ruvelo_tests_dir.'/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__).'/ruvelo-editorial.php';
});

require $ruvelo_tests_dir.'/includes/bootstrap.php';

// Real tables, created once (inside a test they would be temporary).
Install::install();
