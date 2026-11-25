@extends('layouts.app')

@section('page-title', __('Reply Ticket'))


@section('action-button')
    <div class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
        <a href="#" onclick="saveAsPDF();" id="download-buttons" class="btn btn-sm btn-primary btn-icon d-flex align-items-center"
            data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Print')}}" aria-label="{{ __('Print') }}">
            <i class="ti ti-printer" style="font-size:20px"></i>
        </a>
        @php
            $btn_class = 'btn-info';
            if($ticket->status == 'open') {
                $btn_class = 'btn-info';
            } else {
                $btn_class = 'btn-success';
            }
        @endphp
        @php
            /* ONE CONDITION, NOT THREE THAT DISAGREED. The button's `dropdown-toggle` class, its
             * `data-bs-toggle` attribute and the menu's presence were each decided separately, and
             * the three lists did not match -- measured on this file:
             *
             *   class       in_array($status, ['open', 'In Progress', 'solved'])   lowercase 's'
             *   attribute   in_array($status, ['Solved'])                          capital 'S'
             *   the menu    in_array($status, ['open', 'In Progress', 'solved'])   lowercase 's'
             *
             * `SupportTicket::ticket_status_change()` writes 'Solved' with a capital S, so the
             * lowercase 'solved' in two of those lists MATCHES NOTHING THIS APPLICATION WRITES. For
             * a solved ticket the menu was therefore absent while the button still carried a
             * hard-coded `data-bs-toggle="dropdown"` on the next line (which also meant the
             * attribute appeared TWICE in that one state) plus `aria-haspopup="true"` and
             * `aria-expanded="true"` -- a control that announces itself to a screen reader as an
             * already-open menu, with no menu anywhere on the page.
             *
             * The question is not "which status is this" but "is there anywhere to go from here",
             * so it is asked once, from the transitions the screen itself offers below. The
             * transitions are UNCHANGED: open -> In Progress or Solved, In Progress -> Solved,
             * Solved -> nowhere. Which transitions exist is product behaviour and is not this fix's
             * to alter.
             *
             * `aria-expanded` is now `false`, which is what a closed menu is; Bootstrap flips it on
             * open. It was hard-coded `true` in every state, so the announcement was wrong even
             * when the menu did exist. */
            $ssTicketTransitions = [
                'open' => ['In Progress', 'Solved'],
                'In Progress' => ['Solved'],
            ];
            $ssTicketNext = $ssTicketTransitions[$ticket->status] ?? [];
        @endphp
        <div class="btn-group mx-1" id="deliver_btn">
            <button class="btn {{ $btn_class }} {{ $ssTicketNext !== [] ? 'dropdown-toggle' : '' }} order_status_btn" type="button"
                    @if($ssTicketNext !== []) data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" @endif
                    >{{ __('Status') }} : {{ $ticket->status }}
            </button>
            @if($ssTicketNext !== [])
                <div class="dropdown-menu" data-popper-placement="bottom-start">
                    <h6 class="dropdown-header">{{ __('Set Ticket status') }}</h6>
                    @foreach($ssTicketNext as $ssNextStatus)
                        <a class="dropdown-item ticket_status" href="#" data-value="{{ $ssNextStatus }}">
                            <i class="ti ti-check text-success"></i> {{ __($ssNextStatus) }}
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection

@section('breadcrumb')
    <li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('support_ticket.index') }}">{{ __('Support Ticket') }}</a></li>
    <li aria-current="page" class="ss-breadcrumb__item"> {{ __('Reply Ticket') }} - {{ $ticket->ticket_id }}</li>
@endsection
@php
    $logo = get_file('/');
@endphp
@section('content')
    <div class="row mt-3">
        <div class="col-lg-12 col-md-12 col-sm-12 col-xs-12" id="printableArea">
            <div class="card">
                <div class="card-header">
                    <div class="d-flex justify-content-between">
                        <h6>
                            <span class="text-left">
                                {{-- `$ticket->UserData` IS NULL FOR A TICKET WHOSE CUSTOMER IS GONE, and
                                     `->name` on null is an HTTP 500 on the merchant's own screen --
                                     measured by rendering this view for a ticket with no
                                     `customer_id`. `customers` is soft-deleted AND store-scoped, so
                                     the relation answers null both for a deleted shopper and for one
                                     the current scope cannot see. `Unknown` is an existing key in
                                     both language files; no new string is introduced. --}}
                                {{ $ticket->UserData->name ?? __('Unknown') }} <small>({{ $ticket->created_at ? $ticket->created_at->diffForHumans() : '-'}})</small>
                                <span class="d-block"><small></small></span>
                            </span>
                        </h6>
                        <span class="text-right">
                            {{ __('Status') }} : <span class="badge rounded p-2 @if($ticket->status == 'New Ticket') bg-secondary @elseif($ticket->status == 'In Progress')bg-info  @elseif($ticket->status == 'On Hold') bg-warning @elseif($ticket->status == 'Closed') bg-primary @else bg-success @endif">{{ __($ticket->status) }}</span>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div>
                        <span><b>{{ $ticket->title }}</b></span>
                        <p>{!! \App\Support\Html\RichText::clean($ticket->description) !!}</p>
                    </div>
                    @php $attachments = json_decode($ticket->attachment); @endphp

                        @if (!empty($attachments) && count($attachments))
                            <div class="m-1">
                                <h6>{{ __('Attachments') }} :</h6>

                                <ul class="list-group list-group-flush">

                                    @foreach ($attachments as $index => $attachment)
                                        <li class="list-group-item px-0">
                                            {{ $attachment }} <a download="" href="{{ get_file($attachment) }}" class="edit-icon py-1 ml-2" title="{{ __('Download') }}"><i class="ti ti-download ms-2"></i></a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                </div>
            </div>

            @foreach ($ticket->conversions as $conversion)
                <div class="card">
                    <div class="card-header">
                        {{-- `SupportConversion::replyBy()` ends in `->first()`, so it returns NULL
                             when the sender's row is gone -- and it is called once per reply, so a
                             single orphaned reply made the whole conversation a 500 rather than
                             hiding one name. Null-safe rather than re-shaped: turning it into a real
                             relation is a wider change than this screen's repair, and it is recorded
                             in the evidence document as the N+1 it also is. --}}
                        <h6>{{ $conversion->replyBy()?->name ?? __('Unknown') }}
                            <small>({{-- GUARDED LIKE THE TICKET'S OWN, which already was. `support_conversions.created_at`
                             is nullable, so an imported or legacy reply made the whole conversation a 500 --
                             the same shape as the two name reads above, one line apart from them. --}}
                        {{ $conversion->created_at ? $conversion->created_at->diffForHumans() : '-' }})</small>
                        </h6>
                    </div>
                    <div class="card-body">
                        <div>{!! \App\Support\Html\RichText::clean($conversion->description) !!}</div>
                        @php $attachments = json_decode($conversion->attachments); @endphp
                        @if (!empty($attachments) && count($attachments))
                            <div class="m-1">
                                <h6>{{ __('Attachments') }} :</h6>
                                <ul class="list-group list-group-flush">
                                    @foreach ($attachments as $index => $attachment)
                                    <li class="list-group-item px-0">
                                        {{ $attachment }}<a download="" href="{{ get_file($attachment) }}" class="edit-icon py-1 ml-2" title="{{ __('Download') }}"><i class="ti ti-download ms-2"></i></a>
                                    </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        <div class="col-lg-12 col-md-12 col-sm-12 col-xs-12">
            <div class="row">
                <div class="col-lg-12 col-md-12 col-sm-12 col-xs-12">
                    @if($ticket->status != 'Closed')
                    <div class="card">
                        <div class="card-header row">
                            <div class="col-md-4">
                                <h6>{{ __('Add Reply') }}</h6>
                            </div>
                        </div>

                        <form method="post" action="{{ route('conversion.store', $ticket->id) }}"
                            enctype="multipart/form-data">
                            @csrf
                            <div class="card-body">
                                <div class="form-group col-md-7">
                                    {!! Form::label('description', __('Description'), ['class' => 'form-label']) !!}
                                    {!! Form::textarea('reply_description', old('reply_description'), ['class' => 'form-control  summernote-simple-product', 'rows' => 1, 'id' => 'description']) !!}
                                </div>
                                <div class="form-group file-group mb-5 col-md-7">
                                    <label class="require form-label">{{ __('Attachments') }}</label>
                                    <label class="form-label"><small>({{ __('You can select multiple files') }})</small></label>
                                    <div class="choose-file form-group">
                                        <label for="file" class="form-label d-block">
                                            <div>{{ __('Choose File Here') }}</div>

                                            <input type="file" name="reply_attachments[]" id="file" class="form-control mb-2 {{ $errors->has('reply_attachments') ? ' is-invalid' : '' }}" multiple=""  data-filename="multiple_reply_file_selection" onchange="document.getElementById('blah').src = window.URL.createObjectURL(this.files[0])">
                                            <img src="" id="blah" width="20%"/>
                                            <div class="invalid-feedback">
                                                {{ $errors->first('reply_attachments.*') }}
                                            </div>
                                        </label>
                                    </div>
                                </div>
                                <p class="multiple_reply_file_selection"></p>
                                <div class="text-end">
                                    <button class="btn btn-primary btn-block mt-2 btn-submit btn-badge" type="submit">{{ __('Submit') }}</button>
                                </div>
                            </div>

                        </form>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@push('custom-script')
    <script src="{{ asset('js/html2pdf.bundle.min.js') }}{{ '?' . time() }}"></script>
    <script>
        var filename ='{{$ticket->ticket_id}}';
        function saveAsPDF() {
            var element = document.getElementById('printableArea');
            var opt = {
                margin: 0.3,
                filename: filename,
                image: {
                    type: 'jpeg',
                    quality: 1
                },
                html2canvas: {
                    scale: 4,
                    dpi: 72,
                    letterRendering: true
                },
                jsPDF: {
                    unit: 'in',
                    format: 'A2'
                }
            };
            html2pdf().set(opt).from(element).save();

        }

    </script>
    <script>

        $(document).on('click', '.ticket_status', function() {
            var status = $(this).attr('data-value');
            var data = {
                status: status,
                // THE HANDLER IGNORES THIS. It reads the route parameter, which is the id in the
                // url below; a posted `id` used to beat the url and that was the defect. Kept
                // rather than deleted because `TicketStatusChangeDoorTest` posts a DIFFERENT id
                // here deliberately, to prove the route wins.
                id: "{{ $ticket->id }}",
            };

            $.ajax({
                url: '{{ route('support_ticket.status.change', $ticket->id) }}',
                method: 'POST',
                data: data,
                context: this,
                /* THE SUCCESS TOAST WAS IN `complete:`, WHICH FIRES ON SUCCESS *AND* ON FAILURE.
                   So every refusal produced TWO toasts -- the error from `success:` and then
                   "Ticket status updated successfully" from `complete:`, in that order, the
                   reassuring one last. An HTTP failure (403, 422, 500) skips `success:` entirely
                   and produced ONLY the success toast, so the merchant was told the status had
                   changed when nothing had been written. Same class as ORD-12, where the order
                   screen announced status changes that had not happened.

                   The toast now follows the response: success on a success payload, the handler's
                   own message on a refusal, and a stated failure when the request itself fails.
                   `complete:` keeps only the loader, which genuinely must hide either way. */
                success: function(data) {
                    $('#loader').fadeOut();
                    if (!data || data.status !== 'success') {
                        show_toastr('{{ __('Error') }}',
                            (data && data.message) ? data.message : '{{ __('The ticket status could not be updated.') }}',
                            'error');
                        return;
                    }
                    $('.order_status_btn').text('{{ __('Status') }} : ' + data.ticket_status);
                    show_toastr('{{ __('Success') }}', '{{ __('Ticket status updated successfully') }}', 'success');
                },
                error: function() {
                    $('#loader').fadeOut();
                    show_toastr('{{ __('Error') }}', '{{ __('The ticket status could not be updated.') }}', 'error');
                },
                complete: function() {
                    $('#loader').fadeOut();
                }
            });
        });
    </script>

@endpush
