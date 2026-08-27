@extends('layouts.app')
@section('page-title')
    {{ __('EMail Box') }}
@endsection
@section('page-breadcrumb')
    {{ __('EMail Box') }}
@endsection

@php
    $company_settings = getAdminAllSetting();
@endphp
@push('css')
    <style type="text/css">
        .col-md-6 {
            width: 100% !important;
        }
    </style>
    @if ((isset($company_settings['site_rtl']) ? $company_settings['site_rtl'] : 'off') == 'on')
        <link rel="stylesheet" href="{{ asset('packages/workdo/MailBox/src/Resources/assets/css/custom-rtl.css') }}">
    @else
        <link rel="stylesheet" href="{{ asset('packages/workdo/MailBox/src/Resources/assets/css/custom.css') }}">
    @endif
@endpush
@php
    $flag_arr = $message->getFlags();

@endphp
@section('content')
    <!-- [ Main Content ] start -->
    <div class="row">
        <!-- [ sample-page ] start -->
        <div class="col-sm-12">
            <div class="row">
                @include('email-box::layouts.sidebar')
                <div class="col-xl-9">
                    <div id="mail-inbox" class="">
                        <div class="card">
                            <div class="card-header pb-0">
                                <div class="row ">
                                    <div class="col-md-8 col-sm-9 col-12">
                                        <h4 class=" mb-0">
                                            {{ !empty($message->getFrom()[0]->personal) ? $message->getFrom()[0]->personal : $message->getFrom()[0]->mailbox }}
                                        </h4>
                                        <p class=""><span>{{ __('To') }} :
                                            </span>{{ !empty($message->getTo()[0]->personal) ? $message->getTo()[0]->personal : $message->getTo()[0]->mailbox }}
                                        </p>
                                    </div>
                                    <div class="col-md-4  col-sm-3 col-12 text-custom-end">
                                        <p class="mb-0"><span
                                                class="text-mute">{{ date('D, M d,  Y g:i A',  strtotime($message->getDate())) }}
                                            </span></p>
                                        <div class="btn-group">
                                            <button type="button" data-bs-toggle="tooltip" title=""
                                                class="btn btn-light btn-sm action-all-btn action-star mt-0"
                                                data-action="@if ($flag_arr->has('flagged') || $flag_arr->has('Flagged')) unstarred @else starred @endif"
                                                data-id="{{ $message->getMsgn() }}"><i
                                                    class="@if ($flag_arr->has('flagged') || $flag_arr->has('Flagged')) ti ti-star text-warning @else ti ti-star text-secondary @endif"
                                                    data-bs-toggle="tooltip" title=""
                                                    data-original-title="@if ($flag_arr->has('flagged') || $flag_arr->has('Flagged')) {{ __('Starred') }} @else {{ __('Not Starred') }} @endif"
                                                    data-bs-original-title="@if ($flag_arr->has('flagged') || $flag_arr->has('Flagged')) {{ __('Starred') }} @else {{ __('Not Starred') }} @endif"></i>
                                            </button>
                                            @if (request()->segment(count(request()->segments())) == 'inbox' ||
                                                    request()->segment(count(request()->segments())) == 'starred' ||
                                                    request()->segment(count(request()->segments())) == 'sent')
                                                @permission('Emailbox mail reply')
                                                    <a type="button" data-bs-toggle="tooltip" title=""
                                                        data-original-title="{{ __('Reply') }}"
                                                        data-bs-original-title="{{ __('Reply') }}"
                                                        href="{{ route('emailbox.mail.reply', [$message->getMsgn(), request()->segment(count(request()->segments()))]) }}"
                                                        class="btn btn-light btn-sm action-all-btn mt-0" data-action="unseen">
                                                        <i class="ti ti-arrow-back text-info"></i>
                                                    </a>
                                                @endpermission
                                            @elseif(request()->segment(count(request()->segments())) == 'drafts')
                                                @permission('Emailbox mail sent')
                                                    <a type="button" data-bs-toggle="tooltip" title=""
                                                        data-original-title="{{ __('Sent') }}"
                                                        data-bs-original-title="{{ __('Sent') }}"
                                                        href="{{ route('emailbox.create', [$message->getMsgn(), request()->segment(count(request()->segments()))]) }}"
                                                        class="btn btn-light btn-sm action-all-btn mt-0" data-action="">
                                                        <i class="ti ti-send text-info"></i>
                                                    </a>
                                                @endpermission
                                            @elseif(request()->segment(count(request()->segments())) == 'spam' ||
                                                    request()->segment(count(request()->segments())) == 'trash' ||
                                                    request()->segment(count(request()->segments())) == 'archive')
                                                @permission('Emailbox mail move')
                                                    <a type="button" data-bs-toggle="tooltip" title=""
                                                        data-id="{{ $message->getMsgn() }}"
                                                        data-original-title="{{ __('Move to inbox') }}"
                                                        class="btn btn-light btn-sm action-all-btn mt-0 move-to-inbox"
                                                        data-bs-original-title="{{ __('Move to inbox') }}"
                                                        data-action="{{ request()->segment(count(request()->segments())) }}">
                                                        <i class="ti ti-inbox text-info"></i>
                                                    </a>
                                                @endpermission
                                            @endif
                                        </div>
                                    </div>

                                </div>
                            </div>
                            <div class="card-body" style=" min-height: 396px;">
                                <div class="col-lg-12  mb-4">
                                    <p>{{ __('Subject') }} : <b>{{ $message->getSubject() }} </b></p>
                                    @if (empty($message->getHTMLBody(true)))
                                        {{ $message->getTextBody() }}
                                    @else
                                        {!! $message->getHTMLBody(true) !!}
                                    @endif
                                </div>
                                @if ($message->hasAttachments() == 1)
                                    <hr>
                                    @php
                                        $attachments = $message->getAttachments();

                                    @endphp
                                    <div class="row">
                                        <div class="col-md-12 text-center">
                                            <h5 class="mb-4">{{ __('Attachments') }}</h5>
                                        </div>
                                        @foreach ($attachments as $attachment)
                                            <div class="col-lg-2 col-md-4 col-12 text-center mail_attachment_div">
                                                @if (strpos($attachment->getMimeType(), 'image') !== false)
                                                    <img src="data:{{ $attachment->getContentType() }};base64,{{ base64_encode($attachment->getContent()) }}"
                                                        alt="{{ $attachment->getFilename() }}"
                                                        class="mail_attachment m-auto" />
                                                @else
                                                    <div style="position: relative; text-align: center;">
                                                        <img src="https://static.thenounproject.com/png/643663-200.png"
                                                            alt="{{ $attachment->getFilename() }}"
                                                            class="mail_attachment m-auto" />
                                                        <h4 class="text-primary attchament-text">
                                                            {{ strtoupper(pathinfo($attachment->getFilename(), PATHINFO_EXTENSION)) }}
                                                        </h4>
                                                    </div>
                                                @endif
                                                <div class="img-overlay">
                                                    <p>{{ $attachment->getFilename() }}</p>
                                                    @if (strpos($attachment->getMimeType(), 'image') !== false)
                                                        <div class="download-buttons">
                                                            <a href="data:{{ $attachment->getContentType() }};base64,{{ base64_encode($attachment->getContent()) }}"
                                                                download="" class="btn btn-primary">
                                                                <i class="ti ti-download"></i>
                                                            </a>
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@push('scripts')
    <script type="text/javascript">
        $(".action-star").on('click', function() {
            var currentUrl = window.location.href;
            var urlSegments = currentUrl.split('/');
            var lastElement = urlSegments[urlSegments.length - 1];
            var checkedValues = [];
            var token = $('meta[name="csrf-token"]').attr('content');
            checkedValues.push($(this).attr('data-id'));
            var temp = $(this);
            var action = $(this).attr('data-action');
            action = action.trim();
            $.ajax({
                type: 'post',
                url: '{{ url('/mailbox/action') }}',
                dataType: 'json',
                data: {
                    '_token': '{{ csrf_token() }}',
                    'id': checkedValues,
                    'action': action,
                    'folder': lastElement
                },
                beforeSend: function() {
                    $(".loader-wrapper").removeClass('d-none');
                },
                success: function(data) {
                    $(".loader-wrapper").addClass('d-none');
                    if (data.status == 1) {

                        if (action == 'starred') {

                            var html = '<i class="ti ti-star text-warning"></i>';
                            temp.html(html);
                            temp.attr('data-action', 'unstarred');
                            show_toastr('success', data.msg, 'success');

                        } else if (action == 'seen') {
                            var html = '<i class="ti ti-mail text-info"></i>';
                            temp.html(html);
                            temp.attr('data-action', 'unseen');
                            show_toastr('success', data.msg, 'success');
                        } else {}
                    } else if (data.status == 0) {

                        if (action == 'unstarred') {
                            var html = '<i class="ti ti-star text-secondary"></i>';
                            temp.html(html);
                            temp.attr('data-action', 'starred');
                            show_toastr('error', data.msg, 'error');
                        } else if (action == 'unseen') {

                            var html = '<i class="ti ti-mail-opened text-secondary"></i>';
                            temp.html(html);
                            temp.attr('data-action', 'seen');
                            show_toastr('error', data.msg, 'error');
                        } else {}
                    } else {
                        show_toastr('error', data.msg, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    $(".loader-wrapper").addClass('d-none');
                    show_toastr('error', '{{ __('Something went wrong please try again') }}', 'error');
                }
            });
        });
        $(".move-to-inbox").on('click', function() {
            var temp = $(this);
            var folder = $(this).attr('data-action');
            var id = $(this).attr('data-id');
            $.ajax({
                type: 'post',
                url: '{{ url('/mailbox/mail/move') }}',
                dataType: 'json',
                data: {
                    '_token': '{{ csrf_token() }}',
                    'id': id,
                    'folder': folder
                },
                beforeSend: function() {
                    $(".loader-wrapper").removeClass('d-none');
                },
                success: function(data) {
                    $(".loader-wrapper").addClass('d-none');
                    if (data.status == 1) {
                        show_toastr('success', data.msg, 'success');
                        // Redirect to emailbox.index with the specified type
                        var redirectUrl = '{{ route('emailbox.index', ['type' => ':type']) }}';
                        redirectUrl = redirectUrl.replace(':type', data.folder);
                        window.location.href = redirectUrl;

                    } else {
                        show_toastr('error', data.msg, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    $(".loader-wrapper").addClass('d-none');
                    show_toastr('error', '{{ __('Something went wrong please try again') }}', 'error');
                }
            });
        });
    </script>
@endpush
