<?php
declare(strict_types=1);

$tests_dir = getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-tests-lib';

if (is_dir($tests_dir)) {
    // Integration path: load the WordPress PHPUnit test suite.
    require_once $tests_dir . '/includes/functions.php';

    tests_add_filter('muplugins_loaded', static function (): void {
        require_once dirname(__DIR__) . '/brocode-image-optimizer.php';
    });

    require $tests_dir . '/includes/bootstrap.php';
} else {
    // Unit path: define ABSPATH and stub the WP functions that fire at file
    // load time so the plugin can be required without a running WordPress.
    // Individual tests add finer-grained stubs via Brain\Monkey.
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/');
    }

    require_once dirname(__DIR__) . '/vendor/autoload.php';

    if (!function_exists('add_action')) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
        function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
        {
            return true;
        }
    }
    if (!function_exists('register_deactivation_hook')) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
        function register_deactivation_hook(string $file, callable $callback): void {}
    }

    require_once dirname(__DIR__) . '/brocode-image-optimizer.php';
}
