@extends('layouts.app')
@section('page-title')
    {{ __('Reply email') }}
@endsection
@section('page-breadcrumb')
    {{ __('Reply email') }}
@endsection

@push('css')
    <link href="{{ asset('assets/js/plugins/summernote-0.8.18-dist/summernote-lite.min.css') }}" rel="stylesheet">
    <style>
          .col-md-6 {
            width: 100% !important;
        }
    </style>
@endpush
@section('content')
    <!-- [ Main Content ] start -->
    <div class="row">
        <!-- [ sample-page ] start -->
        <div class="col-sm-12">
            <div class="row">
                @include('email-box::layouts.sidebar')
                <div class="col-xl-9">
                    <!--Brand Settings-->
                    <div id="mail-inbox" class="">
                        <div class="card">
                            <form method="post" action="{{ route('emailbox.reply.sent') }}" enctype="multipart/form-data">
                                @csrf
                                <div class="card-body ">
                                    <div class="row">
                                        <div class="form-group">
                                            <label for="to">{{ __('To') }}</label>
                                            <input type="text" class="form-control" id="to" aria-describedby="to" required
                                                placeholder="{{ __('Enter email') }}" value="{{$message->getFrom()[0]->mail}}" name="to">
                                        </div>
                                        <div class="form-group">
                                            <label for="cc">{{ __('CC') }}</label>
                                            <input type="text" class="form-control" id="cc" aria-describedby="cc"
                                                name="cc" placeholder="{{ __('Enter email') }}">
                                        </div>
                                        <div class="form-group">
                                            <label for="subject">{{ __('Subject') }}</label>
                                            <input type="text" class="form-control" id="subject" required
                                                aria-describedby="subject" placeholder="{{ __('Enter Subject') }}"
                                                name="subject" value="{{ !isset($message->getInReplyTo()[0]) ? __('Re') . ': ' . $message->getSubject() : $message->getSubject() }}" >
                                        </div>
                                        <div class="form-group col-md-12" id="editor-container">
                                            <label for="editor">{{__('Content')}}</label>
                                            <textarea class="summernote" id="editor" rows="2" name="content" required><br><br><br><br>{{ __('On') }} {{ date('D, M d, Y g:i A', strtotime($message->getDate())) }} {{ $message->getReplyTo()[0]->personal }} <<a href="mailto:{{ $message->getReplyTo()[0]->mail }}" target="_blank">{{ $message->getReplyTo()[0]->mail }}</a>> {{ __('wrote') }}: <blockquote class="gmail_quote" style="margin:0px 0px 0px 0.8ex;border-left:1px solid rgb(204,204,204);padding-left:1ex">{!! $message->getHTMLBody(true) !!}</blockquote></textarea>
                                        </div>
                                        <div id="textBoxContainer" class="mt-2 mb-2">
                                            <div class="row">
                                                <div class="form-group">
                                                    <label for="attachment">{{ __('Attachments') }}</label>
                                                </div>
                                                <div class="col-sm-11">
                                                    <input type="file" class="form-control" name="attachment[]">
                                                </div>
                                                <div class="col-sm-1 p-0">
                                                    <button type="button" class="btn btn-primary" onclick="addTextBox()"><i
                                                            class="ti ti-plus"></i></button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer text-end">
                                    <button type="submit" class="btn btn-primary">{{ __('Send') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@push('scripts')
    <script src="{{ asset('assets/js/plugins/summernote-0.8.18-dist/summernote-lite.min.js') }}"></script>
    <script>
        summernote();

        function summernote() {
            if ($(".summernote").length > 0) {

                $($(".summernote")).each(function(index, element) {
                    var id = $(element).attr('id');

                    $('#' + id).summernote({
                        placeholder: "Write Here… ",
                        dialogsInBody: !0,
                        tabsize: 2,
                        minHeight: 200,
                        toolbar: [
                            ['style', ['style']],
                            ["font", ["bold", "italic", "underline", "clear", "strikethrough"]],
                            ['fontname', ['fontname']],
                            ['color', ['color']],
                            ['para', ['ul', 'ol', 'paragraph']],
                            ['table', ['table']],
                        ]
                    });
                });
            }
        }
    </script>  
    <script>
        function addTextBox() {
            const container = document.getElementById("textBoxContainer");
            const div = document.createElement("div");
            div.className = "row mt-2 mb-2";
            div.innerHTML = `<div class="col-sm-11">
                <input type="file" class="form-control"  name="attachment[]" accept=".jpg, .jpeg, .png">
                </div><div class="col-sm-1 p-0">
                <button type="button" class="btn btn-danger" onclick="removeTextBox(this)"><i class="ti ti-trash"></i></button></div>
            `;
            container.appendChild(div);
        }

        function removeTextBox(button) {
            const container = document.getElementById("textBoxContainer");
            const grandparent = button.parentElement.parentElement; // Navigate to the grandparent element
            container.removeChild(grandparent);
        }
    </script>
@endpush
