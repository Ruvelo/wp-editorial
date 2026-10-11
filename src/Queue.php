<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial;

use WP_Post;
use WP_Query;

/**
 * The review queue's views and counts, as WP_Query meta queries on the
 * plugin's own keys:
 *
 * - mine: in review and waiting for this user's decision;
 * - changes: changes requested on this user's posts;
 * - approved: approved and not yet published or scheduled (all of them for
 *   people who edit others' posts, otherwise their own);
 * - all: everything in review, with changes requested, or approved and unpublished.
 *
 * Published and scheduled posts are never in the queue: reviews happen before.
 */
final class Queue
{
    public const VIEWS = ['mine', 'changes', 'approved', 'all'];

    public const CACHE_GROUP = 'ruvelo_editorial';

    /**
     * @param array<string, mixed> $filters post_type, author, reviewer, overdue, search, orderby, order, page, per_page
     * @return array{posts: list<WP_Post>, total: int, pages: int}
     */
    public static function query(string $view, int $userId, array $filters = []): array
    {
        $query = new WP_Query(self::args($view, $userId, $filters));
        $posts = array_values(array_filter($query->posts, static fn ($post): bool => $post instanceof WP_Post));

        return ['posts' => $posts, 'total' => (int) $query->found_posts, 'pages' => (int) $query->max_num_pages];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function args(string $view, int $userId, array $filters = []): array
    {
        $types = Settings::enabledTypes();
        $type = is_string($filters['post_type'] ?? null) ? $filters['post_type'] : '';
        $meta = ['relation' => 'AND'];
        $args = [
            'post_type' => $type !== '' && in_array($type, $types, true) ? $type : ($types !== [] ? $types : ['__none__']),
            'post_status' => ['draft', 'pending', 'private'],
            'posts_per_page' => max(1, min(100, (int) ($filters['per_page'] ?? 20))),
            'paged' => max(1, (int) ($filters['page'] ?? 1)),
            'ignore_sticky_posts' => true,
            'no_found_rows' => false,
        ];

        switch ($view) {
            case 'mine':
                $meta[] = ['key' => Review::STATE, 'value' => States::IN_REVIEW];
                $meta[] = ['key' => Review::PENDING, 'value' => $userId, 'type' => 'NUMERIC'];
                break;
            case 'changes':
                $meta[] = ['key' => Review::STATE, 'value' => States::CHANGES];
                $args['author'] = $userId;
                break;
            case 'approved':
                $meta[] = ['key' => Review::STATE, 'value' => States::APPROVED];
                if (! self::seesEveryone($userId)) {
                    $args['author'] = $userId;
                }
                break;
            default:
                $meta[] = ['key' => Review::STATE, 'value' => [States::IN_REVIEW, States::CHANGES, States::APPROVED], 'compare' => 'IN'];
                if (! self::seesEveryone($userId)) {
                    $args['author'] = $userId;
                }
        }

        $author = (int) ($filters['author'] ?? 0);
        if ($author > 0 && ! isset($args['author'])) {
            $args['author'] = $author;
        }
        $reviewer = (int) ($filters['reviewer'] ?? 0);
        if ($reviewer > 0) {
            $meta[] = ['key' => Review::REVIEWER, 'value' => $reviewer, 'type' => 'NUMERIC'];
        }
        if (! empty($filters['overdue'])) {
            $meta[] = ['key' => Review::DUE, 'value' => current_time('Y-m-d'), 'compare' => '<', 'type' => 'CHAR'];
            $meta[] = ['key' => Review::DUE, 'value' => '', 'compare' => '!='];
        }
        $search = is_string($filters['search'] ?? null) ? trim($filters['search']) : '';
        if ($search !== '') {
            $args['s'] = $search;
        }

        $orderby = is_string($filters['orderby'] ?? null) ? $filters['orderby'] : 'updated';
        $order = strtoupper(is_string($filters['order'] ?? null) ? $filters['order'] : '') === 'ASC' ? 'ASC' : 'DESC';
        if ($orderby === 'title') {
            $args['orderby'] = 'title';
            $args['order'] = $order;
        } elseif ($orderby === 'due') {
            $args['orderby'] = ['meta_value' => $order, 'ID' => 'DESC']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Sorting by due date, not filtering.
            $args['meta_key'] = Review::DUE; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Sorting by due date.
        } else {
            $args['orderby'] = ['meta_value_num' => $order, 'ID' => 'DESC'];
            $args['meta_key'] = Review::UPDATED; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Most recent review activity first.
        }
        $args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The review queue is a meta query by design.

        /**
         * Filters the WP_Query arguments of a review queue view.
         *
         * @param array<string, mixed> $args
         * @param string               $view
         * @param int                  $userId
         * @param array<string, mixed> $filters
         */
        $filtered = apply_filters('ruvelo_editorial_queue_args', $args, $view, $userId, $filters);

        return is_array($filtered) ? $filtered : $args;
    }

    /**
     * How many posts each view holds for this user. Cached until the next
     * review event.
     *
     * @return array{mine: int, changes: int, approved: int, all: int}
     */
    public static function counts(int $userId): array
    {
        $key = 'counts:'.$userId.':'.wp_cache_get_last_changed(self::CACHE_GROUP);
        $cached = wp_cache_get($key, self::CACHE_GROUP);
        if (is_array($cached) && isset($cached['mine'], $cached['changes'], $cached['approved'], $cached['all'])) {
            return ['mine' => (int) $cached['mine'], 'changes' => (int) $cached['changes'], 'approved' => (int) $cached['approved'], 'all' => (int) $cached['all']];
        }
        $counts = ['mine' => 0, 'changes' => 0, 'approved' => 0, 'all' => 0];
        if ($userId > 0) {
            foreach (self::VIEWS as $view) {
                $args = self::args($view, $userId, ['per_page' => 1]);
                $args['fields'] = 'ids';
                $query = new WP_Query($args);
                $counts[$view] = (int) $query->found_posts;
            }
        }
        wp_cache_set($key, $counts, self::CACHE_GROUP, HOUR_IN_SECONDS);

        return $counts;
    }

    public static function flush(): void
    {
        wp_cache_set_last_changed(self::CACHE_GROUP);
    }

    public static function seesEveryone(int $userId): bool
    {
        return user_can($userId, 'edit_others_posts') || user_can($userId, Settings::CAP_REVIEW);
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'mine' => __('Waiting for my review', 'ruvelo-editorial'),
            'changes' => __('Changes requested on my posts', 'ruvelo-editorial'),
            'approved' => __('Approved, ready to publish', 'ruvelo-editorial'),
            'all' => __('Everything in review', 'ruvelo-editorial'),
        ];
    }
}
