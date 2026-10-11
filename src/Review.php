<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial;

use WP_Error;
use WP_Post;

/**
 * The one write path for reviews. Every change of state goes through here
 * (the editor panel, the review screen, REST, WP-CLI and abilities all call
 * these methods), is logged in the post's history, fires actions and queues
 * notifications.
 *
 *     Not in review ──request──▶ In review ──approve (N times)──▶ Approved ──▶ core publishes or schedules
 *                                   │  ▲                              │
 *                       request changes  re-request          request changes
 *                                   ▼  │                              │
 *                              Changes requested ◀────────────────────┘
 *
 * State lives in post meta beside core's post status, never instead of it.
 */
final class Review
{
    public const STATE = '_ruvelo_editorial_state';

    public const REVIEWER = '_ruvelo_editorial_reviewer';

    public const PENDING = '_ruvelo_editorial_pending';

    public const APPROVED_BY = '_ruvelo_editorial_approved_by';

    public const CHANGES_BY = '_ruvelo_editorial_changes_by';

    public const ROUND = '_ruvelo_editorial_round';

    public const DUE = '_ruvelo_editorial_due';

    public const REQUESTED_BY = '_ruvelo_editorial_requested_by';

    public const UPDATED = '_ruvelo_editorial_updated';

    /** Every meta key the plugin writes on posts (for uninstall). */
    public const META_KEYS = [self::STATE, self::REVIEWER, self::PENDING, self::APPROVED_BY, self::CHANGES_BY, self::ROUND, self::DUE, self::REQUESTED_BY, self::UPDATED, History::META, Gate::OVERRIDE];

    public static function state(int $postId): string
    {
        $state = get_post_meta($postId, self::STATE, true);

        return is_string($state) && States::valid($state) ? $state : States::DRAFT;
    }

    /**
     * @return list<int>
     */
    public static function reviewers(int $postId): array
    {
        return self::ids($postId, self::REVIEWER);
    }

    /**
     * @return list<int>
     */
    public static function pending(int $postId): array
    {
        return self::ids($postId, self::PENDING);
    }

    /**
     * @return list<int>
     */
    public static function approvedBy(int $postId): array
    {
        return self::ids($postId, self::APPROVED_BY);
    }

    /**
     * @return list<int>
     */
    public static function changesBy(int $postId): array
    {
        return self::ids($postId, self::CHANGES_BY);
    }

    public static function round(int $postId): int
    {
        return (int) get_post_meta($postId, self::ROUND, true);
    }

    public static function due(int $postId): string
    {
        $due = get_post_meta($postId, self::DUE, true);

        return is_string($due) ? $due : '';
    }

    public static function isOverdue(int $postId): bool
    {
        $due = self::due($postId);

        return $due !== '' && in_array(self::state($postId), [States::IN_REVIEW], true) && $due < current_time('Y-m-d');
    }

    public static function requiredApprovals(WP_Post $post): int
    {
        /**
         * Filters how many approvals a post needs before it counts as approved
         * (and, with a gate, before it can be published).
         *
         * @param int     $approvals From the post type's settings.
         * @param WP_Post $post
         */
        return max(1, (int) apply_filters('ruvelo_editorial_required_approvals', Settings::forType($post->post_type)['approvals'], $post));
    }

    /**
     * Ask for a review, change who reviews, or ask again after changes.
     *
     * In review, it updates the reviewers (new people are asked, approvals
     * already given stand). From any other state it starts a new round:
     * everyone is asked again and earlier approvals are cleared.
     *
     * @param list<int>   $reviewerIds
     * @param string|null $due Y-m-d, '' to clear, null to keep.
     */
    public static function request(int $postId, array $reviewerIds, int $actor, string $message = '', ?string $due = null): true|WP_Error
    {
        $post = self::post($postId);
        if ($post instanceof WP_Error) {
            return $post;
        }
        if (! user_can($actor, 'edit_post', $postId)) {
            return self::forbidden(__('You can’t ask for a review of this post.', 'ruvelo-editorial'));
        }
        if (in_array($post->post_status, ['publish', 'future'], true)) {
            return new WP_Error('ruvelo_editorial_published', __('This post is already published or scheduled. Reviews happen before that.', 'ruvelo-editorial'), ['status' => 409]);
        }

        $reviewerIds = array_values(array_unique(array_filter(array_map('intval', $reviewerIds))));
        if ($reviewerIds === []) {
            return new WP_Error('ruvelo_editorial_no_reviewers', __('Choose at least one reviewer.', 'ruvelo-editorial'), ['status' => 400]);
        }
        foreach ($reviewerIds as $id) {
            if (! People::canReview($id, $post)) {
                return new WP_Error('ruvelo_editorial_invalid_reviewer', sprintf(
                    /* translators: %s: a person's name */
                    __('%s can’t review this post. Reviewers need the review capability and access to the post, and can’t be its author.', 'ruvelo-editorial'),
                    get_userdata($id) !== false ? People::name($id) : '#'.$id
                ), ['status' => 400]);
            }
        }
        $required = self::requiredApprovals($post);
        if (count($reviewerIds) < $required) {
            return new WP_Error('ruvelo_editorial_too_few_reviewers', sprintf(
                /* translators: %d: number of approvals */
                _n('This needs %d approval: choose at least that many reviewers.', 'This needs %d approvals: choose at least that many reviewers.', $required, 'ruvelo-editorial'),
                $required
            ), ['status' => 400]);
        }
        if ($due !== null && $due !== '' && ! self::validDate($due)) {
            return new WP_Error('ruvelo_editorial_invalid_due', __('Use a due date like 2026-10-14.', 'ruvelo-editorial'), ['status' => 400]);
        }

        $from = self::state($postId);
        if ($due !== null || ! metadata_exists('post', $postId, self::DUE)) {
            update_post_meta($postId, self::DUE, (string) $due);
        }
        $message = self::cleanMessage($message);

        if ($from === States::IN_REVIEW) {
            $before = self::reviewers($postId);
            $added = array_values(array_diff($reviewerIds, $before));
            $removed = array_values(array_diff($before, $reviewerIds));
            self::setIds($postId, self::REVIEWER, $reviewerIds);
            self::setIds($postId, self::PENDING, array_values(array_unique(array_merge(array_diff(self::pending($postId), $removed), $added))));
            self::setIds($postId, self::APPROVED_BY, array_values(array_diff(self::approvedBy($postId), $removed)));
            self::setIds($postId, self::CHANGES_BY, array_values(array_diff(self::changesBy($postId), $removed)));

            $event = self::log($postId, ['type' => 'reviewers_changed', 'user' => $actor, 'note' => $message, 'from' => $from, 'to' => $from, 'reviewers' => $reviewerIds, 'due' => self::due($postId)]);
            Notifications::queue($added, 'requested', $postId, $actor, $message);
            self::fire('reviewers_changed', $postId, $from, $from, $event);
            self::settle($post, $actor);

            return true;
        }

        $round = max(0, self::round($postId)) + 1;
        update_post_meta($postId, self::ROUND, $round);
        update_post_meta($postId, self::REQUESTED_BY, $actor);
        self::setIds($postId, self::REVIEWER, $reviewerIds);
        self::setIds($postId, self::PENDING, $reviewerIds);
        self::setIds($postId, self::APPROVED_BY, []);
        self::setIds($postId, self::CHANGES_BY, []);
        self::setState($postId, States::IN_REVIEW);

        $event = self::log($postId, ['type' => 'requested', 'user' => $actor, 'note' => $message, 'from' => $from, 'to' => States::IN_REVIEW, 'round' => $round, 'reviewers' => $reviewerIds, 'due' => self::due($postId)]);
        Notifications::queue($reviewerIds, 'requested', $postId, $actor, $message);
        self::fire('requested', $postId, $from, States::IN_REVIEW, $event);

        return true;
    }

    /**
     * An assigned reviewer approves. With enough approvals the post becomes
     * Approved; the author hears about every approval.
     */
    public static function approve(int $postId, int $actor, string $message = '', int $noteId = 0, bool $createNote = false): true|WP_Error
    {
        $post = self::decidable($postId, $actor, [States::IN_REVIEW, States::APPROVED]);
        if ($post instanceof WP_Error) {
            return $post;
        }
        if (in_array($actor, self::approvedBy($postId), true)) {
            return new WP_Error('ruvelo_editorial_already_approved', __('You’ve already approved this post.', 'ruvelo-editorial'), ['status' => 409]);
        }
        $message = self::cleanMessage($message);
        $noteId = self::noteFor($post, $actor, $message, $noteId, $createNote);
        if ($noteId instanceof WP_Error) {
            return $noteId;
        }

        $from = self::state($postId);
        self::setIds($postId, self::PENDING, array_values(array_diff(self::pending($postId), [$actor])));
        self::setIds($postId, self::CHANGES_BY, array_values(array_diff(self::changesBy($postId), [$actor])));
        self::setIds($postId, self::APPROVED_BY, array_values(array_unique(array_merge(self::approvedBy($postId), [$actor]))));

        $event = self::log($postId, ['type' => 'approved', 'user' => $actor, 'note' => $message, 'from' => $from, 'to' => $from, 'note_id' => $noteId]);
        self::fire('approved', $postId, $from, $from, $event);
        if (! self::settle($post, $actor)) {
            Notifications::queue(self::people($post), 'approved', $postId, $actor, $message);
        }

        return true;
    }

    /**
     * An assigned reviewer sends it back to the author, optionally with a
     * message that can start (or point to) a core note.
     */
    public static function requestChanges(int $postId, int $actor, string $message = '', int $noteId = 0, bool $createNote = false): true|WP_Error
    {
        $post = self::decidable($postId, $actor, [States::IN_REVIEW, States::APPROVED]);
        if ($post instanceof WP_Error) {
            return $post;
        }
        $message = self::cleanMessage($message);
        $noteId = self::noteFor($post, $actor, $message, $noteId, $createNote);
        if ($noteId instanceof WP_Error) {
            return $noteId;
        }

        $from = self::state($postId);
        self::setIds($postId, self::PENDING, array_values(array_diff(self::pending($postId), [$actor])));
        self::setIds($postId, self::APPROVED_BY, array_values(array_diff(self::approvedBy($postId), [$actor])));
        self::setIds($postId, self::CHANGES_BY, array_values(array_unique(array_merge(self::changesBy($postId), [$actor]))));
        self::setState($postId, States::CHANGES);

        $event = self::log($postId, ['type' => 'changes_requested', 'user' => $actor, 'note' => $message, 'from' => $from, 'to' => States::CHANGES, 'note_id' => $noteId]);
        Notifications::queue(self::people($post), 'changes_requested', $postId, $actor, $message);
        self::fire('changes_requested', $postId, $from, States::CHANGES, $event);

        return true;
    }

    /**
     * Takes the post out of review. Reviewers stay remembered for next time.
     */
    public static function withdraw(int $postId, int $actor, string $message = ''): true|WP_Error
    {
        $post = self::post($postId);
        if ($post instanceof WP_Error) {
            return $post;
        }
        if (! user_can($actor, 'edit_post', $postId)) {
            return self::forbidden(__('You can’t change the review of this post.', 'ruvelo-editorial'));
        }
        $from = self::state($postId);
        if ($from === States::DRAFT) {
            return new WP_Error('ruvelo_editorial_not_in_review', __('This post isn’t in review.', 'ruvelo-editorial'), ['status' => 409]);
        }
        $pending = self::pending($postId);
        self::setIds($postId, self::PENDING, []);
        self::setIds($postId, self::APPROVED_BY, []);
        self::setIds($postId, self::CHANGES_BY, []);
        self::setState($postId, States::DRAFT);

        $message = self::cleanMessage($message);
        $event = self::log($postId, ['type' => 'withdrawn', 'user' => $actor, 'note' => $message, 'from' => $from, 'to' => States::DRAFT]);
        Notifications::queue($pending, 'withdrawn', $postId, $actor, $message);
        self::fire('withdrawn', $postId, $from, States::DRAFT, $event);

        return true;
    }

    /**
     * Everything the editor panel, REST and abilities show about a review.
     *
     * @return array<string, mixed>
     */
    public static function summary(WP_Post $post, int $viewer = 0): array
    {
        $id = $post->ID;
        $state = self::state($id);
        $approvedBy = self::approvedBy($id);
        $changesBy = self::changesBy($id);
        $pending = self::pending($id);
        $reviewers = [];
        foreach (self::reviewers($id) as $reviewer) {
            $decision = in_array($reviewer, $approvedBy, true) ? 'approved' : (in_array($reviewer, $changesBy, true) ? 'changes_requested' : (in_array($reviewer, $pending, true) ? 'pending' : 'none'));
            $reviewers[] = People::card($reviewer) + ['decision' => $decision];
        }
        $notes = Notes::counts([$id])[$id] ?? ['open' => 0, 'total' => 0];
        $gate = Gate::status($post, $viewer);
        $lastChanges = $state === States::CHANGES ? History::last($id, 'changes_requested') : null;
        $requestedBy = (int) get_post_meta($id, self::REQUESTED_BY, true);
        $isReviewer = in_array($viewer, self::reviewers($id), true);
        $canDecide = $viewer > 0 && $isReviewer && in_array($state, [States::IN_REVIEW, States::APPROVED], true) && ! in_array($post->post_status, ['publish', 'future'], true) && People::canReview($viewer, $post);

        return [
            'post_id' => $id,
            'title' => get_the_title($post),
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'author' => People::card((int) $post->post_author),
            'state' => $state,
            'state_label' => States::label($state),
            'tone' => States::tone($state),
            'round' => self::round($id),
            'reviewers' => $reviewers,
            'approvals' => ['count' => count($approvedBy), 'required' => self::requiredApprovals($post)],
            'due' => self::due($id),
            'overdue' => self::isOverdue($id),
            'requested_by' => $requestedBy > 0 ? People::card($requestedBy) : null,
            'notes' => $notes + ['supported' => Notes::supported($post->post_type)],
            'changes' => $lastChanges !== null ? ['user' => People::card($lastChanges['user']), 'message' => $lastChanges['note'], 'at' => gmdate('c', $lastChanges['at']), 'note_id' => $lastChanges['note_id']] : null,
            'gate' => $gate,
            'can' => [
                'request' => $viewer > 0 && ! in_array($post->post_status, ['publish', 'future'], true) && user_can($viewer, 'edit_post', $id),
                'decide' => $canDecide,
                'approve' => $canDecide && ! in_array($viewer, $approvedBy, true),
                'withdraw' => $viewer > 0 && $state !== States::DRAFT && ! in_array($post->post_status, ['publish', 'future'], true) && user_can($viewer, 'edit_post', $id),
            ],
            'viewer' => ['id' => $viewer, 'is_reviewer' => $isReviewer, 'decision' => in_array($viewer, $approvedBy, true) ? 'approved' : (in_array($viewer, $changesBy, true) ? 'changes_requested' : (in_array($viewer, $pending, true) ? 'pending' : 'none'))],
            'updated' => (int) get_post_meta($id, self::UPDATED, true),
            'links' => ['edit' => (string) get_edit_post_link($id, 'url'), 'review' => Admin\ReviewPage::url($id)],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function historyFor(int $postId): array
    {
        return array_map(static fn (array $event): array => [
            'type' => $event['type'],
            'text' => History::describe($event),
            'user' => People::card($event['user']),
            'at' => gmdate('c', $event['at']),
            'message' => $event['note'],
            'from' => $event['from'],
            'to' => $event['to'],
            'round' => $event['round'],
            'note_id' => $event['note_id'],
            'due' => $event['due'],
        ], History::all($postId));
    }

    /**
     * Moves to Approved once enough reviewers have approved, and tells the
     * author it's ready. Returns whether it did.
     */
    private static function settle(WP_Post $post, int $actor): bool
    {
        $id = $post->ID;
        if (self::state($id) !== States::IN_REVIEW || count(self::approvedBy($id)) < self::requiredApprovals($post)) {
            return false;
        }
        self::setState($id, States::APPROVED);
        $event = self::log($id, ['type' => 'fully_approved', 'user' => $actor, 'from' => States::IN_REVIEW, 'to' => States::APPROVED]);
        Notifications::queue(self::people($post), 'fully_approved', $id, $actor, '');
        self::fire('fully_approved', $id, States::IN_REVIEW, States::APPROVED, $event);

        return true;
    }

    /**
     * The author, and whoever asked for the review if that's someone else.
     *
     * @return list<int>
     */
    private static function people(WP_Post $post): array
    {
        return array_values(array_unique(array_filter([(int) $post->post_author, (int) get_post_meta($post->ID, self::REQUESTED_BY, true)])));
    }

    /**
     * @param list<string> $states
     */
    private static function decidable(int $postId, int $actor, array $states): WP_Post|WP_Error
    {
        $post = self::post($postId);
        if ($post instanceof WP_Error) {
            return $post;
        }
        if (! in_array($actor, self::reviewers($postId), true) || ! People::canReview($actor, $post)) {
            return self::forbidden(__('Only the reviewers of this post can approve it or request changes.', 'ruvelo-editorial'));
        }
        if (! in_array(self::state($postId), $states, true) || in_array($post->post_status, ['publish', 'future'], true)) {
            return new WP_Error('ruvelo_editorial_not_in_review', __('This post isn’t waiting for a review. Ask for one first.', 'ruvelo-editorial'), ['status' => 409]);
        }

        return $post;
    }

    private static function noteFor(WP_Post $post, int $actor, string $message, int $noteId, bool $createNote): int|WP_Error
    {
        if ($noteId > 0) {
            $note = Notes::find($noteId, $post->ID);

            return $note instanceof WP_Error ? $note : (int) $note->comment_ID;
        }
        if ($createNote && $message !== '') {
            return Notes::create($post->ID, $actor, $message);
        }

        return 0;
    }

    private static function post(int $postId): WP_Post|WP_Error
    {
        $post = get_post($postId);
        if (! $post instanceof WP_Post || in_array($post->post_status, ['trash', 'auto-draft', 'inherit'], true)) {
            return new WP_Error('ruvelo_editorial_not_found', __('That post doesn’t exist.', 'ruvelo-editorial'), ['status' => 404]);
        }
        if (! Settings::enabled($post->post_type)) {
            return new WP_Error('ruvelo_editorial_not_enabled', __('Reviews aren’t switched on for this post type.', 'ruvelo-editorial'), ['status' => 400]);
        }

        return $post;
    }

    private static function forbidden(string $message): WP_Error
    {
        return new WP_Error('ruvelo_editorial_forbidden', $message, ['status' => 403]);
    }

    private static function setState(int $postId, string $state): void
    {
        update_post_meta($postId, self::STATE, $state);
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private static function log(int $postId, array $event): array
    {
        $event['round'] ??= self::round($postId);
        update_post_meta($postId, self::UPDATED, Clock::now());
        Queue::flush();

        return History::add($postId, $event);
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function fire(string $type, int $postId, string $from, string $to, array $event): void
    {
        /**
         * Fires after every review event: requested, reviewers_changed,
         * approved, fully_approved, changes_requested and withdrawn.
         *
         * @param int                  $postId
         * @param string               $from   Review state before.
         * @param string               $to     Review state after.
         * @param array<string, mixed> $event  The history entry.
         */
        do_action('ruvelo_editorial_transition', $postId, $from, $to, $event);

        /**
         * Fires after one kind of review event, e.g. `ruvelo_editorial_approved`.
         *
         * @param int                  $postId
         * @param array<string, mixed> $event
         */
        do_action('ruvelo_editorial_'.$type, $postId, $event);
    }

    private static function cleanMessage(string $message): string
    {
        return trim(sanitize_textarea_field($message));
    }

    public static function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /**
     * @return list<int>
     */
    private static function ids(int $postId, string $key): array
    {
        $values = get_post_meta($postId, $key, false);

        return array_values(array_unique(array_filter(array_map('intval', is_array($values) ? $values : []))));
    }

    /**
     * Stored as one meta row per person so queues can query them.
     *
     * @param list<int> $ids
     */
    private static function setIds(int $postId, string $key, array $ids): void
    {
        delete_post_meta($postId, $key);
        foreach (array_values(array_unique($ids)) as $id) {
            add_post_meta($postId, $key, (int) $id);
        }
    }
}
