<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Tests\Feature;

use Ruvelo\WP\Editorial\Review;
use Ruvelo\WP\Editorial\States;
use Ruvelo\WP\Editorial\Tests\TestCase;
use WP_Error;

final class AbilitiesTest extends TestCase
{
    private const NAMES = ['get-review-state', 'get-queue', 'request-review', 'approve', 'request-changes'];

    public function test_the_abilities_are_registered_and_listed_in_rest(): void
    {
        foreach (self::NAMES as $name) {
            $ability = wp_get_ability('ruvelo-editorial/'.$name);
            $this->assertNotNull($ability, $name);
            // 7.0 lists abilities by show_in_rest; 7.1 also reads public.
            $this->assertTrue($ability->get_meta_item('show_in_rest'), $name);
            $this->assertTrue($ability->get_meta_item('public', true), $name);
        }
        $this->assertTrue(wp_get_ability('ruvelo-editorial/get-queue')->get_meta()['annotations']['readonly']);
        $this->assertFalse(wp_get_ability('ruvelo-editorial/approve')->get_meta()['annotations']['readonly']);
    }

    public function test_reading_a_review_state_needs_edit_access(): void
    {
        $ines = $this->reviewer();
        $id = $this->inReview([$ines]);
        $ability = wp_get_ability('ruvelo-editorial/get-review-state');

        wp_set_current_user($this->user('subscriber'));
        $this->assertInstanceOf(WP_Error::class, $ability->execute(['id' => $id]));

        wp_set_current_user($ines);
        $state = $ability->execute(['id' => $id]);
        $this->assertSame('in_review', $state['state']);
        $this->assertSame('requested', $state['history'][0]['type']);
    }

    public function test_the_queue(): void
    {
        $ines = $this->reviewer();
        $id = $this->inReview([$ines]);
        wp_set_current_user($ines);

        $queue = wp_get_ability('ruvelo-editorial/get-queue')->execute(['view' => 'mine']);

        $this->assertSame([$id], array_column($queue, 'post_id'));
    }

    public function test_approving_is_gated_by_capability_and_assignment(): void
    {
        $ines = $this->reviewer();
        $id = $this->inReview([$ines]);
        $ability = wp_get_ability('ruvelo-editorial/approve');

        wp_set_current_user($this->user('author'));
        $this->assertInstanceOf(WP_Error::class, $ability->execute(['id' => $id]));

        wp_set_current_user($this->reviewer());
        $this->assertInstanceOf(WP_Error::class, $ability->execute(['id' => $id]), 'Not one of its reviewers.');

        wp_set_current_user($ines);
        $this->assertSame('approved', $ability->execute(['id' => $id, 'message' => 'From an agent.'])['state']);
    }

    public function test_requesting_a_review_and_changes(): void
    {
        $tom = $this->user();
        $ines = $this->reviewer();
        $id = $this->post($tom);

        wp_set_current_user($tom);
        $this->assertSame('in_review', wp_get_ability('ruvelo-editorial/request-review')->execute(['id' => $id, 'reviewers' => [$ines]])['state']);

        wp_set_current_user($ines);
        $result = wp_get_ability('ruvelo-editorial/request-changes')->execute(['id' => $id, 'message' => 'Tighten it.', 'create_note' => true]);
        $this->assertSame('changes_requested', $result['state']);
        $this->assertSame(1, $result['notes']['open']);
        $this->assertSame(States::CHANGES, Review::state($id));
    }
}
