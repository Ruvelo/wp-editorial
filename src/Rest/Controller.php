<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Rest;

use Ruvelo\WP\Editorial\Gate;
use Ruvelo\WP\Editorial\People;
use Ruvelo\WP\Editorial\Queue;
use Ruvelo\WP\Editorial\Review;
use Ruvelo\WP\Editorial\Settings;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST API: `ruvelo-editorial/v1`. The editor panel uses it, and so can
 * anything else. Every route needs a signed-in user; writes check the same
 * rules as the panel (Review does the checking).
 */
final class Controller
{
    public const NAMESPACE = 'ruvelo-editorial/v1';

    public static function register(): void
    {
        $post = ['id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]];
        $message = ['type' => 'string', 'default' => '', 'maxLength' => 5000];
        $decision = $post + [
            'message' => $message,
            'note_id' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
            'create_note' => ['type' => 'boolean', 'default' => false],
        ];

        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'show'],
            'permission_callback' => [self::class, 'canEdit'],
            'args' => $post,
        ]);
        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)/history', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'history'],
            'permission_callback' => [self::class, 'canEdit'],
            'args' => $post,
        ]);
        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)/request', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'request'],
            'permission_callback' => [self::class, 'canEdit'],
            'args' => $post + [
                'reviewers' => ['type' => 'array', 'items' => ['type' => 'integer'], 'required' => true],
                'due' => ['type' => ['string', 'null'], 'default' => null],
                'message' => $message,
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)/approve', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'approve'],
            'permission_callback' => [self::class, 'canReview'],
            'args' => $decision,
        ]);
        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)/request-changes', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'requestChanges'],
            'permission_callback' => [self::class, 'canReview'],
            'args' => $decision,
        ]);
        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)/withdraw', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'withdraw'],
            'permission_callback' => [self::class, 'canEdit'],
            'args' => $post + ['message' => $message],
        ]);
        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)/override', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'override'],
            'permission_callback' => [self::class, 'canEdit'],
            'args' => $post + ['reason' => ['type' => 'string', 'required' => true, 'maxLength' => 2000]],
        ]);
        register_rest_route(self::NAMESPACE, '/queue', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'queue'],
            'permission_callback' => [self::class, 'canUseQueue'],
            'args' => [
                'view' => ['type' => 'string', 'enum' => Queue::VIEWS, 'default' => 'mine'],
                'post_type' => ['type' => 'string', 'default' => ''],
                'author' => ['type' => 'integer', 'default' => 0],
                'reviewer' => ['type' => 'integer', 'default' => 0],
                'overdue' => ['type' => 'boolean', 'default' => false],
                'search' => ['type' => 'string', 'default' => ''],
                'orderby' => ['type' => 'string', 'enum' => ['updated', 'due', 'title'], 'default' => 'updated'],
                'order' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc'],
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/counts', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => static fn (): WP_REST_Response => new WP_REST_Response(Queue::counts(get_current_user_id())),
            'permission_callback' => [self::class, 'canUseQueue'],
        ]);
        register_rest_route(self::NAMESPACE, '/reviewers', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'reviewers'],
            'permission_callback' => [self::class, 'canUseQueue'],
            'args' => [
                'post' => ['type' => 'integer', 'default' => 0],
                'post_type' => ['type' => 'string', 'default' => 'post'],
            ],
        ]);

        foreach (Settings::enabledTypes() as $type) {
            register_rest_field($type, 'ruvelo_review', [
                'get_callback' => [self::class, 'field'],
                'schema' => [
                    'description' => __('Review state, from Ruvelo Editorial.', 'ruvelo-editorial'),
                    'type' => ['object', 'null'],
                    'context' => ['edit'],
                    'readonly' => true,
                ],
            ]);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public static function canEdit(WP_REST_Request $request): bool|WP_Error
    {
        $id = (int) $request['id'];
        if (! get_post($id) instanceof WP_Post) {
            return new WP_Error('ruvelo_editorial_not_found', __('That post doesn’t exist.', 'ruvelo-editorial'), ['status' => 404]);
        }

        return current_user_can('edit_post', $id);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public static function canReview(WP_REST_Request $request): bool|WP_Error
    {
        $can = self::canEdit($request);

        return $can === true ? current_user_can(Settings::CAP_REVIEW) : $can;
    }

    public static function canUseQueue(): bool
    {
        return is_user_logged_in() && current_user_can('edit_posts');
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public static function show(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post = get_post((int) $request['id']);
        if (! $post instanceof WP_Post || ! Settings::enabled($post->post_type)) {
            return new WP_Error('ruvelo_editorial_not_enabled', __('Reviews aren’t switched on for this post type.', 'ruvelo-editorial'), ['status' => 400]);
        }

        return new WP_REST_Response(self::payload($post));
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public static function history(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response(Review::historyFor((int) $request['id']));
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public static function request(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $reviewers = is_array($request['reviewers']) ? array_map('intval', $request['reviewers']) : [];
        $due = $request['due'];

        return self::respond((int) $request['id'], Review::request((int) $request['id'], array_values($reviewers), get_current_user_id(), (string) $request['message'], is_string($due) ? $due : null));
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public static function approve(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return self::respond((int) $request['id'], Review::approve((int) $request['id'], get_current_user_id(), (string) $request['message'], (int) $request['note_id'], (bool) $request['create_note']));
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public static function requestChanges(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return self::respond((int) $request['id'], Review::requestChanges((int) $request['id'], get_current_user_id(), (string) $request['message'], (int) $request['note_id'], (bool) $request['create_note']));
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public static function withdraw(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return self::respond((int) $request['id'], Review::withdraw((int) $request['id'], get_current_user_id(), (string) $request['message']));
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public static function override(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return self::respond((int) $request['id'], Gate::allowOnce((int) $request['id'], get_current_user_id(), (string) $request['reason']));
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public static function queue(WP_REST_Request $request): WP_REST_Response
    {
        $user = get_current_user_id();
        $result = Queue::query((string) $request['view'], $user, [
            'post_type' => (string) $request['post_type'],
            'author' => (int) $request['author'],
            'reviewer' => (int) $request['reviewer'],
            'overdue' => (bool) $request['overdue'],
            'search' => (string) $request['search'],
            'orderby' => (string) $request['orderby'],
            'order' => (string) $request['order'],
            'page' => (int) $request['page'],
            'per_page' => (int) $request['per_page'],
        ]);
        $items = array_map(static fn (WP_Post $post): array => Review::summary($post, $user), $result['posts']);
        $response = new WP_REST_Response($items);
        $response->header('X-WP-Total', (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) $result['pages']);

        return $response;
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public static function reviewers(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post = null;
        $postId = (int) $request['post'];
        if ($postId > 0) {
            $post = get_post($postId);
            if (! $post instanceof WP_Post || ! current_user_can('edit_post', $postId)) {
                return new WP_Error('ruvelo_editorial_not_found', __('That post doesn’t exist.', 'ruvelo-editorial'), ['status' => 404]);
            }
        }
        $type = $post instanceof WP_Post ? $post->post_type : sanitize_key((string) $request['post_type']);
        $people = array_map(static fn ($user): array => People::card($user->ID), People::reviewers($post, $type));

        return new WP_REST_Response($people);
    }

    /**
     * The `ruvelo_review` field on core's post endpoints (edit context).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public static function field(array $data): ?array
    {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0 || ! current_user_can('edit_post', $id)) {
            return null;
        }
        $post = get_post($id);
        if (! $post instanceof WP_Post) {
            return null;
        }
        $state = Review::state($id);

        return [
            'state' => $state,
            'reviewers' => Review::reviewers($id),
            'approvals' => count(Review::approvedBy($id)),
            'required' => Review::requiredApprovals($post),
            'due' => Review::due($id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(WP_Post $post): array
    {
        $summary = Review::summary($post, get_current_user_id());
        $summary['history'] = Review::historyFor($post->ID);

        return $summary;
    }

    private static function respond(int $postId, true|WP_Error $result): WP_REST_Response|WP_Error
    {
        if ($result instanceof WP_Error) {
            return $result;
        }
        $post = get_post($postId);

        return new WP_REST_Response($post instanceof WP_Post ? self::payload($post) : []);
    }
}
