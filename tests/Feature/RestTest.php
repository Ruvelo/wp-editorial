<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Tests\Feature;

use Ruvelo\WP\Editorial\Review;
use Ruvelo\WP\Editorial\States;
use Ruvelo\WP\Editorial\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Response;

final class RestTest extends TestCase
{
    public function set_up(): void
    {
        parent::set_up();
        do_action('rest_api_init', rest_get_server());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function call(string $method, string $path, array $body = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/ruvelo-editorial/v1'.$path);
        if ($method === 'GET') {
            $request->set_query_params($body);
        } else {
            $request->set_body_params($body);
        }

        return rest_get_server()->dispatch($request);
    }

    public function test_routes_are_registered(): void
    {
        $routes = array_keys(rest_get_server()->get_routes('ruvelo-editorial/v1'));

        foreach (['/posts/(?P<id>\d+)', '/posts/(?P<id>\d+)/history', '/posts/(?P<id>\d+)/request', '/posts/(?P<id>\d+)/approve', '/posts/(?P<id>\d+)/request-changes', '/posts/(?P<id>\d+)/withdraw', '/posts/(?P<id>\d+)/override', '/queue', '/counts', '/reviewers'] as $route) {
            $this->assertContains('/ruvelo-editorial/v1'.$route, $routes);
        }
    }

    public function test_reading_a_review_needs_edit_access(): void
    {
        $author = $this->user();
        $id = $this->post($author);

        $this->assertSame(401, $this->call('GET', '/posts/'.$id)->get_status());

        wp_set_current_user($this->user('author'));
        $this->assertSame(403, $this->call('GET', '/posts/'.$id)->get_status());

        wp_set_current_user($author);
        $response = $this->call('GET', '/posts/'.$id);
        $this->assertSame(200, $response->get_status());
        $this->assertSame('draft', $response->get_data()['state']);
        $this->assertSame([], $response->get_data()['history']);
        $this->assertSame(404, $this->call('GET', '/posts/999999')->get_status());
    }

    public function test_the_whole_loop_over_rest(): void
    {
        $author = $this->user('author', 'Tom Reyes');
        $ines = $this->reviewer('Inès Laurent');
        $id = $this->post($author);

        wp_set_current_user($author);
        $people = $this->call('GET', '/reviewers', ['post' => $id])->get_data();
        $this->assertContains($ines, array_column($people, 'id'));
        $this->assertNotContains($author, array_column($people, 'id'));

        $requested = $this->call('POST', '/posts/'.$id.'/request', ['reviewers' => [$ines], 'due' => '2026-10-14', 'message' => 'Please check the numbers.']);
        $this->assertSame(200, $requested->get_status());
        $this->assertSame('in_review', $requested->get_data()['state']);
        $this->assertSame('2026-10-14', $requested->get_data()['due']);

        // The author can't approve their own post.
        $this->assertSame(403, $this->call('POST', '/posts/'.$id.'/approve')->get_status());

        wp_set_current_user($ines);
        $changes = $this->call('POST', '/posts/'.$id.'/request-changes', ['message' => 'Fix the table.', 'create_note' => true]);
        $this->assertSame(200, $changes->get_status());
        $this->assertSame('changes_requested', $changes->get_data()['state']);
        $this->assertSame('Fix the table.', $changes->get_data()['changes']['message']);
        $this->assertSame(1, $changes->get_data()['notes']['open']);

        wp_set_current_user($author);
        $this->call('POST', '/posts/'.$id.'/request', ['reviewers' => [$ines]]);

        wp_set_current_user($ines);
        $approved = $this->call('POST', '/posts/'.$id.'/approve', ['message' => 'Thanks!']);
        $this->assertSame('approved', $approved->get_data()['state']);
        $this->assertSame(['requested', 'changes_requested', 'requested', 'approved', 'fully_approved'], array_column($approved->get_data()['history'], 'type'));

        $history = $this->call('GET', '/posts/'.$id.'/history')->get_data();
        $this->assertSame('Inès Laurent approved.', $history[3]['text']);
    }

    public function test_errors_come_back_with_a_status_and_a_clear_message(): void
    {
        $author = $this->user();
        $id = $this->post($author);
        wp_set_current_user($author);

        $response = $this->call('POST', '/posts/'.$id.'/request', ['reviewers' => [$this->user('subscriber')]]);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('ruvelo_editorial_invalid_reviewer', $response->get_data()['code']);
        $this->assertStringContainsString('can’t review this post', $response->get_data()['message']);
    }

    public function test_reviewers_without_the_capability_cannot_decide(): void
    {
        $ines = $this->reviewer();
        $id = $this->inReview([$ines]);
        get_role('editor')->remove_cap('ruvelo_review_posts');
        wp_set_current_user($ines);

        $this->assertSame(403, $this->call('POST', '/posts/'.$id.'/approve')->get_status());
        $this->assertSame(States::IN_REVIEW, Review::state($id));
    }

    public function test_the_queue_and_counts(): void
    {
        $ines = $this->reviewer();
        $first = $this->inReview([$ines]);
        $this->inReview([$ines]);
        wp_set_current_user($ines);

        $response = $this->call('GET', '/queue', ['view' => 'mine']);

        $this->assertSame(200, $response->get_status());
        $this->assertCount(2, $response->get_data());
        $this->assertSame('2', $response->get_headers()['X-WP-Total']);
        $this->assertContains($first, array_column($response->get_data(), 'post_id'));
        $this->assertSame(2, $this->call('GET', '/counts')->get_data()['mine']);

        wp_set_current_user($this->user('subscriber'));
        $this->assertSame(403, $this->call('GET', '/queue')->get_status());
    }

    public function test_core_post_responses_carry_the_review_in_edit_context(): void
    {
        $ines = $this->reviewer();
        $author = $this->user();
        $id = $this->inReview([$ines], $author);
        wp_set_current_user($author);

        $request = new WP_REST_Request('GET', '/wp/v2/posts/'.$id);
        $request->set_param('context', 'edit');
        $data = rest_get_server()->dispatch($request)->get_data();

        $this->assertSame('in_review', $data['ruvelo_review']['state']);
        $this->assertSame([$ines], $data['ruvelo_review']['reviewers']);
        $this->assertSame(1, $data['ruvelo_review']['required']);
    }
}
