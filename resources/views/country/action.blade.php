<span class="d-flex gap-1 justify-content-end">
    <button aria-label="{{ __('Edit Country') }}" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost"
        data-url="{{ route('countries.edit', $country->id) }}"
        data-size="md" data-ajax-popup="true"
        data-title="{{ __('Edit Country') }}" data-bs-toggle="tooltip"
        title="{{ __('Edit') }}">
        <i class="ti ti-pencil" aria-hidden="true"></i>
    </button>

    {!! Form::open([
        'method' => 'DELETE',
        'route' => ['countries.destroy', $country->id],
        'class' => 'd-inline',
    ]) !!}
    <button aria-label="{{ __('Delete :thing', ['thing' => __('Country')]) }}" type="button"
        class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet show_confirm" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
        data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
        title="{{ __('Delete') }}">
        <i class="ti ti-trash" aria-hidden="true"></i>
    </button>
    {!! Form::close() !!}
</span>