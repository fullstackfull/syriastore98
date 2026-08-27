<span class="d-flex gap-1 justify-content-end">
    <button class="btn btn-sm btn-badge btn-info" data-url="{{ route('state.edit', $state['id']) }}" data-size="md" data-ajax-popup="true" data-title="{{ __('Edit State') }}" data-bs-toggle="tooltip" title="{{ __('Edit') }}">
        <i class="ti ti-pencil"></i>
    </button>

    {!! Form::open([
        'method' => 'DELETE',
        'route' => ['state.destroy', $state['id']],
        'class' => 'd-inline',
    ]) !!}
    <button type="button" class="btn btn-badge btn-sm btn-danger show_confirm" data-confirm="{{ __('Are You Sure?') }}"
        data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
        data-bs-toggle="tooltip" title="{{ __('Delete') }}">
        <i class="ti ti-trash"></i>
    </button>
    {!! Form::close() !!}
</span>