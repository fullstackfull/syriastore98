{{-- Reference-weight container: quiet, for material the merchant consults. --}}
@props(['title' => null, 'note' => null, 'flush' => false, 'overflow' => false])

<div {{ $attributes->merge(['class' => 'ss-card' . ($overflow ? ' ss-card--overflow' : '')]) }}>
    @if ($title)
        <div class="ss-card__header">
            <div class="ss-page-header__text">
                <h2 class="ss-card__title">{{ $title }}</h2>
                @if ($note)<p class="ss-page-header__subtitle">{{ $note }}</p>@endif
            </div>
            @isset($actions){{ $actions }}@endisset
        </div>
    @endif

    <div class="ss-card__body{{ $flush ? ' ss-card__body--flush' : '' }}">
        {{ $slot }}
    </div>

    @isset($footer)<div class="ss-card__footer">{{ $footer }}</div>@endisset
</div>
