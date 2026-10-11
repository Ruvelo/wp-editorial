<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial;

/**
 * The notification outbox table and the three capabilities. Runs on
 * activation and whenever the stored schema version is behind the code.
 */
final class Install
{
    public const DB_VERSION = '1';

    public const DB_OPTION = 'ruvelo_editorial_db_version';

    public static function activate(): void
    {
        self::install();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(Notifications::CRON_HOOK);
    }

    public static function maybeUpgrade(): void
    {
        if (get_option(self::DB_OPTION) !== self::DB_VERSION) {
            self::install();
        }
    }

    public static function install(): void
    {
        self::createTables();
        self::grantCapabilities();
        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    public static function outboxTable(): string
    {
        global $wpdb;

        return $wpdb->prefix.'ruvelo_editorial_outbox';
    }

    public static function createTables(): void
    {
        global $wpdb;

        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $outbox = self::outboxTable();

        // One row per person per review event, until WP-Cron emails it in
        // that person's next digest.
        dbDelta("CREATE TABLE {$outbox} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  event varchar(40) NOT NULL,
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  message text NOT NULL,
  created_at datetime NOT NULL,
  sent_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY sent_user (sent_at,user_id),
  KEY post_id (post_id)
) {$charset};");
    }

    /**
     * Who gets what, by default:
     *
     * - review posts: administrators and editors (`ruvelo_editorial_reviewer_roles`);
     * - override a publish gate: administrators (`ruvelo_editorial_override_roles`);
     * - change settings: administrators (`ruvelo_editorial_manager_roles`).
     */
    public static function grantCapabilities(): void
    {
        $grants = [
            Settings::CAP_REVIEW => apply_filters('ruvelo_editorial_reviewer_roles', ['administrator', 'editor']),
            Settings::CAP_OVERRIDE => apply_filters('ruvelo_editorial_override_roles', ['administrator']),
            Settings::CAP_MANAGE => apply_filters('ruvelo_editorial_manager_roles', ['administrator']),
        ];
        foreach ($grants as $cap => $roles) {
            foreach (is_array($roles) ? $roles : [] as $name) {
                $role = is_string($name) ? get_role($name) : null;
                $role?->add_cap($cap);
            }
        }
    }

    public static function revokeCapabilities(): void
    {
        foreach (wp_roles()->role_objects as $role) {
            foreach ([Settings::CAP_REVIEW, Settings::CAP_OVERRIDE, Settings::CAP_MANAGE] as $cap) {
                $role->remove_cap($cap);
            }
        }
    }
}
