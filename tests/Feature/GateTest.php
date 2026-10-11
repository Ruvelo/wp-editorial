<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Tests\Feature;

use Ruvelo\WP\Editorial\Gate;
use Ruvelo\WP\Editorial\History;
use Ruvelo\WP\Editorial\Notes;
use Ruvelo\WP\Editorial\Review;
use Ruvelo\WP\Editorial\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

final class GateTest extends TestCase
{
    public function set_up(): void
    {
        parent::set_up();
        $this->gate(1);
        do_action('rest_api_init', rest_get_server());
    }

    private function restPublish(int $id, string $status = 'publish', array $extra = []): \WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/wp/v2/posts/'.$id);
        $request->set_body_params(['status' => $status] + $extra);

        return rest_get_server()->dispatch($request);
    }

    public function test_without_approval_publishing_keeps_the_draft(): void
    {
        $editor = $this->user('editor');
        wp_set_current_user($editor);
        $id = $this->post($editor);
        $held = 0;
        add_action('ruvelo_editorial_publish_blocked', static function () use (&$held): void {
            $held++;
        });

        wp_update_post(['ID' => $id, 'post_status' => 'publish']);

        $this->assertSame('draft', get_post_status($id));
        $this->assertSame(1, $held);
        $error = Gate::blocked($id);
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('It needs 1 approval before it can be published.', $error->get_error_message());
    }

    public function test_scheduling_is_held_too(): void
    {
        $editor = $this->user('editor');
        wp_set_current_user($editor);
        $id = $this->post($editor);

        wp_update_post(['ID' => $id, 'post_status' => 'future', 'post_date' => gmdate('Y-m-d H:i:s', time() + WEEK_IN_SECONDS), 'edit_date' => true]);

        $this->assertSame('draft', get_post_status($id));
    }

    public function test_pending_posts_stay_pending(): void
    {
        $editor = $this->user('editor');
        wp_set_current_user($editor);
        $id = $this->post($editor, 'post', 'pending');

        wp_update_post(['ID' => $id, 'post_status' => 'publish']);

        $this->assertSame('pending', get_post_status($id));
    }

    public function test_a_post_created_straight_into_publish_is_held(): void
    {
        wp_set_current_user($this->user('editor'));

        $id = wp_insert_post(['post_title' => 'Skipping review', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => 'Hello']);

        $this->assertIsInt($id);
        $this->assertSame('draft', get_post_status($id));
    }

    public function test_approved_posts_publish_and_the_publish_is_logged(): void
    {
        $author = $this->user('editor');
        $ines = $this->reviewer();
        $id = $this->inReview([$ines], $author);
        Review::approve($id, $ines);
        wp_set_current_user($author);

        wp_update_post(['ID' => $id, 'post_status' => 'publish']);

        $this->assertSame('publish', get_post_status($id));
        $this->assertSame('published', History::last($id)['type'] ?? '');
    }

    public function test_the_rest_api_refuses_with_the_reason(): void
    {
        $editor = $this->user('editor');
        wp_set_current_user($editor);
        $id = $this->post($editor);

        $response = $this->restPublish($id, 'publish', ['title' => 'Changed title']);

        $this->assertSame(403, $response->get_status());
        $this->assertSame('ruvelo_editorial_gate', $response->get_data()['code']);
        $this->assertSame('It needs 1 approval before it can be published.', $response->get_data()['message']);
        $this->assertSame('draft', get_post_status($id));
        $this->assertSame('How Halyard retries failed payments', get_the_title($id), 'Nothing in that save was applied.');
    }

    public function test_the_rest_api_refuses_creating_a_published_post(): void
    {
        wp_set_current_user($this->user('editor'));
        $request = new WP_REST_Request('POST', '/wp/v2/posts');
        $request->set_body_params(['title' => 'Straight out', 'status' => 'publish']);

        $response = rest_get_server()->dispatch($request);

        $this->assertSame(403, $response->get_status());
    }

    public function test_the_rest_api_still_saves_drafts(): void
    {
        $editor = $this->user('editor');
        wp_set_current_user($editor);
        $id = $this->post($editor);

        $response = $this->restPublish($id, 'draft', ['title' => 'New title']);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('New title', get_the_title($id));
    }

    public function test_the_rest_api_publishes_approved_posts(): void
    {
        $author = $this->user('editor');
        $ines = $this->reviewer();
        $id = $this->inReview([$ines], $author);
        Review::approve($id, $ines);
        wp_set_current_user($author);

        $response = $this->restPublish($id);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('publish', get_post_status($id));
    }

    public function test_quick_edit_cannot_publish_past_the_gate(): void
    {
        require_once ABSPATH.'wp-admin/includes/post.php';
        $editor = $this->user('editor');
        wp_set_current_user($editor);
        $id = $this->post($editor);

        // What wp_ajax_inline_save() hands to edit_post().
        edit_post([
            'post_ID' => $id,
            'post_type' => 'post',
            'post_title' => 'Quick edited',
            '_status' => 'publish',
            'post_status' => 'publish',
            'post_author' => $editor,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'edit_date' => '',
        ]);

        $this->assertSame('draft', get_post_status($id));
        $this->assertSame('Quick edited', get_the_title($id), 'The rest of the edit is saved.');
    }

    public function test_bulk_edit_cannot_publish_past_the_gate(): void
    {
        require_once ABSPATH.'wp-admin/includes/post.php';
        $editor = $this->user('editor');
        wp_set_current_user($editor);
        $ids = [$this->post($editor), $this->post($editor)];

        bulk_edit_posts(['post' => $ids, '_status' => 'publish', 'post_type' => 'post']);

        foreach ($ids as $id) {
            $this->assertSame('draft', get_post_status($id));
        }
    }

    public function test_published_posts_update_freely(): void
    {
        $editor = $this->user('editor');
        wp_set_current_user($editor);
        add_filter('ruvelo_editorial_gate_passes', '__return_true');
        $id = $this->post($editor, 'post', 'publish');
        remove_filter('ruvelo_editorial_gate_passes', '__return_true');

        wp_update_post(['ID' => $id, 'post_title' => 'Fixed a typo']);

        $this->assertSame('publish', get_post_status($id));
        $this->assertSame('Fixed a typo', get_the_title($id));
    }

    public function test_without_a_gate_nothing_is_held(): void
    {
        $this->configure('post', ['enabled' => true, 'gate' => false]);
        $editor = $this->user('editor');
        wp_set_current_user($editor);
        $id = $this->post($editor);

        wp_update_post(['ID' => $id, 'post_status' => 'publish']);

        $this->assertSame('publish', get_post_status($id));
    }

    public function test_changes_requested_holds_publishing_with_its_own_reason(): void
    {
        $author = $this->user('editor');
        $ines = $this->reviewer();
        $id = $this->inReview([$ines], $author);
        Review::approve($id, $ines);
        Review::requestChanges($id, $ines, 'Hold on.');
        $post = get_post($id);
        $this->assertNotNull($post);

        $this->assertSame(['Changes were requested. Make them, then ask for a review again.'], Gate::reasons($post));
    }

    public function test_open_notes_can_hold_publishing(): void
    {
        $this->gate(1, true);
        $author = $this->user('editor');
        $ines = $this->reviewer();
        $id = $this->inReview([$ines], $author);
        Review::approve($id, $ines);
        $note = Notes::create($id, $ines, 'One last thing.');
        $this->assertIsInt($note);
        $post = get_post($id);
        $this->assertNotNull($post);

        $this->assertSame(['Resolve its 1 open note first.'], Gate::reasons($post));

        // Resolve it the way the editor does.
        wp_set_comment_status($note, 'approve');
        $this->assertSame([], Gate::reasons($post));
    }

    public function test_an_override_needs_the_capability_and_a_reason(): void
    {
        $editor = $this->user('editor');
        $id = $this->post($editor);

        $this->assertInstanceOf(WP_Error::class, Gate::allowOnce($id, $editor, 'Urgent.'));
        $admin = $this->admin();
        $empty = Gate::allowOnce($id, $admin, '   ');
        $this->assertInstanceOf(WP_Error::class, $empty);
        $this->assertSame('ruvelo_editorial_reason_required', $empty->get_error_code());
    }

    public function test_overriding_publishes_once_and_logs_the_reason(): void
    {
        $author = $this->user('editor');
        $ines = $this->reviewer();
        $id = $this->inReview([$ines], $author);
        $admin = $this->admin();
        wp_set_current_user($admin);
        $seen = null;
        add_action('ruvelo_editorial_gate_overridden', static function (int $postId, array $event) use (&$seen): void {
            $seen = $event;
        }, 10, 2);

        $this->assertTrue(Gate::allowOnce($id, $admin, 'Incident update; reviewed on a call.'));
        $post = get_post($id);
        $this->assertNotNull($post);
        $this->assertTrue(Gate::status($post, $admin)['passes']);
        $this->assertFalse(Gate::status($post, $author)['passes'], 'Only for the person who overrode.');

        wp_update_post(['ID' => $id, 'post_status' => 'publish']);

        $this->assertSame('publish', get_post_status($id));
        $last = History::last($id);
        $this->assertNotNull($last);
        $this->assertSame('override', $last['type']);
        $this->assertSame('Incident update; reviewed on a call.', $last['note']);
        $this->assertSame($admin, $last['user']);
        $this->assertIsArray($seen);
        $this->assertSame('', get_post_meta($id, Gate::OVERRIDE, true), 'Used up.');
        $this->assertSame([], Review::pending($id), 'Nobody waits on a published post.');
        $told = array_filter($this->outbox(), static fn (array $row): bool => $row['event'] === 'override');
        $this->assertEqualsCanonicalizing([$ines, $author], array_map('intval', array_column($told, 'user_id')));
    }

    public function test_an_override_expires_after_an_hour(): void
    {
        $admin = $this->admin();
        $id = $this->post($this->user('editor'));
        Gate::allowOnce($id, $admin, 'Urgent.');
        update_post_meta($id, Gate::OVERRIDE, ['user' => $admin, 'reason' => 'Urgent.', 'at' => time() - 2 * HOUR_IN_SECONDS]);
        wp_set_current_user($admin);

        wp_update_post(['ID' => $id, 'post_status' => 'publish']);

        $this->assertSame('draft', get_post_status($id));
    }

    public function test_the_override_works_through_rest(): void
    {
        $admin = $this->admin();
        $id = $this->post($this->user('editor'));
        wp_set_current_user($admin);

        $request = new WP_REST_Request('POST', '/ruvelo-editorial/v1/posts/'.$id.'/override');
        $request->set_body_params(['reason' => 'Legal asked for it today.']);
        $this->assertSame(200, rest_get_server()->dispatch($request)->get_status());

        $this->assertSame(200, $this->restPublish($id)->get_status());
        $this->assertSame('override', History::last($id)['type'] ?? '');
    }

    public function test_a_filter_can_let_a_publish_through_and_it_is_logged(): void
    {
        add_filter('ruvelo_editorial_gate_passes', '__return_true');
        $editor = $this->user('editor');
        wp_set_current_user($editor);
        $id = $this->post($editor);

        wp_update_post(['ID' => $id, 'post_status' => 'publish']);

        $this->assertSame('publish', get_post_status($id));
        $this->assertSame('published_unapproved', History::last($id)['type'] ?? '');
    }

    public function test_direct_publishing_in_code_is_logged_as_unapproved(): void
    {
        $id = $this->post($this->user('editor'));

        wp_publish_post($id);

        $this->assertSame('publish', get_post_status($id));
        $this->assertSame('published_unapproved', History::last($id)['type'] ?? '');
    }

    public function test_cli_without_a_user_cannot_publish(): void
    {
        wp_set_current_user(0);
        $id = $this->post($this->user('editor'));

        wp_update_post(['ID' => $id, 'post_status' => 'publish']);

        $this->assertSame('draft', get_post_status($id));
    }
}
