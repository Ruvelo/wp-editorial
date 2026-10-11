<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Tests\Feature;

use Ruvelo\WP\Editorial\History;
use Ruvelo\WP\Editorial\Review;
use Ruvelo\WP\Editorial\States;
use Ruvelo\WP\Editorial\Tests\TestCase;
use WP_Error;

final class StateMachineTest extends TestCase
{
    public function test_a_new_post_is_not_in_review(): void
    {
        $id = $this->post();

        $this->assertSame(States::DRAFT, Review::state($id));
        $this->assertSame([], Review::reviewers($id));
        $this->assertSame([], History::all($id));
    }

    public function test_asking_for_a_review(): void
    {
        $author = $this->user('author', 'Tom Reyes');
        $ines = $this->reviewer('Inès Laurent');
        $id = $this->post($author);

        $this->assertTrue(Review::request($id, [$ines], $author, 'Mostly the numbers.', '2026-10-14'));

        $this->assertSame(States::IN_REVIEW, Review::state($id));
        $this->assertSame([$ines], Review::reviewers($id));
        $this->assertSame([$ines], Review::pending($id));
        $this->assertSame(1, Review::round($id));
        $this->assertSame('2026-10-14', Review::due($id));
        $event = History::last($id);
        $this->assertNotNull($event);
        $this->assertSame('requested', $event['type']);
        $this->assertSame($author, $event['user']);
        $this->assertSame('Mostly the numbers.', $event['note']);
        $this->assertSame([$ines], $event['reviewers']);
        $this->assertSame('Tom Reyes asked Inès Laurent for a review.', History::describe($event));
    }

    public function test_one_approval_approves_by_default(): void
    {
        $ines = $this->reviewer();
        $id = $this->inReview([$ines]);

        $this->assertTrue(Review::approve($id, $ines, 'Good to go.'));

        $this->assertSame(States::APPROVED, Review::state($id));
        $this->assertSame([$ines], Review::approvedBy($id));
        $this->assertSame([], Review::pending($id));
        $this->assertSame(['requested', 'approved', 'fully_approved'], array_column(History::all($id), 'type'));
    }

    public function test_it_waits_for_as_many_approvals_as_the_post_type_needs(): void
    {
        $this->configure('post', ['approvals' => 2]);
        $ines = $this->reviewer();
        $maya = $this->reviewer();
        $id = $this->inReview([$ines, $maya]);

        Review::approve($id, $maya);
        $this->assertSame(States::IN_REVIEW, Review::state($id));
        $this->assertSame([$ines], Review::pending($id));

        Review::approve($id, $ines);
        $this->assertSame(States::APPROVED, Review::state($id));
    }

    public function test_the_required_approvals_can_be_filtered(): void
    {
        add_filter('ruvelo_editorial_required_approvals', static fn (): int => 3);
        $id = $this->post();
        $result = Review::request($id, [$this->reviewer(), $this->reviewer()], (int) get_post_field('post_author', $id));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('ruvelo_editorial_too_few_reviewers', $result->get_error_code());
    }

    public function test_requesting_changes_sends_it_back(): void
    {
        $this->configure('post', ['approvals' => 2]);
        $ines = $this->reviewer('Inès Laurent');
        $maya = $this->reviewer();
        $id = $this->inReview([$ines, $maya]);
        Review::approve($id, $maya);

        $this->assertTrue(Review::requestChanges($id, $ines, 'Split emails 3–5 into sections.'));

        $this->assertSame(States::CHANGES, Review::state($id));
        $this->assertSame([$ines], Review::changesBy($id));
        $this->assertSame([$maya], Review::approvedBy($id));
        $last = History::last($id);
        $this->assertNotNull($last);
        $this->assertSame('Split emails 3–5 into sections.', $last['note']);
        $this->assertSame('Inès Laurent requested changes.', History::describe($last));
    }

    public function test_asking_again_starts_a_new_round(): void
    {
        $this->configure('post', ['approvals' => 2]);
        $author = $this->user();
        $ines = $this->reviewer();
        $maya = $this->reviewer();
        $id = $this->inReview([$ines, $maya], $author);
        Review::approve($id, $maya);
        Review::requestChanges($id, $ines, 'One more pass.');

        $this->assertTrue(Review::request($id, [$ines, $maya], $author, 'Done.'));

        $this->assertSame(States::IN_REVIEW, Review::state($id));
        $this->assertSame(2, Review::round($id));
        $this->assertSame([], Review::approvedBy($id));
        $this->assertSame([], Review::changesBy($id));
        $this->assertEqualsCanonicalizing([$ines, $maya], Review::pending($id));
        $this->assertStringContainsString('to review it again', History::describe(History::last($id) ?? History::normalise([])));
    }

    public function test_changing_reviewers_in_review_keeps_approvals(): void
    {
        $this->configure('post', ['approvals' => 2]);
        $author = $this->user();
        $ines = $this->reviewer();
        $maya = $this->reviewer();
        $kenji = $this->reviewer();
        $id = $this->inReview([$ines, $maya], $author);
        Review::approve($id, $maya);

        $this->assertTrue(Review::request($id, [$maya, $kenji], $author));

        $this->assertSame(1, Review::round($id));
        $this->assertSame([$maya], Review::approvedBy($id));
        $this->assertSame([$kenji], Review::pending($id));
        $this->assertSame('reviewers_changed', History::last($id)['type'] ?? '');
    }

    public function test_taking_it_out_of_review(): void
    {
        $author = $this->user();
        $ines = $this->reviewer();
        $id = $this->inReview([$ines], $author);

        $this->assertTrue(Review::withdraw($id, $author));

        $this->assertSame(States::DRAFT, Review::state($id));
        $this->assertSame([], Review::pending($id));
        $this->assertSame([$ines], Review::reviewers($id), 'Reviewers are remembered for next time.');
        $this->assertInstanceOf(WP_Error::class, Review::withdraw($id, $author));
    }

    public function test_only_assigned_reviewers_decide(): void
    {
        $ines = $this->reviewer();
        $other = $this->reviewer();
        $id = $this->inReview([$ines]);

        $result = Review::approve($id, $other);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(403, $result->get_error_data()['status'] ?? 0);
        $this->assertInstanceOf(WP_Error::class, Review::requestChanges($id, $other));
        $this->assertSame(States::IN_REVIEW, Review::state($id));
    }

    public function test_reviewers_need_the_capability_and_cannot_be_the_author(): void
    {
        $author = $this->user('editor');
        $id = $this->post($author);

        $self = Review::request($id, [$author], $author);
        $this->assertInstanceOf(WP_Error::class, $self);
        $this->assertSame('ruvelo_editorial_invalid_reviewer', $self->get_error_code());

        $this->assertInstanceOf(WP_Error::class, Review::request($id, [$this->user('author')], $author));
        $this->assertInstanceOf(WP_Error::class, Review::request($id, [], $author));
    }

    public function test_self_review_can_be_allowed(): void
    {
        add_filter('ruvelo_editorial_allow_self_review', '__return_true');
        $author = $this->user('editor');
        $id = $this->post($author);

        $this->assertTrue(Review::request($id, [$author], $author));
        $this->assertTrue(Review::approve($id, $author));
    }

    public function test_people_who_cannot_edit_the_post_cannot_ask(): void
    {
        $id = $this->post();
        $result = Review::request($id, [$this->reviewer()], $this->user('author'));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(403, $result->get_error_data()['status'] ?? 0);
    }

    public function test_decisions_need_a_post_in_review(): void
    {
        $ines = $this->reviewer();
        $id = $this->inReview([$ines]);
        Review::approve($id, $ines);

        $again = Review::approve($id, $ines);
        $this->assertInstanceOf(WP_Error::class, $again);
        $this->assertSame('ruvelo_editorial_already_approved', $again->get_error_code());

        Review::requestChanges($id, $ines, 'Wait, one thing.');
        $this->assertInstanceOf(WP_Error::class, Review::approve($id, $ines), 'After changes are requested, it needs to be asked again.');
    }

    public function test_due_dates_are_validated(): void
    {
        $author = $this->user();
        $id = $this->post($author);

        $this->assertInstanceOf(WP_Error::class, Review::request($id, [$this->reviewer()], $author, '', '14/10/2026'));
        $this->assertInstanceOf(WP_Error::class, Review::request($id, [$this->reviewer()], $author, '', '2026-02-30'));
        $this->assertTrue(Review::request($id, [$this->reviewer()], $author, '', '2020-01-01'));
        $this->assertTrue(Review::isOverdue($id));
    }

    public function test_post_types_without_reviews_are_refused(): void
    {
        $this->configure('page', ['enabled' => false]);
        $author = $this->user('editor');
        $id = $this->post($author, 'page');

        $result = Review::request($id, [$this->reviewer()], $author);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('ruvelo_editorial_not_enabled', $result->get_error_code());
    }

    public function test_published_posts_are_not_reviewed(): void
    {
        $author = $this->user('editor');
        $id = $this->post($author, 'post', 'publish');

        $result = Review::request($id, [$this->reviewer()], $author);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('ruvelo_editorial_published', $result->get_error_code());
    }

    public function test_every_transition_fires_actions(): void
    {
        $seen = [];
        add_action('ruvelo_editorial_transition', static function (int $postId, string $from, string $to, array $event) use (&$seen): void {
            $seen[] = $event['type'].':'.$from.'>'.$to;
        }, 10, 4);
        $approved = 0;
        add_action('ruvelo_editorial_fully_approved', static function () use (&$approved): void {
            $approved++;
        });
        $author = $this->user();
        $ines = $this->reviewer();
        $id = $this->inReview([$ines], $author);
        Review::requestChanges($id, $ines);
        Review::request($id, [$ines], $author);
        Review::approve($id, $ines);
        Review::withdraw($id, $author);

        $this->assertSame([
            'requested:draft>in_review',
            'changes_requested:in_review>changes_requested',
            'requested:changes_requested>in_review',
            'approved:in_review>in_review',
            'fully_approved:in_review>approved',
            'withdrawn:approved>draft',
        ], $seen);
        $this->assertSame(1, $approved);
    }

    public function test_history_keeps_order_and_messages_with_slashes(): void
    {
        $ines = $this->reviewer();
        $id = $this->inReview([$ines]);
        Review::requestChanges($id, $ines, 'Use C:\\Billing\\exports, and "quotes".');

        $events = History::all($id);
        $this->assertCount(2, $events);
        $this->assertSame('Use C:\\Billing\\exports, and "quotes".', $events[1]['note']);
        $this->assertGreaterThanOrEqual($events[0]['at'], $events[1]['at']);
    }

    public function test_the_summary_describes_the_review_for_a_viewer(): void
    {
        $this->configure('post', ['approvals' => 2]);
        $author = $this->user();
        $ines = $this->reviewer('Inès Laurent');
        $maya = $this->reviewer('Maya Okafor');
        $id = $this->inReview([$ines, $maya], $author);
        Review::approve($id, $maya);

        $post = get_post($id);
        $this->assertNotNull($post);
        $forInes = Review::summary($post, $ines);
        $this->assertSame('in_review', $forInes['state']);
        $this->assertSame(['count' => 1, 'required' => 2], $forInes['approvals']);
        $this->assertSame(['pending', 'approved'], array_column($forInes['reviewers'], 'decision'));
        $this->assertSame('IL', $forInes['reviewers'][0]['initials']);
        $this->assertTrue($forInes['can']['approve']);

        $forMaya = Review::summary($post, $maya);
        $this->assertFalse($forMaya['can']['approve']);
        $this->assertTrue($forMaya['can']['decide']);

        $forAuthor = Review::summary($post, $author);
        $this->assertFalse($forAuthor['can']['decide']);
        $this->assertTrue($forAuthor['can']['request']);
        $this->assertTrue($forAuthor['can']['withdraw']);
    }
}
