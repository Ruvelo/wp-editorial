<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Cli;

use Ruvelo\WP\Editorial\Demo;
use Ruvelo\WP\Editorial\Gate;
use Ruvelo\WP\Editorial\Notifications;
use Ruvelo\WP\Editorial\Queue;
use Ruvelo\WP\Editorial\Review;
use Ruvelo\WP\Editorial\Settings;
use WP_CLI;
use WP_CLI\Utils;
use WP_Error;
use WP_Post;
use WP_User;

/**
 * Reviews from the command line. Actions run as the WP-CLI user, so pass
 * the global `--user` flag: `wp ruvelo-editorial approve 42 --user=ines`.
 *
 * ## EXAMPLES
 *
 *     wp ruvelo-editorial request 42 --reviewers=ines,maya --due=2026-10-14 --user=tom
 *     wp ruvelo-editorial approve 42 --message="Ship it." --user=ines
 *     wp ruvelo-editorial status 42
 *     wp ruvelo-editorial queue --view=mine --user=ines
 */
final class Command
{
    public static function register(): void
    {
        WP_CLI::add_command('ruvelo-editorial', self::class);
    }

    /**
     * Asks people to review a post (or changes who reviews it).
     *
     * ## OPTIONS
     *
     * <post>
     * : The post ID.
     *
     * --reviewers=<reviewers>
     * : Comma-separated logins, emails or user IDs.
     *
     * [--due=<date>]
     * : Due date, Y-m-d. Pass an empty value to clear it.
     *
     * [--message=<message>]
     * : A message for the reviewers.
     *
     * ## EXAMPLES
     *
     *     wp ruvelo-editorial request 42 --reviewers=ines,maya --due=2026-10-14 --user=tom
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function request(array $args, array $assoc): void
    {
        $id = $this->postId($args);
        $reviewers = [];
        foreach (array_filter(array_map('trim', explode(',', (string) ($assoc['reviewers'] ?? '')))) as $who) {
            $reviewers[] = $this->user($who)->ID;
        }
        $due = array_key_exists('due', $assoc) ? (string) $assoc['due'] : null;
        $this->done(Review::request($id, $reviewers, $this->actor(), (string) ($assoc['message'] ?? ''), $due), $id, 'Review requested.');
    }

    /**
     * Approves a post, as the --user.
     *
     * ## OPTIONS
     *
     * <post>
     * : The post ID.
     *
     * [--message=<message>]
     * : An optional comment.
     *
     * ## EXAMPLES
     *
     *     wp ruvelo-editorial approve 42 --user=ines
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function approve(array $args, array $assoc): void
    {
        $id = $this->postId($args);
        $this->done(Review::approve($id, $this->actor(), (string) ($assoc['message'] ?? '')), $id, 'Approved.');
    }

    /**
     * Requests changes on a post, as the --user.
     *
     * ## OPTIONS
     *
     * <post>
     * : The post ID.
     *
     * [--message=<message>]
     * : What needs to change.
     *
     * [--note]
     * : Also leave the message as a core note on the post.
     *
     * ## EXAMPLES
     *
     *     wp ruvelo-editorial request-changes 42 --message="The pricing table is out of date." --note --user=ines
     *
     * @subcommand request-changes
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function requestChanges(array $args, array $assoc): void
    {
        $id = $this->postId($args);
        $this->done(Review::requestChanges($id, $this->actor(), (string) ($assoc['message'] ?? ''), 0, ! empty($assoc['note'])), $id, 'Changes requested.');
    }

    /**
     * Takes a post out of review.
     *
     * ## OPTIONS
     *
     * <post>
     * : The post ID.
     *
     * [--message=<message>]
     * : Why.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function withdraw(array $args, array $assoc): void
    {
        $id = $this->postId($args);
        $this->done(Review::withdraw($id, $this->actor(), (string) ($assoc['message'] ?? '')), $id, 'Taken out of review.');
    }

    /**
     * Shows a post's review state, reviewers, approvals, notes and gate.
     *
     * ## OPTIONS
     *
     * <post>
     * : The post ID.
     *
     * [--format=<format>]
     * : table, json or yaml.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $id = $this->postId($args);
        $post = get_post($id);
        if (! $post instanceof WP_Post || ! Settings::enabled($post->post_type)) {
            $this->fail('Reviews aren’t switched on for this post type.');
        }
        $summary = Review::summary($post, get_current_user_id());
        $format = (string) ($assoc['format'] ?? 'table');
        if ($format !== 'table') {
            $summary['history'] = Review::historyFor($id);
            WP_CLI::print_value($summary, ['format' => $format]);

            return;
        }
        $gate = Gate::status($post, get_current_user_id());
        $reviewers = array_map(static fn (array $r): string => $r['name'].' ('.str_replace('_', ' ', (string) $r['decision']).')', $summary['reviewers']);
        $rows = [
            ['field' => 'post', 'value' => $summary['title'].' (#'.$id.', '.$post->post_status.')'],
            ['field' => 'state', 'value' => $summary['state_label']],
            ['field' => 'approvals', 'value' => $summary['approvals']['count'].' of '.$summary['approvals']['required']],
            ['field' => 'reviewers', 'value' => implode(', ', $reviewers)],
            ['field' => 'due', 'value' => $summary['due'].($summary['overdue'] ? ' (overdue)' : '')],
            ['field' => 'open_notes', 'value' => (string) $summary['notes']['open']],
            ['field' => 'gate', 'value' => ! $gate['enabled'] ? 'off' : ($gate['passes'] ? 'can publish' : implode(' ', $gate['reasons']))],
        ];
        Utils\format_items('table', $rows, ['field', 'value']);
    }

    /**
     * Lists a post's review history, oldest first.
     *
     * ## OPTIONS
     *
     * <post>
     * : The post ID.
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function history(array $args, array $assoc): void
    {
        $rows = array_map(static fn (array $event): array => ['at' => $event['at'], 'event' => $event['text'], 'message' => $event['message']], Review::historyFor($this->postId($args)));
        Utils\format_items((string) ($assoc['format'] ?? 'table'), $rows, ['at', 'event', 'message']);
    }

    /**
     * Lists the --user's review queue.
     *
     * ## OPTIONS
     *
     * [--view=<view>]
     * : mine (waiting for my review), changes (changes requested on my posts), approved (ready to publish) or all.
     * ---
     * default: mine
     * options:
     *   - mine
     *   - changes
     *   - approved
     *   - all
     * ---
     *
     * [--post_type=<type>]
     * : Only this post type.
     *
     * [--overdue]
     * : Only posts past their due date.
     *
     * [--format=<format>]
     * : table, json, csv, ids or count.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp ruvelo-editorial queue --user=ines
     *     wp ruvelo-editorial queue --view=approved --format=ids --user=maya
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function queue(array $args, array $assoc): void
    {
        $user = $this->actor();
        $view = (string) ($assoc['view'] ?? 'mine');
        if (! in_array($view, Queue::VIEWS, true)) {
            $this->fail('Use --view=mine, changes, approved or all.');
        }
        $result = Queue::query($view, $user, ['post_type' => (string) ($assoc['post_type'] ?? ''), 'overdue' => ! empty($assoc['overdue']), 'per_page' => 100]);
        $format = (string) ($assoc['format'] ?? 'table');
        if ($format === 'ids') {
            WP_CLI::line(implode(' ', array_map(static fn (WP_Post $post): int => $post->ID, $result['posts'])));

            return;
        }
        if ($format === 'count') {
            WP_CLI::line((string) $result['total']);

            return;
        }
        $rows = array_map(static function (WP_Post $post) use ($user): array {
            $summary = Review::summary($post, $user);

            return [
                'ID' => $post->ID,
                'title' => $summary['title'],
                'state' => $summary['state_label'],
                'approvals' => $summary['approvals']['count'].'/'.$summary['approvals']['required'],
                'author' => $summary['author']['name'],
                'due' => $summary['due'],
                'open_notes' => $summary['notes']['open'],
            ];
        }, $result['posts']);
        Utils\format_items($format, $rows, ['ID', 'title', 'state', 'approvals', 'author', 'due', 'open_notes']);
    }

    /**
     * Sends the review emails that are waiting, now.
     *
     * ## EXAMPLES
     *
     *     wp ruvelo-editorial notify
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function notify(array $args = [], array $assoc = []): void
    {
        $sent = Notifications::run(1000);
        WP_CLI::success(sprintf('%d email(s) sent, %d still waiting.', $sent, Notifications::pending()));
    }

    /**
     * Seeds Halyard's marketing team and posts in every review state, for
     * the Playground demo and screenshots.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Don't ask for confirmation.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function demo(array $args, array $assoc): void
    {
        WP_CLI::confirm('This creates demo users and posts on this site. Continue?', $assoc);
        $result = Demo::seed();
        WP_CLI::success(sprintf('Halyard is ready: %d posts, %d people. Sign in as ines.laurent / password to review.', $result['posts'], $result['people']));
    }

    /**
     * @param list<string> $args
     */
    private function postId(array $args): int
    {
        $id = (int) ($args[0] ?? 0);
        if ($id <= 0 || ! get_post($id) instanceof WP_Post) {
            $this->fail('Give a post ID.');
        }

        return $id;
    }

    private function actor(): int
    {
        $id = get_current_user_id();
        if ($id <= 0) {
            $this->fail('Run it as someone: add --user=<login>.');
        }

        return $id;
    }

    private function user(string $who): WP_User
    {
        $user = is_numeric($who) ? get_userdata((int) $who) : (get_user_by('login', $who) ?: get_user_by('email', $who));
        if (! $user instanceof WP_User) {
            $this->fail(sprintf('No user "%s".', $who));
        }

        return $user;
    }

    /**
     * $this->fail() exits; the stubs don't say so.
     */
    private function fail(string $message): never
    {
        WP_CLI::error($message);
        exit(1);
    }

    private function done(true|WP_Error $result, int $postId, string $message): void
    {
        if ($result instanceof WP_Error) {
            $this->fail($result->get_error_message());
        }
        $post = get_post($postId);
        WP_CLI::success($message.($post instanceof WP_Post ? ' Now: '.Review::summary($post)['state_label'].'.' : ''));
    }
}
