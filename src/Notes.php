<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial;

use WP_Comment;
use WP_Error;
use WP_Post;

/**
 * Core Notes (WordPress 6.9+), read and written the way the block editor
 * does it, so a note made here is an ordinary note in the editor's Notes
 * sidebar:
 *
 * - a note is a comment with `comment_type = 'note'`; replies set `comment_parent`;
 * - an open thread's top-level note is held (`comment_approved = '0'`), a
 *   resolved one approved ('1'), and the resolve/reopen action itself is an
 *   empty reply carrying `_wp_note_status` = resolved | reopen;
 * - a thread is anchored to a block by the block's `metadata.noteId`
 *   attribute: one id in 7.0, a list of ids from 7.1 (which also adds
 *   inline `<mark class="wp-note">` anchors and @mentions).
 *
 * The plugin never copies or replaces notes; it counts them, links a review
 * decision to one, and can start a thread for a "changes requested" message.
 */
final class Notes
{
    public const TYPE = 'note';

    /**
     * Comment types counted as notes. Filterable so a future core type
     * (core's Suggestions, say) can join the open count.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        $types = apply_filters('ruvelo_editorial_note_types', [self::TYPE]);

        return array_values(array_filter(is_array($types) ? $types : [self::TYPE], 'is_string'));
    }

    public static function supported(string $postType): bool
    {
        $supports = get_all_post_type_supports($postType);
        if (! isset($supports['editor']) || ! is_array($supports['editor'])) {
            return false;
        }
        foreach ($supports['editor'] as $item) {
            if (is_array($item) && ! empty($item['notes'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turns core Notes on for a post type the workflow covers (post and page
     * have them already).
     */
    public static function enableFor(string $postType): void
    {
        if (! post_type_supports($postType, 'editor') || self::supported($postType)) {
            return;
        }
        /**
         * Whether to switch core Notes on for a post type when the review
         * workflow covers it.
         *
         * @param bool   $enable
         * @param string $postType
         */
        if (apply_filters('ruvelo_editorial_enable_notes', true, $postType)) {
            add_post_type_support($postType, 'editor', ['notes' => true]);
        }
    }

    /**
     * Open (unresolved) note threads on a post.
     */
    public static function openCount(int $postId): int
    {
        return self::counts([$postId])[$postId]['open'] ?? 0;
    }

    /**
     * Open and total thread counts for several posts in one query.
     *
     * @param list<int> $postIds
     * @return array<int, array{open: int, total: int}>
     */
    public static function counts(array $postIds): array
    {
        global $wpdb;

        $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds))));
        $counts = [];
        foreach ($postIds as $id) {
            $counts[$id] = ['open' => 0, 'total' => 0];
        }
        if ($postIds === []) {
            return $counts;
        }

        $types = self::types();
        $ids = implode(',', array_fill(0, count($postIds), '%d'));
        $in = implode(',', array_fill(0, count($types), '%s'));
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One grouped count per screen; the placeholders are built above.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT comment_post_ID AS post_id, SUM(comment_approved = '0') AS open_count, COUNT(*) AS total FROM {$wpdb->comments} WHERE comment_post_ID IN ($ids) AND comment_type IN ($in) AND comment_parent = 0 AND comment_approved IN ('0', '1') GROUP BY comment_post_ID",
            ...array_merge($postIds, $types)
        ), ARRAY_A);
        // phpcs:enable
        foreach (is_array($rows) ? $rows : [] as $row) {
            $counts[(int) $row['post_id']] = ['open' => (int) $row['open_count'], 'total' => (int) $row['total']];
        }

        return $counts;
    }

    /**
     * A note thread on this post, or an error saying why it can't be linked.
     */
    public static function find(int $noteId, int $postId): WP_Comment|WP_Error
    {
        $note = get_comment($noteId);
        if (! $note instanceof WP_Comment || ! in_array($note->comment_type, self::types(), true) || (int) $note->comment_post_ID !== $postId) {
            return new WP_Error('ruvelo_editorial_note_not_found', __('That note isn’t on this post.', 'ruvelo-editorial'), ['status' => 400]);
        }

        // Link the thread, not a reply inside it.
        if ((int) $note->comment_parent > 0) {
            $parent = get_comment((int) $note->comment_parent);
            if ($parent instanceof WP_Comment) {
                return $parent;
            }
        }

        return $note;
    }

    public static function isResolved(WP_Comment $note): bool
    {
        return $note->comment_approved === '1';
    }

    /**
     * Starts a note thread as `$userId` from a plain-text message, anchored
     * to the post's first block that can take it. Core's own REST hooks (which email the post author
     * and anyone mentioned) don't run: the review email covers it.
     */
    public static function create(int $postId, int $userId, string $content): int|WP_Error
    {
        $post = get_post($postId);
        $user = get_userdata($userId);
        $content = trim($content);
        if (! $post instanceof WP_Post || $user === false) {
            return new WP_Error('ruvelo_editorial_note_failed', __('The note couldn’t be added to this post.', 'ruvelo-editorial'), ['status' => 400]);
        }
        if ($content === '') {
            return new WP_Error('ruvelo_editorial_note_empty', __('Write a message to leave it as a note.', 'ruvelo-editorial'), ['status' => 400]);
        }

        $id = wp_insert_comment([
            'comment_post_ID' => $postId,
            'comment_type' => self::TYPE,
            'comment_approved' => '0',
            'comment_parent' => 0,
            'comment_content' => esc_html($content),
            'comment_author' => $user->display_name,
            'comment_author_email' => $user->user_email,
            'user_id' => $userId,
            'comment_agent' => 'ruvelo-editorial',
        ]);
        if (! is_int($id) || $id <= 0) {
            return new WP_Error('ruvelo_editorial_note_failed', __('The note couldn’t be added to this post.', 'ruvelo-editorial'), ['status' => 500]);
        }

        self::anchor($post, $id);

        /**
         * Fires after the plugin starts a core note thread.
         *
         * @param int $noteId
         * @param int $postId
         * @param int $userId
         */
        do_action('ruvelo_editorial_note_created', $id, $postId, $userId);

        return $id;
    }

    /**
     * Anchors a thread to the first top-level block without a note, the way
     * the editor stores it. From 7.1 a block can hold several notes, so the
     * first block takes it when every block already has one. Returns false
     * when the content has no blocks (the note then shows as unanchored).
     */
    public static function anchor(WP_Post $post, int $noteId): bool
    {
        if (! has_blocks($post->post_content)) {
            return false;
        }
        $blocks = parse_blocks($post->post_content);
        $target = null;
        foreach ($blocks as $index => $block) {
            if ($block['blockName'] !== null && ! isset($block['attrs']['metadata']['noteId'])) {
                $target = $index;
                break;
            }
        }
        if ($target === null && self::multipleNotesPerBlock()) {
            foreach ($blocks as $index => $block) {
                if ($block['blockName'] !== null) {
                    $target = $index;
                    break;
                }
            }
        }
        if ($target === null) {
            return false;
        }

        $metadata = isset($blocks[$target]['attrs']['metadata']) && is_array($blocks[$target]['attrs']['metadata']) ? $blocks[$target]['attrs']['metadata'] : [];
        if (isset($metadata['noteId'])) {
            $ids = is_array($metadata['noteId']) ? $metadata['noteId'] : [$metadata['noteId']];
            $ids[] = $noteId;
            $metadata['noteId'] = array_values(array_unique(array_map('intval', $ids)));
        } else {
            $metadata['noteId'] = $noteId;
        }
        $block = $blocks[$target];
        $block['attrs']['metadata'] = $metadata;
        $blocks[$target] = $block;

        global $wpdb;
        // Only the block's comment delimiter changes. A direct update keeps
        // kses, revisions and save hooks out of what is a metadata change.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update($wpdb->posts, ['post_content' => serialize_blocks($blocks)], ['ID' => $post->ID]);
        clean_post_cache($post->ID);

        return true;
    }

    /**
     * WordPress 7.1 stores a list of note ids per block; 7.0 one.
     */
    public static function multipleNotesPerBlock(): bool
    {
        return function_exists('wp_get_note_mentioned_user_ids');
    }

    /**
     * The editor link that opens a post with its Notes.
     */
    public static function editorUrl(int $postId): string
    {
        return (string) get_edit_post_link($postId, 'url');
    }
}
