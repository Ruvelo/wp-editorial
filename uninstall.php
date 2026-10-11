<?php

/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * The email queue, its scheduled event and the capabilities are always
 * removed, so nothing is left running. Review states, history and settings are deleted only when
 * the site owner ticked "also delete review states, history and settings".
 * Core notes are never touched: they belong to the posts.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

wp_clear_scheduled_hook('ruvelo_editorial_send_notifications');
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own table.
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ruvelo_editorial_outbox");
delete_option('ruvelo_editorial_db_version');

// Capabilities mean nothing without the plugin; activating again grants the defaults.
foreach (wp_roles()->role_objects as $ruvelo_role) {
    foreach (['ruvelo_review_posts', 'ruvelo_override_review_gate', 'ruvelo_manage_editorial'] as $ruvelo_cap) {
        $ruvelo_role->remove_cap($ruvelo_cap);
    }
}

$ruvelo_settings = get_option('ruvelo_editorial_settings', []);
$ruvelo_delete = is_array($ruvelo_settings) && ! empty($ruvelo_settings['delete_data']);

/**
 * Lets a site decide in code, e.g. on a staging copy.
 *
 * @param bool $delete
 */
if (! apply_filters('ruvelo_editorial_uninstall_delete_data', $ruvelo_delete)) {
    return;
}

$ruvelo_keys = [
    '_ruvelo_editorial_state',
    '_ruvelo_editorial_reviewer',
    '_ruvelo_editorial_pending',
    '_ruvelo_editorial_approved_by',
    '_ruvelo_editorial_changes_by',
    '_ruvelo_editorial_round',
    '_ruvelo_editorial_due',
    '_ruvelo_editorial_requested_by',
    '_ruvelo_editorial_updated',
    '_ruvelo_editorial_event',
    '_ruvelo_editorial_override',
];
foreach ($ruvelo_keys as $ruvelo_key) {
    delete_post_meta_by_key($ruvelo_key);
}
foreach (['ruvelo_editorial_emails', 'ruvelo_editorial_theme'] as $ruvelo_key) {
    delete_metadata('user', 0, $ruvelo_key, '', true);
}

delete_option('ruvelo_editorial_settings');
