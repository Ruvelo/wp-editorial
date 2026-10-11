<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial;

use WP_Post;

/**
 * Wires the plugin into WordPress. Nothing here talks to the outside world:
 * no remote requests, no telemetry, no admin notices outside its own screens.
 */
final class Plugin
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        add_action('init', [self::class, 'init'], 99);
        add_action('rest_api_init', [Rest\Controller::class, 'register']);
        add_action(Notifications::CRON_HOOK, [Notifications::class, 'cron']);
        add_action('before_delete_post', [self::class, 'deleted'], 10, 2);
        foreach (['wp_insert_comment', 'transition_comment_status', 'deleted_comment'] as $hook) {
            add_action($hook, [Queue::class, 'flush']);
        }

        Gate::register();
        Privacy::register();

        if (function_exists('wp_register_ability')) {
            Abilities::register();
        }
        if (is_admin()) {
            Admin\Admin::register();
        }
        if (defined('WP_CLI') && WP_CLI) {
            Cli\Command::register();
        }
    }

    /**
     * Late on init, so post types registered by themes and other plugins exist.
     */
    public static function init(): void
    {
        Install::maybeUpgrade();
        foreach (Settings::enabledTypes() as $type) {
            Notes::enableFor($type);
        }
    }

    public static function deleted(int $postId, WP_Post $post): void
    {
        global $wpdb;

        if (! Settings::enabled($post->post_type)) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $wpdb->delete(Install::outboxTable(), ['post_id' => $postId], ['%d']);
        Queue::flush();
    }
}
