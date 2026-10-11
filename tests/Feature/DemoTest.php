<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Tests\Feature;

use Ruvelo\WP\Editorial\Demo;
use Ruvelo\WP\Editorial\Gate;
use Ruvelo\WP\Editorial\History;
use Ruvelo\WP\Editorial\Notes;
use Ruvelo\WP\Editorial\Queue;
use Ruvelo\WP\Editorial\Review;
use Ruvelo\WP\Editorial\States;
use Ruvelo\WP\Editorial\Tests\TestCase;

final class DemoTest extends TestCase
{
    private function id(string $title, string $type = 'post'): int
    {
        $post = get_page_by_path(sanitize_title($title), OBJECT, $type);
        $this->assertNotNull($post, $title);

        return $post->ID;
    }

    public function test_halyard_has_a_post_in_every_state(): void
    {
        $result = Demo::seed();

        $this->assertSame(11, $result['posts']);
        $ines = get_user_by('login', 'ines.laurent');
        $this->assertNotFalse($ines);
        $this->assertSame('Inès Laurent', $ines->display_name);
        $this->assertTrue(wp_check_password(Demo::PASSWORD, $ines->user_pass, $ines->ID));

        $this->assertSame(States::IN_REVIEW, Review::state($this->id('How Halyard retries failed payments')));
        $this->assertSame(2, Notes::openCount($this->id('How Halyard retries failed payments')));
        $this->assertSame(States::CHANGES, Review::state($this->id('Five dunning emails that win customers back')));
        $this->assertSame(States::APPROVED, Review::state($this->id('Usage-based billing is here')));
        $this->assertSame(States::DRAFT, Review::state($this->id('Webinar: closing the books in three days')));
        $this->assertSame(2, Review::round($this->id('Northwind cut days sales outstanding by 18 days')));
        $this->assertTrue(Review::isOverdue($this->id('Pricing: what changed and why', 'page')));
        $this->assertSame('publish', get_post_status($this->id('Halyard now supports SEPA Direct Debit')));
        $this->assertSame('future', get_post_status($this->id('2026 in review: what we shipped')));
        $this->assertSame('override', History::last($this->id('Incident report: delayed invoice emails on 3 October'))['type'] ?? '');

        $counts = Queue::counts($ines->ID);
        $this->assertSame(4, $counts['mine']);
        $this->assertSame(2, $counts['approved']);
        $this->assertTrue(Gate::applies('post'));
    }

    public function test_seeding_twice_starts_over(): void
    {
        Demo::seed();
        Demo::seed();

        $this->assertCount(1, get_posts(['post_type' => 'post', 'post_status' => 'any', 'title' => 'How Halyard retries failed payments']));
    }
}
