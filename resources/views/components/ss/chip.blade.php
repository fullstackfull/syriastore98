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
])

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => 'ss-chip']) }}
       @if ($pressed) aria-current="page" @endif>
        {{ $label }}
        @if (! is_null($count))<span class="ss-num">({{ $count }})</span>@endif
    </a>
@elseif ($interactive)
    <button type="button" {{ $attributes->merge(['class' => 'ss-chip']) }}
            aria-pressed="{{ $pressed ? 'true' : 'false' }}">
        {{ $label }}
        @if (! is_null($count))<span class="ss-num">({{ $count }})</span>@endif
    </button>
@else
    <span {{ $attributes->merge(['class' => 'ss-chip ss-chip--static']) }}>
        {{ $label }}
        @if (! is_null($count))<span class="ss-num">({{ $count }})</span>@endif
    </span>
@endif
