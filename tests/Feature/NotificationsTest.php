<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Tests\Feature;

use Ruvelo\WP\Editorial\Notifications;
use Ruvelo\WP\Editorial\Review;
use Ruvelo\WP\Editorial\Settings;
use Ruvelo\WP\Editorial\Tests\TestCase;

final class NotificationsTest extends TestCase
{
    /**
     * @return list<array{to: string, subject: string, body: string}>
     */
    private function sent(): array
    {
        $mailer = tests_retrieve_phpmailer_instance();
        $mails = [];
        foreach ($mailer->mock_sent as $mail) {
            $mails[] = ['to' => $mail['to'][0][0], 'subject' => $mail['subject'], 'body' => $mail['body']];
        }

        return $mails;
    }

    public function test_a_request_queues_one_row_per_reviewer_and_schedules_cron(): void
    {
        $author = $this->user('author', 'Tom Reyes');
        $ines = $this->reviewer();
        $maya = $this->reviewer();

        $this->inReview([$ines, $maya], $author);

        $rows = $this->outbox();
        $this->assertSame([$ines, $maya], array_map('intval', array_column($rows, 'user_id')));
        $this->assertSame(['requested', 'requested'], array_column($rows, 'event'));
        $this->assertNotFalse(wp_next_scheduled(Notifications::CRON_HOOK));
        $this->assertSame([], $this->sent(), 'Nothing is sent inside the request.');
    }

    public function test_the_actor_is_never_told_about_their_own_action(): void
    {
        $ines = $this->reviewer();
        $author = $this->user();
        $id = $this->inReview([$ines], $author);
        global $wpdb;
        $wpdb->query('DELETE FROM '.$wpdb->prefix.'ruvelo_editorial_outbox'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        Review::approve($id, $ines);

        $this->assertNotContains((string) $ines, array_column($this->outbox(), 'user_id'));
        $this->assertSame(['fully_approved'], array_column($this->outbox(), 'event'));
    }

    public function test_each_person_gets_one_digest_with_everything(): void
    {
        $this->configure('post', ['approvals' => 2]);
        $author = $this->user('author', 'Tom Reyes');
        $ines = $this->reviewer('Inès Laurent');
        $maya = $this->reviewer('Maya Okafor');
        $id = $this->inReview([$ines, $maya], $author);
        Review::approve($id, $maya, 'Numbers check out.');
        Review::requestChanges($id, $ines, 'Split the last section.');

        $sent = Notifications::run();

        $this->assertSame(3, $sent);
        $mails = $this->sent();
        $byTo = [];
        foreach ($mails as $mail) {
            $byTo[$mail['to']] = $mail;
        }
        $tom = $byTo[get_userdata($author)->user_email];
        $this->assertSame('[Test Blog] 2 review updates', $tom['subject']);
        $this->assertStringContainsString('Maya Okafor approved “How Halyard retries failed payments” (1 of 2 approvals).', $tom['body']);
        $this->assertStringContainsString('Inès Laurent requested changes on “How Halyard retries failed payments”.', $tom['body']);
        $this->assertStringContainsString('> Split the last section.', $tom['body']);
        $this->assertStringContainsString('post.php?post='.$id.'&action=edit', $tom['body']);

        $toInes = $byTo[get_userdata($ines)->user_email];
        $this->assertSame('[Test Blog] Tom Reyes asked you to review “How Halyard retries failed payments”.', $toInes['subject']);
        $this->assertSame(0, Notifications::pending());
        $this->assertSame(0, Notifications::run(), 'Nothing is sent twice.');
    }

    public function test_the_due_date_is_in_the_request_email(): void
    {
        $author = $this->user('author', 'Tom Reyes');
        $ines = $this->reviewer();
        Review::request($this->post($author), [$ines], $author, '', '2026-10-14');

        Notifications::run();

        $this->assertStringContainsString('by October 14, 2026', $this->sent()[0]['subject']);
    }

    public function test_batches_are_limited_and_cron_comes_back_for_the_rest(): void
    {
        $author = $this->user();
        $reviewers = [$this->reviewer(), $this->reviewer(), $this->reviewer()];
        $this->inReview($reviewers, $author);
        wp_clear_scheduled_hook(Notifications::CRON_HOOK);

        $this->assertSame(2, Notifications::run(2));

        $this->assertSame(1, Notifications::pending());
        $this->assertNotFalse(wp_next_scheduled(Notifications::CRON_HOOK));
    }

    public function test_people_can_turn_emails_off(): void
    {
        $ines = $this->reviewer();
        update_user_meta($ines, Notifications::USER_OPTOUT, 'off');
        $this->inReview([$ines]);

        $this->assertSame(0, Notifications::run());
        $this->assertSame([], $this->sent());
        $this->assertSame(0, Notifications::pending());
    }

    public function test_the_site_can_turn_emails_off(): void
    {
        Settings::update(['emails' => false]);
        $this->inReview([$this->reviewer()]);

        $this->assertSame([], $this->outbox());
    }

    public function test_ruvelo_inbox_gets_the_events_when_it_is_active(): void
    {
        $delivered = [];
        add_action('ruvelo_inbox_notify', static function (array $users, array $message) use (&$delivered): void {
            $delivered[] = [$users, $message];
        }, 10, 2);
        $author = $this->user('author', 'Tom Reyes');
        $ines = $this->reviewer();

        $id = $this->inReview([$ines], $author);

        $this->assertCount(1, $delivered);
        [$users, $message] = $delivered[0];
        $this->assertSame([$ines], $users);
        $this->assertSame('Tom Reyes asked you to review “How Halyard retries failed payments”.', $message['title']);
        $this->assertSame('ruvelo-editorial/requested', $message['type']);
        $this->assertSame($id, $message['object_id']);
        $this->assertStringContainsString('post.php?post='.$id, $message['url']);
        $this->assertSame('Tom Reyes', $message['actor']);
    }

    public function test_without_an_inbox_nothing_is_fired(): void
    {
        $this->assertFalse(has_action('ruvelo_inbox_notify'));
        $fired = 0;
        add_action('ruvelo_editorial_notification', static function () use (&$fired): void {
            $fired++;
        });

        $this->inReview([$this->reviewer()]);

        $this->assertSame(1, $fired, 'The plugin’s own action always fires.');
    }

    public function test_the_inbox_can_be_switched_off(): void
    {
        Settings::update(['inbox' => false]);
        $delivered = 0;
        add_action('ruvelo_inbox_notify', static function () use (&$delivered): void {
            $delivered++;
        });

        $this->inReview([$this->reviewer()]);

        $this->assertSame(0, $delivered);
    }

    public function test_paused_notifications_queue_nothing(): void
    {
        Notifications::pause(true);
        $this->inReview([$this->reviewer()]);
        Notifications::pause(false);

        $this->assertSame([], $this->outbox());
    }

    public function test_the_email_can_be_filtered(): void
    {
        add_filter('ruvelo_editorial_email', static function (array $email): array {
            $email['subject'] = 'Custom: '.$email['subject'];

            return $email;
        });
        $this->inReview([$this->reviewer()]);

        Notifications::run();

        $this->assertStringStartsWith('Custom: ', $this->sent()[0]['subject']);
    }

    public function test_deleting_a_post_drops_its_queued_emails(): void
    {
        $id = $this->inReview([$this->reviewer()]);

        wp_delete_post($id, true);

        $this->assertSame([], $this->outbox());
    }
}
