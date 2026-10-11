<?php
/**
 * Local dev only, never shipped: bin/dev-site.sh copies it into .wp/ as a
 * must-use plugin for bin/screenshots.sh. ?shot_as=<login> renders a page as
 * that user, ?shot_login=<login>&to=<path> signs in for real (for the block
 * editor), ?shot_theme=dark|light sets the review screens' theme. In the
 * editor: ?shot_select=<n> selects the nth block, ?shot_publish opens the
 * pre-publish panel, ?shot_click=<text> presses a Review panel button.
 */
add_filter('determine_current_user', function ($id) {
    if (isset($_GET['shot_as'])) {
        $user = get_user_by('login', sanitize_user(wp_unslash($_GET['shot_as'])));
        return $user ? $user->ID : $id;
    }
    return $id;
}, 99);
add_action('plugins_loaded', function () {
    if (! isset($_GET['shot_as'])) {
        return;
    }
    $user = get_user_by('login', sanitize_user(wp_unslash($_GET['shot_as'])));
    if ($user) {
        $exp = time() + HOUR_IN_SECONDS;
        $_COOKIE[AUTH_COOKIE] = wp_generate_auth_cookie($user->ID, $exp, 'auth');
        $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($user->ID, $exp, 'logged_in');
    }
}, 0);
add_filter('get_user_metadata', function ($value, $id, $key) {
    if ($key === 'ruvelo_editorial_theme' && isset($_GET['shot_theme'])) {
        return [sanitize_key($_GET['shot_theme'])];
    }
    return $value;
}, 10, 3);
add_action('admin_init', function () {
    if (isset($_GET['shot_as'])) {
        remove_action('admin_enqueue_scripts', 'wp_auth_check_load');
        add_action('admin_enqueue_scripts', function () { wp_deregister_script('heartbeat'); wp_register_script('heartbeat', false); }, 0);
    }
});
add_action('init', function () {
    if (isset($_GET['shot_login'], $_GET['to'])) {
        // Fresh editor for every shot: no post lock, no stale autosave.
        if (preg_match('/post=(\d+)/', wp_unslash($_GET['to']), $m)) {
            delete_post_meta((int) $m[1], '_edit_lock');
            foreach (get_posts(['post_type' => 'revision', 'post_parent' => (int) $m[1], 'post_status' => 'any', 'numberposts' => -1]) as $revision) {
                if (str_contains($revision->post_name, 'autosave')) {
                    wp_delete_post_revision($revision->ID);
                }
            }
        }
        $user = get_user_by('login', sanitize_user(wp_unslash($_GET['shot_login'])));
        if ($user) {
            wp_set_auth_cookie($user->ID, false);
            wp_safe_redirect(home_url(wp_unslash($_GET['to'])));
            exit;
        }
    }
}, 1);
// In the block editor: ?shot_publish opens the pre-publish panel,
// ?shot_click=<button text> presses a button in the Review panel.
add_action('admin_footer', function () {
    if (! isset($_GET['shot_publish']) && ! isset($_GET['shot_click']) && ! isset($_GET['shot_select'])) {
        return;
    }
    $click = isset($_GET['shot_click']) ? sanitize_text_field(wp_unslash($_GET['shot_click'])) : '';
    ?>
    <script>
    setTimeout(function () {
        <?php if (isset($_GET['shot_select'])) : ?>
        var first = wp.data.select('core/block-editor').getBlockOrder()[<?php echo (int) $_GET['shot_select']; ?>];
        if (first) { wp.data.dispatch('core/block-editor').selectBlock(first); }
        setTimeout(function () { wp.data.dispatch('core/edit-post').openGeneralSidebar('edit-post/document'); }, 300);
        <?php endif; ?>
        <?php if (isset($_GET['shot_publish'])) : ?>
        wp.data.dispatch('core/editor').openPublishSidebar();
        <?php endif; ?>
        var text = <?php echo wp_json_encode($click); ?>;
        if (text) setTimeout(function () {
            Array.prototype.forEach.call(document.querySelectorAll('.ruvelo-ed-panel button'), function (b) {
                if (b.textContent.trim() === text) { b.click(); }
            });
        }, 500);
    }, 3000);
    </script>
    <?php
});
// Clean admin for screenshots: no post locks left by earlier shots, no
// SQLite badge, and the Reviews widget first on the dashboard.
add_action('admin_init', function () {
    if (isset($_GET['shot_as'])) {
        delete_metadata('post', 0, '_edit_lock', '', true);
    }
}, 1);
add_action('admin_head', function () {
    echo '<style>#wp-admin-bar-sqlite-db-integration{display:none!important}</style>';
});
add_filter('get_user_option_meta-box-order_dashboard', function ($order) {
    return isset($_GET['shot_as']) ? ['normal' => 'ruvelo_editorial,dashboard_activity', 'side' => 'dashboard_right_now,dashboard_quick_press', 'column3' => '', 'column4' => ''] : $order;
});
add_action('wp_dashboard_setup', function () {
    if (isset($_GET['shot_as'])) {
        remove_meta_box('dashboard_primary', 'dashboard', 'side');
    }
}, 99);

// The SQLite drop-in adds a "Database: SQLite" item to the admin bar; keep it out of screenshots.
add_action('admin_bar_menu', function ($bar) { $bar->remove_node('sqlite-db-integration'); $bar->remove_node('sqlite_integration'); }, 9999);
add_action('wp_before_admin_bar_render', function () {
    global $wp_admin_bar;
    foreach ($wp_admin_bar->get_nodes() as $n) { if (stripos((string) $n->title, 'Database') !== false) { $wp_admin_bar->remove_node($n->id); } }
});
