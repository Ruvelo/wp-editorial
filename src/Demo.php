<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial;

use WP_Post;
use WP_User;

/**
 * Halyard's marketing team mid-week, for the Playground demo and
 * screenshots: `wp ruvelo-editorial demo --yes`. Every step goes through
 * the plugin's own write path with the clock moved back, so states,
 * history and notes are exactly what real use would leave.
 */
final class Demo
{
    public const PASSWORD = 'password';

    /** @var array<string, int> name => user id */
    private static array $people = [];

    /**
     * @return array{posts: int, people: int}
     */
    public static function seed(): array
    {
        $content = require RUVELO_EDITORIAL_DIR.'demo/content.php';
        if (! is_array($content)) {
            return ['posts' => 0, 'people' => 0];
        }

        Notifications::pause(true);
        Install::install();
        update_option('blogname', 'Halyard');
        update_option('blogdescription', 'Billing for B2B software');
        Settings::update([
            'post_types' => [
                'post' => ['enabled' => true, 'gate' => true, 'approvals' => 2, 'resolve_notes' => false],
                'page' => ['enabled' => true, 'gate' => true, 'approvals' => 1, 'resolve_notes' => false],
            ],
        ]);
        foreach (['post', 'page'] as $type) {
            Notes::enableFor($type);
        }

        $previous = get_current_user_id();
        foreach ($content['people'] as [$name, $role, $job]) {
            self::person((string) $name, (string) $role, (string) $job);
        }

        $now = time();
        $count = 0;
        foreach ($content['posts'] as [$key, $title, $author, $type, $paragraphs, $script]) {
            $first = $script !== [] ? (float) $script[0][0] : 1.5;
            $id = self::post((string) $title, self::person((string) $author), (string) $type, $paragraphs, $now - (int) (($first + 0.8) * DAY_IN_SECONDS));
            if ($id === 0) {
                continue;
            }
            $count++;
            foreach ($script as [$days, $who, $action, $extra]) {
                self::step($id, (int) round($now - (float) $days * DAY_IN_SECONDS), self::person((string) $who), (string) $action, $extra, $now);
            }
        }

        Clock::freeze(null);
        wp_set_current_user($previous);
        Queue::flush();
        Notifications::pause(false);

        return ['posts' => $count, 'people' => count(self::$people)];
    }

    private static function step(int $postId, int $at, int $actor, string $action, mixed $extra, int $now): void
    {
        Clock::freeze($at);
        wp_set_current_user($actor);
        $extra = is_array($extra) ? $extra : ['value' => $extra];

        switch ($action) {
            case 'request':
                $reviewers = array_values(array_map(static fn ($name): int => self::person((string) $name), (array) ($extra['reviewers'] ?? [])));
                $due = isset($extra['due']) ? gmdate('Y-m-d', $now + (int) $extra['due'] * DAY_IN_SECONDS) : null;
                Review::request($postId, $reviewers, $actor, (string) ($extra['message'] ?? ''), $due);
                break;
            case 'approve':
                Review::approve($postId, $actor, (string) ($extra['value'] ?? ''));
                break;
            case 'changes':
                Review::requestChanges($postId, $actor, (string) ($extra['message'] ?? ''), 0, ! empty($extra['note']));
                self::dateNotes($postId, $at);
                break;
            case 'note':
                Notes::create($postId, $actor, (string) ($extra['value'] ?? ''));
                self::dateNotes($postId, $at);
                break;
            case 'publish':
                wp_update_post(['ID' => $postId, 'post_status' => 'publish', 'post_date' => (string) wp_date('Y-m-d H:i:s', $at), 'post_date_gmt' => gmdate('Y-m-d H:i:s', $at), 'edit_date' => true]);
                break;
            case 'schedule':
                $when = (string) wp_date('Y-m-d 09:00:00', $now + (int) ($extra['value'] ?? 7) * DAY_IN_SECONDS);
                wp_update_post(['ID' => $postId, 'post_status' => 'future', 'post_date' => $when, 'post_date_gmt' => get_gmt_from_date($when), 'edit_date' => true]);
                break;
            case 'override':
                Gate::allowOnce($postId, $actor, (string) ($extra['value'] ?? ''));
                wp_update_post(['ID' => $postId, 'post_status' => 'publish', 'post_date' => (string) wp_date('Y-m-d H:i:s', $at), 'post_date_gmt' => gmdate('Y-m-d H:i:s', $at), 'edit_date' => true]);
                break;
        }
    }

    /**
     * Notes made by the demo get the demo's clock, not today's date.
     */
    private static function dateNotes(int $postId, int $at): void
    {
        $notes = get_comments(['post_id' => $postId, 'type' => Notes::TYPE, 'status' => 'all', 'number' => 1, 'orderby' => 'comment_ID', 'order' => 'DESC']);
        $note = is_array($notes) ? ($notes[0] ?? null) : null;
        if ($note instanceof \WP_Comment && strtotime($note->comment_date_gmt.' UTC') > $at) {
            wp_update_comment(['comment_ID' => (int) $note->comment_ID, 'comment_date' => (string) wp_date('Y-m-d H:i:s', $at), 'comment_date_gmt' => gmdate('Y-m-d H:i:s', $at)]);
        }
    }

    /**
     * @param list<string> $paragraphs
     */
    private static function post(string $title, int $author, string $type, array $paragraphs, int $created): int
    {
        $existing = get_page_by_path(sanitize_title($title), OBJECT, $type);
        if ($existing instanceof WP_Post) {
            wp_delete_post($existing->ID, true);
        }
        $blocks = [];
        foreach ($paragraphs as $text) {
            $blocks[] = str_starts_with($text, '## ')
                ? "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">".esc_html(substr($text, 3))."</h2>\n<!-- /wp:heading -->"
                : "<!-- wp:paragraph -->\n<p>".esc_html($text)."</p>\n<!-- /wp:paragraph -->";
        }
        $id = wp_insert_post([
            'post_title' => $title,
            'post_name' => sanitize_title($title),
            'post_content' => implode("\n\n", $blocks),
            'post_status' => 'draft',
            'post_type' => $type,
            'post_author' => $author,
            'post_date' => (string) wp_date('Y-m-d H:i:s', $created),
            'post_date_gmt' => gmdate('Y-m-d H:i:s', $created),
        ]);

        return is_int($id) ? $id : 0;
    }

    public static function person(string $name, string $role = 'author', string $job = ''): int
    {
        if (isset(self::$people[$name])) {
            return self::$people[$name];
        }
        $login = sanitize_user(strtolower(str_replace(' ', '.', remove_accents($name))), true);
        $user = get_user_by('login', $login);
        if ($user === false && $name === 'Maya Okafor') {
            // The site's first administrator plays Maya.
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID']);
            $user = $admins[0] ?? false;
        }
        $parts = ['display_name' => $name, 'first_name' => strtok($name, ' ') ?: $name, 'last_name' => trim((string) strstr($name, ' ')), 'description' => $job];
        if ($user instanceof WP_User) {
            wp_update_user(['ID' => $user->ID] + $parts);
            if (! in_array($role, $user->roles, true) && ! in_array('administrator', $user->roles, true)) {
                $user->set_role($role);
            }
            $id = $user->ID;
        } else {
            $created = wp_insert_user($parts + [
                'user_login' => $login,
                'user_email' => $login.'@halyard.test',
                'user_pass' => self::PASSWORD,
                'role' => $role,
            ]);
            $id = is_int($created) ? $created : 0;
        }
        if ($id > 0) {
            global $wpdb;
            // Skip the editor's welcome guide for the demo team.
            update_user_meta($id, $wpdb->get_blog_prefix().'persisted_preferences', ['core/edit-post' => ['welcomeGuide' => false], 'core' => ['fixedToolbar' => false], '_modified' => gmdate('c')]);
        }

        return self::$people[$name] = $id;
    }
}
