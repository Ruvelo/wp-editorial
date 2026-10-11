<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial;

use WP_Post;
use WP_User;

/**
 * Who can review, and how people are shown: by name and initials, never
 * by Gravatar (the plugin makes no remote requests, even from the browser).
 */
final class People
{
    public static function name(int $userId): string
    {
        if ($userId === 0) {
            return __('WordPress', 'ruvelo-editorial');
        }
        $user = get_userdata($userId);

        return $user instanceof WP_User ? $user->display_name : __('A former user', 'ruvelo-editorial');
    }

    /**
     * @param list<int> $userIds
     */
    public static function list(array $userIds): string
    {
        $names = array_map([self::class, 'name'], $userIds);

        return $names === [] ? __('nobody', 'ruvelo-editorial') : wp_sprintf('%l', $names);
    }

    public static function initials(int $userId): string
    {
        $name = trim(self::name($userId));
        $parts = preg_split('/\s+/u', $name) ?: [];
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? (string) end($parts) : '';
        $initials = mb_substr($first, 0, 1).mb_substr($last, 0, 1);

        return mb_strtoupper($initials !== '' ? $initials : '?');
    }

    /**
     * @return array{id: int, name: string, initials: string}
     */
    public static function card(int $userId): array
    {
        return ['id' => $userId, 'name' => self::name($userId), 'initials' => self::initials($userId)];
    }

    /**
     * People who may be asked to review a post: the review capability, and
     * edit access to the post so they can open it and read its notes. The
     * author is left out unless `ruvelo_editorial_allow_self_review` says so.
     *
     * @return list<WP_User>
     */
    public static function reviewers(?WP_Post $post = null, string $postType = 'post'): array
    {
        $users = get_users(['capability' => Settings::CAP_REVIEW, 'orderby' => 'display_name', 'order' => 'ASC', 'number' => 200]);
        $list = [];
        foreach ($users as $user) {
            if ($post instanceof WP_Post ? self::canReview($user->ID, $post) : user_can($user, self::editOthersCap($postType))) {
                $list[] = $user;
            }
        }

        /**
         * Filters the people who can be asked to review a post.
         *
         * @param list<WP_User>  $list
         * @param WP_Post|null   $post
         * @param string         $postType
         */
        $filtered = apply_filters('ruvelo_editorial_reviewers', $list, $post, $postType);

        return is_array($filtered) ? array_values(array_filter($filtered, static fn ($user): bool => $user instanceof WP_User)) : $list;
    }

    public static function canReview(int $userId, WP_Post $post): bool
    {
        $can = $userId > 0
            && user_can($userId, Settings::CAP_REVIEW)
            && user_can($userId, 'edit_post', $post->ID)
            && ($userId !== (int) $post->post_author || self::selfReview($post));

        /**
         * Filters whether a user can review a post.
         *
         * @param bool    $can
         * @param int     $userId
         * @param WP_Post $post
         */
        return (bool) apply_filters('ruvelo_editorial_can_review', $can, $userId, $post);
    }

    private static function selfReview(WP_Post $post): bool
    {
        /**
         * Whether authors may review (and approve) their own posts. Off by default.
         *
         * @param bool    $allow
         * @param WP_Post $post
         */
        return (bool) apply_filters('ruvelo_editorial_allow_self_review', false, $post);
    }

    private static function editOthersCap(string $postType): string
    {
        $type = get_post_type_object($postType);

        return $type !== null ? (string) $type->cap->edit_others_posts : 'edit_others_posts';
    }
}
