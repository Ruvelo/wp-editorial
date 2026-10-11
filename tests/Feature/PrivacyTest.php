<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Tests\Feature;

use Ruvelo\WP\Editorial\History;
use Ruvelo\WP\Editorial\Privacy;
use Ruvelo\WP\Editorial\Review;
use Ruvelo\WP\Editorial\Tests\TestCase;

final class PrivacyTest extends TestCase
{
    public function test_the_tools_are_registered(): void
    {
        $this->assertArrayHasKey('ruvelo-editorial', apply_filters('wp_privacy_personal_data_exporters', []));
        $this->assertArrayHasKey('ruvelo-editorial', apply_filters('wp_privacy_personal_data_erasers', []));
    }

    public function test_exporting_a_reviewers_decisions(): void
    {
        $ines = $this->reviewer('Inès Laurent');
        $id = $this->inReview([$ines]);
        Review::requestChanges($id, $ines, 'Split the last section.');

        $export = Privacy::export((string) get_userdata($ines)->user_email);

        $this->assertTrue($export['done']);
        $this->assertCount(1, $export['data']);
        $values = array_column($export['data'][0]['data'], 'value');
        $this->assertContains('Inès Laurent requested changes.', $values);
        $this->assertContains('Split the last section.', $values);
    }

    public function test_erasing_keeps_the_record_but_not_the_person(): void
    {
        $ines = $this->reviewer('Inès Laurent');
        $tom = $this->user();
        $id = $this->inReview([$ines], $tom);
        Review::requestChanges($id, $ines, 'Split the last section.');

        $result = Privacy::erase((string) get_userdata($ines)->user_email);

        $this->assertTrue($result['items_removed']);
        $events = History::all($id);
        $this->assertSame($tom, $events[0]['user']);
        $this->assertSame(-1, $events[1]['user']);
        $this->assertSame('', $events[1]['note']);
        $this->assertSame('A former user requested changes.', History::describe($events[1]));
    }
}
