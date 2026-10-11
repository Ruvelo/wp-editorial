<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Tests;

use Ruvelo\WP\Editorial\Clock;
use Ruvelo\WP\Editorial\Gate;
use Ruvelo\WP\Editorial\Install;
use Ruvelo\WP\Editorial\Notifications;
use Ruvelo\WP\Editorial\Queue;
use Ruvelo\WP\Editorial\Review;
use Ruvelo\WP\Editorial\Settings;
use WP_UnitTestCase;

abstract class TestCase extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        global $wpdb;
        $wpdb->query('DELETE FROM '.Install::outboxTable()); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        delete_option(Settings::OPTION);
        Settings::flush();
        Install::grantCapabilities();
        Queue::flush();
        Gate::reset();
        Clock::freeze(null);
        Notifications::pause(false);
        wp_clear_scheduled_hook(Notifications::CRON_HOOK);
        reset_phpmailer_instance();
    }

    public function tear_down(): void
    {
        Clock::freeze(null);
        parent::tear_down();
    }

    protected function user(string $role = 'author', string $name = ''): int
    {
        $args = ['role' => $role];
        if ($name !== '') {
            $args['display_name'] = $name;
        }

        return self::factory()->user->create($args);
    }

    protected function reviewer(string $name = ''): int
    {
        return $this->user('editor', $name);
    }

    protected function admin(): int
    {
        return $this->user('administrator');
    }

    protected function post(int $author = 0, string $type = 'post', string $status = 'draft', string $content = ''): int
    {
        $author = $author > 0 ? $author : $this->user();
        $content = $content !== '' ? $content : "<!-- wp:paragraph -->\n<p>First paragraph.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Second paragraph.</p>\n<!-- /wp:paragraph -->";

        return self::factory()->post->create(['post_author' => $author, 'post_type' => $type, 'post_status' => $status, 'post_title' => 'How Halyard retries failed payments', 'post_content' => $content]);
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function configure(string $type, array $config): void
    {
        $types = Settings::get('post_types');
        $types = is_array($types) ? $types : [];
        $types[$type] = $config + Settings::postTypeDefaults(true);
        Settings::update(['post_types' => $types]);
    }

    protected function gate(int $approvals = 1, bool $notes = false, string $type = 'post'): void
    {
        $this->configure($type, ['enabled' => true, 'gate' => true, 'approvals' => $approvals, 'resolve_notes' => $notes]);
    }

    /**
     * A post in review with these reviewers, asked by its author.
     *
     * @param list<int> $reviewers
     */
    protected function inReview(array $reviewers, int $author = 0): int
    {
        $author = $author > 0 ? $author : $this->user();
        $id = $this->post($author);
        $this->assertTrue(Review::request($id, $reviewers, $author));

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function outbox(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results('SELECT * FROM '.Install::outboxTable().' ORDER BY id ASC', ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        return is_array($rows) ? array_values($rows) : [];
    }
}
