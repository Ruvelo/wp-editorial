<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Admin;

/**
 * One-time messages after a form posts and redirects, shown on the
 * plugin's own screens only.
 */
final class Notice
{
    public static function flash(string $type, string $message): void
    {
        set_transient('ruvelo_editorial_notice_'.get_current_user_id(), ['type' => $type, 'message' => $message], MINUTE_IN_SECONDS * 5);
    }

    public static function render(): void
    {
        $key = 'ruvelo_editorial_notice_'.get_current_user_id();
        $notice = get_transient($key);
        if (! is_array($notice) || ! isset($notice['message'])) {
            return;
        }
        delete_transient($key);
        $type = ($notice['type'] ?? '') === 'error' ? 'error' : 'success';
        printf(
            '<div class="notice notice-%s ruvelo-ed-notice" role="%s"><p>%s</p></div>',
            esc_attr($type),
            $type === 'error' ? 'alert' : 'status',
            esc_html((string) $notice['message'])
        );
    }
}
