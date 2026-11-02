{{--
    Status badge. The dot is not decoration: colour alone must not carry
    meaning (WCAG 1.4.1), and seven order statuses are not distinguishable by
    hue in dark mode. The dot plus the text is the signal.
--}}
@props(['tone' => 'neutral', 'label'])

@php
    $toneClass = in_array($tone, ['brand', 'success', 'warning', 'danger', 'info'], true)
        ? ' ss-badge--' . $tone
        : '';
@endphp

<span {{ $attributes->merge(['class' => 'ss-badge' . $toneClass]) }}>
    <span class="ss-badge__dot" aria-hidden="true"></span>{{ $label }}
</span>
