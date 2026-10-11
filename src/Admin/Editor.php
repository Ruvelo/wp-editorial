<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Admin;

use Ruvelo\WP\Editorial\Notes;
use Ruvelo\WP\Editorial\Rest\Controller;
use Ruvelo\WP\Editorial\Settings;

/**
 * The block editor's Review panel. Plain JavaScript on the `wp.*` globals,
 * no build step: assets/js/editor.js is the source.
 */
final class Editor
{
    public static function enqueue(): void
    {
        $screen = get_current_screen();
        $type = $screen !== null ? (string) $screen->post_type : '';
        if ($type === '' || ! Settings::enabled($type)) {
            return;
        }
        wp_enqueue_script(
            'ruvelo-editorial-editor',
            RUVELO_EDITORIAL_URL.'assets/js/editor.js',
            ['wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-hooks', 'wp-notices', 'wp-core-data', 'wp-block-editor', 'wp-compose', 'wp-a11y'],
            RUVELO_EDITORIAL_VERSION,
            true
        );
        wp_enqueue_style('ruvelo-editorial-editor', RUVELO_EDITORIAL_URL.'assets/css/editor.css', [], RUVELO_EDITORIAL_VERSION);
        wp_set_script_translations('ruvelo-editorial-editor', 'ruvelo-editorial', RUVELO_EDITORIAL_DIR.'languages');
        wp_add_inline_script('ruvelo-editorial-editor', 'window.ruveloEditorial = '.wp_json_encode([
            'namespace' => Controller::NAMESPACE,
            'notes' => Notes::supported($type),
            'multipleNotes' => Notes::multipleNotesPerBlock(),
            'queueUrl' => admin_url('admin.php?page='.QueuePage::SLUG),
            'reviewUrl' => admin_url('admin.php?page='.ReviewPage::SLUG.'&post='),
        ]).';', 'before');
    }
}
