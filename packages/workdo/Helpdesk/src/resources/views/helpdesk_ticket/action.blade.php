<div class="d-flex align-items-center gap-1 justify-content-end">
    @permission('Reply Helpdesk Ticket')
        <a href="{{ route('helpdesk.ticket.reply', ['ticketId' => encrypt($ticket->ticket_id)]) }}" class="btn btn-sm btn-badge btn-primary" data-bs-toggle="tooltip" title="{{ __('Reply') }}">
            <span><i class="ti ti-corner-up-left"></i></span>
        </a>
        <a href="{{ route('helpdesk.ticket.view', ['ticketId' => encrypt($ticket->ticket_id)]) }}" class="btn btn-sm btn-badge btn-warning" data-bs-toggle="tooltip" title="{{ __('Details') }}" target="_blank">
            <span><i class="ti ti-eye"></i></span>
        </a>
    @endpermission

    @permission('Edit Helpdesk Ticket')
        <a href="{{ route('helpdesk.ticket.edit', ['ticketId' => encrypt($ticket->ticket_id)]) }}" class="btn btn-sm btn-badge btn-info" data-bs-toggle="tooltip" title="{{ __('Edit') }}">
            <span><i class="ti ti-pencil"></i></span>
        </a>
    @endpermission

    @permission('Delete Helpdesk Ticket')
        @if (Auth::user()->id == $ticket->created_by || Auth::user()->type == 'super admin')
            <form method="POST" action="{{ route('helpdesk.ticket.delete', ['ticketId' => $ticket->id]) }}" id="user-form-{{ $ticket->id }}">
                @csrf
                @method('DELETE')
                <input name="_method" type="hidden" value="DELETE">
                <button type="button" class="btn btn-sm btn-badge btn-danger show_confirm" data-bs-toggle="tooltip" title="{{ __('Delete')}}">
                    <span><i class="ti ti-trash"></i></span>
                </button>
            </form>
        @endif
    @endpermission
</div>
