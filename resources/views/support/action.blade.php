<span class="d-flex gap-1 justify-content-end">
@permission('Replay Support Ticket')
<a class="btn btn-sm btn-primary" href="{{route('support_ticket.edit',$ticket->id)}}"  data-bs-toggle="tooltip"
title="{{ __('Reply') }}">
    <i class="fas fa-share"></i>
</a>
@endpermission

@permission('Delete Support Ticket')
{!! Form::open(['method' => 'DELETE', 'route' => ['support_ticket.destroy', $ticket->id], 'class' => 'd-inline']) !!}
<button type="button" class="btn btn-sm btn-danger show_confirm"  data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
title="{{ __('Delete') }}">
    <i class="ti ti-trash"></i>
</button>
{!! Form::close() !!}
@endpermission
</span>