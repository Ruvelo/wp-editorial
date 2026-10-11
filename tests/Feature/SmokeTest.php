<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Tests\Feature;

use Ruvelo\WP\Editorial\Notes;
use Ruvelo\WP\Editorial\Notifications;
use Ruvelo\WP\Editorial\Review;
use Ruvelo\WP\Editorial\Settings;
use Ruvelo\WP\Editorial\Tests\TestCase;

final class SmokeTest extends TestCase
{
    public function test_the_plugin_is_loaded_on_a_supported_wordpress(): void
    {
        $this->assertTrue(version_compare((string) get_bloginfo('version'), '7.0', '>='));
        $this->assertSame('1.0.0', RUVELO_EDITORIAL_VERSION);
        $this->assertSame(['post', 'page'], Settings::enabledTypes());
        $this->assertFalse(Settings::forType('post')['gate'], 'Gates are opt-in.');
    }

    public function test_capabilities_go_to_the_right_roles(): void
    {
        $this->assertTrue(get_role('editor')->has_cap(Settings::CAP_REVIEW));
        $this->assertFalse(get_role('editor')->has_cap(Settings::CAP_OVERRIDE));
        $this->assertFalse(get_role('author')->has_cap(Settings::CAP_REVIEW));
        $this->assertTrue(get_role('administrator')->has_cap(Settings::CAP_OVERRIDE));
        $this->assertTrue(get_role('administrator')->has_cap(Settings::CAP_MANAGE));
    }

    public function test_notes_storage_matches_this_wordpress_version(): void
    {
        // 7.1 added @mentions (and a list of note ids per block); 7.0 has neither.
        $this->assertSame(version_compare((string) get_bloginfo('version'), '7.1-alpha', '>='), Notes::multipleNotesPerBlock());
    }

    public function test_nothing_is_fetched_from_elsewhere(): void
    {
        $requests = 0;
        add_filter('pre_http_request', static function ($pre) use (&$requests) {
            $requests++;

            return $pre;
        });
        $id = $this->inReview([$this->reviewer()]);
        Notifications::run();
        Review::summary(get_post($id));

        $this->assertSame(0, $requests);
    }
}
