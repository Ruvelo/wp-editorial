<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Tests\Feature;

use Ruvelo\WP\Editorial\History;
use Ruvelo\WP\Editorial\Install;
use Ruvelo\WP\Editorial\Notes;
use Ruvelo\WP\Editorial\Notifications;
use Ruvelo\WP\Editorial\Review;
use Ruvelo\WP\Editorial\Settings;
use Ruvelo\WP\Editorial\Tests\TestCase;

/**
 * @runTestsInSeparateProcesses
 *
 * @preserveGlobalState disabled
 */
final class UninstallTest extends TestCase
{
    public function tear_down(): void
    {
        // The outbox is always dropped; later tests need it back.
        Install::install();
        parent::tear_down();
    }

    private function uninstall(): void
    {
        if (! defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'ruvelo-editorial/ruvelo-editorial.php');
        }
        // Let DROP TABLE reach the real table.
        remove_filter('query', [$this, '_drop_temporary_tables']);
        remove_filter('query', [$this, '_create_temporary_tables']);
        include dirname(__DIR__, 2).'/uninstall.php';
    }

    private function tableExists(string $table): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public function test_by_default_the_review_history_stays(): void
    {
        $ines = $this->reviewer();
        $id = $this->inReview([$ines]);
        $note = Notes::create($id, $ines, 'Keep me.');
        $this->assertNotFalse(wp_next_scheduled(Notifications::CRON_HOOK));

        $this->uninstall();

        $this->assertFalse(wp_next_scheduled(Notifications::CRON_HOOK));
        $this->assertFalse($this->tableExists(Install::outboxTable()));
        $this->assertSame('in_review', get_post_meta($id, Review::STATE, true));
        $this->assertCount(1, History::all($id));
        $this->assertNotNull(get_comment($note));
        $this->assertFalse(get_role('editor')->has_cap(Settings::CAP_REVIEW), 'Capabilities always go.');
    }

    public function test_opting_in_deletes_states_history_and_settings_but_never_notes(): void
    {
        Settings::update(['delete_data' => true]);
        $ines = $this->reviewer();
        $id = $this->inReview([$ines]);
        $note = Notes::create($id, $ines, 'Keep me.');
        update_user_meta($ines, Notifications::USER_OPTOUT, 'off');

        $this->uninstall();

        foreach (Review::META_KEYS as $key) {
            $this->assertSame([], get_post_meta($id, $key), $key);
        }
        $this->assertFalse(get_option(Settings::OPTION));
        $this->assertSame('', get_user_meta($ines, Notifications::USER_OPTOUT, true));
        $this->assertNotNull(get_post($id));
        $this->assertNotNull(get_comment($note));
    }

    public function test_a_filter_can_decide(): void
    {
        Settings::update(['delete_data' => true]);
        add_filter('ruvelo_editorial_uninstall_delete_data', '__return_false');
        $id = $this->inReview([$this->reviewer()]);

        $this->uninstall();

        $this->assertSame('in_review', get_post_meta($id, Review::STATE, true));
    }
}
