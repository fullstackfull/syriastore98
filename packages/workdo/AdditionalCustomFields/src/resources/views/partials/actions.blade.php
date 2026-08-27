<div class="d-flex justify-content-center gap-2">
    @permission('Edit AdditionalCustomFields')    
        <a href="#" data-url="{{ route('additionalcustomfields.edit', $field->id) }}" 
           data-bs-toggle="tooltip" 
           title="{{ __('Edit') }}"
           data-ajax-popup="true" 
           data-title="{{ __('Edit Custom Field') }}" 
           class="btn btn-sm btn-info">
            <i class="ti ti-pencil"></i>
        </a>
    @endpermission
    
    @permission('Delete AdditionalCustomFields')
    <form action="{{ route('additionalcustomfields.destroy', $field->id) }}" method="POST">
        @csrf
        @method('DELETE')
        <button type="button" class="btn btn-sm btn-danger show_confirm"  data-confirm="{{ __('Are You Sure?') }}"
        data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"  data-bs-toggle="tooltip" title="{{__('Delete')}}">
            <i class="ti ti-trash"></i>
        </button>
    </form>
    @endpermission
</div>
