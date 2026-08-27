<div class="auth-wrapper auth-v1">
    <div class="bg-auth-side bg-primary"></div>
        <div class="auth-content">
            <div class="mx-3 mx-md-5">
                <div class="mb-3">
                    <h5 class="text-white ">{{ __('Ticket') }} - {{$ticket->ticket_id}}</h5>
                </div>
            </div>
            <div class="sm:ml-10">
                <div class="card mb-3 p-4 w-fit ml-auto bg-primary/10 border-primary text-right shadow-lg">
                    @csrf
                    <div class=" mb-1">
                        <div class="card-header"><h6 class="mb-2 font-bold">{{ $ticket->UserData->name }} <small>({{$ticket->created_at->diffForHumans()}})</small></h6></div>
                        <div class="card-body w-100">
                            <div>
                                <p>{!! $ticket->description !!}</p>

                            </div>
                            @php
                                $attachments=json_decode($ticket->attachment);
                            @endphp
                            @if(!is_null($attachments) && count($attachments)>0)
                                <div class="m-1 ml-2">
                                    <b>{{ __('Attachments') }} :</b>
                                    <ul class="list-group list-group-flush">
                                        @foreach($attachments as $index => $attachment)
                                            <li class="list-group-item file-name">
                                                {{__('Attachment.png')}}<a download="" href="{{ asset(Storage::url('tickets/'.$ticket->ticket_id."/".$attachment)) }}" class="edit-icon py-1 ml-2" title="{{ __('Download') }}"><i class="fa fa-download ms-2"></i></a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            @foreach($ticket->conversions as $conversion)
            <div class=" @if($conversion->sender == 'sender' || $conversion->sender == 'user') sm:ml-10 @else sm:mr-10  @endif">
                <div class="card mb-3 p-4 w-fit mr-auto shadow-lg @if($conversion->sender == 'sender' || $conversion->sender == 'user') !mr-0 ml-auto text-right bg-primary/10 border-primary @else bg-gray-100 @endif">
                    
                        <div class="card-header">
                            <h6 class="mb-2 font-bold">
                                @if($conversion->sender == 'sender')

                                @else
                                {{$conversion->replyBy()->name}}
                                @endif
                                <small>({{$conversion->created_at->diffForHumans()}})
                                </small>
                            </h6>
                        </div>  
                        <div class="card-body w-100">
                            <div>{!! $conversion->description !!}</div>
                            @php
                                $attachments=json_decode($conversion->attachments);
                            @endphp
                            @if(count($attachments))
                                <div class="mt-2">
                                    <b>{{ __('Attachments') }} :</b>
                                    <ul class="list-group list-group-flush">

                                        @foreach($attachments as $index => $attachment)
                                            <li class="list-group-item px-0 file-name">
                                                {{__('Attachment.png')}}<a download="" href="{{ get_file($attachment)  }}" class="edit-icon py-1 ml-2" title="{{ __('Download') }}"><i class="fa fa-download ms-2"></i></a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
            @if($ticket->status != 'Closed')
                <div>
                    <form method="post" action="{{ route('ticket.reply',[$slug,$ticket->id]) }}" enctype="multipart/form-data" class="space-y-4">
                        @csrf
                        <div class="grid grid-cols-1 md:grid-cols-1 gap-4">
                            <div >
                                <label class="require block mb-2 font-medium md:text-base text-sm">{{ __('Description') }}</label>
                                <textarea name="reply_description" class="pc-tinymce-2 {{ $errors->has('reply_description') ? ' is-invalid' : '' }}">{{old('reply_description')}}</textarea>
                                <div class="invalid-feedback">
                                    {{ $errors->first('reply_description') }}
                                </div>
                            </div>
                            <div>
                                <label class="block mb-2 font-medium md:text-base text-sm">{{ __('Attachments') }}:<small>({{__('You can select multiple files')}})</small></label>
                                <div class="input-group file-select-set mb-1">
                                    <input type="hidden" class="form-input p-2 rounded" readonly="" placeholder="Choose file" id="attachments">
                                    <input type="file" class="form-input file-opc {{ $errors->has('reply_attachments') ? ' is-invalid' : '' }}" name="reply_attachments[]" id="file" aria-label="Upload" multiple=""  data-filename="multiple_file_selection" onchange="document.getElementById('blah').src = window.URL.createObjectURL(this.files[0])">
                                    {{-- <label class="input-group-text file-opc bg-primary" for="attachments"><i
                                            class="ti ti-circle-plus"></i>{{__('Browse')}}</label> --}}
                                    <img src="" id="blah" width="20%"/>
                                    <div class="invalid-feedback">
                                        {{ $errors->first('reply_attachments.*') }}
                                    </div>
                                </div>
                                <p class="multiple_file_selection mx-4"></p>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-4">
                            <input type="hidden" name="status" value="New Ticket"/>
                            <button type="submit" class="btn-primary btn-block">
                                {{ __('Submit') }}
                            </button>
                            <button type="button" class="close-modal bg-gray-50 border text-gray-700 font-medium py-2.5 px-6 rounded-md hover:bg-primary/10 transition-all duration-300 cancel-btn" data-bs-dismiss="modal">
                                {{ __('Cancel') }}
                            </button>
                        </div>
                    </form>
                </div>
            @else
                <div class="card">
                    <div class="card-body">
                        <p class="text-blue font-weight-bold text-center mb-0">{{ __('Ticket is closed you cannot replay.') }}</p>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

<script src="{{ asset('assets/css/summernote/summernote-bs4.js') }}"></script>
<script src="{{ asset('assets/js/plugins/tinymce/tinymce.min.js') }}"></script>
<script>
    if ($(".pc-tinymce-2").length) {
        tinymce.init({
            selector: '.pc-tinymce-2',
            toolbar: 'link image',
            plugins: 'image code',
            image_title: true,
            statusbar: false,
            branding: false,
            automatic_uploads: true,
            file_picker_types: 'image',
            file_picker_callback: function(cb, value, meta) {
                var input = document.createElement('input');
                input.setAttribute('type', 'file');
                input.setAttribute('accept', 'image/*');
                input.onchange = function() {
                    var file = this.files[0];

                    var reader = new FileReader();
                    reader.onload = function() {
                        var id = 'blobid' + (new Date()).getTime();
                        var blobCache = tinymce.activeEditor.editorUpload.blobCache;
                        var base64 = reader.result.split(',')[1];
                        var blobInfo = blobCache.create(id, file, base64);
                        blobCache.add(blobInfo);
                        cb(blobInfo.blobUri(), {
                            title: file.name
                        });
                    };
                    reader.readAsDataURL(file);
                };

                input.click();
            },
            height: "400",
            content_style: 'body { font-family: "Inter", sans-serif; }'
        });
    }
    document.addEventListener('focusin', function(e) {
        if (e.target.closest('.tox-tinymce-aux, .moxman-window, .tam-assetmanager-root') !== null) {
            e.stopImmediatePropagation();
        }
    });
</script>
<script>
    $(document).ready(function() {
        $('.summernote').summernote({
            height: 200,
        });
    });
</script>

