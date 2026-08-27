<div class="d-flex gap-1">
    @if (Laratrust::hasPermission('Edit Helpdesk Category') || Laratrust::hasPermission('Delete Helpdesk Category'))
        @permission('Edit Helpdesk Category')
            <a href="javascript::void(0)" class="btn btn-badge btn-sm btn-info"
                data-url="{{ route('helpdesk.category.edit', $category->id) }}" data-ajax-popup="true" data-size="md"
                data-bs-toggle="tooltip" title="{{ __('Edit') }}" data-title="{{ __('Edit Category') }}"
                data-bs-original-title="{{ __('Edit') }}">
                <i class="ti ti-pencil"></i>
            </a>
        @endpermission
        @permission('Delete Helpdesk Category')
            {{ Form::open(['route' => ['helpdesk.category.delete', $category->id], 'class' => 'm-0']) }}
            @method('DELETE')
            <a href="javascript::void(0)" class="btn btn-badge btn-sm btn-danger show_confirm" data-bs-toggle="tooltip" title="{{ __('Delete') }}"
                data-bs-original-title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}" data-confirm="{{ __('Are You Sure?') }}"
                data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
                data-confirm-yes="delete-form-{{ $category->id }}"><i class="ti ti-trash"></i></a>
            {{ Form::close() }}
        @endpermission
    @endif
</div>

