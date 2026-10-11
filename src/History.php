<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial;

/**
 * The review log of a post: who did what, when, and what they said. One
 * post meta row per event, so it travels with the post (exports, staging
 * copies) and is never rewritten.
 *
 * @phpstan-type Event array{type: string, user: int, at: int, note: string, from: string, to: string, round: int, reviewers: list<int>, note_id: int, due: string}
 */
final class History
{
    public const META = '_ruvelo_editorial_event';

    /**
     * @param array<string, mixed> $event
     * @return Event
     */
    public static function add(int $postId, array $event): array
    {
        $clean = self::normalise($event + ['at' => Clock::now()]);
        add_post_meta($postId, self::META, wp_slash($clean));

        return $clean;
    }

    /**
     * Oldest first.
     *
     * @return list<Event>
     */
    public static function all(int $postId): array
    {
        global $wpdb;

        // Meta ids give the true order when two events share a second.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Ordered read of the plugin's own meta rows.
        $rows = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC", $postId, self::META));
        $events = [];
        foreach ($rows as $row) {
            $value = maybe_unserialize((string) $row);
            if (is_array($value)) {
                $events[] = self::normalise($value);
            }
        }

        return $events;
    }

    /**
     * @return Event|null
     */
    public static function last(int $postId, ?string $type = null): ?array
    {
        $events = self::all($postId);
        for ($i = count($events) - 1; $i >= 0; $i--) {
            if ($type === null || $events[$i]['type'] === $type) {
                return $events[$i];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $event
     * @return Event
     */
    public static function normalise(array $event): array
    {
        $reviewers = isset($event['reviewers']) && is_array($event['reviewers']) ? array_values(array_map('intval', $event['reviewers'])) : [];

        return [
            'type' => is_string($event['type'] ?? null) ? $event['type'] : 'unknown',
            'user' => (int) ($event['user'] ?? 0),
            'at' => (int) ($event['at'] ?? 0),
            'note' => is_string($event['note'] ?? null) ? $event['note'] : '',
            'from' => is_string($event['from'] ?? null) ? $event['from'] : '',
            'to' => is_string($event['to'] ?? null) ? $event['to'] : '',
            'round' => (int) ($event['round'] ?? 0),
            'reviewers' => $reviewers,
            'note_id' => (int) ($event['note_id'] ?? 0),
            'due' => is_string($event['due'] ?? null) ? $event['due'] : '',
        ];
    }

    /**
     * One sentence for the log: "Inès Laurent approved."
     *
     * @param Event $event
     */
    public static function describe(array $event): string
    {
        $who = People::name($event['user']);
        $names = People::list($event['reviewers']);

        switch ($event['type']) {
            case 'requested':
                if ($event['round'] > 1) {
                    /* translators: 1: person asking, 2: reviewers' names */
                    return sprintf(__('%1$s asked %2$s to review it again.', 'ruvelo-editorial'), $who, $names);
                }

                /* translators: 1: person asking, 2: reviewers' names */
                return sprintf(__('%1$s asked %2$s for a review.', 'ruvelo-editorial'), $who, $names);
            case 'reviewers_changed':
                /* translators: 1: person, 2: reviewers' names */
                return sprintf(__('%1$s changed the reviewers to %2$s.', 'ruvelo-editorial'), $who, $names);
            case 'approved':
                /* translators: %s: reviewer */
                return sprintf(__('%s approved.', 'ruvelo-editorial'), $who);
            case 'fully_approved':
                return __('It has the approvals it needs and is ready to publish.', 'ruvelo-editorial');
            case 'changes_requested':
                /* translators: %s: reviewer */
                return sprintf(__('%s requested changes.', 'ruvelo-editorial'), $who);
            case 'withdrawn':
                /* translators: %s: person */
                return sprintf(__('%s took it out of review.', 'ruvelo-editorial'), $who);
            case 'published':
                /* translators: %s: person */
                return sprintf(__('%s published it.', 'ruvelo-editorial'), $who);
            case 'scheduled':
                /* translators: %s: person */
                return sprintf(__('%s scheduled it.', 'ruvelo-editorial'), $who);
            case 'override':
                /* translators: %s: person */
                return sprintf(__('%s published it without the approvals it needed.', 'ruvelo-editorial'), $who);
            case 'published_unapproved':
                return __('It was published without the approvals it needed, outside the editor.', 'ruvelo-editorial');
            default:
                return $event['type'];
        }
    }
}
