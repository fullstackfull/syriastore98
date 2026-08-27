
<style>
    .tags-width .select2-container{
        width: 100% !important;
    }
</style>
{{ Form::open(['route' => 'digitalproduct.store', 'method' => 'post', 'id' => 'choice_form', 'enctype' => 'multipart/form-data']) }}
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
                        <div class="form-group col-6 parmalink " style =  "display: none; ">
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
                                    <option value="{{ $Key }}">
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
                        <div class="form-group col-6" data_val='0'>
                            {!! Form::label('', __('Brand'), ['class' => 'form-label']) !!}
                            <span>
                                {!! Form::select('brand_id', $brands, null, [
                                    'class' => 'form-control',
                                    'data-role' => 'tagsinput',
                                    'id' => 'brand-dropdown',
                                ]) !!}
                            </span>
                        </div>
                        <div class="form-group col-6" data_val='0'>
                            {!! Form::label('', __('Label'), ['class' => 'form-label']) !!}
                            <span>
                                {!! Form::select('label_id', $labels, null, [
                                    'class' => 'form-control',
                                    'data-role' => 'tagsinput',
                                    'id' => 'label-dropdown',
                                ]) !!}
                            </span>
                        </div>
                    </div>
                    <div class="row">
                        <div class="form-group col-6  tags-width">
                            {!! Form::label('', __('Tags'), ['class' => 'form-label']) !!}
                            <select name ="tag_id[]" class="select2 form-control" id="tag_id" multiple>
                                @foreach ($tag as $t)
                                    <option value="{{$t->id}}">{{$t->name}}</option>
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
                    <div class="row">
                            <div class="form-group col-6">
                                <label for="sub_images"
                                    class="col-form-label">{{ __('Upload Product File') }}</label>
                                <input type="file" name="sub_images[]" id="sub_images"
                                    class="form-control custom-input-file" multiple>
                            </div>
                            <div class="form-group col-6">
                                <label for="cover_image"
                                    class="col-form-label">{{ __('Upload Cover Image') }}</label>
                                <input type="file" name="cover_image" id="cover_image"
                                    class="form-control custom-input-file"
                                    onchange="document.getElementById('upcoverImg').src = window.URL.createObjectURL(this.files[0]);"
                                    multiple>
                                <img id="upcoverImg" src="" width="20%" class="mt-2"/>
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
                                    <input type="file" class="form-control" name="preview_video" id="preview_video"
                                        onchange="document.getElementById('preview_video').src = window.URL.createObjectURL(this.files[0]);"
                                        multiple>
                                    <div class="invalid-feedback">{{ __('invalid form file') }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="form-group col-md-6 col-12 ml-auto d-none" id="preview-iframe-div">
                            {{ Form::label('preview_iframe', __('Preview iFrame'), ['class' => 'form-label']) }}
                            {{ Form::textarea('preview_iframe', null, ['class' => 'form-control font-style', 'rows' => 2]) }}
                        </div>
                        <div class="form-group col-md-6 col-12" id="video_url_div">
                            {{ Form::label('video_url', __('Video URL'), ['class' => 'form-label']) }}
                            {{ Form::text('video_url', null, ['class' => 'form-control font-style']) }}
                        </div>
                    </div>
                </div>
            </div>
            @stack('addsizeguidefields')
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
                                <input type="hidden" name="trending" value="0">
                                <input type="checkbox" class="form-check-input" name="trending" id="trending_product"
                                    value="1">
                                <label class="form-check-label" for="trending_product"></label>
                            </div>
                        </div>
                        <div class="form-group col-lg-3 col-md-6  col-12">
                            {!! Form::label('', __('Display Product'), ['class' => 'form-label']) !!}
                            <div class="form-check form-switch">
                                <input type="hidden" name="status" value="0">
                                <input type="checkbox" class="form-check-input" name="status" id="status"
                                    value="1">
                                <label class="form-check-label" for="status"></label>
                            </div>
                        </div>
                        <div class="form-group col-lg-3 col-md-6  col-12">
                            {!! Form::label('', __('Custom  Field'), ['class' => 'form-label']) !!}
                            <div class="form-check form-switch">
                                <input type="hidden" name="custom_field_status" value="0">
                                <input type="checkbox" class="form-check-input" name="custom_field_status"
                                    id="enable_custom_field" value="1">
                                <label class="form-check-label" for="enable_custom_field"></label>
                            </div>
                        </div>
                        <input type="hidden" class="hidden" name="product_type" value="digital">
                        @stack('addwholesalefields')
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="btn btn-badge btn-primary mx-1">
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
    </script>
