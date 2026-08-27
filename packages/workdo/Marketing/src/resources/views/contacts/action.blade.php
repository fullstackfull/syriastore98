<div class="d-flex">
    @permission('Edit Marketing Contact')
        {{-- <div class="action-btn bg-success ms-2"> --}}
            <a href="#" class="btn btn-sm btn-success btn-badge mr-1 d-inline-flex align-items-center cp_link" data-toggle="modal"
                data-target="#commonModel" data-ajax-popup="true" data-size="lg"
                data-url="{{ route('contact.add-note', $contactsDetails->id) }}"
                data-title="{{ __('Add Note & Change Status') }}" data-bs-toggle="tooltip"
                data-bs-original-title="{{ __('Add Note & Change Status') }}">
                <span class=""><i class="ti ti-note"></i></span></a>
        {{-- </div> --}}
    @endpermission
    @permission('Delete Marketing Contact')
        {{-- <div class="action-btn bg-danger ms-2"> --}}
            {{ Form::open(['route' => ['vCard.contacts.destroy', $contactsDetails->id], 'class' => 'm-0']) }}
            <a class="btn btn-sm btn-danger btn-badge mr-1 align-items-center bs-pass-para show_confirm" data-bs-toggle="tooltip" title=""
                data-bs-original-title="Delete" aria-label="Delete" data-confirm="{{ __('Are You Sure?') }}"
                data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
                data-confirm-yes="delete-form-{{ $contactsDetails->id }}"><i class="ti ti-trash "></i></a>
            {!! Form::close() !!}
        {{-- </div> --}}
    @endpermission

</div>
