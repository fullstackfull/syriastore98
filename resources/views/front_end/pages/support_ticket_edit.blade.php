@php
    $logo = get_file('/');
@endphp
{{ Form::model($tickets, ['route' => ['update.support.ticket', $slug,$tickets->id], 'method' => 'POST', 'enctype' => 'multipart/form-data', 'class' => 'space-y-4']) }}
<input type="hidden" name="customer_id" value="{{ auth('customers')->user()->id }}">
<div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
        <div>
            <div class="form-group">
                <label class="block mb-2 font-medium md:text-base text-sm">{{ __('Title') }}:</label>
                {!! Form::text('title', null, ['class' => 'form-input', 'placeholder' => 'Enter Title']) !!}
            </div>
        </div>
        <div>
            {!! Form::label('', __('Order'), ['class' => 'block mb-2 font-medium md:text-base text-sm']) !!}
            {!! Form::select('order_id',$orders, $tickets->order_id, ['class' => 'form-input select2']) !!}
        </div>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
        <div>
            <label class="block mb-2 font-medium md:text-base text-sm">{{ __('Attachments') }}:<small>({{__('You can select multiple files')}})</small></label>
            <div class="input-group file-select-set mb-3">
                <input type="hidden" class="form-input p-2 rounded" readonly="" placeholder="Choose file" id="attachments">
                <input type="file" class="form-input file-opc {{ $errors->has('attachments') ? ' is-invalid' : '' }}" name="attachments[]" id="file" aria-label="Upload" multiple=""  data-filename="multiple_file_selection" onchange="document.getElementById('blah').src = window.URL.createObjectURL(this.files[0])">
                {{-- <label class="input-group-text file-opc bg-primary" for="attachments"><i
                        class="ti ti-circle-plus"></i>{{__('Browse')}}</label> --}}
                <img src="" id="blah" width="20%"/>
                <div class="invalid-feedback">
                    {{ $errors->first('attachments.*') }}
                </div>
            </div>
            <p class="multiple_file_selection mx-4"></p>
        </div>
        <div>
            <p class="multiple_file_selection mb-0 "></p>
            <ul class="list-group list-group-flush w-100 attachment_list">
                @php $attachments = json_decode($tickets->attachment); @endphp

                @if(!empty($tickets->attachment))
                    @foreach ($attachments as $index => $attachment)
                        <li class="list-group-item px-0 me-3 b-0 d-flex gap-2">
                            <a download="" href="{{ get_file($attachment) }}" class="btn btn-sm btn-primary d-inline-flex align-items-center mx-2" data-bs-toggle="tooltip" title="{{ __('Download') }}">
                                <i class="ti ti-arrow-bar-to-down me-2"></i>
                                    {{__('Attachment.png')}}
                            </a>
                            <a class="bg-danger ms-2 mx-3 btn btn-sm d-inline-flex align-items-center" title="{{ __('Delete') }}" onclick="(confirm('Are You Sure?')?(document.getElementById('user-form-{{ $index }}').submit()):'');">
                                <i class="ti ti-trash text-white"></i>
                            </a>
                        </li>
                    @endforeach
                @endif
            </ul>
        </div>

    </div>
    <div class="grid grid-cols-1 md:grid-cols-1 gap-4">
        <div>
            {!! Form::label('', __('Description'), ['class' => 'block mb-2 font-medium md:text-base text-sm']) !!}
                <div class="form-group mt-3">
                    {!! Form::textarea('description', null, ['id' => 'description', 'rows' => 8, 'class'=>'pc-tinymce-2']) !!}
                </div>
        </div>
    </div>
</div>
<div class="flex flex-wrap gap-4">
    <button type="submit" class="btn-primary continue-btn">
        {{ __('Update') }}
    </button>
    <button type="button" class="close-modal bg-gray-50 border text-gray-700 font-medium py-2.5 px-6 rounded-md hover:bg-primary/10 transition-all duration-300 cancel-btn" data-bs-dismiss="modal">
        {{ __('Cancel') }}
    </button>
</div>
{!! Form::close() !!}
@if($tickets->attachment)
@foreach ($attachments as $index => $attachment)
    <form method="post" id="user-form-{{ $index }}" action="{{ route('tickets.attachment.destroy', [$slug,$tickets->id, $index]) }}">
        @csrf
        @method('DELETE')
    </form>
@endforeach
@endif
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
