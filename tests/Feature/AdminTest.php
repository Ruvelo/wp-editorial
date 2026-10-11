<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Tests\Feature;

use Ruvelo\WP\Editorial\Admin\Admin;
use Ruvelo\WP\Editorial\Admin\Dashboard;
use Ruvelo\WP\Editorial\Admin\QueuePage;
use Ruvelo\WP\Editorial\Admin\ReviewPage;
use Ruvelo\WP\Editorial\Admin\SettingsPage;
use Ruvelo\WP\Editorial\History;
use Ruvelo\WP\Editorial\Notifications;
use Ruvelo\WP\Editorial\Review;
use Ruvelo\WP\Editorial\Settings;
use Ruvelo\WP\Editorial\States;
use Ruvelo\WP\Editorial\Tests\TestCase;
use WP_Error;

final class AdminTest extends TestCase
{
    public function set_up(): void
    {
        parent::set_up();
        require_once ABSPATH.'wp-admin/includes/admin.php';
        set_current_screen('toplevel_page_'.QueuePage::SLUG);
    }

    public function tear_down(): void
    {
        unset($_GET['view'], $_GET['post'], $_POST['ruvelo_editorial_profile_nonce'], $_POST['ruvelo_editorial_emails'], $_POST['ruvelo_editorial_theme']);
        set_current_screen('front');
        parent::tear_down();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function perform(string $do, int $postId, int $user, array $input = []): string|WP_Error
    {
        wp_set_current_user($user);

        return ReviewPage::perform($do, $postId, $user, $input + ['message' => '', 'create_note' => false, 'reviewers' => [], 'due' => null, 'reason' => '']);
    }

    public function test_every_action_works_as_a_plain_form(): void
    {
        $tom = $this->user();
        $ines = $this->reviewer();
        $id = $this->post($tom);

        $this->assertSame('Review requested. The reviewers have been told.', $this->perform('request', $id, $tom, ['reviewers' => [$ines], 'due' => '2026-10-14']));
        $this->assertSame('Changes requested. The author has been told.', $this->perform('changes', $id, $ines, ['message' => 'Not yet.', 'create_note' => true]));
        $this->assertSame('Review requested. The reviewers have been told.', $this->perform('request', $id, $tom, ['reviewers' => [$ines]]));
        $this->assertSame('Approved. The author has been told.', $this->perform('approve', $id, $ines));
        $this->assertSame(States::APPROVED, Review::state($id));
        $this->assertSame('Taken out of review.', $this->perform('withdraw', $id, $tom));
        $this->assertInstanceOf(WP_Error::class, $this->perform('approve', $id, $this->user('author')));
        $this->assertInstanceOf(WP_Error::class, $this->perform('nonsense', $id, $tom));
    }

    public function test_publishing_past_the_gate_from_the_review_screen(): void
    {
        $this->gate(2);
        $tom = $this->user('editor');
        $id = $this->inReview([$this->reviewer(), $this->reviewer()], $tom);
        $admin = $this->admin();

        $this->assertInstanceOf(WP_Error::class, $this->perform('override', $id, $admin), 'A reason is required.');
        $this->assertSame('draft', get_post_status($id));

        $this->assertSame('Published, with your reason logged.', $this->perform('override', $id, $admin, ['reason' => 'Status page update.']));
        $this->assertSame('publish', get_post_status($id));
        $this->assertSame('override', History::last($id)['type'] ?? '');
    }

    public function test_the_queue_screen(): void
    {
        $ines = $this->reviewer();
        $tom = $this->user('author', 'Tom Reyes');
        $id = $this->post($tom);
        Review::request($id, [$ines], $tom, '', '2020-01-01');
        wp_set_current_user($ines);

        ob_start();
        QueuePage::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Waiting for my review', $html);
        $this->assertStringContainsString('How Halyard retries failed payments', $html);
        $this->assertStringContainsString('Post by Tom Reyes', $html);
        $this->assertStringContainsString('ruvelo-ed-badge ruvelo-ed-tone-accent', $html);
        $this->assertStringContainsString('Overdue', $html);
        $this->assertStringContainsString('0 of 1 approvals', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
    }

    public function test_an_empty_queue_invites_the_next_step(): void
    {
        wp_set_current_user($this->reviewer());
        $_GET['view'] = 'mine';

        ob_start();
        QueuePage::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Nothing is waiting for your review.', $html);
    }

    public function test_the_review_screen_shows_the_history_and_forms(): void
    {
        $ines = $this->reviewer('Inès Laurent');
        $tom = $this->user('author', 'Tom Reyes');
        $id = $this->inReview([$ines], $tom);
        Review::requestChanges($id, $ines, 'Give each email its own section. <script>alert(1)</script>');
        wp_set_current_user($tom);
        $_GET['post'] = (string) $id;

        ob_start();
        ReviewPage::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Inès Laurent requested changes', $html);
        $this->assertStringContainsString('Give each email its own section.', $html);
        $this->assertStringNotContainsString('<script>', $html, 'Messages are plain text.');
        $this->assertStringContainsString('Tom Reyes asked Inès Laurent for a review.', $html);
        $this->assertStringContainsString('Ask for a review again', $html);
        $this->assertStringContainsString('name="_wpnonce"', $html);
        $this->assertStringNotContainsString('value="approve"', $html, 'The author can’t approve.');
    }

    public function test_settings_are_sanitised(): void
    {
        $clean = SettingsPage::sanitize([
            'post_types' => ['post' => ['enabled' => '1', 'gate' => '1', 'approvals' => '99', 'resolve_notes' => '1'], 'nonsense' => ['enabled' => '1']],
            'emails' => '1',
            'digest_minutes' => '-4',
        ]);

        $this->assertSame(['enabled' => true, 'gate' => true, 'approvals' => 10, 'resolve_notes' => true], $clean['post_types']['post']);
        $this->assertArrayNotHasKey('nonsense', $clean['post_types']);
        $this->assertFalse($clean['post_types']['page']['enabled']);
        $this->assertSame(0, $clean['digest_minutes']);
        $this->assertFalse($clean['inbox']);
    }

    public function test_the_settings_screen_needs_the_capability(): void
    {
        $this->assertFalse(user_can($this->reviewer(), Settings::CAP_MANAGE));
        $this->assertTrue(user_can($this->admin(), Settings::CAP_MANAGE));
        wp_set_current_user($this->admin());

        ob_start();
        SettingsPage::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Hold publishing until approved', $html);
        $this->assertStringContainsString('No inbox is active', $html);
    }

    public function test_the_review_column(): void
    {
        $ines = $this->reviewer();
        $id = $this->inReview([$ines]);
        Review::approve($id, $ines);

        ob_start();
        Admin::column('ruvelo_review', $id);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Approved', $html);
        $this->assertStringContainsString('1 of 1 approvals', $html);
        $this->assertSame(['cb' => '', 'title' => 'Title', 'ruvelo_review' => 'Review', 'date' => 'Date'], Admin::addColumn(['cb' => '', 'title' => 'Title', 'date' => 'Date']));
    }

    public function test_the_dashboard_widget(): void
    {
        $ines = $this->reviewer();
        $this->inReview([$ines]);
        wp_set_current_user($ines);

        ob_start();
        Dashboard::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Waiting for you', $html);
        $this->assertStringContainsString('How Halyard retries failed payments', $html);
        $this->assertStringContainsString('Open the review queue', $html);
    }

    public function test_profile_preferences(): void
    {
        $tom = $this->user();
        wp_set_current_user($tom);
        $_POST['ruvelo_editorial_profile_nonce'] = wp_create_nonce('ruvelo_editorial_profile');
        $_POST['ruvelo_editorial_theme'] = 'dark';

        Admin::saveProfile($tom);

        $this->assertSame('off', get_user_meta($tom, Notifications::USER_OPTOUT, true));
        $this->assertSame('dark', Admin::theme($tom));
    }

    public function test_profile_preferences_need_a_nonce(): void
    {
        $tom = $this->user();
        wp_set_current_user($tom);
        $_POST['ruvelo_editorial_theme'] = 'dark';

        Admin::saveProfile($tom);

        $this->assertSame('light', Admin::theme($tom));
    }
}
