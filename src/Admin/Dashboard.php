<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Admin;

use Ruvelo\WP\Editorial\People;
use Ruvelo\WP\Editorial\Queue;
use Ruvelo\WP\Editorial\Review;
use Ruvelo\WP\Editorial\Settings;
use Ruvelo\WP\Editorial\Support\Html;

/**
 * The Reviews dashboard widget: three counts and what's next for you.
 */
final class Dashboard
{
    public static function register(): void
    {
        if (! current_user_can('edit_posts') || Settings::enabledTypes() === []) {
            return;
        }
        wp_add_dashboard_widget('ruvelo_editorial', __('Reviews', 'ruvelo-editorial'), [self::class, 'render']);
    }

    public static function render(): void
    {
        $user = get_current_user_id();
        $counts = Queue::counts($user);
        $labels = [
            'mine' => __('Waiting for you', 'ruvelo-editorial'),
            'changes' => __('Need your changes', 'ruvelo-editorial'),
            'approved' => __('Ready to publish', 'ruvelo-editorial'),
        ];
        echo '<div class="ruvelo-ed ruvelo-ed-widget"><ul class="ruvelo-ed-tiles ruvelo-ed-tiles--small">';
        foreach ($labels as $key => $label) {
            printf(
                '<li><a class="ruvelo-ed-tile" href="%s"><span class="ruvelo-ed-tile-n">%d</span><span class="ruvelo-ed-tile-l">%s</span></a></li>',
                esc_url(add_query_arg(['page' => QueuePage::SLUG, 'view' => $key], admin_url('admin.php'))),
                (int) $counts[$key],
                esc_html($label)
            );
        }
        echo '</ul>';

        $view = $counts['mine'] > 0 ? 'mine' : ($counts['changes'] > 0 ? 'changes' : 'approved');
        $items = Queue::query($view, $user, ['per_page' => 5, 'orderby' => $view === 'mine' ? 'due' : 'updated', 'order' => $view === 'mine' ? 'asc' : 'desc'])['posts'];
        if ($items === []) {
            echo '<p class="ruvelo-ed-quiet">'.esc_html__('You’re all caught up.', 'ruvelo-editorial').'</p></div>';

            return;
        }
        echo '<h3 class="ruvelo-ed-widget-h">'.esc_html(Queue::labels()[$view]).'</h3><ul class="ruvelo-ed-widget-list">';
        foreach ($items as $post) {
            $title = get_the_title($post) !== '' ? get_the_title($post) : __('(no title)', 'ruvelo-editorial');
            echo '<li><a href="'.esc_url(ReviewPage::url($post->ID)).'">'.esc_html($title).'</a>';
            echo '<span class="ruvelo-ed-widget-meta">'.esc_html(People::name((int) $post->post_author)).' · '.Html::due($post->ID).'</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Html::due() escapes.
            echo Html::badge(Review::state($post->ID)).'</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Html::badge() escapes.
        }
        echo '</ul><p class="ruvelo-ed-widget-foot"><a href="'.esc_url(admin_url('admin.php?page='.QueuePage::SLUG)).'">'.esc_html__('Open the review queue', 'ruvelo-editorial').'</a></p></div>';
    }
}
