{{--
    Chip. Three modes, decided by what the caller passes:

      href given      a link into a filtered list
      href null +
      interactive     a toggle; selection comes from aria-pressed, so the
                      visual state and the announced state cannot drift apart
      interactive
      false           a static label. Used when the viewer lacks the
                      permission to open what the chip refers to: a link the
                      controller will refuse is a dead link, and a toggle that
                      does nothing is worse than a label.
--}}
@props([
    'label',
    'pressed' => false,
    'count' => null,
    'href' => null,
    'interactive' => true,
    'share' => null,
])

@php
    /*
        THE METER IS OPT-IN. `share` is a float 0..1, and only a caller that knows the whole
        distribution can supply one -- the chip cannot work it out from its own count. Without it
        nothing changes, which is what keeps the filter toggles and every other chip in the
        product exactly as they were.

        CLAMPED, because a share above 1 would render a bar wider than its chip, and a negative
        one would render nothing while looking deliberate.
    */
    $ssShare = is_null($share) ? null : max(0.0, min(1.0, (float) $share));
    $ssChipClass = 'ss-chip' . (is_null($ssShare) ? '' : ' ss-chip--metered');
    $ssChipStyle = is_null($ssShare) ? null : '--ss-chip-share: ' . round($ssShare * 100, 2) . '%';
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $ssChipClass]) }}
       @if ($ssChipStyle) style="{{ $ssChipStyle }}" @endif
       @if ($pressed) aria-current="page" @endif>
        {{ $label }}
        @if (! is_null($count))<span class="ss-num">({{ $count }})</span>@endif
    </a>
@elseif ($interactive)
    <button type="button" {{ $attributes->merge(['class' => $ssChipClass]) }}
            @if ($ssChipStyle) style="{{ $ssChipStyle }}" @endif
            aria-pressed="{{ $pressed ? 'true' : 'false' }}">
        {{ $label }}
        @if (! is_null($count))<span class="ss-num">({{ $count }})</span>@endif
    </button>
@else
    <span {{ $attributes->merge(['class' => $ssChipClass . ' ss-chip--static']) }}
          @if ($ssChipStyle) style="{{ $ssChipStyle }}" @endif>
        {{ $label }}
        @if (! is_null($count))<span class="ss-num">({{ $count }})</span>@endif
    </span>
@endif
