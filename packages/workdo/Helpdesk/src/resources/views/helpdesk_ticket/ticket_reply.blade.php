@extends('layouts.app')

@section('page-title')
    {{ __('Reply Ticket') }} - {{ $ticket->ticket_id }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Tickets Reply') }}</li>
@endsection
@push('css')
    <link href="{{ asset('assets/js/plugins/summernote-0.8.18-dist/summernote-lite.min.css') }}" rel="stylesheet">
@endpush

@section('content')
    <div class="row mt-3">
        <div class="col-lg-12 col-md-12 col-sm-12 col-xs-12">

            <div class="card">
                <div class="card-header">
                    <div class="d-flex justify-content-between">
                        <h6>
                            <span class="text-left">
                                {{ $ticket->name }} <small>({{ $ticket->created_at->diffForHumans() }})</small>
                                <span class="d-block"><small>{{ $ticket->email }}</small></span>
                            </span>
                        </h6>
                        <small>
                            <span class="text-right"> {{ __('Status') }} :
                                <span class="badge rounded p-2 mb-1"
                                    style="background: {{ isset($ticket->getTicketStatus->status_color) ? $ticket->getTicketStatus->status_color : '' }};">
                                    {{ __(isset($ticket->getTicketStatus->status_name) ? $ticket->getTicketStatus->status_name : '') }}
                                </span>
                            </span>
                            <span class="d-block">
                                {{ __('Category') }} :
                                <span class="badge rounded p-2"
                                    style="background: {{ isset($ticket->getTicketCategory->color) ? $ticket->getTicketCategory->color : '' }};">
                                    {{ isset($ticket->getTicketCategory->name) ? $ticket->getTicketCategory->name : '' }}
                                </span>
                            </span>
                        </small>
                    </div>
                </div>
                <div class="card-body">
                    <div>
                        <p>{!! $ticket->description !!}</p>
                    </div>
                    @if (!empty($ticket->attachments))
                        @php $attachments = json_decode($ticket->attachments); @endphp
                        @if (count($attachments))
                            <div class="m-1">
                                <h6>{{ __('Attachments') }} :</h6>
                                <ul class="list-group list-group-flush">
                                    @foreach ($attachments as $index => $attachment)
                                        <li class="list-group-item px-0">
                                            {{ $attachment->name }}
                                            <a download="" href="{{ get_file($attachment->path) }}"
                                                class="edit-icon py-1 ml-2" title="{{ __('Download') }}"><i
                                                    class="fas fa-download ms-2"></i></a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endif
                </div>
            </div>

            @foreach ($ticket->conversions as $conversion)
                <div class="card">
                    <div class="card-header">
                        <h6>{{ $conversion->replyBy()->name }}
                            <small>({{ $conversion->created_at->diffForHumans() }})</small>
                        </h6>
                    </div>
                    <div class="card-body">
                        <div>{!! $conversion->description !!}</div>
                        @php $attachments = json_decode($conversion->attachments); @endphp
                        @if (count($attachments))
                            <div class="m-1">
                                <h6>{{ __('Attachments') }} :</h6>
                                <ul class="list-group list-group-flush">
                                    @foreach ($attachments as $index => $attachment)
                                        <li class="list-group-item px-0">
                                            {{ $attachment->name }}<a download=""
                                                href="{{ get_file($attachment->path) }}" class="edit-icon py-1 ml-2"
                                                title="{{ __('Download') }}"><i class="fa fa-download ms-2"></i></a>
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
                @permission('Reply Helpdesk Ticket')
                    <div class="col-lg-6 col-md-12 col-sm-12 col-xs-12">
                        <div class="card">
                            <div class="card-header">
                                <h6>{{ __('Add Reply') }}
                                </h6>
                            </div>
                            <form method="post" id="SummernoteForm"
                                action="{{ route('helpdesk-ticket.conversion.store', $ticket->id) }}"
                                enctype="multipart/form-data">
                                @csrf
                                <div class="card-body">
                                    <div class="form-group">
                                        <label class="require form-label">{{ __('Description') }}</label>
                                        <textarea name="reply_description" placeholder="{{ __('Enter Description') }}" class="form-control summernote" id="reply_description"></textarea>
                                        <div class="invalid-feedback d-block ">
                                            {{ $errors->first('reply_description') }}
                                        </div>
                                    </div>

                                    <p class="text-danger d-none" id="skill_validation">
                                        {{ __('Description field is required.') }}
                                    </p>
                                    <div class="form-group file-group">
                                        <label class="require form-label">{{ __('Attachments') }}</label>
                                        <label
                                            class="form-label"><small>({{ __('You can select multiple files') }})</small></label>
                                        <div class="choose-file">
                                            <label for="file" class="form-label d-block">
                                                <input type="file" name="reply_attachments[]" id="file"
                                                    class="form-control mb-2 {{ $errors->has('reply_attachments') ? ' is-invalid' : '' }}"
                                                    multiple="" data-filename="multiple_reply_file_selection"
                                                    onchange="document.getElementById('blah').src = window.URL.createObjectURL(this.files[0])">

                                                <div class="invalid-feedback">
                                                    {{ $errors->first('reply_attachments.*') }}
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                    <p class="multiple_reply_file_selection"></p>
                                    <div class="text-end">
                                        <button class="btn btn-primary btn-badge btn-block btn-submit" type="submit"
                                            id="save">{{ __('Submit') }}</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="col-lg-6 col-md-12 col-sm-12 col-xs-12">
                        <div class="card">
                            <div class="card-header">
                                <h6>{{ __('Note') }}
                                </h6>
                            </div>
                            <form method="post" id="notesform"
                                action="{{ route('helpdesk-ticket.note.store', $ticket->id) }}">
                                @csrf
                                <div class="card-body adjust_card_width">
                                    <div class="form-group ckfix_height">
                                        <textarea name="note" placeholder="{{ __('Enter Note') }}" class="form-control summernote" id="note">{{ $ticket->note }}</textarea>

                                        <div class="invalid-feedback">
                                            {{ $errors->first('note') }}
                                        </div>
                                        <p class="text-danger d-none" id="note_validation">
                                            {{ __('Note field is required.') }}
                                        </p>
                                    </div>

                                    <div class="text-end">
                                        <button class="btn btn-primary btn-block btn-badge btn-submit"
                                            type="submit">{{ __('Add Note') }}</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                @endpermission
            </div>
        </div>
    </div>
@endsection
@push('scripts')
    <script src="{{ asset('assets/js/plugins/summernote-0.8.18-dist/summernote-lite.min.js') }}"></script>
    <script>
        $("#SummernoteForm").submit(function(e) {
            var desc = $("#reply_description").val();
            if (!isNaN(desc)) {
                $('#skill_validation').removeClass('d-none')
                event.preventDefault();
            } else {
                $('#skill_validation').addClass('d-none')
            }
        });
    </script>
    <script>
        $("#notesform").submit(function(e) {
            var desc = $("#notesform .summernote").val();
            if (!isNaN(desc)) {
                $('#note_validation').removeClass('d-none')
                event.preventDefault();
            } else {
                $('#note_validation').addClass('d-none')
            }

        });
    </script>
@endpush
@push('css')
    <style>
        .attachment_list li {
            list-style: none;
            display: inline;
        }
    </style>
@endpush
