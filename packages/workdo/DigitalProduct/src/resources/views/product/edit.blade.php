
<style>
    .tags-width .select2-container{
        width: 100% !important;
}
</style>
{{Form::model($product, array('route' => array('digitalproduct.update', $product->id), 'method' => 'PUT', 'enctype' => 'multipart/form-data')) }}
<div class="row pt-4">
    <div class="col-md-12">
        <div class="row">
            <h5 class="mb-3">{{__('Main Informations')}}</h5>
            <div class="">
                <div class="">
                    <div class="row">
                        <div class="form-group col-6">
                            {!! Form::label('', __('Name'), ['class' => 'form-label']) !!}
                            {!! Form::text('name', null, ['class' => 'form-control name']) !!}
                        </div>
                        <div class="form-group col-6 parmalink ">
                            {!! Form::label('', __('Parmalink'), ['class' => 'form-label col-md-3']) !!}
                            <div class="d-flex flex-wrap gap-3">
                                <input class="input-group-text col-12 " readonly id="basic-addon2" value="{{ $link }}">
                                {!! Form::text('slug', null, ['class' => 'form-control slug col-12']) !!}
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="form-group col-6">
                            {!! Form::label('', __('Category'), ['class' => 'form-label']) !!}
                            {!! Form::select('category_id', $Category, null, [
                                'class' => 'form-control',
                                'data-role' => 'tagsinput',
                                'id' => 'category_id',
                            ]) !!}
                        </div>
                    </div>
                    <div class="row">
                        <div class="form-group col-md-6 col-12 switch-width">
                            {{ Form::label('tax_id', __('Taxs'), ['class' => ' form-label']) }}
                            <select name="tax_id[]"  data-role="tagsinput" id="tax_id" multiple>

                                @foreach ($Tax as $Key => $tax)
                                    <option @if (in_array($Key, $get_tax)) selected @endif value={{$Key}}>
                                        {{ $tax }}</option>
                                @endforeach
                            </select>

                        </div>
                        <div class="form-group col-md-6 col-12">
                            {!! Form::label('', __('Tax Status'), ['class' => 'form-label']) !!}
                            {!! Form::select('tax_status', $Tax_status, null, [
                                'class' => 'form-control',
                                'data-role' => 'tagsinput',
                                'id' => 'tax_id',
                            ]) !!}
                        </div>
                    </div>
                    <div class="row">
                        <div class="form-group  col-6" data_val='0'>
                            {!! Form::label('', __('Brand'), ['class' => 'form-label']) !!}
                            {!! Form::select('brand_id', $brands, null, [
                                'class' => 'form-control',
                                'data-role' => 'tagsinput',
                                'id' => 'brand-dropdown',
                            ]) !!}
                        </div>
                        <div class="form-group  col-6" data_val='0'>
                            {!! Form::label('', __('Label'), ['class' => 'form-label']) !!}
                            {!! Form::select('label_id', $labels, null, [
                                'class' => 'form-control',
                                'data-role' => 'tagsinput',
                                'id' => 'label-dropdown',
                            ]) !!}
                        </div>
                    </div>
                    <div class="row">
                        <div class="form-group col-6 tags-width">
                            {!! Form::label('', __('Tags'), ['class' => 'form-label']) !!}
                            <select name ="tag_id[]" class="select2 form-control" id="tag_id" multiple required>
                                @foreach ($tag as $t)
                                    <option @if (in_array($t->id, $get_tags)) selected @endif value="{{$t->id}}">{{$t->name}}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="row product-price-div">
                        <div class="form-group col-md-6 col-12 product_price">
                            {!! Form::label('', __('Price'), ['class' => 'form-label']) !!}
                            {!! Form::number('price', null, ['class' => 'form-control', 'min' => '0', 'step' => '0.01']) !!}
                        </div>
                        <div class="form-group col-md-6 col-12">
                            {!! Form::label('', __('Sale Price'), ['class' => 'form-label']) !!}
                            {!! Form::number('sale_price', null, ['class' => 'form-control', 'min' => '0', 'step' => '0.01']) !!}
                        </div>
                    </div>
                </div>
            </div>
            <h5 class="mb-3">{{ __('Product Image') }}</h5>
            <div class="">
                <div class="">
                    <div class="">
                        <div class="row">
                            <div class="form-group col-6">
                                <label for="sub_images"
                                    class="col-form-label">{{ __('Upload Product File') }}</label>
                                <input type="file" name="sub_images[]" id="sub_images"
                                    class="form-control custom-input-file" multiple>
                            </div>
                            <div class="form-group col-6">
                                @foreach ($product_image as $file)
                                    <div class="product_Image {{'delete_img_'.$file->id }}" data-id="{{ $file->id }}">
                                        <div class="position-relative p-2">
                                            <h6>{{__('Attachment')}}</h6>
                                            <div class="position-absolute text-center top-0 end-0 start-0 pb-3">
                                                <a href="{{ get_file($file->image_path) }}" download="" data-original-title="{{ __('Download') }}" class="btn btn-sm btn-primary me-2"><i class="ti ti-download"></i></a>
                                                <a class="btn btn-sm btn-danger bg-danger deleteRecord" name="deleteRecord" data-id="{{ $file->id }}"><i class="ti ti-trash"></i></a>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="form-group col-6">
                                <label for="cover_image"
                                    class="col-form-label">{{ __('Upload Cover Image') }}</label>
                                <input type="file" name="cover_image" id="cover_image"
                                    class="form-control custom-input-file"
                                    onchange="document.getElementById('upcoverImg').src = window.URL.createObjectURL(this.files[0]);"
                                    multiple>
                                <img id="upcoverImg" src="{{get_file($product->cover_image_path)}}" width="20%" class="mt-2"/>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="form-group col-md-6 col-12" id="preview_type">
                            {{ Form::label('preview_type', __('Preview Type'), ['class' => 'form-label']) }}
                            {{ Form::select('preview_type', $preview_type, null, ['class' => 'form-control font-style', 'id' => 'preview_type']) }}
                        </div>
                        <div class="form-group  col-md-6 col-12" id="preview-video-div">
                            <div class="form-group">
                                <div class="choose-file">
                                    <label for="preview_video" class="form-label">{{ __('Preview Video') }}</label>
                                    <input type="file" class="form-control" name="preview_video" id="preview_video" value="{{ $product->preview_type == 'Video File' ? $product->preview_content : '' }}"
                                        onchange="document.getElementById('preview_video').src = window.URL.createObjectURL(this.files[0]);"
                                        multiple>
                                        @if ($product->preview_content != '')
                                        <a href="{{ get_file($product->preview_content) }}" target="_blank">
                                            <video height="100px" controls="" class="mt-2">
                                                <source id="preview_video" src="{{ get_file($product->preview_content) }}"
                                                    type="video/mp4">
                                            </video>
                                        </a>
                                    @endif
                                    <div class="invalid-feedback">{{ __('invalid form file') }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group col-md-6 col-12 ml-auto d-none" id="preview-iframe-div">
                            {{ Form::label('preview_iframe', __('Preview iFrame'), ['class' => 'form-label']) }}
                            <textarea name="preview_iframe" id="preview_iframe" class="form-control font-style" rows="2" value="">{{ $product->preview_type == 'iFrame' ? $product->preview_content : '' }}</textarea>
                        </div>
                        <div class="form-group col-md-6 col-12" id="video_url_div">
                            {{ Form::label('video_url', __('Video URL'), ['class' => 'form-label']) }}
                            <input class="form-control font-style" name="video_url" type="text" id="video_url"
                             value="{{ $product->preview_type == 'Video Url' ? $product->preview_content : '' }}">
                        </div>
                    </div>
                </div>
                @stack('editsizeguidefields')
            </div>
            <h5 class="mb-3">{{ __('About product') }}</h5>
            <div class="">
                <div class="">
                    <div class="form-group">
                        {{ Form::label('description', __('Product Description'), ['class' => 'form-label']) }}
                        {{ Form::textarea('description', null, ['class' => 'form-control  summernote-simple-product', 'rows' => 1, 'placeholder' => __('Product Description'), 'id' => 'description']) }}
                    </div>
                    <div class="form-group">
                        {{ Form::label('specification', __('Product Specification'), ['class' => 'form-label']) }}
                        {{ Form::textarea('specification', null, ['class' => 'form-control  summernote-simple-product', 'rows' => 1, 'placeholder' => __('Product Specification'), 'id' => 'specification']) }}
                    </div>
                    <div class="form-group">
                        {{ Form::label('detail', __('Product Details'), ['class' => 'form-label']) }}
                        {{ Form::textarea('detail', null, ['class' => 'form-control  summernote-simple-product', 'rows' => 1, 'placeholder' => __('Product Details'), 'id' => 'detail']) }}
                    </div>
                </div>
            </div>
            <h5 class="mb-3">{{__('Main Informations')}}</h5>
            <div class="">
                <div class="">
                    <div class="row">
                        <div class="form-group col-lg-3 col-md-6  col-12">
                            {!! Form::label('', __('Trending'), ['class' => 'form-label']) !!}
                            <div class="form-check form-switch">
                                {!! Form::hidden('trending', 0) !!}
                                {!! Form::checkbox('trending', 1, null, ['class' => 'form-check-input', 'id' => 'trending_product']) !!}
                                {!! Form::label('', '', ['class' => 'form-check-label', 'for' => 'trending_product']) !!}
                            </div>
                        </div>
                        <div class="form-group col-lg-3 col-md-6  col-12">
                            {!! Form::label('', __('Display Product'), ['class' => 'form-label']) !!}
                            <div class="form-check form-switch">
                                {!! Form::hidden('status', 0) !!}
                                {!! Form::checkbox('status', 1, null, ['class' => 'form-check-input', 'id' => 'status']) !!}
                                {!! Form::label('', '', ['class' => 'form-check-label', 'for' => 'status']) !!}
                            </div>
                        </div>
                        <div class="form-group col-lg-3 col-md-6  col-12">
                            {!! Form::label('', __('Custom  Field'), ['class' => 'form-label']) !!}
                            <div class="form-check form-switch">
                                <input type="hidden" name="custom_field_status" value="0">

                                {!! Form::checkbox('custom_field_status', 1, null, [
                                    'class' => 'form-check-input',
                                    'id' => 'enable_custom_field',
                                ]) !!}
                                <label class="form-check-label" for="enable_custom_field"></label>
                            </div>
                        </div>
                        <input type="hidden" class="hidden" name="product_type" value="digital">
                        @stack('editwholesalefields')
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Update')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>


{!! Form::close() !!}
<script src="{{ asset('js/jquery-ui.min.js') }}"></script>
<script src="{{ asset('js/repeater.js') }}"></script>
<script src="{{ asset('assets/css/summernote/summernote-bs4.js') }}"></script>
<script>
    $(document).ready(function() {
        // tag
        $('#tag_id').select2({
            tags: true,
            dropdownParent: $('#tag_id').parent(),
            createTag: function (params) {
            var term = $.trim(params.term);
            if (term === '') {
                return null;
            }
            return {
                id: term,
                text: term,
                newTag: true
            };
            }
        });

        var link = $('.slug').val();
        var focusOutCalled = false;

        // permalink
        $('.name').on('focusout', function() {
            var nameval = $(this).val();
            if (!focusOutCalled) {
                $.ajax({
                    url: "{{ route('get.slug') }}",
                    type: 'POST',
                    data: {
                        'value': nameval
                    },
                    dataType: 'json',
                    success: function(response) {
                        $('#loader').fadeOut();
                        $('.slug').val(response.result);
                        $('.parmalink').show();
                        focusOutCalled = true;
                    },
                    error: function(error) {
                        $('#loader').fadeOut();
                    }
                });
            }


        });
        // prview video
        $("#preview_type").change(function() {
            $(this).find("option:selected").each(function() {
                var optionValue = $(this).attr("value");
                if (optionValue == 'Video Url') {

                    $('#video_url_div').removeClass('d-none');
                    $('#video_url_div').addClass('d-block');

                    $('#preview-iframe-div').addClass('d-none');
                    $('#preview-iframe-div').removeClass('d-block');

                    $('#preview-video-div').addClass('d-none');
                    $('#preview-video-div').removeClass('d-block');

                } else if (optionValue == 'iFrame') {
                    $('#video_url_div').addClass('d-none');
                    $('#video_url_div').removeClass('d-block');

                    $('#preview-iframe-div').removeClass('d-none');
                    $('#preview-iframe-div').addClass('d-block');

                    $('#preview-video-div').addClass('d-none');
                    $('#preview-video-div').removeClass('d-block');

                } else if (optionValue == 'Video File') {

                    $('#video_url_div').addClass('d-none');
                    $('#video_url_div').removeClass('d-block');

                    $('#preview-iframe-div').addClass('d-none');
                    $('#preview-iframe-div').removeClass('d-block');

                    $('#preview-video-div').removeClass('d-none');
                    $('#preview-video-div').addClass('d-block');
                }
            });
        }).change();
    });

    $('.deleteRecord').on('click', function() {
    var id = $(this).data("id");
    $.ajax({
        url: '{{ route("digital.products.file.delete", ["id" => "__product_id"]) }}'.replace('__product_id', id),
        type: 'DELETE',
        data: {
            id: id,
        },
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        },
        success: function(data) {
            $('.delete_img_' + id).hide();
            $('#loader').fadeOut();
            if (data.success) {
                show_toastr('success', data.success, 'success');
            }
        }
    });
});

</script>
