<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial;

/**
 * The time review events are stamped with. Only the demo moves it, to
 * seed a believable week of history.
 */
final class Clock
{
    private static ?int $frozen = null;

    public static function now(): int
    {
        return self::$frozen ?? time();
    }

    public static function freeze(?int $timestamp): void
    {
        self::$frozen = $timestamp;
    }
}
