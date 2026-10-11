<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial;

/**
 * Every setting in one place: defaults, the saved option, and the
 * `ruvelo_editorial_settings` filter so a site can define them in code.
 */
final class Settings
{
    public const OPTION = 'ruvelo_editorial_settings';

    /** Change settings. */
    public const CAP_MANAGE = 'ruvelo_manage_editorial';

    /** Be asked for reviews, approve and request changes. */
    public const CAP_REVIEW = 'ruvelo_review_posts';

    /** Publish past a gate, with a reason that is logged. */
    public const CAP_OVERRIDE = 'ruvelo_override_review_gate';

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            // post type => [enabled, gate, approvals, resolve_notes]
            'post_types' => [
                'post' => self::postTypeDefaults(true),
                'page' => self::postTypeDefaults(true),
            ],
            'emails' => true,
            'inbox' => true,
            'digest_minutes' => 5,
            'batch_size' => 50,
            'delete_data' => false,
        ];
    }

    /**
     * @return array{enabled: bool, gate: bool, approvals: int, resolve_notes: bool}
     */
    public static function postTypeDefaults(bool $enabled = false): array
    {
        return ['enabled' => $enabled, 'gate' => false, 'approvals' => 1, 'resolve_notes' => false];
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (self::$cache === null) {
            $saved = get_option(self::OPTION, []);
            $settings = array_merge(self::defaults(), is_array($saved) ? $saved : []);

            /**
             * Filters the settings. Use it to define settings in code.
             *
             * @param array<string, mixed> $settings
             */
            $filtered = apply_filters('ruvelo_editorial_settings', $settings);
            self::$cache = is_array($filtered) ? $filtered : $settings;
        }

        return self::$cache;
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? self::defaults()[$key] ?? null;
    }

    public static function int(string $key): int
    {
        $value = self::get($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    public static function bool(string $key): bool
    {
        return (bool) self::get($key);
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function update(array $values): void
    {
        $saved = get_option(self::OPTION, []);
        update_option(self::OPTION, array_merge(is_array($saved) ? $saved : [], $values));
        self::flush();
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * The workflow settings for one post type, normalised.
     *
     * @return array{enabled: bool, gate: bool, approvals: int, resolve_notes: bool}
     */
    public static function forType(string $postType): array
    {
        $all = self::get('post_types');
        $raw = is_array($all) && isset($all[$postType]) && is_array($all[$postType]) ? $all[$postType] : [];
        $config = [
            'enabled' => ! empty($raw['enabled']),
            'gate' => ! empty($raw['gate']),
            'approvals' => max(1, min(10, isset($raw['approvals']) && is_numeric($raw['approvals']) ? (int) $raw['approvals'] : 1)),
            'resolve_notes' => ! empty($raw['resolve_notes']),
        ];
        if (! post_type_exists($postType)) {
            $config['enabled'] = false;
        }

        return $config;
    }

    public static function enabled(string $postType): bool
    {
        return self::forType($postType)['enabled'];
    }

    /**
     * Post types with the review workflow switched on.
     *
     * @return list<string>
     */
    public static function enabledTypes(): array
    {
        $all = self::get('post_types');
        $types = [];
        foreach (is_array($all) ? array_keys($all) : [] as $type) {
            if (is_string($type) && self::enabled($type)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Post types the settings screen offers: anything with an admin UI and
     * the block or classic editor.
     *
     * @return array<string, string> name => label
     */
    public static function availableTypes(): array
    {
        $types = [];
        foreach (get_post_types(['show_ui' => true], 'objects') as $type) {
            if ($type->name === 'attachment' || str_starts_with($type->name, 'wp_') || ! post_type_supports($type->name, 'editor')) {
                continue;
            }
            $types[$type->name] = (string) $type->labels->name;
        }

        return $types;
    }
}
