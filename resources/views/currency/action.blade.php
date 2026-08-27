<span class="d-flex gap-1 justify-content-end">
    <button class="btn btn-sm btn-info btn-badge"
        data-url="{{ route('currency.edit', $currency->id) }}" data-size="md"
        data-ajax-popup="true" data-title="{{ __('Edit Currency') }}" data-bs-toggle="tooltip"
        title="{{ __('Edit') }}">
        <i class="ti ti-pencil"></i>
    </button>
</span>