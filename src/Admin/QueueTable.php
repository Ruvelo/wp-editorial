<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Admin;

use Ruvelo\WP\Editorial\Notes;
use Ruvelo\WP\Editorial\People;
use Ruvelo\WP\Editorial\Queue;
use Ruvelo\WP\Editorial\Review;
use Ruvelo\WP\Editorial\Settings;
use Ruvelo\WP\Editorial\Support\Html;
use WP_List_Table;
use WP_Post;

if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists('WP_List_Table')) {
    require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The review queue as a standard list table: views for the three queues,
 * filters, sorting and paging, all plain links and GET forms.
 */
final class QueueTable extends WP_List_Table
{
    private string $view;

    /** @var array<string, mixed> */
    private array $filters;

    /** @var array<int, array{open: int, total: int}> */
    private array $notes = [];

    public function __construct(string $view)
    {
        parent::__construct(['singular' => 'review', 'plural' => 'reviews', 'ajax' => false, 'screen' => 'toplevel_page_'.QueuePage::SLUG]);
        $this->view = $view;
        $this->filters = self::filters();
    }

    /**
     * @return array<string, mixed>
     */
    public static function filters(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET filters on a read-only screen.
        return [
            'post_type' => isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '',
            'author' => isset($_GET['author']) ? absint($_GET['author']) : 0,
            'reviewer' => isset($_GET['reviewer']) ? absint($_GET['reviewer']) : 0,
            'overdue' => ! empty($_GET['overdue']),
            'search' => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'orderby' => isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'updated',
            'order' => isset($_GET['order']) ? sanitize_key(wp_unslash($_GET['order'])) : 'desc',
            'page' => isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1,
        ];
        // phpcs:enable
    }

    public function prepare_items(): void
    {
        $perPage = 20;
        $result = Queue::query($this->view, get_current_user_id(), $this->filters + ['per_page' => $perPage]);
        $this->items = $result['posts'];
        $this->notes = Notes::counts(array_map(static fn (WP_Post $post): int => $post->ID, $result['posts']));
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'title'];
        $this->set_pagination_args(['total_items' => $result['total'], 'per_page' => $perPage, 'total_pages' => $result['pages']]);
    }

    /**
     * @return array<string, string>
     */
    public function get_columns(): array
    {
        return [
            'title' => __('Post', 'ruvelo-editorial'),
            'state' => __('Review', 'ruvelo-editorial'),
            'reviewers' => __('Reviewers', 'ruvelo-editorial'),
            'notes' => __('Open notes', 'ruvelo-editorial'),
            'due' => __('Due', 'ruvelo-editorial'),
            'updated' => __('Last activity', 'ruvelo-editorial'),
        ];
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    protected function get_sortable_columns(): array
    {
        return ['title' => ['title', false], 'due' => ['due', false], 'updated' => ['updated', true]];
    }

    /**
     * @return array<string, string>
     */
    protected function get_views(): array
    {
        $counts = Queue::counts(get_current_user_id());
        $views = [];
        foreach (Queue::labels() as $key => $label) {
            $url = add_query_arg(['page' => QueuePage::SLUG, 'view' => $key], admin_url('admin.php'));
            $views[$key] = sprintf(
                '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
                esc_url($url),
                $key === $this->view ? ' class="current" aria-current="page"' : '',
                esc_html($label),
                $counts[$key]
            );
        }

        return $views;
    }

    protected function extra_tablenav($which): void
    {
        if ($which !== 'top') {
            return;
        }
        echo '<div class="alignleft actions ruvelo-ed-filters">';
        $types = Settings::enabledTypes();
        if (count($types) > 1) {
            echo '<label for="ruvelo-ed-type" class="screen-reader-text">'.esc_html__('Post type', 'ruvelo-editorial').'</label><select name="post_type" id="ruvelo-ed-type"><option value="">'.esc_html__('All types', 'ruvelo-editorial').'</option>';
            foreach ($types as $type) {
                $object = get_post_type_object($type);
                echo '<option value="'.esc_attr($type).'"'.selected($this->filters['post_type'], $type, false).'>'.esc_html($object !== null ? (string) $object->labels->singular_name : $type).'</option>';
            }
            echo '</select>';
        }
        echo '<label for="ruvelo-ed-reviewer" class="screen-reader-text">'.esc_html__('Reviewer', 'ruvelo-editorial').'</label><select name="reviewer" id="ruvelo-ed-reviewer"><option value="0">'.esc_html__('Any reviewer', 'ruvelo-editorial').'</option>';
        foreach (People::reviewers(null, $types[0] ?? 'post') as $user) {
            echo '<option value="'.esc_attr((string) $user->ID).'"'.selected((int) $this->filters['reviewer'], $user->ID, false).'>'.esc_html($user->display_name).'</option>';
        }
        echo '</select>';
        if (Queue::seesEveryone(get_current_user_id()) && ! in_array($this->view, ['changes'], true)) {
            wp_dropdown_users([
                'name' => 'author',
                'id' => 'ruvelo-ed-author',
                'show_option_all' => __('Any author', 'ruvelo-editorial'),
                'selected' => (int) $this->filters['author'],
                'capability' => ['edit_posts'],
                'show' => 'display_name',
            ]);
            echo '<label for="ruvelo-ed-author" class="screen-reader-text">'.esc_html__('Author', 'ruvelo-editorial').'</label>';
        }
        echo '<label class="ruvelo-ed-check"><input type="checkbox" name="overdue" value="1"'.checked((bool) $this->filters['overdue'], true, false).'> '.esc_html__('Overdue only', 'ruvelo-editorial').'</label>';
        submit_button(__('Filter', 'ruvelo-editorial'), '', 'filter_action', false);
        echo '</div>';
    }

    public function no_items(): void
    {
        $messages = [
            'mine' => __('Nothing is waiting for your review. When someone asks you, it shows up here.', 'ruvelo-editorial'),
            'changes' => __('No changes requested on your posts.', 'ruvelo-editorial'),
            'approved' => __('Nothing approved is waiting to go out.', 'ruvelo-editorial'),
            'all' => __('No posts are in review. Ask for one from the Review panel in the editor.', 'ruvelo-editorial'),
        ];
        echo esc_html($messages[$this->view] ?? $messages['all']);
    }

    /**
     * @param WP_Post $item
     */
    protected function column_title($item): string
    {
        $title = get_the_title($item);
        $title = $title !== '' ? $title : __('(no title)', 'ruvelo-editorial');
        $edit = (string) get_edit_post_link($item->ID, 'url');
        $type = get_post_type_object($item->post_type);
        $meta = sprintf(
            /* translators: 1: post type, 2: author */
            __('%1$s by %2$s', 'ruvelo-editorial'),
            $type !== null ? (string) $type->labels->singular_name : $item->post_type,
            People::name((int) $item->post_author)
        );
        $out = '<strong><a class="row-title" href="'.esc_url(ReviewPage::url($item->ID)).'">'.esc_html($title).'</a></strong>';
        $out .= '<span class="ruvelo-ed-row-meta">'.esc_html($meta).'</span>';
        // On phones the other columns fold away; keep the essentials in view.
        $out .= '<span class="ruvelo-ed-mobile">'.Html::badge(Review::state($item->ID)).Html::reviewers($item->ID).Html::due($item->ID).'</span>';
        $actions = ['review' => '<a href="'.esc_url(ReviewPage::url($item->ID)).'">'.esc_html__('Review and history', 'ruvelo-editorial').'</a>'];
        if ($edit !== '') {
            $actions['edit'] = '<a href="'.esc_url($edit).'">'.esc_html__('Open in the editor', 'ruvelo-editorial').'</a>';
        }
        $preview = get_preview_post_link($item);
        if (is_string($preview) && $preview !== '') {
            $actions['view'] = '<a href="'.esc_url($preview).'">'.esc_html__('Preview', 'ruvelo-editorial').'</a>';
        }

        return $out.$this->row_actions($actions);
    }

    /**
     * @param WP_Post $item
     */
    protected function column_state($item): string
    {
        $required = Review::requiredApprovals($item);
        $have = count(Review::approvedBy($item->ID));

        return Html::badge(Review::state($item->ID)).'<span class="ruvelo-ed-meter" role="img" aria-label="'.esc_attr(sprintf(
            /* translators: 1: approvals so far, 2: approvals needed */
            __('%1$d of %2$d approvals', 'ruvelo-editorial'),
            $have,
            $required
        )).'"><span style="width:'.esc_attr((string) min(100, (int) round($have / max(1, $required) * 100))).'%"></span></span><span class="ruvelo-ed-cell-meta" aria-hidden="true">'.esc_html(sprintf(
            /* translators: 1: approvals so far, 2: approvals needed */
            __('%1$d of %2$d approvals', 'ruvelo-editorial'),
            $have,
            $required
        )).'</span>';
    }

    /**
     * @param WP_Post $item
     */
    protected function column_reviewers($item): string
    {
        return Html::reviewers($item->ID);
    }

    /**
     * @param WP_Post $item
     */
    protected function column_notes($item): string
    {
        $open = $this->notes[$item->ID]['open'] ?? 0;
        if ($open === 0) {
            return '<span class="ruvelo-ed-quiet">0</span>';
        }

        return '<a class="ruvelo-ed-count" href="'.esc_url((string) get_edit_post_link($item->ID, 'url')).'">'.esc_html((string) $open).'</a>';
    }

    /**
     * @param WP_Post $item
     */
    protected function column_due($item): string
    {
        return Html::due($item->ID);
    }

    /**
     * @param WP_Post $item
     */
    protected function column_updated($item): string
    {
        $time = (int) get_post_meta($item->ID, Review::UPDATED, true);

        return '<span class="ruvelo-ed-quiet">'.esc_html(Html::ago($time)).'</span>';
    }

    /**
     * @param WP_Post $item
     * @param string  $column_name
     */
    protected function column_default($item, $column_name): string
    {
        return '';
    }
}
