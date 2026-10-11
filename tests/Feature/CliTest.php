<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Tests\Feature;

use RuntimeException;
use Ruvelo\WP\Editorial\Cli\Command;
use Ruvelo\WP\Editorial\Notifications;
use Ruvelo\WP\Editorial\Review;
use Ruvelo\WP\Editorial\States;
use Ruvelo\WP\Editorial\Tests\TestCase;
use WP_CLI;

require_once dirname(__DIR__).'/Support/FakeCli.php';

final class CliTest extends TestCase
{
    private Command $cli;

    public function set_up(): void
    {
        parent::set_up();
        WP_CLI::$out = [];
        $this->cli = new Command;
    }

    public function test_request_approve_status_and_queue(): void
    {
        $tom = self::factory()->user->create(['role' => 'author', 'user_login' => 'tom']);
        $ines = self::factory()->user->create(['role' => 'editor', 'user_login' => 'ines', 'display_name' => 'Inès Laurent']);
        $id = $this->post($tom);

        wp_set_current_user($tom);
        $this->cli->request([(string) $id], ['reviewers' => 'ines', 'due' => '2026-10-14', 'message' => 'Numbers, please.']);
        $this->assertSame('Review requested. Now: In review.', WP_CLI::last());
        $this->assertSame([$ines], Review::reviewers($id));

        wp_set_current_user($ines);
        $this->cli->queue([], ['format' => 'ids']);
        $this->assertSame((string) $id, WP_CLI::last());
        $this->cli->queue([], ['format' => 'count']);
        $this->assertSame('1', WP_CLI::last());

        $this->cli->approve([(string) $id], ['message' => 'Ship it.']);
        $this->assertSame(States::APPROVED, Review::state($id));

        $this->cli->status([(string) $id], []);
        $table = json_decode(WP_CLI::last(), true);
        $this->assertSame('Approved', $table[1]['value']);
        $this->assertSame('1 of 1', $table[2]['value']);
        $this->assertSame('Inès Laurent (approved)', $table[3]['value']);

        $this->cli->history([(string) $id], []);
        $this->assertCount(3, json_decode(WP_CLI::last(), true));
    }

    public function test_request_changes_and_withdraw(): void
    {
        $tom = $this->user();
        $ines = self::factory()->user->create(['role' => 'editor', 'user_login' => 'ines2']);
        $id = $this->inReview([$ines], $tom);

        wp_set_current_user($ines);
        $this->cli->requestChanges([(string) $id], ['message' => 'The table is out of date.', 'note' => true]);
        $this->assertSame(States::CHANGES, Review::state($id));

        wp_set_current_user($tom);
        $this->cli->withdraw([(string) $id], []);
        $this->assertSame(States::DRAFT, Review::state($id));
    }

    public function test_actions_need_a_user(): void
    {
        $id = $this->post();
        wp_set_current_user(0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Run it as someone: add --user=<login>.');
        $this->cli->approve([(string) $id], []);
    }

    public function test_errors_are_reported(): void
    {
        $tom = $this->user();
        $id = $this->post($tom);
        wp_set_current_user($tom);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No user "nobody".');
        $this->cli->request([(string) $id], ['reviewers' => 'nobody']);
    }

    public function test_sending_waiting_emails(): void
    {
        $this->inReview([$this->reviewer()]);

        $this->cli->notify();

        $this->assertSame('1 email(s) sent, 0 still waiting.', WP_CLI::last());
        $this->assertSame(0, Notifications::pending());
    }
}
