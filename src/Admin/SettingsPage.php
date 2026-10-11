<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Admin;

use Ruvelo\WP\Editorial\Settings;

/**
 * Reviews → Settings: which post types are reviewed, their publish gates,
 * notifications, and what uninstalling removes.
 */
final class SettingsPage
{
    public const SLUG = 'ruvelo-editorial-settings';

    public static function render(): void
    {
        if (! current_user_can(Settings::CAP_MANAGE)) {
            wp_die(esc_html__('You can’t change review settings.', 'ruvelo-editorial'), 403);
        }
        $types = Settings::availableTypes();
        echo '<div class="wrap ruvelo-ed ruvelo-ed-settings">';
        echo '<div class="ruvelo-ed-head"><span class="ruvelo-ed-mark" aria-hidden="true">E</span><div><h1>'.esc_html__('Review settings', 'ruvelo-editorial').'</h1>';
        echo '<p class="ruvelo-ed-lede">'.esc_html__('Reviews sit beside WordPress’s own statuses and Notes. Turn them on per post type, and decide whether publishing waits for approval.', 'ruvelo-editorial').'</p></div></div>';
        Notice::render();

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="ruvelo_editorial_settings">';
        wp_nonce_field('ruvelo_editorial_settings');

        echo '<h2>'.esc_html__('Post types', 'ruvelo-editorial').'</h2>';
        echo '<table class="widefat striped ruvelo-ed-types"><thead><tr><th scope="col">'.esc_html__('Post type', 'ruvelo-editorial').'</th><th scope="col">'.esc_html__('Reviews', 'ruvelo-editorial').'</th><th scope="col">'.esc_html__('Publish gate', 'ruvelo-editorial').'</th><th scope="col">'.esc_html__('Approvals needed', 'ruvelo-editorial').'</th><th scope="col">'.esc_html__('Open notes', 'ruvelo-editorial').'</th></tr></thead><tbody>';
        foreach ($types as $name => $label) {
            $config = Settings::forType($name);
            $field = 'post_types['.$name.']';
            $id = 'ruvelo-ed-'.sanitize_html_class($name);
            echo '<tr><th scope="row">'.esc_html($label).' <code>'.esc_html($name).'</code></th>';
            echo '<td><label><input type="checkbox" name="'.esc_attr($field.'[enabled]').'" value="1"'.checked($config['enabled'], true, false).'> '.esc_html__('On', 'ruvelo-editorial').'</label></td>';
            echo '<td><label><input type="checkbox" name="'.esc_attr($field.'[gate]').'" value="1"'.checked($config['gate'], true, false).'> '.esc_html__('Hold publishing until approved', 'ruvelo-editorial').'</label></td>';
            echo '<td><label class="screen-reader-text" for="'.esc_attr($id.'-approvals').'">'.esc_html__('Approvals needed', 'ruvelo-editorial').'</label><input type="number" min="1" max="10" class="small-text" id="'.esc_attr($id.'-approvals').'" name="'.esc_attr($field.'[approvals]').'" value="'.esc_attr((string) $config['approvals']).'"></td>';
            echo '<td><label><input type="checkbox" name="'.esc_attr($field.'[resolve_notes]').'" value="1"'.checked($config['resolve_notes'], true, false).'> '.esc_html__('Must be resolved before publishing', 'ruvelo-editorial').'</label></td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">'.esc_html__('The gate applies when a post is first published or scheduled, from any screen, the REST API or WP-CLI. Administrators can publish past it with a reason, which is logged.', 'ruvelo-editorial').'</p>';

        $inbox = has_action('ruvelo_inbox_notify');
        echo '<h2>'.esc_html__('Notifications', 'ruvelo-editorial').'</h2><table class="form-table" role="presentation">';
        echo '<tr><th scope="row">'.esc_html__('Email', 'ruvelo-editorial').'</th><td><label><input type="checkbox" name="emails" value="1"'.checked(Settings::bool('emails'), true, false).'> '.esc_html__('Email reviewers and authors about requests, approvals and changes', 'ruvelo-editorial').'</label>';
        echo '<p><label for="ruvelo-ed-digest">'.esc_html__('Group emails for', 'ruvelo-editorial').'</label> <input type="number" min="0" max="120" class="small-text" id="ruvelo-ed-digest" name="digest_minutes" value="'.esc_attr((string) Settings::int('digest_minutes')).'"> '.esc_html__('minutes', 'ruvelo-editorial').'</p>';
        echo '<p class="description">'.esc_html__('WP-Cron sends each person one email with everything that happened in that window. People can turn emails off on their profile.', 'ruvelo-editorial').'</p></td></tr>';
        echo '<tr><th scope="row">'.esc_html__('Ruvelo Inbox', 'ruvelo-editorial').'</th><td><label><input type="checkbox" name="inbox" value="1"'.checked(Settings::bool('inbox'), true, false).'> '.esc_html__('Also deliver to the in-app inbox', 'ruvelo-editorial').'</label>';
        echo '<p class="description">'.esc_html($inbox ? __('An inbox is active on this site.', 'ruvelo-editorial') : __('No inbox is active, so nothing is sent there. Install Ruvelo Inbox to collect review updates in the admin bar.', 'ruvelo-editorial')).'</p></td></tr>';
        echo '</table>';

        echo '<h2>'.esc_html__('Uninstalling', 'ruvelo-editorial').'</h2><table class="form-table" role="presentation"><tr><th scope="row">'.esc_html__('Data', 'ruvelo-editorial').'</th><td>';
        echo '<label><input type="checkbox" name="delete_data" value="1"'.checked(Settings::bool('delete_data'), true, false).'> '.esc_html__('When the plugin is deleted, also delete review states, history and settings', 'ruvelo-editorial').'</label>';
        echo '<p class="description">'.esc_html__('Off by default: deleting the plugin keeps the review history. Core notes are never deleted; they belong to your posts.', 'ruvelo-editorial').'</p></td></tr></table>';

        submit_button(__('Save changes', 'ruvelo-editorial'));
        echo '</form></div>';
    }

    /**
     * admin-post.php?action=ruvelo_editorial_settings
     */
    public static function save(): void
    {
        if (! current_user_can(Settings::CAP_MANAGE)) {
            wp_die(esc_html__('You can’t change review settings.', 'ruvelo-editorial'), 403);
        }
        check_admin_referer('ruvelo_editorial_settings');
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitised in sanitize().
        $raw = isset($_POST['post_types']) && is_array($_POST['post_types']) ? wp_unslash($_POST['post_types']) : [];
        Settings::update(self::sanitize([
            'post_types' => $raw,
            'emails' => ! empty($_POST['emails']),
            'inbox' => ! empty($_POST['inbox']),
            'digest_minutes' => isset($_POST['digest_minutes']) ? absint($_POST['digest_minutes']) : 5,
            'delete_data' => ! empty($_POST['delete_data']),
        ]));
        Notice::flash('success', __('Settings saved.', 'ruvelo-editorial'));
        wp_safe_redirect(admin_url('admin.php?page='.self::SLUG));
        exit;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function sanitize(array $input): array
    {
        $types = [];
        $raw = is_array($input['post_types'] ?? null) ? $input['post_types'] : [];
        foreach (array_keys(Settings::availableTypes()) as $name) {
            $row = isset($raw[$name]) && is_array($raw[$name]) ? $raw[$name] : [];
            $types[$name] = [
                'enabled' => ! empty($row['enabled']),
                'gate' => ! empty($row['gate']),
                'approvals' => max(1, min(10, isset($row['approvals']) && is_numeric($row['approvals']) ? (int) $row['approvals'] : 1)),
                'resolve_notes' => ! empty($row['resolve_notes']),
            ];
        }

        return [
            'post_types' => $types,
            'emails' => ! empty($input['emails']),
            'inbox' => ! empty($input['inbox']),
            'digest_minutes' => max(0, min(120, (int) ($input['digest_minutes'] ?? 5))),
            'delete_data' => ! empty($input['delete_data']),
        ];
    }
}
