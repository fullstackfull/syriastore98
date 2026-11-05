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
        distribution can supply one -- the chip cannot work it out from its own count.

        IT IS DRIVEN BY THE STYLE ATTRIBUTE, NOT A CLASS, and that is a backward-compatibility
        fix rather than a preference. P2.5-WAVE-3b first added an `ss-chip--metered` class; the
        chip's class string (`ss-chip`, `ss-chip ss-chip--static`) is a CONTRACT that
        DashboardStatusChipLinkTest and FinanceStatusBadgeContrastTest match exactly, and
        `status-badge.blade.php`'s own comment calls `<span class="ss-chip ss-chip--static">`
        a shape other code relies on. Adding a class changed that string and broke both. A custom
        property, present only when a share is passed, keeps a share-less chip BYTE-IDENTICAL to
        the pre-WAVE-3b markup -- which is what the two tests assert.

        The whole ` style="..."` string (leading space included) is built here and interpolated
        raw, so a chip with no share emits no attribute and no stray whitespace inside the tag.
        The value is numeric via round(), so `{!! !!}` carries no injection.

        CLAMPED, because a share above 1 would render a bar wider than its chip, and a negative
        one would render nothing while looking deliberate.
    */
    $ssShare = is_null($share) ? null : max(0.0, min(1.0, (float) $share));
    $ssChipStyle = is_null($ssShare) ? '' : ' style="--ss-chip-share: ' . round($ssShare * 100, 2) . '%"';
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => 'ss-chip']) }}{!! $ssChipStyle !!}
       @if ($pressed) aria-current="page" @endif>
        {{ $label }}
        @if (! is_null($count))<span class="ss-num">({{ $count }})</span>@endif
    </a>
@elseif ($interactive)
    <button type="button" {{ $attributes->merge(['class' => 'ss-chip']) }}{!! $ssChipStyle !!}
            aria-pressed="{{ $pressed ? 'true' : 'false' }}">
        {{ $label }}
        @if (! is_null($count))<span class="ss-num">({{ $count }})</span>@endif
    </button>
@else
    <span {{ $attributes->merge(['class' => 'ss-chip ss-chip--static']) }}{!! $ssChipStyle !!}>
        {{ $label }}
        @if (! is_null($count))<span class="ss-num">({{ $count }})</span>@endif
    </span>
@endif
