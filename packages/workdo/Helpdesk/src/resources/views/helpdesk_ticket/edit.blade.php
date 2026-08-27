@extends('layouts.app')
@section('page-title', __('Helpdesk'))
@section('breadcrumb')
    <li class="breadcrumb-item">{{ $ticket->ticket_id }}</li>
@endsection
@push('css')
    <link href="{{ asset('assets/js/plugins/summernote-0.8.18-dist/summernote-lite.min.css') }}" rel="stylesheet">
@endpush

@section('content')
    <form action="{{ route('helpdesk.ticket.update', ['ticketId' => encrypt($ticket->ticket_id)]) }}" class="mt-3"
        method="post" enctype="multipart/form-data">
        @csrf
        <div class="row">
            <div class="col-md-12 col-xs-12">
                <div class="card">
                    <div class="card-body">
                        @if (Auth::user()->hasRole('super admin'))
                            <div class="row">
                                @php
                                    $name = 'Customers';
                                @endphp
                                <div class="form-group col-md-6" id="company_id">
                                    <label class="require form-label">{{ $name }}</label>
                                    <select class="form-control select_person_email" name="company_id">
                                        <option value="">{{ __('Select User') }}</option>
                                        @foreach ($company as $key => $value)
                                            <option value="{{ $key }}"
                                                {{ $ticket->user_id == $key ? 'selected' : '' }}>
                                                {{ $value }}
                                            </option>
                                        @endforeach
                                    </select>


                                    <div class="validation-error text-danger">
                                        @error('company_id')
                                            {{ $message }}
                                        @enderror
                                    </div>
                                </div>
                                <div class="form-group col-md-6">
                                    <label class="require form-label">{{ __('Email') }}</label>
                                    <input class="form-control emailAddressField" type="email" name="email"
                                        placeholder="{{ __('Enter Email') }}" readonly value="{{ $ticket->email }}">
                                    <div class="validation-error text-danger">
                                        @error('email')
                                            {{ $message }}
                                        @enderror
                                    </div>
                                </div>
                            </div>
                        @endif

                        <div class="row">
                            <div class="form-group col-md-6">
                                <label class="require form-label">{{ __('Category') }}</label>
                                <select class="form-control" name="category" id="category">
                                    <option value="">{{ __('Select Category') }}</option>
                                    @foreach ($helpdeskCategory as $category)
                                        <option value="{{ $category->id }}"
                                            {{ $ticket->category_id == $category->id ? 'selected' : '' }}>
                                            {{ $category->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="validation-error text-danger">
                                    @error('category')
                                        {{ $message }}
                                    @enderror
                                </div>
                            </div>
                            @if (auth()->user() && auth()->user()->type == 'super admin')
                                <div class="form-group col-md-6">
                                    <label class="require form-label">{{ __('Status') }}</label>
                                    <select class="form-control" name="status" id="status">
                                        <option value="">{{ __('Select Status') }}</option>
                                        @foreach ($helpdeskStatus as $status)
                                            <option value="{{ $status->id }}"
                                                {{ $ticket->status_id == $status->id ? 'selected' : '' }}>
                                                {{ $status->status_name }}</option>
                                        @endforeach
                                    </select>
                                    <div class="validation-error text-danger">
                                        @error('status')
                                            {{ $message }}
                                        @enderror
                                    </div>
                                </div>
                            @endif
                            <div class="form-group col-md-6">
                                <label class="require form-label">{{ __('Subject') }}</label>
                                <input class="form-control" type="text" name="subject" placeholder="{{ __('Enter Subject') }}"
                                    value="{{ $ticket->subject }}">
                                <div class="validation-error text-danger">
                                    @error('subject')
                                        {{ $message }}
                                    @enderror
                                </div>
                            </div>

                            <div class="form-group col-md-6">
                                <label class="require form-label">{{ __('Attachments') }}
                                    <small>({{ __('You can select multiple files') }})</small> </label>
                                <div class="choose-file form-group">
                                    <label for="file" class="form-label d-block">

                                        <input type="file" name="attachments[]" id="file" class="form-control mb-2"
                                            multiple="" data-filename="multiple_file_selection"
                                            onchange="document.getElementById('blah').src = window.URL.createObjectURL(this.files[0])">
                                        <img src="" id="blah" width="20%" />
                                    </label>
                                </div>
                                <p class="multiple_file_selection mx-4"></p>
                            </div>

                            <div class="form-group col-md-12" style="margin-top: -15px !important;">
                                <label class="require form-label">{{ __('Description') }}</label>
                                <textarea name="description" placeholder="{{ __('Enter Description') }}" class="form-control summernote" id="help-desc">{{ $ticket->description }}</textarea>
                                <div class="validation-error text-danger">
                                    @error('description')
                                        {{ $message }}
                                    @enderror
                                </div>
                            </div>

                        </div>
                        <div class="d-flex justify-content-end text-end">
                            <a class="btn btn-secondary btn-badge btn-submit"
                                href="{{ route('helpdesk.ticket.index') }}">{{ __('Cancel') }}</a>
                            <button class="btn btn-primary btn-badge btn-submit ms-2" type="submit">{{ __('Submit') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection
@push('scripts')
    <script src="{{ asset('assets/js/plugins/summernote-0.8.18-dist/summernote-lite.min.js') }}"></script>
    <script>
        $(document).on('change', '.select_person_email', function() {
            var userId = $(this).val();
            $.ajax({
                url: '{{ route('helpdesk-tickets.getuser') }}',
                type: 'POST',
                data: {
                    "user_id": userId,
                    "_token": "{{ csrf_token() }}",
                },
                success: function(data) {
                    $('#loader').fadeOut();
                    if (data.email) {
                        $('.emailAddressField').val(data.email);
                        $('.emailAddressField').prop('readonly', true);
                        $('.emailAddressField').css('background-color', '#e9ecef');
                    } else {
                        $('.emailAddressField').val('');
                        $('.emailAddressField').prop('readonly', false);
                        $('.emailAddressField').css('background-color', '');
                    }
                }
            });
        });
    </script>
@endpush
