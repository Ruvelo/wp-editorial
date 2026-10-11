<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Tests\Feature;

use Ruvelo\WP\Editorial\Queue;
use Ruvelo\WP\Editorial\Review;
use Ruvelo\WP\Editorial\Tests\TestCase;
use WP_Post;

final class QueueTest extends TestCase
{
    /**
     * @param array<string, mixed> $filters
     * @return list<int>
     */
    private function ids(string $view, int $user, array $filters = []): array
    {
        return array_map(static fn (WP_Post $post): int => $post->ID, Queue::query($view, $user, $filters)['posts']);
    }

    public function test_the_three_queues(): void
    {
        $tom = $this->user();
        $ines = $this->reviewer();
        $maya = $this->reviewer();
        $waiting = $this->inReview([$ines], $tom);
        $changes = $this->inReview([$ines], $tom);
        Review::requestChanges($changes, $ines, 'Not yet.');
        $approved = $this->inReview([$maya], $tom);
        Review::approve($approved, $maya);
        $draft = $this->post($tom);

        $this->assertSame([$waiting], $this->ids('mine', $ines));
        $this->assertSame([], $this->ids('mine', $maya));
        $this->assertSame([$changes], $this->ids('changes', $tom));
        $this->assertSame([], $this->ids('changes', $ines));
        $this->assertSame([$approved], $this->ids('approved', $tom));
        $this->assertSame([$approved], $this->ids('approved', $ines), 'Reviewers see everything that is ready.');
        $this->assertNotContains($draft, $this->ids('all', $ines));
        $this->assertCount(3, $this->ids('all', $ines));
        $this->assertSame(['mine' => 1, 'changes' => 0, 'approved' => 1, 'all' => 3], Queue::counts($ines));
        $this->assertSame(['mine' => 0, 'changes' => 1, 'approved' => 1, 'all' => 3], Queue::counts($tom));
    }

    public function test_authors_only_see_their_own_posts(): void
    {
        $ines = $this->reviewer();
        $tom = $this->user();
        $kenji = $this->user();
        $this->inReview([$ines], $tom);
        $kenjis = $this->inReview([$ines], $kenji);

        $this->assertSame([$kenjis], $this->ids('all', $kenji));
    }

    public function test_counts_are_cached_until_the_next_event(): void
    {
        $ines = $this->reviewer();
        $this->inReview([$ines]);
        $this->assertSame(1, Queue::counts($ines)['mine']);

        $this->inReview([$ines]);

        $this->assertSame(2, Queue::counts($ines)['mine']);
    }

    public function test_filters(): void
    {
        $ines = $this->reviewer();
        $maya = $this->reviewer();
        $tom = $this->user();
        $late = $this->post($tom);
        Review::request($late, [$ines], $tom, '', '2020-01-01');
        $later = $this->post($tom);
        Review::request($later, [$ines, $maya], $tom, '', '2099-01-01');
        $undated = $this->inReview([$ines], $tom);

        $this->assertSame([$late], $this->ids('mine', $ines, ['overdue' => true]));
        $this->assertSame([$later], $this->ids('mine', $ines, ['reviewer' => $maya]));
        $this->assertSame([$undated, $late, $later], $this->ids('mine', $ines, ['orderby' => 'due', 'order' => 'asc']));
        $this->assertSame([], $this->ids('mine', $ines, ['search' => 'zebra crossing']));
        $this->assertCount(3, $this->ids('mine', $ines, ['search' => 'retries']));
    }

    public function test_most_recent_activity_comes_first(): void
    {
        $ines = $this->reviewer();
        $tom = $this->user();
        $old = $this->inReview([$ines], $tom);
        $new = $this->inReview([$ines], $tom);
        update_post_meta($old, Review::UPDATED, time() - DAY_IN_SECONDS);

        $this->assertSame([$new, $old], $this->ids('mine', $ines));
    }

    public function test_published_posts_leave_the_queue(): void
    {
        $ines = $this->reviewer();
        $tom = $this->user('editor');
        $id = $this->inReview([$ines], $tom);
        wp_set_current_user($tom);

        wp_update_post(['ID' => $id, 'post_status' => 'publish']);

        $this->assertSame([], $this->ids('mine', $ines));
        $this->assertSame([], Review::pending($id));
    }
}
