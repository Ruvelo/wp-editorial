<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Tests\Feature;

use Ruvelo\WP\Editorial\History;
use Ruvelo\WP\Editorial\Notes;
use Ruvelo\WP\Editorial\Plugin;
use Ruvelo\WP\Editorial\Review;
use Ruvelo\WP\Editorial\Settings;
use Ruvelo\WP\Editorial\Tests\TestCase;
use WP_Error;

/**
 * Core Notes, as WordPress 6.9–7.1 store them: comments of type `note`,
 * held while open, approved once resolved, anchored by block metadata.
 */
final class NotesTest extends TestCase
{
    /**
     * A note thread the way the block editor creates one over REST.
     */
    private function coreNote(int $postId, int $userId, string $content, int $parent = 0): int
    {
        return self::factory()->comment->create([
            'comment_post_ID' => $postId,
            'comment_type' => 'note',
            'comment_approved' => '0',
            'comment_parent' => $parent,
            'comment_content' => $content,
            'user_id' => $userId,
        ]);
    }

    /**
     * Resolving, the way the editor does it: the thread becomes approved and
     * an empty reply carries `_wp_note_status = resolved`.
     */
    private function resolve(int $noteId, int $postId, int $userId): void
    {
        wp_set_comment_status($noteId, 'approve');
        $reply = $this->coreNote($postId, $userId, '', $noteId);
        wp_set_comment_status($reply, 'approve');
        add_comment_meta($reply, '_wp_note_status', 'resolved');
    }

    public function test_posts_and_pages_support_notes(): void
    {
        $this->assertTrue(Notes::supported('post'));
        $this->assertTrue(Notes::supported('page'));
    }

    public function test_open_and_resolved_threads_are_counted(): void
    {
        $ines = $this->reviewer();
        $id = $this->post();
        $first = $this->coreNote($id, $ines, 'Say “up to four times”.');
        $this->coreNote($id, $ines, 'Link the decline codes article.');
        $this->coreNote($id, $ines, 'A reply is not a thread.', $first);

        $this->assertSame(2, Notes::openCount($id));

        $this->resolve($first, $id, $ines);

        $this->assertSame(['open' => 1, 'total' => 2], Notes::counts([$id])[$id]);
    }

    public function test_trashed_and_regular_comments_are_ignored(): void
    {
        $id = $this->post();
        $note = $this->coreNote($id, $this->reviewer(), 'Gone soon.');
        self::factory()->comment->create(['comment_post_ID' => $id, 'comment_approved' => '0']);

        wp_trash_comment($note);

        $this->assertSame(['open' => 0, 'total' => 0], Notes::counts([$id])[$id]);
    }

    public function test_counts_for_many_posts_in_one_go(): void
    {
        $a = $this->post();
        $b = $this->post();
        $this->coreNote($a, $this->reviewer(), 'One.');

        $counts = Notes::counts([$a, $b]);

        $this->assertSame(1, $counts[$a]['open']);
        $this->assertSame(0, $counts[$b]['open']);
    }

    public function test_a_new_note_is_anchored_to_the_first_free_block(): void
    {
        $ines = $this->reviewer('Inès Laurent');
        $id = $this->post();

        $first = Notes::create($id, $ines, 'Tighten the intro.');
        $second = Notes::create($id, $ines, 'And the second paragraph.');

        $this->assertIsInt($first);
        $this->assertIsInt($second);
        $note = get_comment($first);
        $this->assertSame('note', $note->comment_type);
        $this->assertSame('0', $note->comment_approved, 'Open threads are held, like the editor makes them.');
        $this->assertSame('Inès Laurent', $note->comment_author);

        $blocks = parse_blocks((string) get_post_field('post_content', $id));
        $this->assertSame($first, $blocks[0]['attrs']['metadata']['noteId']);
        $this->assertSame($second, $blocks[2]['attrs']['metadata']['noteId']);
        $this->assertStringContainsString('<p>First paragraph.</p>', (string) get_post_field('post_content', $id));
    }

    public function test_when_every_block_has_a_note(): void
    {
        $ines = $this->reviewer();
        $id = $this->post(0, 'post', 'draft', "<!-- wp:paragraph {\"metadata\":{\"noteId\":5}} -->\n<p>Only block.</p>\n<!-- /wp:paragraph -->");

        $note = Notes::create($id, $ines, 'Another thought.');
        $this->assertIsInt($note);
        $blocks = parse_blocks((string) get_post_field('post_content', $id));

        if (Notes::multipleNotesPerBlock()) {
            // WordPress 7.1 keeps a list of note ids per block.
            $this->assertSame([5, $note], $blocks[0]['attrs']['metadata']['noteId']);
        } else {
            // 7.0 keeps one; the note stays unanchored rather than replacing it.
            $this->assertSame(5, $blocks[0]['attrs']['metadata']['noteId']);
        }
    }

    public function test_classic_content_cannot_anchor_a_note(): void
    {
        $id = $this->post(0, 'post', 'draft', '<p>Classic content.</p>');
        $post = get_post($id);
        $this->assertNotNull($post);

        $note = Notes::create($id, $this->reviewer(), 'Still a note.');

        $this->assertIsInt($note);
        $this->assertFalse(Notes::anchor($post, $note));
        $this->assertSame('<p>Classic content.</p>', get_post_field('post_content', $id));
    }

    public function test_requesting_changes_can_start_a_note(): void
    {
        $ines = $this->reviewer();
        $id = $this->inReview([$ines]);

        $this->assertTrue(Review::requestChanges($id, $ines, 'Give each email its own section.', 0, true));

        $event = History::last($id);
        $this->assertNotNull($event);
        $this->assertGreaterThan(0, $event['note_id']);
        $note = get_comment($event['note_id']);
        $this->assertSame('Give each email its own section.', $note->comment_content);
        $this->assertSame($ines, (int) $note->user_id);
        $this->assertSame(1, Notes::openCount($id));
    }

    public function test_a_decision_can_link_an_existing_note(): void
    {
        $ines = $this->reviewer();
        $id = $this->inReview([$ines]);
        $thread = $this->coreNote($id, $ines, 'See the pricing table.');
        $reply = $this->coreNote($id, $ines, 'Row three.', $thread);

        $this->assertTrue(Review::requestChanges($id, $ines, 'See my note.', $reply));

        $this->assertSame($thread, History::last($id)['note_id'] ?? 0, 'A reply links its thread.');
    }

    public function test_a_note_from_another_post_cannot_be_linked(): void
    {
        $ines = $this->reviewer();
        $id = $this->inReview([$ines]);
        $elsewhere = $this->coreNote($this->post(), $ines, 'Not here.');

        $result = Review::approve($id, $ines, '', $elsewhere);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('ruvelo_editorial_note_not_found', $result->get_error_code());
        $this->assertSame([], Review::approvedBy($id));
    }

    public function test_an_empty_message_makes_no_note(): void
    {
        $ines = $this->reviewer();
        $id = $this->inReview([$ines]);

        Review::approve($id, $ines, '', 0, true);

        $this->assertSame(0, Notes::counts([$id])[$id]['total']);
    }

    public function test_reviewed_custom_post_types_get_notes(): void
    {
        register_post_type('ruvelo_case', ['public' => true, 'show_ui' => true, 'supports' => ['title', 'editor']]);
        $this->configure('ruvelo_case', ['enabled' => true]);
        $this->assertFalse(Notes::supported('ruvelo_case'));

        Plugin::init();

        $this->assertTrue(Notes::supported('ruvelo_case'));
        $this->assertContains('ruvelo_case', Settings::enabledTypes());
        unregister_post_type('ruvelo_case');
    }

    public function test_more_comment_types_can_count_as_notes(): void
    {
        add_filter('ruvelo_editorial_note_types', static fn (array $types): array => array_merge($types, ['suggestion']));
        $id = $this->post();
        self::factory()->comment->create(['comment_post_ID' => $id, 'comment_type' => 'suggestion', 'comment_approved' => '0']);

        $this->assertSame(1, Notes::openCount($id));
    }
}
