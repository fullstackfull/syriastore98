<span class="d-flex gap-1 justify-content-end">
    @permission('Edit Pre Order Setting')
        <button class="btn btn-sm btn-info"
            data-url="{{ route('preordersetting.edit', $preorder_setting->id) }}" data-size="md"
            data-ajax-popup="true" data-title="{{ __('Edit Pre Order Setting') }}" data-bs-toggle="tooltip"
            title="{{ __('Edit') }}">
            <i class="ti ti-pencil" ></i>
        </button>
    @endpermission

    @permission('Delete Pre Order Setting')
        {!! Form::open(['method' => 'DELETE', 'route' => ['preordersetting.destroy', $preorder_setting->id], 'class' => 'd-inline']) !!}
        <button type="button" class="btn btn-sm btn-danger show_confirm" data-bs-toggle="tooltip"
        title="{{ __('Delete Pre Order Setting') }}">
            <i class="ti ti-trash"></i>
        </button>
        {!! Form::close() !!}
    @endpermission
</span>
