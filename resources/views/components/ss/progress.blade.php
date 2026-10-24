{{--
    Progress. Paired with its own text value, because colour alone must not
    carry a measurement, and a bare bar is unreadable to a screen reader.
--}}
@props(['value', 'max' => 100, 'label', 'tone' => null])

@php
    $pct = $max > 0 ? max(0, min(100, ($value / $max) * 100)) : 0;
    $toneClass = in_array($tone, ['warning', 'danger'], true) ? ' ss-progress--' . $tone : '';
@endphp

<div {{ $attributes }}>
    <div class="ss-progress{{ $toneClass }}" role="progressbar"
         aria-valuenow="{{ round($pct) }}" aria-valuemin="0" aria-valuemax="100"
         aria-label="{{ $label }}">
        <span class="ss-progress__fill" style="width: {{ round($pct, 2) }}%"></span>
    </div>
    <p class="ss-stat__period ss-num">{{ round($pct) }}%</p>
</div>
