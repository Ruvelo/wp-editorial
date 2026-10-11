<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Admin;

use Ruvelo\WP\Editorial\Gate;
use Ruvelo\WP\Editorial\History;
use Ruvelo\WP\Editorial\Notes;
use Ruvelo\WP\Editorial\People;
use Ruvelo\WP\Editorial\Review;
use Ruvelo\WP\Editorial\Settings;
use Ruvelo\WP\Editorial\States;
use Ruvelo\WP\Editorial\Support\Html;
use WP_Error;
use WP_Post;

/**
 * One post's review: its state, reviewers, notes and gate, every action as
 * a plain form (so it all works without JavaScript), and the full history.
 */
final class ReviewPage
{
    public const SLUG = 'ruvelo-editorial-review';

    public static function url(int $postId): string
    {
        return add_query_arg(['page' => self::SLUG, 'post' => $postId], admin_url('admin.php'));
    }

    public static function render(): void
    {
        $postId = isset($_GET['post']) ? absint($_GET['post']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which post to show.
        $post = get_post($postId);
        if (! $post instanceof WP_Post || ! current_user_can('edit_post', $postId) || ! Settings::enabled($post->post_type)) {
            wp_die(esc_html__('That post doesn’t exist, or you can’t review it.', 'ruvelo-editorial'), 404);
        }
        $user = get_current_user_id();
        $summary = Review::summary($post, $user);
        $state = (string) $summary['state'];
        $gate = Gate::status($post, $user);
        $title = get_the_title($post) !== '' ? get_the_title($post) : __('(no title)', 'ruvelo-editorial');
        $statusObject = get_post_status_object($post->post_status);

        echo '<div class="wrap ruvelo-ed ruvelo-ed-review">';
        echo '<p class="ruvelo-ed-back"><a href="'.esc_url(admin_url('admin.php?page='.QueuePage::SLUG)).'">'.esc_html__('← Review queue', 'ruvelo-editorial').'</a></p>';
        echo '<div class="ruvelo-ed-title"><h1>'.esc_html($title).'</h1>'.Html::badge($state).'</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Html::badge() escapes.
        echo '<p class="ruvelo-ed-lede">';
        printf(
            /* translators: 1: author, 2: post status (Draft, Pending…) */
            esc_html__('By %1$s · %2$s', 'ruvelo-editorial'),
            esc_html(People::name((int) $post->post_author)),
            esc_html($statusObject !== null ? (string) $statusObject->label : $post->post_status)
        );
        $edit = (string) get_edit_post_link($postId, 'url');
        if ($edit !== '') {
            echo ' · <a href="'.esc_url($edit).'">'.esc_html__('Open in the editor', 'ruvelo-editorial').'</a>';
        }
        $preview = get_preview_post_link($post);
        if (is_string($preview) && $preview !== '') {
            echo ' · <a href="'.esc_url($preview).'">'.esc_html__('Preview', 'ruvelo-editorial').'</a>';
        }
        echo '</p>';
        Notice::render();

        echo '<div class="ruvelo-ed-grid"><div class="ruvelo-ed-main">';
        self::changesPanel($summary);
        self::gatePanel($post, $gate);
        self::actions($post, $summary);
        self::history($postId);
        echo '</div><aside class="ruvelo-ed-side">';
        self::facts($post, $summary);
        echo '</aside></div></div>';
    }

    /**
     * @param array<string, mixed> $summary
     */
    private static function changesPanel(array $summary): void
    {
        $changes = $summary['changes'] ?? null;
        if (! is_array($changes)) {
            return;
        }
        echo '<section class="ruvelo-ed-card ruvelo-ed-card--warning"><h2>';
        /* translators: %s: reviewer */
        echo esc_html(sprintf(__('%s requested changes', 'ruvelo-editorial'), (string) $changes['user']['name']));
        echo '</h2>';
        if ($changes['message'] !== '') {
            echo '<blockquote class="ruvelo-ed-quote">'.wp_kses_post(wpautop(esc_html((string) $changes['message']))).'</blockquote>';
        }
        echo '<p class="ruvelo-ed-quiet">'.esc_html__('Make the changes, then ask for a review again below.', 'ruvelo-editorial').'</p></section>';
    }

    /**
     * @param array<string, mixed> $gate
     */
    private static function gatePanel(WP_Post $post, array $gate): void
    {
        if (empty($gate['enabled']) || in_array($post->post_status, ['publish', 'future'], true)) {
            return;
        }
        $reasons = is_array($gate['reasons']) ? $gate['reasons'] : [];
        if ($reasons === []) {
            echo '<section class="ruvelo-ed-card ruvelo-ed-card--success"><h2>'.esc_html__('Ready to publish', 'ruvelo-editorial').'</h2><p>'.esc_html__('It has the approvals it needs. Publish or schedule it from the editor.', 'ruvelo-editorial').'</p></section>';

            return;
        }
        echo '<section class="ruvelo-ed-card ruvelo-ed-card--gate"><h2>'.esc_html__('Publishing is on hold', 'ruvelo-editorial').'</h2><p>'.esc_html(implode(' ', array_map('strval', $reasons))).'</p>';
        if (! empty($gate['can_override'])) {
            echo '<details class="ruvelo-ed-override"><summary>'.esc_html__('Publish without the approvals', 'ruvelo-editorial').'</summary>';
            self::formOpen($post->ID, 'override');
            echo '<p><label for="ruvelo-ed-reason">'.esc_html__('Why can’t this wait? (logged and sent to the reviewers)', 'ruvelo-editorial').'</label>';
            echo '<textarea id="ruvelo-ed-reason" name="reason" rows="3" required></textarea></p>';
            submit_button(__('Publish now', 'ruvelo-editorial'), 'secondary ruvelo-ed-danger', 'submit', false);
            echo '</form></details>';
        }
        echo '</section>';
    }

    /**
     * @param array<string, mixed> $summary
     */
    private static function actions(WP_Post $post, array $summary): void
    {
        $can = is_array($summary['can']) ? $summary['can'] : [];
        $state = (string) $summary['state'];

        if (! empty($can['decide'])) {
            echo '<section class="ruvelo-ed-card"><h2>'.esc_html__('Your review', 'ruvelo-editorial').'</h2>';
            if (($summary['viewer']['decision'] ?? '') === 'approved') {
                echo '<p>'.esc_html__('You approved this post.', 'ruvelo-editorial').'</p>';
            }
            self::formOpen($post->ID, 'decide');
            echo '<p><label for="ruvelo-ed-message">'.esc_html__('Message for the author (optional when approving)', 'ruvelo-editorial').'</label>';
            echo '<textarea id="ruvelo-ed-message" name="message" rows="4"></textarea></p>';
            if (Notes::supported($post->post_type)) {
                echo '<p><label><input type="checkbox" name="create_note" value="1"> '.esc_html__('Also leave it as a note on the post', 'ruvelo-editorial').'</label></p>';
            }
            echo '<p class="ruvelo-ed-buttons">';
            if (! empty($can['approve'])) {
                echo '<button type="submit" name="do" value="approve" class="button button-primary">'.esc_html__('Approve', 'ruvelo-editorial').'</button> ';
            }
            echo '<button type="submit" name="do" value="changes" class="button">'.esc_html__('Request changes', 'ruvelo-editorial').'</button></p></form></section>';
        }

        if (! empty($can['request'])) {
            $current = Review::reviewers($post->ID);
            $label = match ($state) {
                States::IN_REVIEW => __('Change reviewers', 'ruvelo-editorial'),
                States::DRAFT => __('Ask for a review', 'ruvelo-editorial'),
                default => __('Ask for a review again', 'ruvelo-editorial'),
            };
            echo '<section class="ruvelo-ed-card"><h2>'.esc_html($label).'</h2>';
            self::formOpen($post->ID, 'request');
            $people = People::reviewers($post);
            if ($people === []) {
                echo '<p>'.esc_html__('Nobody can review this post yet. Reviewers need the “Review posts” capability (editors and administrators have it).', 'ruvelo-editorial').'</p></form></section>';
            } else {
                echo '<fieldset class="ruvelo-ed-pick"><legend>'.esc_html__('Reviewers', 'ruvelo-editorial').'</legend>';
                foreach ($people as $person) {
                    echo '<label><input type="checkbox" name="reviewers[]" value="'.esc_attr((string) $person->ID).'"'.checked(in_array($person->ID, $current, true), true, false).'> '.Html::person($person->ID).' '.esc_html($person->display_name).'</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Html::person() escapes.
                }
                echo '</fieldset>';
                echo '<p class="ruvelo-ed-inline"><label for="ruvelo-ed-due">'.esc_html__('Due', 'ruvelo-editorial').'</label> <input type="date" id="ruvelo-ed-due" name="due" value="'.esc_attr((string) $summary['due']).'"></p>';
                echo '<p><label for="ruvelo-ed-request-message">'.esc_html__('Message for the reviewers (optional)', 'ruvelo-editorial').'</label><textarea id="ruvelo-ed-request-message" name="message" rows="3"></textarea></p>';
                echo '<p class="ruvelo-ed-buttons"><button type="submit" name="do" value="request" class="button button-primary">'.esc_html($state === States::IN_REVIEW ? __('Save reviewers', 'ruvelo-editorial') : __('Ask for a review', 'ruvelo-editorial')).'</button>';
                if (! empty($can['withdraw'])) {
                    echo ' <button type="submit" name="do" value="withdraw" class="button button-link ruvelo-ed-link-danger" formnovalidate>'.esc_html__('Take it out of review', 'ruvelo-editorial').'</button>';
                }
                echo '</p></form></section>';
            }
        }
    }

    private static function history(int $postId): void
    {
        $events = array_reverse(History::all($postId));
        echo '<section class="ruvelo-ed-card"><h2>'.esc_html__('History', 'ruvelo-editorial').'</h2>';
        if ($events === []) {
            echo '<p class="ruvelo-ed-quiet">'.esc_html__('Nothing yet. Ask for a review to start.', 'ruvelo-editorial').'</p></section>';

            return;
        }
        echo '<ol class="ruvelo-ed-timeline">';
        foreach ($events as $event) {
            $tone = match ($event['type']) {
                'approved', 'fully_approved', 'published', 'scheduled' => 'success',
                'changes_requested' => 'warning',
                'override', 'published_unapproved' => 'danger',
                'withdrawn' => 'neutral',
                default => 'accent',
            };
            echo '<li class="ruvelo-ed-tone-'.esc_attr($tone).'"><span class="ruvelo-ed-dot" aria-hidden="true"></span><div>';
            echo '<p class="ruvelo-ed-event">'.esc_html(History::describe($event));
            if ($event['due'] !== '' && $event['type'] === 'requested') {
                /* translators: %s: date */
                echo ' '.esc_html(sprintf(__('Due %s.', 'ruvelo-editorial'), (string) wp_date((string) get_option('date_format'), (int) strtotime($event['due'].' 12:00:00'))));
            }
            echo '</p>';
            if ($event['note'] !== '') {
                echo '<blockquote class="ruvelo-ed-quote">'.wp_kses_post(wpautop(esc_html($event['note']))).'</blockquote>';
            }
            if ($event['note_id'] > 0) {
                echo '<p class="ruvelo-ed-quiet">'.esc_html__('Left as a note on the post.', 'ruvelo-editorial').'</p>';
            }
            echo '<time class="ruvelo-ed-quiet" datetime="'.esc_attr(gmdate('c', $event['at'])).'">'.esc_html((string) wp_date((string) get_option('date_format').' '.(string) get_option('time_format'), $event['at'])).'</time>';
            echo '</div></li>';
        }
        echo '</ol></section>';
    }

    /**
     * @param array<string, mixed> $summary
     */
    private static function facts(WP_Post $post, array $summary): void
    {
        $approvals = is_array($summary['approvals']) ? $summary['approvals'] : ['count' => 0, 'required' => 1];
        $percent = min(100, (int) round((int) $approvals['count'] / max(1, (int) $approvals['required']) * 100));
        echo '<section class="ruvelo-ed-card"><h2>'.esc_html__('Approvals', 'ruvelo-editorial').'</h2>';
        echo '<p class="ruvelo-ed-big">'.esc_html(sprintf(
            /* translators: 1: approvals so far, 2: approvals needed */
            __('%1$d of %2$d', 'ruvelo-editorial'),
            (int) $approvals['count'],
            (int) $approvals['required']
        )).'</p><span class="ruvelo-ed-meter ruvelo-ed-meter--wide" aria-hidden="true"><span style="width:'.esc_attr((string) $percent).'%"></span></span>';
        echo '<h3>'.esc_html__('Reviewers', 'ruvelo-editorial').'</h3>';
        $reviewers = is_array($summary['reviewers']) ? $summary['reviewers'] : [];
        if ($reviewers === []) {
            echo '<p class="ruvelo-ed-quiet">'.esc_html__('Nobody yet.', 'ruvelo-editorial').'</p>';
        } else {
            $labels = ['approved' => __('Approved', 'ruvelo-editorial'), 'changes_requested' => __('Requested changes', 'ruvelo-editorial'), 'pending' => __('Hasn’t reviewed yet', 'ruvelo-editorial'), 'none' => __('Not asked this round', 'ruvelo-editorial')];
            echo '<ul class="ruvelo-ed-people-list">';
            foreach ($reviewers as $reviewer) {
                $decision = (string) $reviewer['decision'];
                echo '<li>'.Html::person((int) $reviewer['id'], $decision).'<span><strong>'.esc_html((string) $reviewer['name']).'</strong><small>'.esc_html($labels[$decision] ?? '').'</small></span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Html::person() escapes.
            }
            echo '</ul>';
        }
        echo '<dl class="ruvelo-ed-facts">';
        echo '<dt>'.esc_html__('Due', 'ruvelo-editorial').'</dt><dd>'.Html::due($post->ID).'</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Html::due() escapes.
        $notes = is_array($summary['notes']) ? $summary['notes'] : ['open' => 0, 'total' => 0];
        echo '<dt>'.esc_html__('Notes', 'ruvelo-editorial').'</dt><dd>';
        if ((int) $notes['total'] === 0) {
            echo '<span class="ruvelo-ed-quiet">'.esc_html__('None', 'ruvelo-editorial').'</span>';
        } else {
            $edit = (string) get_edit_post_link($post->ID, 'url');
            /* translators: 1: open notes, 2: all notes */
            $text = sprintf(__('%1$d open of %2$d', 'ruvelo-editorial'), (int) $notes['open'], (int) $notes['total']);
            echo $edit !== '' ? '<a href="'.esc_url($edit).'">'.esc_html($text).'</a>' : esc_html($text);
        }
        echo '</dd>';
        if (is_array($summary['requested_by'] ?? null)) {
            echo '<dt>'.esc_html__('Asked by', 'ruvelo-editorial').'</dt><dd>'.esc_html((string) $summary['requested_by']['name']).'</dd>';
        }
        if ((int) $summary['round'] > 1) {
            echo '<dt>'.esc_html__('Round', 'ruvelo-editorial').'</dt><dd>'.esc_html((string) $summary['round']).'</dd>';
        }
        echo '</dl></section>';
    }

    private static function formOpen(int $postId, string $form): void
    {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="ruvelo-ed-form">';
        echo '<input type="hidden" name="action" value="ruvelo_editorial_action"><input type="hidden" name="post" value="'.esc_attr((string) $postId).'">';
        if ($form === 'override') {
            echo '<input type="hidden" name="do" value="override">';
        }
        wp_nonce_field('ruvelo_editorial_action_'.$postId);
    }

    /**
     * admin-post.php?action=ruvelo_editorial_action
     */
    public static function handle(): void
    {
        $postId = isset($_POST['post']) ? absint($_POST['post']) : 0;
        check_admin_referer('ruvelo_editorial_action_'.$postId);
        $do = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        $user = get_current_user_id();
        $result = self::perform($do, $postId, $user, [
            'message' => $message,
            'create_note' => ! empty($_POST['create_note']),
            'reviewers' => isset($_POST['reviewers']) && is_array($_POST['reviewers']) ? array_values(array_map('absint', wp_unslash($_POST['reviewers']))) : [],
            'due' => isset($_POST['due']) ? sanitize_text_field(wp_unslash($_POST['due'])) : null,
            'reason' => isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '',
        ]);
        if ($result instanceof WP_Error) {
            Notice::flash('error', $result->get_error_message());
        } else {
            Notice::flash('success', $result);
        }
        wp_safe_redirect(self::url($postId));
        exit;
    }

    /**
     * The form actions, separate from the request so they can be tested.
     *
     * @param array{message: string, create_note: bool, reviewers: list<int>, due: string|null, reason: string} $input
     */
    public static function perform(string $do, int $postId, int $user, array $input): string|WP_Error
    {
        if (! current_user_can('edit_post', $postId)) {
            return new WP_Error('ruvelo_editorial_forbidden', __('You can’t change the review of this post.', 'ruvelo-editorial'));
        }
        switch ($do) {
            case 'approve':
                $result = Review::approve($postId, $user, $input['message'], 0, $input['create_note']);

                return $result === true ? __('Approved. The author has been told.', 'ruvelo-editorial') : $result;
            case 'changes':
                $result = Review::requestChanges($postId, $user, $input['message'], 0, $input['create_note']);

                return $result === true ? __('Changes requested. The author has been told.', 'ruvelo-editorial') : $result;
            case 'request':
                $result = Review::request($postId, $input['reviewers'], $user, $input['message'], $input['due']);

                return $result === true ? __('Review requested. The reviewers have been told.', 'ruvelo-editorial') : $result;
            case 'withdraw':
                $result = Review::withdraw($postId, $user, $input['message']);

                return $result === true ? __('Taken out of review.', 'ruvelo-editorial') : $result;
            case 'override':
                $result = Gate::allowOnce($postId, $user, $input['reason']);
                if ($result instanceof WP_Error) {
                    return $result;
                }
                $updated = wp_update_post(['ID' => $postId, 'post_status' => 'publish'], true);
                if ($updated instanceof WP_Error) {
                    return $updated;
                }
                $status = get_post_status($postId);

                return $status === 'future' ? __('Scheduled, with your reason logged.', 'ruvelo-editorial') : __('Published, with your reason logged.', 'ruvelo-editorial');
            default:
                return new WP_Error('ruvelo_editorial_unknown', __('Unknown action.', 'ruvelo-editorial'));
        }
    }
}
