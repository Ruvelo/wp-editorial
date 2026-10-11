<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial;

use WP_User;

/**
 * Personal data tools (Tools → Export / Erase Personal Data): the review
 * decisions and messages someone left, and erasing their name from them.
 */
final class Privacy
{
    private const PER_PAGE = 100;

    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'exporters']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'erasers']);
    }

    /**
     * @param array<string, mixed> $exporters
     * @return array<string, mixed>
     */
    public static function exporters(array $exporters): array
    {
        $exporters['ruvelo-editorial'] = ['exporter_friendly_name' => __('Reviews (Ruvelo Editorial)', 'ruvelo-editorial'), 'callback' => [self::class, 'export']];

        return $exporters;
    }

    /**
     * @param array<string, mixed> $erasers
     * @return array<string, mixed>
     */
    public static function erasers(array $erasers): array
    {
        $erasers['ruvelo-editorial'] = ['eraser_friendly_name' => __('Reviews (Ruvelo Editorial)', 'ruvelo-editorial'), 'callback' => [self::class, 'erase']];

        return $erasers;
    }

    /**
     * @return array{data: list<array<string, mixed>>, done: bool}
     */
    public static function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (! $user instanceof WP_User) {
            return ['data' => [], 'done' => true];
        }
        $ids = self::posts($user->ID, $page);
        $data = [];
        foreach ($ids as $postId) {
            foreach (History::all($postId) as $index => $event) {
                if ($event['user'] !== $user->ID) {
                    continue;
                }
                $data[] = [
                    'group_id' => 'ruvelo-editorial',
                    'group_label' => __('Reviews', 'ruvelo-editorial'),
                    'item_id' => 'ruvelo-editorial-'.$postId.'-'.$index,
                    'data' => [
                        ['name' => __('Post', 'ruvelo-editorial'), 'value' => get_the_title($postId)],
                        ['name' => __('What happened', 'ruvelo-editorial'), 'value' => History::describe($event)],
                        ['name' => __('Message', 'ruvelo-editorial'), 'value' => $event['note']],
                        ['name' => __('Date', 'ruvelo-editorial'), 'value' => gmdate('c', $event['at'])],
                    ],
                ];
            }
        }

        return ['data' => $data, 'done' => count($ids) < self::PER_PAGE];
    }

    /**
     * Keeps the history (it is the post's editorial record) but removes the
     * person: their events become "A former user" with no message.
     *
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public static function erase(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (! $user instanceof WP_User) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }
        global $wpdb;

        $ids = self::posts($user->ID, $page);
        $removed = false;
        foreach ($ids as $postId) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rewriting the plugin's own rows in place keeps their order.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $postId, History::META), ARRAY_A);
            foreach (is_array($rows) ? $rows : [] as $row) {
                $event = maybe_unserialize((string) $row['meta_value']);
                if (is_array($event) && (int) ($event['user'] ?? 0) === $user->ID) {
                    $event['user'] = -1;
                    $event['note'] = '';
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One row, by primary key.
                    $wpdb->update($wpdb->postmeta, ['meta_value' => maybe_serialize($event)], ['meta_id' => (int) $row['meta_id']]);
                    $removed = true;
                }
            }
        }

        foreach ($ids as $postId) {
            wp_cache_delete($postId, 'post_meta');
        }

        return ['items_removed' => $removed, 'items_retained' => false, 'messages' => [], 'done' => count($ids) < self::PER_PAGE];
    }

    /**
     * Posts this person wrote, asked about or reviewed.
     *
     * @return list<int>
     */
    private static function posts(int $userId, int $page): array
    {
        $ids = get_posts([
            'post_type' => 'any',
            'post_status' => 'any',
            'fields' => 'ids',
            'numberposts' => self::PER_PAGE,
            'paged' => max(1, $page),
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Personal data requests are rare.
                'relation' => 'OR',
                ['key' => Review::REVIEWER, 'value' => $userId, 'type' => 'NUMERIC'],
                ['key' => Review::REQUESTED_BY, 'value' => $userId, 'type' => 'NUMERIC'],
                ['key' => History::META, 'value' => 's:4:"user";i:'.$userId.';', 'compare' => 'LIKE'],
            ],
        ]);

        return array_values(array_map('intval', $ids));
    }
}
