<?php

namespace App\Support;

/**
 * The design system's status badge, for the callers that cannot use the Blade component.
 * =================================================================================================
 *
 * `resources/views/components/ss/status-badge.blade.php` is the real definition and every Blade
 * caller should use it. The DataTables classes cannot: they build their columns as HTML strings,
 * so a `<x-ss.status-badge>` would be rendered as literal text. This is the same markup for them,
 * in one place rather than one copy per column.
 *
 * THE DOT IS NOT DECORATION. Colour alone must not carry meaning (WCAG 1.4.1), and the order
 * statuses are not distinguishable by hue in dark mode. The dot is the second channel and is
 * `aria-hidden` because the text beside it already says what it is.
 *
 * THE LABEL IS ESCAPED HERE. Every caller writes into a `rawColumns` column, where nothing else
 * will escape it.
 */
final class DsBadge
{
    private const TONES = ['brand', 'success', 'warning', 'danger', 'info'];

    /**
     * `$tone` outside the known set renders the neutral badge rather than an unstyled one, so a
     * typo is a quiet grey pill instead of unreadable default text on a transparent background.
     *
     * `$title` CARRIES THE RULE BEHIND THE LABEL (P2-03-9). A stock badge that says "Low stock"
     * without saying what counts as low is a colour with a word on it: the merchant cannot act on
     * it or tell whether it agrees with the filter they just used. It is optional and empty by
     * default, so the seven existing callers are untouched, and it is ESCAPED like the label --
     * it interpolates a threshold that comes from settings. The Blade sibling
     * (`components/ss/status-badge.blade.php`) already accepts arbitrary attributes through
     * `$attributes->merge()`, so it needs no change to do the same thing.
     */
    public static function render(string $tone, string $label, string $title = ''): string
    {
        $class = 'ss-badge' . (in_array($tone, self::TONES, true) ? ' ss-badge--' . $tone : '');

        return '<span class="' . $class . '"'
            . ($title !== '' ? ' title="' . e($title) . '"' : '')
            . '>'
            . '<span class="ss-badge__dot" aria-hidden="true"></span>'
            . e($label)
            . '</span>';
    }
}
