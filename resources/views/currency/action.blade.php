<span class="d-flex gap-1 justify-content-end">
    <button aria-label="{{ __('Edit Currency') }}" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost"
        data-url="{{ route('currency.edit', $currency->id) }}" data-size="md"
        data-ajax-popup="true" data-title="{{ __('Edit Currency') }}" data-bs-toggle="tooltip"
        title="{{ __('Edit') }}">
        <i class="ti ti-pencil" aria-hidden="true"></i>
    </button>
</span>