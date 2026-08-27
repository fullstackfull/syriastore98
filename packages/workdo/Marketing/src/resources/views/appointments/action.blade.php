<div class="d-flex">
@permission('Edit Appointment')
    {{-- <div class="action-btn bg-success  ms-2"> --}}
        <a href="#" class=" btn btn-sm btn-success btn-badge d-inline-flex align-items-center cp_link" data-toggle="modal"
            data-target="#commanModel" data-ajax-popup="true" data-size="lg"
            data-url="{{ route('appointment.add-note', $appointmentDetails->id) }}" data-title="{{ __('Add Note & Change Status') }}"
            data-bs-toggle="tooltip" data-bs-original-title="{{ __('Add Note & Change Status') }}">
            <span class=""><i class="ti ti-note"></i></span></a>
    {{-- </div> --}}
@endpermission
@permission('Delete Appointment')
    {{-- <div class="action-btn bg-danger ms-2"> --}}
        {{ Form::open(['route' => ['appointment.destroy', $appointmentDetails->id], 'class' => 'm-0']) }}
        @method('DELETE')
        <a class=" btn btn-sm btn-danger btn-badge align-items-center bs-pass-para show_confirm" data-bs-toggle="tooltip" title=""
            data-bs-original-title="Delete" aria-label="Delete" data-confirm="{{ __('Are You Sure?') }}"
            data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
            data-confirm-yes="delete-form-{{ $appointmentDetails->id }}"><i class="ti ti-trash "></i></a>
        {!! Form::close() !!}
    {{-- </div> --}}
@endpermission
</div>
