{{--
    Empty state. It carries the teaching, because an empty screen is when
    someone most needs it -- and it is an invitation to act, not an apology.
--}}
@props(['title', 'body' => null, 'icon' => 'inbox'])

<div {{ $attributes->merge(['class' => 'ss-empty']) }}>
    <div class="ss-empty__icon" aria-hidden="true"><i class="ti ti-{{ $icon }}"></i></div>
    <div class="ss-empty__body">
        <p class="ss-empty__title">{{ $title }}</p>
        @if ($body)<p class="ss-page-header__subtitle">{{ $body }}</p>@endif
        @isset($action){{ $action }}@endisset
    </div>
</div>
