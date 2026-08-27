<div class="d-flex gap-1">
    @if (Laratrust::hasPermission('Edit Helpdesk Status') || Laratrust::hasPermission('Delete Helpdesk Status'))
        @permission('Edit Helpdesk Status')
            <a href="javascript::void(0)" class="btn btn-badge btn-sm btn-info"
                data-url="{{ route('helpdesk.status.edit', $status->id) }}" data-ajax-popup="true" data-size="md"
                data-bs-toggle="tooltip" title="{{ __('Edit') }}" data-title="{{ __('Edit Status') }}"
                data-bs-original-title="{{ __('Edit') }}">
                <i class="ti ti-pencil"></i>
            </a>
        @endpermission
        @permission('Delete Helpdesk Status')
            {{ Form::open(['route' => ['helpdesk.status.delete', $status->id], 'class' => 'm-0']) }}
            @method('DELETE')
            <a href="javascript::void(0)" class="btn btn-badge btn-sm btn-danger show_confirm" data-bs-toggle="tooltip" title="{{ __('Delete') }}"
                data-bs-original-title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}" data-confirm="{{ __('Are You Sure?') }}"
                data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
                data-confirm-yes="delete-form-{{ $status->id }}"><i class="ti ti-trash"></i></a>
            {{ Form::close() }}
        @endpermission
    @endif
</div>
