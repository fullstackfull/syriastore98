{{--
    Page header. The store name is restated here on screens where knowing
    which store you are editing matters -- destructive and financial ones.
--}}
@props(['title', 'subtitle' => null, 'store' => null])

<div {{ $attributes->merge(['class' => 'ss-page-header']) }}>
    <div class="ss-page-header__text">
        <h1 class="ss-page-header__title">{{ $title }}</h1>
        @if ($store)
            <p class="ss-page-header__subtitle">{{ $store }}</p>
        @elseif ($subtitle)
            <p class="ss-page-header__subtitle">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="ss-page-header__actions">{{ $actions }}</div>
    @endisset
</div>
