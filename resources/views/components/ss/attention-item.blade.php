{{--
    One row of the attention block: a count, what it means, and the one thing
    to do about it. The action is a real link, not a card-wide click target.
--}}
@props(['count', 'text', 'href' => null, 'action' => null])

<li class="ss-attention__item">
    <span class="ss-attention__count ss-num">{{ $count }}</span>
    <span class="ss-attention__text">{{ $text }}</span>
    @if ($href && $action)
        <a href="{{ $href }}" class="ss-btn ss-btn--secondary ss-btn--sm">{{ $action }}</a>
    @endif
</li>
