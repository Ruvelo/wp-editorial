<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Admin;

use Ruvelo\WP\Editorial\Gate;
use Ruvelo\WP\Editorial\History;
use Ruvelo\WP\Editorial\Notes;
use Ruvelo\WP\Editorial\Notifications;
use Ruvelo\WP\Editorial\Queue;
use Ruvelo\WP\Editorial\Review;
use Ruvelo\WP\Editorial\Settings;
use Ruvelo\WP\Editorial\States;
use Ruvelo\WP\Editorial\Support\Html;
use WP_Post;
use WP_Query;
use WP_User;

/**
 * The admin side: the Reviews menu (with the "waiting for me" count), the
 * Review column and filter on post lists, the dashboard widget, the editor
 * panel's assets and the profile preferences. Nothing shows outside the
 * plugin's own screens except where it reports on a post's review.
 */
final class Admin
{
    public const THEME_META = 'ruvelo_editorial_theme';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('enqueue_block_editor_assets', [Editor::class, 'enqueue']);
        add_action('admin_init', [self::class, 'listColumns']);
        add_action('wp_dashboard_setup', [Dashboard::class, 'register']);
        add_action('admin_post_ruvelo_editorial_action', [ReviewPage::class, 'handle']);
        add_action('admin_post_ruvelo_editorial_settings', [SettingsPage::class, 'save']);
        add_filter('admin_body_class', [self::class, 'bodyClass']);
        add_action('admin_notices', [self::class, 'heldNotice']);
        add_action('show_user_profile', [self::class, 'profileFields']);
        add_action('edit_user_profile', [self::class, 'profileFields']);
        add_action('personal_options_update', [self::class, 'saveProfile']);
        add_action('edit_user_profile_update', [self::class, 'saveProfile']);
        add_action('pre_get_posts', [self::class, 'filterList']);
    }

    public static function menu(): void
    {
        if (! current_user_can('edit_posts') || Settings::enabledTypes() === []) {
            if (current_user_can(Settings::CAP_MANAGE)) {
                add_options_page(__('Reviews', 'ruvelo-editorial'), __('Reviews', 'ruvelo-editorial'), Settings::CAP_MANAGE, SettingsPage::SLUG, [SettingsPage::class, 'render']);
            }

            return;
        }
        $count = Queue::counts(get_current_user_id())['mine'];
        $bubble = $count > 0 ? sprintf(' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%1$d</span><span class="screen-reader-text">%2$s</span></span>', $count, esc_html(sprintf(
            /* translators: %d: number of posts */
            _n('%d post waiting for your review', '%d posts waiting for your review', $count, 'ruvelo-editorial'),
            $count
        ))) : '';

        add_menu_page(__('Reviews', 'ruvelo-editorial'), __('Reviews', 'ruvelo-editorial').$bubble, 'edit_posts', QueuePage::SLUG, [QueuePage::class, 'render'], 'dashicons-yes-alt', 4);
        add_submenu_page(QueuePage::SLUG, __('Review queue', 'ruvelo-editorial'), __('Review queue', 'ruvelo-editorial'), 'edit_posts', QueuePage::SLUG, [QueuePage::class, 'render']);
        add_submenu_page(QueuePage::SLUG, __('Review', 'ruvelo-editorial'), __('Review', 'ruvelo-editorial'), 'edit_posts', ReviewPage::SLUG, [ReviewPage::class, 'render']);
        add_submenu_page(QueuePage::SLUG, __('Review settings', 'ruvelo-editorial'), __('Settings', 'ruvelo-editorial'), Settings::CAP_MANAGE, SettingsPage::SLUG, [SettingsPage::class, 'render']);
        // One post's review is reached from the queue, not the menu: the
        // entry stays registered (so the page and its highlight work) but hidden.
        wp_add_inline_style('admin-menu', '#adminmenu .toplevel_page_'.QueuePage::SLUG.' li:has(> a[href$="page='.ReviewPage::SLUG.'"]){display:none}');
        if (isset($_GET['page']) && $_GET['page'] === ReviewPage::SLUG) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Menu highlight only.
            add_filter('submenu_file', static fn (): string => QueuePage::SLUG);
        }
    }

    public static function assets(string $hook): void
    {
        $screen = get_current_screen();
        $ours = isset($_GET['page']) && in_array(sanitize_key(wp_unslash($_GET['page'])), [QueuePage::SLUG, ReviewPage::SLUG, SettingsPage::SLUG], true); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks the stylesheet.
        $list = $screen !== null && $screen->base === 'edit' && Settings::enabled((string) $screen->post_type);
        if ($ours || $list || $hook === 'index.php') {
            wp_enqueue_style('ruvelo-editorial-admin', RUVELO_EDITORIAL_URL.'assets/css/admin.css', [], RUVELO_EDITORIAL_VERSION);
        }
    }

    public static function bodyClass(string $classes): string
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks a class.
        if (! in_array($page, [QueuePage::SLUG, ReviewPage::SLUG, SettingsPage::SLUG], true)) {
            return $classes;
        }

        return $classes.' ruvelo-ed-screen ruvelo-ed-theme-'.self::theme(get_current_user_id());
    }

    /**
     * light, dark or auto (follow the device). WordPress's admin has no dark
     * mode of its own, so this only changes the plugin's screens.
     */
    public static function theme(int $userId): string
    {
        $theme = get_user_meta($userId, self::THEME_META, true);

        return in_array($theme, ['light', 'dark', 'auto'], true) ? (string) $theme : 'light';
    }

    /**
     * The Review column and state filter on every enabled post type's list.
     */
    public static function listColumns(): void
    {
        foreach (Settings::enabledTypes() as $type) {
            add_filter('manage_'.$type.'_posts_columns', [self::class, 'addColumn']);
            add_action('manage_'.$type.'_posts_custom_column', [self::class, 'column'], 10, 2);
        }
        add_action('restrict_manage_posts', [self::class, 'stateFilter'], 10, 1);
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function addColumn(array $columns): array
    {
        $out = [];
        foreach ($columns as $key => $label) {
            $out[$key] = $label;
            if ($key === 'title') {
                $out['ruvelo_review'] = __('Review', 'ruvelo-editorial');
            }
        }
        if (! isset($out['ruvelo_review'])) {
            $out['ruvelo_review'] = __('Review', 'ruvelo-editorial');
        }

        return $out;
    }

    public static function column(string $column, int $postId): void
    {
        if ($column !== 'ruvelo_review') {
            return;
        }
        $post = get_post($postId);
        if (! $post instanceof WP_Post) {
            return;
        }
        $state = Review::state($postId);
        if (in_array($post->post_status, ['publish', 'future'], true) && $state !== States::APPROVED) {
            $last = History::last($postId);
            if ($last !== null && in_array($last['type'], ['override', 'published_unapproved'], true)) {
                echo '<a class="ruvelo-ed-cell" href="'.esc_url(ReviewPage::url($postId)).'"><span class="ruvelo-ed-badge ruvelo-ed-tone-danger">'.esc_html__('Published without approval', 'ruvelo-editorial').'</span></a>';
            } else {
                echo '<span class="ruvelo-ed-quiet" aria-label="'.esc_attr(States::label(States::DRAFT)).'">—</span>';
            }

            return;
        }
        if ($state === States::DRAFT) {
            echo '<span class="ruvelo-ed-quiet" aria-label="'.esc_attr(States::label($state)).'">—</span>';

            return;
        }
        $required = Review::requiredApprovals($post);
        $approvals = count(Review::approvedBy($postId));
        echo '<a class="ruvelo-ed-cell" href="'.esc_url(ReviewPage::url($postId)).'">';
        echo Html::badge($state); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Html::badge().
        echo '<span class="ruvelo-ed-cell-meta">';
        /* translators: 1: approvals so far, 2: approvals needed */
        echo esc_html(sprintf(__('%1$d of %2$d approvals', 'ruvelo-editorial'), $approvals, $required));
        $open = Notes::openCount($postId);
        if ($open > 0) {
            /* translators: %d: open notes */
            echo ' · '.esc_html(sprintf(_n('%d open note', '%d open notes', $open, 'ruvelo-editorial'), $open));
        }
        echo '</span></a>';
    }

    public static function stateFilter(string $postType): void
    {
        if (! Settings::enabled($postType)) {
            return;
        }
        $current = isset($_GET['ruvelo_review']) ? sanitize_key(wp_unslash($_GET['ruvelo_review'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A list filter.
        echo '<label for="ruvelo-ed-state-filter" class="screen-reader-text">'.esc_html__('Filter by review state', 'ruvelo-editorial').'</label>';
        echo '<select name="ruvelo_review" id="ruvelo-ed-state-filter"><option value="">'.esc_html__('All review states', 'ruvelo-editorial').'</option>';
        foreach (States::all() as $key => $state) {
            echo '<option value="'.esc_attr($key).'"'.selected($current, $key, false).'>'.esc_html($state['label']).'</option>';
        }
        echo '</select>';
    }

    public static function filterList(WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query() || ! isset($_GET['ruvelo_review'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A list filter.
            return;
        }
        $state = sanitize_key(wp_unslash($_GET['ruvelo_review'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A list filter.
        $type = $query->get('post_type');
        if (! States::valid($state) || ! is_string($type) || ! Settings::enabled($type)) {
            return;
        }
        $meta = $state === States::DRAFT
            ? ['relation' => 'OR', ['key' => Review::STATE, 'compare' => 'NOT EXISTS'], ['key' => Review::STATE, 'value' => States::DRAFT]]
            : [['key' => Review::STATE, 'value' => $state]];
        $query->set('meta_query', $meta); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Filtering the list by review state.
    }

    /**
     * Classic editor: the post was saved but not published, and why.
     */
    public static function heldNotice(): void
    {
        $screen = get_current_screen();
        if ($screen === null || $screen->base !== 'post' || ! isset($_GET['ruvelo_editorial_held'], $_GET['post'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
            return;
        }
        $post = get_post((int) $_GET['post']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
        if (! $post instanceof WP_Post) {
            return;
        }
        $reasons = Gate::reasons($post);
        echo '<div class="notice notice-warning"><p><strong>'.esc_html__('Saved, not published.', 'ruvelo-editorial').'</strong> '.esc_html(implode(' ', $reasons)).' <a href="'.esc_url(ReviewPage::url($post->ID)).'">'.esc_html__('Open the review', 'ruvelo-editorial').'</a></p></div>';
    }

    public static function profileFields(WP_User $user): void
    {
        if (! user_can($user, 'edit_posts')) {
            return;
        }
        $emails = get_user_meta($user->ID, Notifications::USER_OPTOUT, true) !== 'off';
        $theme = self::theme($user->ID);
        wp_nonce_field('ruvelo_editorial_profile', 'ruvelo_editorial_profile_nonce');
        echo '<h2 id="ruvelo-editorial">'.esc_html__('Reviews', 'ruvelo-editorial').'</h2><table class="form-table" role="presentation"><tr><th scope="row">'.esc_html__('Emails', 'ruvelo-editorial').'</th><td>';
        echo '<label><input type="checkbox" name="ruvelo_editorial_emails" value="1"'.checked($emails, true, false).'> '.esc_html__('Email me when I’m asked to review, and when my posts are approved or need changes', 'ruvelo-editorial').'</label>';
        echo '<p class="description">'.esc_html__('Emails are grouped: one every few minutes at most.', 'ruvelo-editorial').'</p></td></tr>';
        echo '<tr><th scope="row"><label for="ruvelo-editorial-theme">'.esc_html__('Review screens', 'ruvelo-editorial').'</label></th><td><select name="ruvelo_editorial_theme" id="ruvelo-editorial-theme">';
        foreach (['light' => __('Light', 'ruvelo-editorial'), 'dark' => __('Dark', 'ruvelo-editorial'), 'auto' => __('Match my device', 'ruvelo-editorial')] as $key => $label) {
            echo '<option value="'.esc_attr($key).'"'.selected($theme, $key, false).'>'.esc_html($label).'</option>';
        }
        echo '</select></td></tr></table>';
    }

    public static function saveProfile(int $userId): void
    {
        if (! current_user_can('edit_user', $userId) || ! isset($_POST['ruvelo_editorial_profile_nonce']) || ! wp_verify_nonce(sanitize_key(wp_unslash($_POST['ruvelo_editorial_profile_nonce'])), 'ruvelo_editorial_profile')) {
            return;
        }
        update_user_meta($userId, Notifications::USER_OPTOUT, empty($_POST['ruvelo_editorial_emails']) ? 'off' : 'on');
        $theme = isset($_POST['ruvelo_editorial_theme']) ? sanitize_key(wp_unslash($_POST['ruvelo_editorial_theme'])) : 'light';
        update_user_meta($userId, self::THEME_META, in_array($theme, ['light', 'dark', 'auto'], true) ? $theme : 'light');
    }
}
