<?php

declare(strict_types=1);

/**
 * A stand-in for WP-CLI, so the commands can be tested in-process: it records
 * what they print and turns WP_CLI::error() into an exception.
 */

namespace {
    if (! class_exists('WP_CLI')) {
        final class WP_CLI
        {
            /** @var list<array{0: string, 1: string}> */
            public static array $out = [];

            public static function add_command(string $name, mixed $callable): void {}

            public static function success(string $message): void
            {
                self::$out[] = ['success', $message];
            }

            public static function log(string $message): void
            {
                self::$out[] = ['log', $message];
            }

            public static function line(string $message = ''): void
            {
                self::$out[] = ['line', $message];
            }

            public static function warning(string $message): void
            {
                self::$out[] = ['warning', $message];
            }

            /**
             * @param array<string, mixed> $assoc
             */
            public static function confirm(string $question, array $assoc = []): void {}

            /**
             * @param array<string, mixed> $assoc
             */
            public static function print_value(mixed $value, array $assoc = []): void
            {
                self::$out[] = ['value', (string) wp_json_encode($value)];
            }

            public static function error(string $message): never
            {
                throw new RuntimeException($message);
            }

            public static function last(): string
            {
                $last = end(self::$out);

                return $last === false ? '' : $last[1];
            }
        }
    }
}

namespace WP_CLI\Utils {
    if (! function_exists('WP_CLI\Utils\format_items')) {
        /**
         * @param list<array<string, mixed>> $items
         * @param list<string>|string $fields
         */
        function format_items(string $format, array $items, array|string $fields): void
        {
            \WP_CLI::$out[] = ['items', (string) wp_json_encode($items)];
        }
    }
}
