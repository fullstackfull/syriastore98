@extends('layouts.app')

@section('page-title')
    {{ __('Email Box') }}
@endsection

@section('page-breadcrumb')
    {{ __('Email Box') }}
@endsection

@push('css')
    <style>
        .btn.dropdown-toggle {
            border: none;
            background: transparent;
            box-shadow: none;
            padding: 0;
            width: 20px;
            height: 20px;
            right: 8px;
            top: 12px;
        }

        .ml-4 {
            margin-left: 1.5rem !important;
        }

        .dropdown-toggle::after {
            display: none;
        }

        .dropdown-menu-end {
            --bs-position: start;
        }
        [data-action] {
            display: block;
            margin: 10px auto;
            font-size: 17px;
            min-width: 3em;
            text-align: left;
            background: transparent;
            border: 0;
        }
        .card-body .row .col-md-12 .col-md-2 .action-btn {
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease !important;
        }

        .card-body .row .col-md-12 .col-md-2 .action-btn:hover {
            background-color: #35abb6 !important; /* Darker shade for hover */
            color: #ffffff !important;           /* White text */
            border-color: #32a1ab !important;    /* Slightly darker border */
        }

        .card-body .row .col-md-12 .col-md-2 .action-btn:hover i {
            color: #ffffff !important;           /* White icon */
        }

    
    </style>
@endpush

@section('content')
    <div class="row">
        <div class="col-sm-12">
            <div class="row">
                @include('email-box::layouts.sidebar')
                <div class="col-xl-9">
                    <div id="mail-inbox">
                        <div class="card">
                            <div class="card-header @if ($messages->count()) p-2 @endif">
                                <div class="toolbar">
                                    @if ($messages->count())
                                         <div class="btn-group text-end">
                                            @permission('Emailbox mail move')
                                                <button type="button" class="btn btn-light"
                                                    style="margin-top: 10px; margin-bottom: 10px;" data-bs-toggle="dropdown"
                                                    aria-haspopup="true" aria-expanded="true" data-action=""
                                                    data-bs-tooltip="tooltip" data-bs-placement="bottom"
                                                    title="{{ __('Move') }}">
                                                    <i class="ti ti-file-export text-secondary"></i>
                                                </button>
                                                <div class="dropdown-menu dropdown-menu-end "
                                                    data-popper-placement="bottom-end">
                                                    <a class="dropdown-item action-all-btn" data-action="move" data-id="INBOX">
                                                        <i class="ti ti-inbox"></i>
                                                        <span>{{ __('Inbox') }}</span>
                                                    </a>
                                                    <a class="dropdown-item action-all-btn" data-action="move" data-id="Sent">
                                                        <i class="ti ti-send"></i>
                                                        <span>{{ __('Sent') }}</span>
                                                    </a>
                                                    <a class="dropdown-item action-all-btn" data-action="move" data-id="Drafts">
                                                        <i class="ti ti-file"></i>
                                                        <span>{{ __('Draft') }}</span>
                                                    </a>
                                                    <a class="dropdown-item action-all-btn" data-action="move" data-id="spam">
                                                        <i class="ti ti-alert-octagon"></i>
                                                        <span>{{ __('Spam') }}</span>
                                                    </a>
                                                    <a class="dropdown-item action-all-btn" data-action="move" data-id="Trash">
                                                        <i class="ti ti-trash"></i>
                                                        <span>{{ __('Trash') }}</span>
                                                    </a>
                                                    <a class="dropdown-item action-all-btn" data-action="move"
                                                        data-id="Archive">
                                                        <i class="ti ti-archive"></i>
                                                        <span>{{ __('Archive') }}</span>
                                                    </a>
                                                </div>
                                            @endpermission
                                            <button type="button" data-bs-toggle="tooltip" title=""
                                                data-original-title="{{ __('Mark As Read') }}"
                                                data-bs-original-title="{{ __('Mark As Read') }}"
                                                class="btn btn-light action-all-btn" data-action="seen">
                                                <i class="ti ti-mail text-info"></i>
                                            </button>
                                            <button type="button" data-bs-toggle="tooltip" title=""
                                                data-original-title="{{ __('Mark As Unread') }}"
                                                data-bs-original-title="{{ __('Mark As Unread') }}"
                                                class="btn btn-light action-all-btn" data-action="unseen">
                                                <i class="ti ti-mail-opened"></i>
                                            </button>
                                            <button type="button" class="btn btn-light action-all-btn"
                                                data-bs-toggle="tooltip" title=""
                                                data-original-title="{{ __('Starred') }}"
                                                data-bs-original-title="{{ __('Starred') }}" data-action="starred">
                                                <i class="ti ti-star text-warning"></i>
                                            </button>
                                            <button type="button" class="btn btn-light action-all-btn"
                                                data-bs-toggle="tooltip" title=""
                                                data-bs-original-title="{{ __('Not Starred') }}" data-action="unstarred">
                                                <i class="ti ti-star"></i>
                                            </button>

                                        </div>
                                    @else
                                        <h5 class="card-title mb-4"></h5>
                                    @endif
                                </div>
                            </div>
                            <div class="card-body">
                                @if ($messages->count())
                                    <div class="row">
                                        @foreach ($messages as $message)
                                            @php
                                                $flags = $message->getFlags();
                                            @endphp
                                            <div class="col-md-12 pb-1">
                                                <div class="row align-items-center">
                                                <div class="col-md-1">
                                                        <div class="d-flex">
                                                            <input type="checkbox" name="mail_check"
                                                                class="form-check-input"
                                                                value="{{ $message->getMsgn() }}">

                                                            <a href="javascript:void(0)" class="action-btn action"
                                                                style="display: inline-block; margin: auto auto; min-width: 2em"
                                                                data-action="{{ $flags->has('flagged') ? 'unstarred' : 'starred' }}"
                                                                data-id="{{ $message->getMsgn() }}"
                                                                data-bs-toggle="tooltip" title=""
                                                                data-original-title="{{ $flags->has('flagged') ? __('Unstar') : __('Star') }}"
                                                                data-bs-original-title="@if ($flags->has('flagged') || $flags->has('Flagged')) {{ __('Starred') }} @else {{ __('Not Starred') }} @endif">


                                                                <i class="{{ $flags->has('flagged') ? 'ti ti-star text-warning' : 'ti ti-star text-secondary' }}"></i>
                                                            </a>

                                                        </div>
                                                    </div>
                                                   
                                                    <div class="col-md-2">
                                                        {{ $message->getFrom()[0]->mailbox }}
                                                    </div>
                                                    <div class="col-md-7">
                                                        <b>{{ $message->getSubject() }}</b>
                                                        <a href="{{ route('emailbox.show', [$message->getMsgn(), request()->segment(count(request()->segments()))]) }}">
                                                            {{ Str::limit($message->getTextBody(), 180, '...') }}
                                                        </a>
                                                    </div>

                                            <div class="col-md-2">
                                                    <div class="d-flex gap-2 align-items-center">
                                                        <!-- Show Button -->
                                                        <a href="{{ route('emailbox.show', [$message->getMsgn(), request()->segment(count(request()->segments()))]) }}"
                                                            class="btn btn-sm btn-warning align-items-center" data-bs-toggle="tooltip" title="{{ __('Show') }}">
                                                            <i class="ti ti-eye"></i>
                                                        </a>

                                                        <!-- Mark as Read/Unread Button -->
                                                        <a href="javascript:void(0)"
                                                        class="btn btn-sm align-items-center btn-info action-btn"
                                                       style="--bs-btn-bg: #e8f8ff ; background-color:#E8F8ff ; --bs-btn-color: #35abb6; --bs-btn-border-color: #e8f8ff;margin: 0;font-size: 14px;min-width: auto;"
                                                        data-action="seen"
                                                        data-id="{{ $message->getMsgn() }}"
                                                        data-bs-toggle="tooltip"
                                                        title="Mark as read">
                                                        <i class="ti ti-mail text-info"></i>
                                                        </a>





                                                        <!-- Delete Button -->
                                                        @permission('Emailbox mail delete')
                                                        {!! Form::open(['method' => 'DELETE', 'route' => ['emailbox.destroy', [$message->getMsgn(), request()->segment(count(request()->segments()))]], 'class' => 'd-inline']) !!}
                                                            <button type="button"
                                                                class="btn btn-sm btn-danger btn-badge show_confirm"
                                                                data-confirm="{{ __('Are You Sure?') }}"
                                                                data-text="{{ __('This action cannot be undone. Do you want to continue?') }}"
                                                                data-text-yes="{{ __('Yes') }}"
                                                                data-text-no="{{ __('No') }}" >
                                                                <i class="ti ti-trash text-white py-1" data-bs-toggle="tooltip" title="{{ __('Delete') }}"></i>
                                                            </button>
                                                        {!! Form::close() !!}
                                                        @endpermission
                                                       



                                                    </div>

                                                    </div>



                                                    
                                                </div>
                                                <hr>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <div class="text-center">
                                        <p class="p-4">{{ __('No data found') }}</p>
                                    </div>
                                @endif
                            </div>
                            <div class="card-footer">
                                {!! $messages->links('email-box::pagination.global-pagination') !!}
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
        $(".action-all-btn").on('click', function() {
            var currentURL = window.location.href;
            var urlParts = currentURL.split('/');
            var lastElement = urlParts[urlParts.length - 1];
            var checkedValues = [];
            $('input[name="mail_check"]:checked').each(function() {

                checkedValues.push($(this).val());
            });
            if(checkedValues.length < 1){
                show_toastr('error', 'Select Atleast 1 Mail!', 'error');
                return ;
            }
            var token = $('meta[name="csrf-token"]').attr('content');
            var id = checkedValues;
            var action = $(this).attr('data-action');
            action = action.trim();
            var moveToFolder = '';
            if (action == 'move') {
                moveToFolder = $(this).attr('data-id');
            }
            $.ajax({
                type: 'post',
                url: '{{ url('/emailbox/action') }}',
                dataType: 'json',
                data: {
                    '_token': '{{ csrf_token() }}',
                    'id': id,
                    'action': action,
                    'folder': lastElement,
                    'moveToFolder': moveToFolder
                },
                beforeSend: function() {
                    $('#loader').fadeIn();
                },
                success: function(data) {
                    $('#loader').fadeOut();
                    if (data.status == 1) {

                        location.reload(true);
                        show_toastr('Success', data.msg, 'success');
                    } else if (data.status == 0) {
                        location.reload(true);
                        show_toastr('Success', data.msg, 'success');
                    } else {
                        show_toastr('Error', data.msg, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    $('#loader').fadeOut();
                    show_toastr('Error', '{{ __('Something went wrong please try again') }}', 'error');
                }
            });

        });
        $(".action-btn").on('click', function() {
            var checkedValues = [];
            var token = $('meta[name="csrf-token"]').attr('content');
            checkedValues.push($(this).attr('data-id'));
            var temp = $(this);
            var action = $(this).attr('data-action');
            action = action.trim();
            $.ajax({
                type: 'post',
                url: '{{ url('/emailbox/action') }}',
                dataType: 'json',
                data: {
                    '_token': '{{ csrf_token() }}',
                    'id': checkedValues,
                    'action': action
                },
                beforeSend: function() {
                    $('#loader').fadeIn();
                },
                success: function(data) {
                    $('#loader').fadeOut();
                    if (data.status == 1) {

                        if (action == 'starred') {

                            var html = '<i class="ti ti-star text-warning"></i>';
                            temp.html(html);
                            temp.attr('data-action', 'unstarred');
                            show_toastr('Success', data.msg, 'success');

                        } else if (action == 'seen') {
                            var html = '<i class="ti ti-mail text-info"></i>';
                            temp.html(html);
                            temp.attr('data-action', 'unseen');
                            show_toastr('Success', data.msg, 'success');
                        } else {}
                    } else if (data.status == 0) {

                        if (action == 'unstarred') {
                            var html = '<i class="ti ti-star text-secondary"></i>';
                            temp.html(html);
                            temp.attr('data-action', 'starred');
                            show_toastr('Success', data.msg, 'success');
                          
                        } else if (action == 'unseen') {

                            var html = '<i class="ti ti-mail-opened text-secondary"></i>';
                            temp.html(html);
                            temp.attr('data-action', 'seen');
                            show_toastr('Success', data.msg, 'success');
                         
                        } else {}
                    } else {
                        show_toastr('Error', data.msg, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    $('#loader').fadeOut();
                    show_toastr('Error', '{{ __('Something went wrong please try again') }}', 'error');
                }
            });
        });
    </script>
@endpush
