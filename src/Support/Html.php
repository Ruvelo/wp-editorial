<?php

declare(strict_types=1);

namespace Ruvelo\WP\Editorial\Support;

use Ruvelo\WP\Editorial\People;
use Ruvelo\WP\Editorial\Review;
use Ruvelo\WP\Editorial\States;

/**
 * Small, escaped pieces of admin markup shared by the screens.
 */
final class Html
{
    public static function badge(string $state, string $label = ''): string
    {
        return sprintf(
            '<span class="ruvelo-ed-badge ruvelo-ed-tone-%s">%s</span>',
            esc_attr(States::tone($state)),
            esc_html($label !== '' ? $label : States::label($state))
        );
    }

    /**
     * A person as initials, with their decision as a ring and a hidden label.
     */
    public static function person(int $userId, string $decision = ''): string
    {
        $labels = [
            'approved' => __('approved', 'ruvelo-editorial'),
            'changes_requested' => __('requested changes', 'ruvelo-editorial'),
            'pending' => __('hasn’t reviewed yet', 'ruvelo-editorial'),
        ];
        $name = People::name($userId);
        $text = isset($labels[$decision]) ? $name.', '.$labels[$decision] : $name;

        return sprintf(
            '<span class="ruvelo-ed-person%s" title="%s"><span aria-hidden="true">%s</span><span class="screen-reader-text">%s</span></span>',
            $decision !== '' ? ' is-'.esc_attr(str_replace('_', '-', $decision)) : '',
            esc_attr($text),
            esc_html(People::initials($userId)),
            esc_html($text)
        );
    }

    /**
     * Reviewer chips for a post.
     */
    public static function reviewers(int $postId): string
    {
        $approved = Review::approvedBy($postId);
        $changes = Review::changesBy($postId);
        $pending = Review::pending($postId);
        $out = '';
        foreach (Review::reviewers($postId) as $id) {
            $decision = in_array($id, $approved, true) ? 'approved' : (in_array($id, $changes, true) ? 'changes_requested' : (in_array($id, $pending, true) ? 'pending' : ''));
            $out .= self::person($id, $decision);
        }

        return $out !== '' ? '<span class="ruvelo-ed-people">'.$out.'</span>' : '';
    }

    public static function due(int $postId): string
    {
        $due = Review::due($postId);
        if ($due === '') {
            return '<span class="ruvelo-ed-quiet">—</span>';
        }
        $date = wp_date((string) get_option('date_format'), (int) strtotime($due.' 12:00:00'));
        if (Review::isOverdue($postId)) {
            return '<span class="ruvelo-ed-due is-overdue"><strong>'.esc_html__('Overdue', 'ruvelo-editorial').'</strong> '.esc_html((string) $date).'</span>';
        }

        return '<span class="ruvelo-ed-due">'.esc_html((string) $date).'</span>';
    }

    public static function ago(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }

        /* translators: %s: human time difference, e.g. "2 hours" */
        return sprintf(__('%s ago', 'ruvelo-editorial'), human_time_diff($timestamp, time()));
    }
}
