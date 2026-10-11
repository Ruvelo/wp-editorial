<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial;

/**
 * The four review states and how they look. They sit beside core's post
 * statuses (draft, pending, future, publish), never instead of them.
 */
final class States
{
    public const DRAFT = 'draft';

    public const IN_REVIEW = 'in_review';

    public const CHANGES = 'changes_requested';

    public const APPROVED = 'approved';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [self::DRAFT, self::IN_REVIEW, self::CHANGES, self::APPROVED];
    }

    public static function valid(string $state): bool
    {
        return in_array($state, self::keys(), true);
    }

    /**
     * @return array<string, array{label: string, tone: string}>
     */
    public static function all(): array
    {
        $states = [
            self::DRAFT => ['label' => __('Not in review', 'ruvelo-editorial'), 'tone' => 'neutral'],
            self::IN_REVIEW => ['label' => __('In review', 'ruvelo-editorial'), 'tone' => 'accent'],
            self::CHANGES => ['label' => __('Changes requested', 'ruvelo-editorial'), 'tone' => 'warning'],
            self::APPROVED => ['label' => __('Approved', 'ruvelo-editorial'), 'tone' => 'success'],
        ];

        /**
         * Filters the labels and tones (accent, success, warning, danger,
         * neutral) of the review states. The keys can't change.
         *
         * @param array<string, array{label: string, tone: string}> $states
         */
        $filtered = apply_filters('ruvelo_editorial_states', $states);
        if (is_array($filtered)) {
            foreach ($states as $key => $state) {
                if (isset($filtered[$key]['label']) && is_string($filtered[$key]['label'])) {
                    $states[$key]['label'] = $filtered[$key]['label'];
                }
                if (isset($filtered[$key]['tone']) && in_array($filtered[$key]['tone'], ['accent', 'success', 'warning', 'danger', 'neutral'], true)) {
                    $states[$key]['tone'] = $filtered[$key]['tone'];
                }
            }
        }

        return $states;
    }

    public static function label(string $state): string
    {
        return self::all()[$state]['label'] ?? $state;
    }

    public static function tone(string $state): string
    {
        return self::all()[$state]['tone'] ?? 'neutral';
    }
}
