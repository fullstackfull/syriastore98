{{--
    Cache, on the design system. The size readout was an `<input disabled>` with NO label of any
    kind — a form control that is not a form control, announced as "edit text, disabled, blank"
    with the number in it and nothing saying what the number is. It is a read-only figure, so it
    is `<x-ss.stat>`: a value with a label, which is what it always was.
--}}
<div class="ss-card" id="Cache_Settings">
    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('Cache Settings') }}</h2>
            <p class="ss-page-header__subtitle">
                {{ __('This is a page meant for more advanced users, simply ignore it if you do not understand what cache is.') }}
            </p>
        </div>
    </div>

    <div class="ss-card__body">
        <x-ss.stats>
            <x-ss.stat :label="__('Cache size')"
                       :value="\App\Models\Utility::GetCacheSize() . ' ' . __('MB')"
                       :period="__('right now')" />
        </x-ss.stats>
    </div>

    <div class="ss-card__footer ss-card__footer--end">
        <a href="{{ url('config-cache') }}" class="ss-btn ss-btn--secondary">{{ __('Clear Cache') }}</a>
    </div>
</div>
