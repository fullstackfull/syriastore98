{{--
    One measure.

    `period` is REQUIRED and intentionally has no default. A count with no
    period is the commonest way a dashboard misleads, so the component will
    not render one for you -- pass __('all time'), __('last 7 days') or
    whatever is true.

    `value` of null means "the system cannot produce this figure", which is
    rendered as an honest unavailable state and must not look like a zero.
--}}
@props([
    'label',
    'value' => null,
    'period',
    'href' => null,
    'hint' => null,
])

<div {{ $attributes->merge(['class' => 'ss-stat' . (is_null($value) ? ' ss-stat--unavailable' : '')]) }}>
    <span class="ss-stat__label">
        @if ($href)
            <a href="{{ $href }}" class="ss-breadcrumb__link">{{ $label }}</a>
        @else
            {{ $label }}
        @endif
    </span>

    <span class="ss-stat__value">
        @if (is_null($value))
            {{ __('Not available') }}
        @else
            {{ $value }}
        @endif
    </span>

    <span class="ss-stat__period">
        {{ $period }}
        @if ($hint)
            <span class="ss-visually-hidden">{{ $hint }}</span>
        @endif
    </span>
</div>
