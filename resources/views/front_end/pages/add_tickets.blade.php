{{ Form::open(['route' => ['support.ticket.store',$slug], 'method' => 'post', 'enctype' => 'multipart/form-data', 'class' => 'space-y-4']) }}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            {!! Form::label('', __('Title'), ['class' => 'block mb-2 font-medium md:text-base text-sm']) !!}
            {!! Form::text('title', null, ['class' => 'form-input','placeholder' => 'Enter Ticket Title']) !!}
        </div>
        @if (isset($order_id) && $order_id != 0)
            <input type="hidden" name="order_id" value="{{ $order_id }}">
        @else
            <div>
                {{ Form::label('order_id', __('Select Order'),['class'=>'block mb-2 font-medium md:text-base text-sm']) }}
                {{ Form::select('order_id', $orders,null, array('class' => 'form-input select','required'=>'required','placeholder'=>'Select Order')) }}
            </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @endif
        <div>
            <label class="d-block block mb-2 font-medium md:text-base text-sm">{{ __('Attachments') }}:<small>({{__('You can select multiple files')}})</small></label>
            <div class="input-group file-select-set">
                <input type="hidden" class="form-input p-2 rounded" readonly="" placeholder="Choose file" id="attachments">
                <input type="file" class="form-input file-opc {{ $errors->has('attachments') ? ' is-invalid' : '' }}" name="attachments[]" id="file" aria-label="Upload" multiple=""  data-filename="multiple_file_selection" onchange="document.getElementById('blah').src = window.URL.createObjectURL(this.files[0])">
                <img src="" id="blah" width="20%"/>
                <div class="invalid-feedback">
                    {{ $errors->first('attachments.*') }}
                </div>
            </div>
            <p class="multiple_file_selection mx-4"></p>
        </div>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-1 gap-4">
        <div>
            {!! Form::label('', __('Description'), ['class' => 'block mb-2 font-medium md:text-base text-sm']) !!}
            <div class="form-group mt-3">
                <textarea class="pc-tinymce-2" name="description" id="description" rows="4"></textarea>
            </div>
        </div>
    </div>
    <div class="flex flex-wrap gap-4">
        <button type="submit" class="btn-primary continue-btn">
            {{ __('Create') }}
        </button>
        <button type="button" class="close-modal bg-gray-50 border text-gray-700 font-medium py-2.5 px-6 rounded-md hover:bg-primary/10 transition-all duration-300 cancel-btn" data-bs-dismiss="modal">
            {{ __('Cancel') }}
        </button>
    </div>
{!! Form::close() !!}

<script src="{{ asset('assets/css/summernote/summernote-bs4.js') }}"></script>
<script src="{{ asset('assets/js/plugins/tinymce/tinymce.min.js') }}"></script>
<script>
    if ($(".pc-tinymce-2").length) {
        tinymce.init({
            selector: '.pc-tinymce-2',
            toolbar: 'link image',
            plugins: 'image code',
            statusbar: false,
            branding: false,
            image_title: true,
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
