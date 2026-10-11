<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Admin;

use Ruvelo\WP\Editorial\Queue;

/**
 * Reviews → Review queue: what waits for me, what came back to me, what's
 * ready to go out.
 */
final class QueuePage
{
    public const SLUG = 'ruvelo-editorial';

    public static function currentView(): string
    {
        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.
        if (in_array($view, Queue::VIEWS, true)) {
            return $view;
        }
        // Land on the first view with something in it.
        $counts = Queue::counts(get_current_user_id());
        foreach (['mine', 'changes', 'approved'] as $key) {
            if ($counts[$key] > 0) {
                return $key;
            }
        }

        return 'mine';
    }

    public static function render(): void
    {
        if (! current_user_can('edit_posts')) {
            wp_die(esc_html__('You can’t see the review queue.', 'ruvelo-editorial'), 403);
        }
        $view = self::currentView();
        $table = new QueueTable($view);
        $table->prepare_items();
        $counts = Queue::counts(get_current_user_id());
        $labels = Queue::labels();

        echo '<div class="wrap ruvelo-ed">';
        echo '<div class="ruvelo-ed-head"><span class="ruvelo-ed-mark" aria-hidden="true">E</span><div><h1 class="wp-heading-inline">'.esc_html__('Reviews', 'ruvelo-editorial').'</h1>';
        echo '<p class="ruvelo-ed-lede">'.esc_html__('Posts waiting for a decision, posts sent back for changes, and posts ready to publish.', 'ruvelo-editorial').'</p></div></div>';
        Notice::render();

        echo '<ul class="ruvelo-ed-tiles">';
        foreach (['mine', 'changes', 'approved'] as $key) {
            $url = add_query_arg(['page' => self::SLUG, 'view' => $key], admin_url('admin.php'));
            printf(
                '<li><a class="ruvelo-ed-tile%s" href="%s"%s><span class="ruvelo-ed-tile-n">%d</span><span class="ruvelo-ed-tile-l">%s</span></a></li>',
                $key === $view ? ' is-current' : '',
                esc_url($url),
                $key === $view ? ' aria-current="page"' : '',
                (int) $counts[$key],
                esc_html($labels[$key])
            );
        }
        echo '</ul>';

        echo '<form method="get" class="ruvelo-ed-queue">';
        echo '<input type="hidden" name="page" value="'.esc_attr(self::SLUG).'"><input type="hidden" name="view" value="'.esc_attr($view).'">';
        $table->views();
        $table->search_box(__('Search posts', 'ruvelo-editorial'), 'ruvelo-ed-search');
        $table->display();
        echo '</form></div>';
    }
}
