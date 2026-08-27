<span class="d-flex gap-1 justify-content-end">
    <button class="btn btn-sm btn-info" data-url="{{ route('spintowin-label.edit', $label->id) }}" data-size="md"
        data-ajax-popup="true" data-title="{{ __('Edit Label') }}" data-bs-toggle="tooltip"
        title="{{ __('Edit') }}">
        <i class="ti ti-pencil"></i>
    </button>
    {!! Form::open([
        'method' => 'DELETE',
        'route' => ['spintowin-label.destroy', $label->id],
        'class' => 'd-inline',
    ]) !!}
    <button type="button" class="btn btn-sm btn-danger show_confirm" data-bs-toggle="tooltip"
    title="{{ __('Delete') }}">
        <i class="ti ti-trash"></i>
    </button>
    {!! Form::close() !!}
</span>