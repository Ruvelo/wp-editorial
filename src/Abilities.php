<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial;

use WP_Error;
use WP_Post;

/**
 * Abilities API (WordPress 6.9+): what AI agents and other tools may do with
 * reviews. Reads need edit access to the post; approving and requesting
 * changes need the review capability and an assignment, exactly like the
 * editor panel. The MCP adapter picks them up when it is installed.
 *
 * Both `public` (7.1) and `show_in_rest` (7.0 reads only this one) are set,
 * so the abilities are listed on either version.
 */
final class Abilities
{
    public const CATEGORY = 'ruvelo-editorial';

    public static function register(): void
    {
        add_action('wp_abilities_api_categories_init', [self::class, 'category']);
        add_action('wp_abilities_api_init', [self::class, 'abilities']);
    }

    public static function category(): void
    {
        wp_register_ability_category(self::CATEGORY, [
            'label' => __('Editorial reviews', 'ruvelo-editorial'),
            'description' => __('Review states, the review queue, approvals and change requests.', 'ruvelo-editorial'),
        ]);
    }

    public static function abilities(): void
    {
        $public = ['public' => true, 'show_in_rest' => true, 'mcp' => ['public' => true]];
        $read = ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]] + $public;
        $write = ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]] + $public;
        $id = ['id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The post ID.']];
        $state = self::stateSchema();

        wp_register_ability('ruvelo-editorial/get-review-state', [
            'label' => __('Get a post’s review state', 'ruvelo-editorial'),
            'description' => __('Returns where a post is in review: Not in review, In review, Changes requested or Approved; its reviewers and their decisions; approvals so far and needed; open core notes; the due date; whether its publish gate is satisfied; and the review history.', 'ruvelo-editorial'),
            'category' => self::CATEGORY,
            'input_schema' => ['type' => 'object', 'properties' => $id, 'required' => ['id'], 'additionalProperties' => false],
            'output_schema' => $state,
            'execute_callback' => [self::class, 'getState'],
            'permission_callback' => [self::class, 'canEdit'],
            'meta' => $read,
        ]);

        wp_register_ability('ruvelo-editorial/get-queue', [
            'label' => __('Get the review queue', 'ruvelo-editorial'),
            'description' => __('Lists posts in the current user’s review queue. Views: mine (waiting for my review), changes (changes requested on my posts), approved (approved and ready to publish), all (everything in review).', 'ruvelo-editorial'),
            'category' => self::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'view' => ['type' => 'string', 'enum' => Queue::VIEWS, 'default' => 'mine'],
                    'post_type' => ['type' => 'string'],
                    'overdue' => ['type' => 'boolean', 'default' => false],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'array', 'items' => $state],
            'execute_callback' => [self::class, 'getQueue'],
            'permission_callback' => static fn (): bool => is_user_logged_in() && current_user_can('edit_posts'),
            'meta' => $read,
        ]);

        wp_register_ability('ruvelo-editorial/request-review', [
            'label' => __('Ask for a review', 'ruvelo-editorial'),
            'description' => __('Asks reviewers (user IDs) to review a post, with an optional due date (Y-m-d) and message. On a post already in review it changes the reviewers; after changes were requested it starts a new round. Needs edit access to the post.', 'ruvelo-editorial'),
            'category' => self::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => $id + [
                    'reviewers' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1],
                    'due' => ['type' => 'string'],
                    'message' => ['type' => 'string', 'maxLength' => 5000],
                ],
                'required' => ['id', 'reviewers'],
                'additionalProperties' => false,
            ],
            'output_schema' => $state,
            'execute_callback' => [self::class, 'requestReview'],
            'permission_callback' => [self::class, 'canEdit'],
            'meta' => $write,
        ]);

        wp_register_ability('ruvelo-editorial/approve', [
            'label' => __('Approve a post', 'ruvelo-editorial'),
            'description' => __('Approves a post as the current user, who must be one of its reviewers and have the ruvelo_review_posts capability. With enough approvals the post becomes Approved.', 'ruvelo-editorial'),
            'category' => self::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => $id + ['message' => ['type' => 'string', 'maxLength' => 5000]],
                'required' => ['id'],
                'additionalProperties' => false,
            ],
            'output_schema' => $state,
            'execute_callback' => [self::class, 'approve'],
            'permission_callback' => [self::class, 'canReview'],
            'meta' => $write,
        ]);

        wp_register_ability('ruvelo-editorial/request-changes', [
            'label' => __('Request changes on a post', 'ruvelo-editorial'),
            'description' => __('Sends a post back to its author with a message, as the current user (one of its reviewers, with the ruvelo_review_posts capability). Set create_note to also leave the message as a core note on the post.', 'ruvelo-editorial'),
            'category' => self::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => $id + [
                    'message' => ['type' => 'string', 'maxLength' => 5000],
                    'create_note' => ['type' => 'boolean', 'default' => false],
                ],
                'required' => ['id', 'message'],
                'additionalProperties' => false,
            ],
            'output_schema' => $state,
            'execute_callback' => [self::class, 'requestChanges'],
            'permission_callback' => [self::class, 'canReview'],
            'meta' => $write,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function stateSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'state' => ['type' => 'string', 'enum' => States::keys()],
                'state_label' => ['type' => 'string'],
                'reviewers' => ['type' => 'array'],
                'approvals' => ['type' => 'object'],
                'due' => ['type' => 'string'],
                'notes' => ['type' => 'object'],
                'gate' => ['type' => 'object'],
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $input
     */
    public static function canEdit(?array $input = null): bool
    {
        $id = (int) ($input['id'] ?? 0);

        return $id > 0 && current_user_can('edit_post', $id);
    }

    /**
     * @param array<string, mixed>|null $input
     */
    public static function canReview(?array $input = null): bool
    {
        return self::canEdit($input) && current_user_can(Settings::CAP_REVIEW);
    }

    /**
     * @param array<string, mixed>|null $input
     * @return array<string, mixed>|WP_Error
     */
    public static function getState(?array $input = null): array|WP_Error
    {
        $post = get_post((int) ($input['id'] ?? 0));
        if (! $post instanceof WP_Post || ! Settings::enabled($post->post_type)) {
            return new WP_Error('ruvelo_editorial_not_enabled', __('Reviews aren’t switched on for this post type.', 'ruvelo-editorial'));
        }
        $summary = Review::summary($post, get_current_user_id());
        $summary['history'] = Review::historyFor($post->ID);

        return $summary;
    }

    /**
     * @param array<string, mixed>|null $input
     * @return list<array<string, mixed>>
     */
    public static function getQueue(?array $input = null): array
    {
        $user = get_current_user_id();
        $view = is_string($input['view'] ?? null) && in_array($input['view'], Queue::VIEWS, true) ? $input['view'] : 'mine';
        $result = Queue::query($view, $user, [
            'post_type' => is_string($input['post_type'] ?? null) ? $input['post_type'] : '',
            'overdue' => ! empty($input['overdue']),
            'per_page' => (int) ($input['limit'] ?? 20),
        ]);

        return array_map(static fn (WP_Post $post): array => Review::summary($post, $user), $result['posts']);
    }

    /**
     * @param array<string, mixed>|null $input
     * @return array<string, mixed>|WP_Error
     */
    public static function requestReview(?array $input = null): array|WP_Error
    {
        $reviewers = is_array($input['reviewers'] ?? null) ? array_values(array_map('intval', $input['reviewers'])) : [];
        $due = is_string($input['due'] ?? null) ? $input['due'] : null;
        $result = Review::request((int) ($input['id'] ?? 0), $reviewers, get_current_user_id(), (string) ($input['message'] ?? ''), $due);

        return $result instanceof WP_Error ? $result : self::getState($input);
    }

    /**
     * @param array<string, mixed>|null $input
     * @return array<string, mixed>|WP_Error
     */
    public static function approve(?array $input = null): array|WP_Error
    {
        $result = Review::approve((int) ($input['id'] ?? 0), get_current_user_id(), (string) ($input['message'] ?? ''));

        return $result instanceof WP_Error ? $result : self::getState($input);
    }

    /**
     * @param array<string, mixed>|null $input
     * @return array<string, mixed>|WP_Error
     */
    public static function requestChanges(?array $input = null): array|WP_Error
    {
        $result = Review::requestChanges((int) ($input['id'] ?? 0), get_current_user_id(), (string) ($input['message'] ?? ''), 0, ! empty($input['create_note']));

        return $result instanceof WP_Error ? $result : self::getState($input);
    }
}
