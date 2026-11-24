{{--
    One measure.

    `period` is REQUIRED and intentionally has no default. A count with no
    period is the commonest way a dashboard misleads, so the component will
    not render one for you -- pass __('all time'), __('last 7 days') or
    whatever is true.

    `value` of null means "the system cannot produce this figure", which is
    rendered as an honest unavailable state and must not look like a zero.

    `hint` IS VISIBLE, AND THAT IS P5-11. It used to render inside
    `<span class="ss-visually-hidden">`, which `store-syria-ds.css:2238-2246`
    clips to 1x1 with no `:hover`, `:focus` or `:focus-within` rule anywhere
    that reveals it -- so it was not hover-only, it was unreachable by sight.
    Ten call sites pass a hint and nine of them exist to stop a figure being
    misread ("This is not payments received, and not profit"), which is the
    whole of ANA-007. That work shipped into a slot nobody could read.

    IT IS ALSO ASSOCIATED WITH THE VALUE NOW, via `aria-describedby`. Before,
    the hint sat INSIDE `.ss-stat__period`, so a screen reader announced it as
    a continuation of the period with no link to the figure it qualifies --
    worse than it looked, because the markup implied the wrong relationship.
    The id is per-instance: `<x-ss.stats>` renders up to six tiles and
    `reports/_sales_stats` renders four hints in one grid, so a static id
    would emit duplicates and every `aria-describedby` after the first would
    resolve to the wrong tile.

    The three sibling components already did this -- `ss-advanced__hint`,
    `ss-field__hint`, and `choice-group`'s visible `aria-describedby` target.
    This one was the exception, and no test pinned the difference.

    NOTHING HERE COMPUTES. No figure, period, label or amount changes; only
    whether the caveat can be seen. Pinned by tests/Unit/Ui/StatHintIsVisibleTest.
--}}
@props([
    'label',
    'value' => null,
    'period',
    'href' => null,
    'hint' => null,
    'comparison' => null,
])

@php
    $hintId = $hint ? 'ss-stat-hint-' . uniqid() : null;

    /*
     * ANA-002. The figures layer hands over MACHINE values -- direction, magnitude, baseline,
     * period, an optional percentage and an optional note -- and the wording is done here, which
     * is the split that lets the Arabic read as Arabic rather than as a translated template.
     *
     * THE DIRECTION IS IN WORDS, NOT COLOUR. Colour as the sole carrier of meaning fails WCAG
     * 1.4.1, and a green/red pair would also need its own contrast proof in all four combinations
     * (AR/EN x light/dark). The arrow is decorative and `aria-hidden`; "Up"/"Down"/"No change" is
     * the actual statement, in either language.
     *
     * NO PERCENTAGE IS PRINTED WHEN THE FIGURES LAYER WITHHELD ONE. `percent` is null when the
     * earlier period was empty -- there is nothing to divide by -- and `note` then says so. This
     * view does not reconstruct a figure the metric refused to state.
     */
    $trend = null;
    $comparisonLine = null;

    if (is_array($comparison)) {
        $trend = match ($comparison['direction'] ?? '') {
            'up' => '▲',
            'down' => '▼',
            default => '—',
        };

        $replacements = [
            'change' => $comparison['change'] ?? '',
            'baseline' => $comparison['baseline'] ?? '',
            'period' => $comparison['period'] ?? '',
        ];

        $comparisonLine = match ($comparison['direction'] ?? '') {
            'up' => __('Up :change from :baseline in :period', $replacements),
            'down' => __('Down :change from :baseline in :period', $replacements),
            default => __('No change from :baseline in :period', $replacements),
        };

        if (! empty($comparison['percent'])) {
            $comparisonLine .= ' (' . $comparison['percent'] . ')';
        }

        if (! empty($comparison['note'])) {
            $comparisonLine .= ' ' . $comparison['note'];
        }
    }
@endphp

<div {{ $attributes->merge(['class' => 'ss-stat' . (is_null($value) ? ' ss-stat--unavailable' : '')]) }}>
    <span class="ss-stat__label">
        @if ($href)
            <a href="{{ $href }}" class="ss-breadcrumb__link">{{ $label }}</a>
        @else
            {{ $label }}
        @endif
    </span>

    {{-- THE DIRECTIVE IS TIGHT AGAINST THE QUOTE AND THE `>` ON PURPOSE. Written as
         `class="ss-stat__value" @if (...) ... @endif>` it emits `class="ss-stat__value" >` -- a
         stray space before the bracket -- for every tile WITHOUT a hint, which is most of them.
         SaleByReportHonestyTest asserts the exact string `ss-stat__value"> 7 </span>` to prove the
         order COUNT renders as a bare number rather than as money, and that space broke it. The
         behaviour was never wrong; the markup was, and the test caught it before the push. --}}
    <span class="ss-stat__value"@if ($hintId) aria-describedby="{{ $hintId }}"@endif>
        @if (is_null($value))
            {{ __('Not available') }}
        @else
            {{ $value }}
        @endif
    </span>

    <span class="ss-stat__period">{{ $period }}</span>

    {{-- The comparison is a SIBLING paragraph in reading order rather than another
         `aria-describedby` target. The hint needed that association because it used to sit inside
         the period span, disconnected from the figure it qualifies; this line already follows the
         value and the period inside the same tile, and `aria-describedby` only accepts a list,
         which would change the exact attribute value `StatHintIsVisibleTest` pins. --}}
    @if ($comparisonLine)
        <p class="ss-stat__comparison">
            <span aria-hidden="true" class="ss-stat__trend">{{ $trend }}</span>
            {{ $comparisonLine }}
        </p>
    @endif

    @if ($hint)
        <p class="ss-stat__hint" id="{{ $hintId }}">{{ $hint }}</p>
    @endif
</div>
