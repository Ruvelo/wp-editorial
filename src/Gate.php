<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial;

use WP_Error;
use WP_Post;
use WP_REST_Request;

/**
 * Publish gates: with a gate on, a post of that type can't go from
 * unpublished to published or scheduled until it has its approvals (and,
 * optionally, no open notes).
 *
 * The block editor hears about it first (its panel explains and holds the
 * button), but the rule lives on the server: the REST API refuses the save,
 * and every other path through wp_insert_post() (classic editor, quick and
 * bulk edit, XML-RPC, `wp post update`) keeps the post's previous status.
 * Code that calls wp_publish_post() directly skips wp_insert_post(); that
 * publish is still logged as unapproved.
 *
 * People with the override capability can let one publish through with a
 * reason, which is logged and sent to the reviewers.
 */
final class Gate
{
    public const OVERRIDE = '_ruvelo_editorial_override';

    /** @var array<int, WP_Error> post id => why its publish was held */
    private static array $blocked = [];

    public static function register(): void
    {
        add_filter('wp_insert_post_data', [self::class, 'filterInsert'], 99, 4);
        add_action('transition_post_status', [self::class, 'onTransition'], 10, 3);
        add_action('rest_api_init', [self::class, 'registerRest'], 5);
        add_filter('redirect_post_location', [self::class, 'redirectLocation'], 10, 2);
    }

    public static function registerRest(): void
    {
        foreach (Settings::enabledTypes() as $type) {
            add_filter('rest_pre_insert_'.$type, [self::class, 'filterRest'], 10, 2);
        }
    }

    public static function applies(string $postType): bool
    {
        $config = Settings::forType($postType);

        return $config['enabled'] && $config['gate'];
    }

    /**
     * What the post still needs, in sentences. Empty when nothing.
     *
     * @return list<string>
     */
    public static function reasons(WP_Post $post): array
    {
        if (! self::applies($post->post_type)) {
            return [];
        }
        $reasons = [];
        $required = Review::requiredApprovals($post);
        $state = $post->ID > 0 ? Review::state($post->ID) : States::DRAFT;
        $have = $post->ID > 0 ? count(Review::approvedBy($post->ID)) : 0;
        if ($state === States::CHANGES) {
            $reasons[] = __('Changes were requested. Make them, then ask for a review again.', 'ruvelo-editorial');
        } elseif ($state !== States::APPROVED || $have < $required) {
            $reasons[] = $have > 0
                ? sprintf(
                    /* translators: 1: approvals needed, 2: approvals so far */
                    _n('It needs %1$d approval before it can be published (it has %2$d).', 'It needs %1$d approvals before it can be published (it has %2$d).', $required, 'ruvelo-editorial'),
                    $required,
                    $have
                )
                : sprintf(
                    /* translators: %d: approvals needed */
                    _n('It needs %d approval before it can be published.', 'It needs %d approvals before it can be published.', $required, 'ruvelo-editorial'),
                    $required
                );
        }
        if (Settings::forType($post->post_type)['resolve_notes'] && $post->ID > 0) {
            $open = Notes::openCount($post->ID);
            if ($open > 0) {
                $reasons[] = sprintf(
                    /* translators: %d: number of open notes */
                    _n('Resolve its %d open note first.', 'Resolve its %d open notes first.', $open, 'ruvelo-editorial'),
                    $open
                );
            }
        }

        return $reasons;
    }

    /**
     * Whether this user can publish this post now, and why not.
     *
     * @return array{applies: bool, passes: bool, reasons: list<string>, override: array{user: int, reason: string, at: int}|null}
     */
    public static function evaluate(WP_Post $post, int $userId): array
    {
        $applies = self::applies($post->post_type);
        $reasons = self::reasons($post);
        $override = $reasons !== [] ? self::override($post->ID, $userId) : null;
        $passes = $reasons === [] || $override !== null;

        if ($applies) {
            /**
             * Filters whether a post may be published or scheduled past its gate.
             *
             * @param bool         $passes
             * @param WP_Post      $post
             * @param int          $userId  Who is publishing (0 for none, e.g. WP-CLI without --user).
             * @param list<string> $reasons What it still needs.
             */
            $passes = (bool) apply_filters('ruvelo_editorial_gate_passes', $passes, $post, $userId, $reasons);
        }

        return ['applies' => $applies, 'passes' => $passes, 'reasons' => $reasons, 'override' => $override];
    }

    public static function check(WP_Post $post, int $userId): true|WP_Error
    {
        $result = self::evaluate($post, $userId);
        if ($result['passes']) {
            return true;
        }

        return new WP_Error('ruvelo_editorial_gate', implode(' ', $result['reasons']), ['status' => 403, 'reasons' => $result['reasons']]);
    }

    /**
     * For the editor panel and REST.
     *
     * @return array<string, mixed>
     */
    public static function status(WP_Post $post, int $viewer): array
    {
        $result = self::evaluate($post, $viewer);

        return [
            'enabled' => $result['applies'],
            'passes' => $result['passes'],
            'reasons' => $result['reasons'],
            'can_override' => $result['applies'] && $result['reasons'] !== [] && self::canOverride($post, $viewer),
            'override' => $result['override'] !== null ? ['user' => People::card($result['override']['user']), 'reason' => $result['override']['reason']] : null,
        ];
    }

    public static function canOverride(WP_Post $post, int $userId): bool
    {
        return $userId > 0 && user_can($userId, Settings::CAP_OVERRIDE) && user_can($userId, 'edit_post', $post->ID);
    }

    /**
     * Lets this user publish this post once past its gate, within the hour.
     * The reason is logged when they publish.
     */
    public static function allowOnce(int $postId, int $userId, string $reason): true|WP_Error
    {
        $post = get_post($postId);
        if (! $post instanceof WP_Post || ! Settings::enabled($post->post_type)) {
            return new WP_Error('ruvelo_editorial_not_found', __('That post doesn’t exist.', 'ruvelo-editorial'), ['status' => 404]);
        }
        if (! self::canOverride($post, $userId)) {
            return new WP_Error('ruvelo_editorial_forbidden', __('You can’t publish past the review gate.', 'ruvelo-editorial'), ['status' => 403]);
        }
        $reason = trim(sanitize_textarea_field($reason));
        if ($reason === '') {
            return new WP_Error('ruvelo_editorial_reason_required', __('Say why it should go out without its approvals. The reason is logged and sent to the reviewers.', 'ruvelo-editorial'), ['status' => 400]);
        }
        update_post_meta($postId, self::OVERRIDE, wp_slash(['user' => $userId, 'reason' => $reason, 'at' => time()]));

        return true;
    }

    /**
     * A live override by this user, if there is one.
     *
     * @return array{user: int, reason: string, at: int}|null
     */
    public static function override(int $postId, int $userId): ?array
    {
        if ($postId <= 0 || $userId <= 0) {
            return null;
        }
        $token = get_post_meta($postId, self::OVERRIDE, true);
        if (! is_array($token) || (int) ($token['user'] ?? 0) !== $userId || (int) ($token['at'] ?? 0) < time() - HOUR_IN_SECONDS) {
            return null;
        }
        $post = get_post($postId);
        if (! $post instanceof WP_Post || ! self::canOverride($post, $userId)) {
            return null;
        }

        return ['user' => $userId, 'reason' => (string) ($token['reason'] ?? ''), 'at' => (int) $token['at']];
    }

    /**
     * REST (the block editor saves here): refuse with the reason, so the
     * editor shows "Publishing failed. It needs 2 approvals…".
     *
     * @param \stdClass $prepared
     * @param WP_REST_Request<array<string, mixed>> $request
     * @return \stdClass|WP_Error
     */
    public static function filterRest($prepared, $request)
    {
        if (! is_object($prepared) || ! isset($prepared->post_status) || ! in_array($prepared->post_status, ['publish', 'future'], true)) {
            return $prepared;
        }
        $id = isset($prepared->ID) ? (int) $prepared->ID : 0;
        $post = self::subject($id, isset($prepared->post_type) ? (string) $prepared->post_type : (string) get_post_type($id), (int) ($prepared->post_author ?? get_current_user_id()));
        if ($post === null) {
            return $prepared;
        }
        $result = self::check($post, get_current_user_id());
        if ($result instanceof WP_Error) {
            /** This action is documented in src/Gate.php */
            do_action('ruvelo_editorial_publish_blocked', $id, $result);

            return $result;
        }

        return $prepared;
    }

    /**
     * Every other path: keep the previous status instead of publishing.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $postarr
     * @param array<string, mixed> $unsanitized
     * @return array<string, mixed>
     */
    public static function filterInsert($data, $postarr, $unsanitized = [], bool $update = false): array
    {
        $status = is_string($data['post_status'] ?? null) ? $data['post_status'] : '';
        if (! in_array($status, ['publish', 'future'], true) || wp_installing() || (defined('WP_IMPORTING') && WP_IMPORTING)) {
            return $data;
        }
        $id = (int) ($postarr['ID'] ?? 0);
        $post = self::subject($id, (string) ($data['post_type'] ?? ''), (int) ($data['post_author'] ?? 0));
        if ($post === null) {
            return $data;
        }
        $result = self::check($post, get_current_user_id());
        if ($result === true) {
            return $data;
        }
        $previous = $id > 0 ? (string) get_post_status($id) : '';
        $data['post_status'] = in_array($previous, ['draft', 'pending', 'private'], true) ? $previous : 'draft';
        self::$blocked[$id] = $result;

        /**
         * Fires when the gate holds back a publish or schedule.
         *
         * @param int      $postId 0 for a post being created.
         * @param WP_Error $error  The reasons, as the message.
         */
        do_action('ruvelo_editorial_publish_blocked', $id, $result);

        return $data;
    }

    /**
     * The post as it is about to be published, or null when the gate
     * doesn't apply (type not gated, or already public).
     */
    private static function subject(int $id, string $type, int $author): ?WP_Post
    {
        if ($id > 0) {
            $post = get_post($id);
            if (! $post instanceof WP_Post || in_array($post->post_status, ['publish', 'future'], true) || wp_is_post_revision($post) || wp_is_post_autosave($post)) {
                return null;
            }

            return self::applies($post->post_type) ? $post : null;
        }
        if ($type === '' || ! self::applies($type)) {
            return null;
        }

        // A post created straight into "publish" has never been reviewed.
        return new WP_Post((object) ['ID' => 0, 'post_type' => $type, 'post_author' => $author, 'post_status' => 'draft', 'filter' => 'raw']);
    }

    /**
     * Logs every first publish or schedule of a reviewed post type: who did
     * it, whether it was approved, and the reason when the gate was overridden.
     */
    public static function onTransition(string $new, string $old, WP_Post $post): void
    {
        if (! in_array($new, ['publish', 'future'], true) || in_array($old, ['publish', 'future'], true) || ! Settings::enabled($post->post_type) || wp_is_post_revision($post)) {
            return;
        }
        $user = get_current_user_id();
        $reasons = self::reasons($post);
        $override = $reasons !== [] ? self::override($post->ID, $user) : null;
        $from = Review::state($post->ID);

        if ($override !== null) {
            delete_post_meta($post->ID, self::OVERRIDE);
            $event = History::add($post->ID, ['type' => 'override', 'user' => $user, 'note' => $override['reason'], 'from' => $from, 'to' => $from, 'round' => Review::round($post->ID)]);
            Notifications::queue(array_values(array_unique(array_merge(Review::reviewers($post->ID), [(int) $post->post_author]))), 'override', $post->ID, $user, $override['reason']);

            /**
             * Fires when someone publishes past the gate with a reason.
             *
             * @param int                  $postId
             * @param array<string, mixed> $event
             */
            do_action('ruvelo_editorial_gate_overridden', $post->ID, $event);
        } elseif ($reasons !== []) {
            $event = History::add($post->ID, ['type' => 'published_unapproved', 'user' => $user, 'from' => $from, 'to' => $from, 'round' => Review::round($post->ID)]);

            /**
             * Fires when a gated post reaches publish or future without its
             * approvals: a `ruvelo_editorial_gate_passes` filter let it
             * through, or code called wp_publish_post() directly.
             *
             * @param int                  $postId
             * @param array<string, mixed> $event
             */
            do_action('ruvelo_editorial_published_unapproved', $post->ID, $event);
        } elseif ($from !== States::DRAFT || self::applies($post->post_type)) {
            History::add($post->ID, ['type' => $new === 'future' ? 'scheduled' : 'published', 'user' => $user, 'from' => $from, 'to' => $from, 'round' => Review::round($post->ID)]);
        }
        // Once it's out, nobody is waited on any more.
        delete_post_meta($post->ID, Review::PENDING);
        update_post_meta($post->ID, Review::UPDATED, Clock::now());
        Queue::flush();
    }

    /**
     * Classic editor: say why the post wasn't published.
     */
    public static function redirectLocation(string $location, int $postId): string
    {
        return isset(self::$blocked[$postId]) ? add_query_arg('ruvelo_editorial_held', 1, $location) : $location;
    }

    public static function blocked(int $postId): ?WP_Error
    {
        return self::$blocked[$postId] ?? null;
    }

    public static function reset(): void
    {
        self::$blocked = [];
    }
}
