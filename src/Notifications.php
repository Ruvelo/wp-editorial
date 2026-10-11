<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial;

use WP_Post;
use WP_User;

/**
 * Telling people. Every review event queues one row per recipient; WP-Cron
 * sends each person one email a few minutes later with everything that
 * happened meanwhile, so a busy morning is one email, not twelve.
 *
 * When a Ruvelo Inbox is active (anything listening on `ruvelo_inbox_notify`),
 * the same events land there straight away.
 */
final class Notifications
{
    public const CRON_HOOK = 'ruvelo_editorial_send_notifications';

    public const USER_OPTOUT = 'ruvelo_editorial_emails';

    public const EVENTS = ['requested', 'approved', 'fully_approved', 'changes_requested', 'withdrawn', 'override'];

    private static bool $paused = false;

    /**
     * Stops notifying while true: for imports and the demo.
     */
    public static function pause(bool $paused = true): void
    {
        self::$paused = $paused;
    }

    /**
     * @param list<int> $recipients
     */
    public static function queue(array $recipients, string $event, int $postId, int $actor, string $message): int
    {
        global $wpdb;

        $recipients = array_values(array_filter(array_unique(array_map('intval', $recipients)), static fn (int $id): bool => $id > 0 && $id !== $actor && get_userdata($id) instanceof WP_User));
        if (self::$paused || $recipients === []) {
            return 0;
        }

        /**
         * Fires for every review notification, before email and inbox
         * delivery: hook in to send it somewhere else (Slack, a webhook).
         *
         * @param list<int> $recipients User ids.
         * @param string    $event      requested, approved, fully_approved, changes_requested, withdrawn or override.
         * @param int       $postId
         * @param int       $actor      Who did it.
         * @param string    $message    Their message, if any.
         */
        do_action('ruvelo_editorial_notification', $recipients, $event, $postId, $actor, $message);

        if (Settings::bool('inbox') && has_action('ruvelo_inbox_notify')) {
            $inbox = self::inboxMessage($event, $postId, $actor, $message);

            /**
             * Delivers to Ruvelo Inbox (or anything implementing the same
             * action): user ids and a message with title, body, url, icon and actor.
             *
             * @param list<int>            $recipients
             * @param array<string, mixed> $inbox
             */
            do_action('ruvelo_inbox_notify', $recipients, $inbox);
        }

        if (! Settings::bool('emails')) {
            return 0;
        }

        $queued = 0;
        foreach ($recipients as $userId) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
            $queued += (int) $wpdb->insert(Install::outboxTable(), [
                'user_id' => $userId,
                'post_id' => $postId,
                'event' => $event,
                'actor_id' => $actor,
                'message' => $message,
                'created_at' => current_time('mysql', true),
            ], ['%d', '%d', '%s', '%d', '%s', '%s']);
        }
        self::schedule(max(0, Settings::int('digest_minutes')) * MINUTE_IN_SECONDS);

        return $queued;
    }

    public static function schedule(int $delay): void
    {
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + max(1, $delay), self::CRON_HOOK);
        }
    }

    public static function pending(): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE sent_at IS NULL', Install::outboxTable()));
    }

    public static function cron(): void
    {
        self::run();
    }

    /**
     * Sends one digest to each of up to `batch_size` people, then schedules
     * itself again if anyone is still waiting.
     *
     * @return int Emails sent.
     */
    public static function run(?int $batch = null): int
    {
        global $wpdb;

        $table = Install::outboxTable();
        $batch = $batch ?? max(1, Settings::int('batch_size'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $users = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT user_id FROM %i WHERE sent_at IS NULL ORDER BY user_id ASC LIMIT %d', $table, $batch));
        $sent = 0;
        foreach ($users as $userId) {
            $sent += self::sendDigest((int) $userId) ? 1 : 0;
        }

        // Keep a month of sent rows for troubleshooting, no more.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE sent_at IS NOT NULL AND sent_at < %s', $table, gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)));

        if (self::pending() > 0) {
            self::schedule(MINUTE_IN_SECONDS);
        }

        return $sent;
    }

    private static function sendDigest(int $userId): bool
    {
        global $wpdb;

        $table = Install::outboxTable();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE user_id = %d AND sent_at IS NULL ORDER BY id ASC', $table, $userId), ARRAY_A);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], is_array($rows) ? $rows : []);
        if ($ids === []) {
            return false;
        }
        $markSent = static function () use ($wpdb, $table, $ids): void {
            $in = implode(',', array_fill(0, count($ids), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders built above.
            $wpdb->query($wpdb->prepare("UPDATE %i SET sent_at = %s WHERE id IN ($in)", $table, current_time('mysql', true), ...$ids));
        };

        $user = get_userdata($userId);
        if (! $user instanceof WP_User || $user->user_email === '' || get_user_meta($userId, self::USER_OPTOUT, true) === 'off') {
            $markSent();

            return false;
        }

        $switched = switch_to_user_locale($userId);
        $items = [];
        foreach ((array) $rows as $row) {
            $post = get_post((int) $row['post_id']);
            if (! $post instanceof WP_Post || $post->post_status === 'trash') {
                continue;
            }
            $items[] = [
                'headline' => self::headline((string) $row['event'], $post, (int) $row['actor_id']),
                'message' => (string) $row['message'],
                'url' => self::editUrl($post),
                'event' => (string) $row['event'],
                'post_id' => $post->ID,
            ];
        }
        if ($items === []) {
            $markSent();
            if ($switched) {
                restore_previous_locale();
            }

            return false;
        }

        $blog = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $subject = count($items) === 1
            ? sprintf('[%s] %s', $blog, $items[0]['headline'])
            : sprintf(
                /* translators: 1: site name, 2: number of updates */
                __('[%1$s] %2$d review updates', 'ruvelo-editorial'),
                $blog,
                count($items)
            );

        $lines = [];
        foreach ($items as $item) {
            $lines[] = $item['headline'];
            if ($item['message'] !== '') {
                $lines[] = '';
                foreach (explode("\n", $item['message']) as $line) {
                    $lines[] = '> '.$line;
                }
            }
            $lines[] = '';
            $lines[] = __('Open it:', 'ruvelo-editorial').' '.$item['url'];
            $lines[] = '';
            $lines[] = '---';
            $lines[] = '';
        }
        $lines[] = __('Everything waiting for you:', 'ruvelo-editorial').' '.admin_url('admin.php?page='.Admin\QueuePage::SLUG);
        $lines[] = __('Stop these emails on your profile page:', 'ruvelo-editorial').' '.admin_url('profile.php#ruvelo-editorial');

        $email = [
            'to' => $user->user_email,
            'subject' => $subject,
            'message' => implode("\n", $lines),
            'headers' => ['Content-Type: text/plain; charset="'.get_option('blog_charset').'"'],
        ];

        /**
         * Filters a review digest email before it's sent.
         *
         * @param array{to: string, subject: string, message: string, headers: list<string>} $email
         * @param WP_User                   $user
         * @param list<array<string, mixed>> $items One per event: headline, message, url, event, post_id.
         */
        $email = apply_filters('ruvelo_editorial_email', $email, $user, $items);
        $sent = is_array($email) && wp_mail((string) $email['to'], (string) $email['subject'], (string) $email['message'], $email['headers'] ?? []);
        $markSent();
        if ($switched) {
            restore_previous_locale();
        }

        /**
         * Fires after a digest email is sent (or fails).
         *
         * @param int                        $userId
         * @param bool                       $sent
         * @param list<array<string, mixed>> $items
         */
        do_action('ruvelo_editorial_email_sent', $userId, $sent, $items);

        return $sent;
    }

    public static function headline(string $event, WP_Post $post, int $actor): string
    {
        $title = wp_specialchars_decode(get_the_title($post) !== '' ? get_the_title($post) : __('(no title)', 'ruvelo-editorial'), ENT_QUOTES);
        $who = People::name($actor);
        switch ($event) {
            case 'requested':
                $due = Review::due($post->ID);
                if ($due !== '') {
                    /* translators: 1: person, 2: post title, 3: due date */
                    return sprintf(__('%1$s asked you to review “%2$s” by %3$s.', 'ruvelo-editorial'), $who, $title, wp_date((string) get_option('date_format'), (int) strtotime($due.' 12:00:00')));
                }

                /* translators: 1: person, 2: post title */
                return sprintf(__('%1$s asked you to review “%2$s”.', 'ruvelo-editorial'), $who, $title);
            case 'approved':
                /* translators: 1: reviewer, 2: post title, 3: approvals so far, 4: approvals needed */
                return sprintf(__('%1$s approved “%2$s” (%3$d of %4$d approvals).', 'ruvelo-editorial'), $who, $title, count(Review::approvedBy($post->ID)), Review::requiredApprovals($post));
            case 'fully_approved':
                /* translators: %s: post title */
                return sprintf(__('“%s” is approved and ready to publish.', 'ruvelo-editorial'), $title);
            case 'changes_requested':
                /* translators: 1: reviewer, 2: post title */
                return sprintf(__('%1$s requested changes on “%2$s”.', 'ruvelo-editorial'), $who, $title);
            case 'withdrawn':
                /* translators: 1: person, 2: post title */
                return sprintf(__('%1$s took “%2$s” out of review. Nothing to do for now.', 'ruvelo-editorial'), $who, $title);
            case 'override':
                /* translators: 1: person, 2: post title */
                return sprintf(__('%1$s published “%2$s” without its approvals.', 'ruvelo-editorial'), $who, $title);
            default:
                /* translators: %s: post title */
                return sprintf(__('Review update on “%s”.', 'ruvelo-editorial'), $title);
        }
    }

    /**
     * Built by hand: get_edit_post_link() answers for the current user, and
     * under WP-Cron there is none.
     */
    public static function editUrl(WP_Post $post): string
    {
        $type = get_post_type_object($post->post_type);
        $link = $type !== null && is_string($type->_edit_link) && $type->_edit_link !== '' ? $type->_edit_link : 'post.php?post=%d';

        return admin_url(sprintf($link.'&action=edit', $post->ID));
    }

    /**
     * @return array<string, mixed>
     */
    public static function inboxMessage(string $event, int $postId, int $actor, string $message): array
    {
        $post = get_post($postId);
        $inbox = [
            'title' => $post instanceof WP_Post ? self::headline($event, $post, $actor) : '',
            'body' => $message,
            'url' => $post instanceof WP_Post ? self::editUrl($post) : '',
            'icon' => '',
            'actor' => People::name($actor),
            'actor_id' => $actor,
            'type' => 'ruvelo-editorial/'.$event,
            'source' => 'ruvelo-editorial',
            'object_id' => $postId,
        ];

        /**
         * Filters what goes to Ruvelo Inbox for a review event.
         *
         * @param array<string, mixed> $inbox
         * @param string               $event
         * @param int                  $postId
         */
        $filtered = apply_filters('ruvelo_editorial_inbox_message', $inbox, $event, $postId);

        return is_array($filtered) ? $filtered : $inbox;
    }
}
