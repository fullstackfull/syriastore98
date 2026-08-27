@extends('layouts.app')

@section('page-title')
    {{ __('HubSpot Product') }}
@endsection


@section('breadcrumb')
    <li class="breadcrumb-item" aria-current="page"><a href="{{ route('product.index') }}">{{ __('Product') }}</a></li>
    <li class="breadcrumb-item" aria-current="page">{{ __('Create HubSpot Product') }}</li>
@endsection

@section('content')
    {{ Form::open(['route' => 'product.store', 'method' => 'post', 'id' => 'choice_form', 'enctype' => 'multipart/form-data']) }}
    {{ Form::hidden('type', 'product', ['id' => 'type']) }}
    <div class="">
        <div class="row">
            <div class="col-sm-12">
                <div class="col-md-12 d-flex align-items-center justify-content-between justify-content-md-end mb-4">
                    <div class="col-md-4">
                        <ul class="nav nav-pills nav-fill cust-nav information-tab" style="gap: 10px" id="pills-tab"
                            role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="details" data-bs-toggle="pill"
                                    data-bs-target="#details-tab" type="button">{{ __('Details') }}</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="about" data-bs-toggle="pill" data-bs-target="#about-tab"
                                    type="button">{{ __('About Product') }}</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="pricing" data-bs-toggle="pill" data-bs-target="#pricing-tab"
                                    type="button">{{ __('Pricing') }}</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="media" data-bs-toggle="pill" data-bs-target="#media-tab"
                                    type="button">{{ __('Media') }}</button>
                            </li>

                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body">
                        <div class="tab-content" id="pills-tabContent">

                            {{-- Details --}}
                            <div class="tab-pane fade show active" id="details-tab" role="tabpanel"
                                aria-labelledby="pills-user-tab-1">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            {{ Form::label('name', __('Name'), ['class' => 'form-label']) }}<span
                                                class="text-danger">*</span>
                                            <div class="form-icon-user">
                                                {{ Form::text('name', !empty($product_service['name']) ? $product_service['name'] : '', ['class' => 'form-control']) }}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        {!! Form::label('', __('Tags'), ['class' => 'form-label']) !!}
                                        <select name ="tag_id[]" class="select2 form-control" id="tag_id" multiple
                                            required>
                                            @foreach ($tag as $key => $t)
                                                <option value="{{ $key }}">{{ $t }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-sm-6 col-12 form-group">
                                        {!! Form::label('', __('Category'), ['class' => 'form-label']) !!}<span class="text-danger">*</span>
                                        {!! Form::select('category_id', $main_category, null, [
                                            'class' => 'form-control',
                                            'data-role' => 'tagsinput',
                                            'id' => 'category_id',
                                        ]) !!}
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-sm-6 col-12 form-group" data_val='0'>
                                        {!! Form::label('', __('Brand'), ['class' => 'form-label']) !!}
                                        <span>
                                            {!! Form::select('brand_id', $brands, null, [
                                                'class' => 'form-control',
                                                'data-role' => 'tagsinput',
                                                'id' => 'brand-dropdown',
                                            ]) !!}
                                        </span>
                                    </div>
                                    <div class="col-sm-6 col-12 form-group" data_val='0'>
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
                                    <div class="col-sm-6 col-12 form-group">
                                        <div class="form-group switch-width">
                                            {{ Form::label('tax_id', __('Taxs'), ['class' => ' form-label']) }}
                                            <select name="tax_id[]" data-role="tagsinput" id="tax_id" multiple>
                                                @foreach ($Tax as $Key => $tax)
                                                    <option value={{ $Key }}>
                                                        {{ $tax }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-sm-6 col-12 form-group">
                                        {!! Form::label('', __('Tax Status'), ['class' => 'form-label']) !!}
                                        {!! Form::select('tax_status', $Tax_status, null, [
                                            'class' => 'form-control',
                                            'data-role' => 'tagsinput',
                                            'id' => 'tax_id',
                                        ]) !!}
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-sm-6 col-12 form-group">
                                        {!! Form::label('', __('Shipping'), ['class' => 'form-label']) !!}
                                        {!! Form::select('shipping_id', $Shipping, null, [
                                            'class' => 'form-control',
                                            'data-role' => 'tagsinput',
                                            'id' => 'shipping_id',
                                        ]) !!}
                                    </div>
                                    <div class="col-sm-6 col-12 form-group product-weight">
                                        {!! Form::label('', __('Weight(Kg)'), ['class' => 'form-label ']) !!}
                                        {!! Form::number('product_weight', null, ['class' => 'form-control', 'min' => '0', 'step' => '0.01']) !!}
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col"></div>
                                    <div class="col-6 text-end">
                                        <button class="btn btn-primary d-inline-flex align-items-center"
                                            onClick="changetab('#about-tab')" type="button">{{ __('Next') }}<i
                                                class="ti ti-chevron-right ms-2"></i></button>
                                    </div>
                                </div>
                            </div>

                            {{-- About Product --}}
                            <div class="tab-pane fade" id="about-tab" role="tabpanel" aria-labelledby="pills-user-tab-2">
                                <div class="form-group col-md-12">
                                    {{ Form::label('description', __('Product Description'), ['class' => 'form-label']) }}
                                    {{ Form::textarea('description', !empty($product_service['description']) ? $product_service['description'] : '', ['class' => 'form-control  summernote-simple-product', 'rows' => 1, 'placeholder' => __('Product Description'), 'id' => 'description']) }}
                                </div>
                                <div class="row">
                                    <div class="form-group col-md-6">
                                        {{ Form::label('specification', __('Product Specification'), ['class' => 'form-label']) }}
                                        {{ Form::textarea('specification', null, ['class' => 'form-control  summernote-simple-product', 'rows' => 1, 'placeholder' => __('Product Specification'), 'id' => 'specification']) }}
                                    </div>
                                    <div class="form-group col-md-6">
                                        {{ Form::label('detail', __('Product Details'), ['class' => 'form-label']) }}
                                        {{ Form::textarea('detail', null, ['class' => 'form-control  summernote-simple-product', 'rows' => 1, 'placeholder' => __('Product Details'), 'id' => 'detail']) }}
                                    </div>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-6">
                                        <button class="btn btn-outline-secondary d-inline-flex align-items-center"
                                            onClick="changetab('#details-tab')" type="button"><i
                                                class="ti ti-chevron-left me-2"></i>{{ __('Previous') }}</button>
                                    </div>
                                    <div class="col-6 text-end">
                                        <button class="btn btn-primary d-inline-flex align-items-center"
                                            onClick="changetab('#pricing-tab')" type="button">{{ __('Next') }}<i
                                                class="ti ti-chevron-right ms-2"></i></button>
                                    </div>
                                </div>
                            </div>

                            {{-- Pricing --}}
                            <div class="tab-pane fade" id="pricing-tab" role="tabpanel"
                                aria-labelledby="pills-user-tab-3">
                                <div class="row">

                                    {{-- PURCHASE PRICE --}}
                                    <div class="col-md-6 form-group product_price product-weight">
                                        {{ Form::label('price', __('Purchase Price'), ['class' => 'form-label']) }}<span
                                            class="text-danger">*</span>
                                        <div class="form-icon-user">
                                            {{ Form::number('price', !empty($product_service['price']) ? $product_service['price'] : '', ['class' => 'form-control', 'step' => '0.01']) }}
                                        </div>
                                    </div>

                                    {{-- SALE PRICE --}}
                                    <div class="col-md-6 form-group product-weight">
                                        {{ Form::label('sale_price', __('Sale Price'), ['class' => 'form-label']) }}<span
                                            class="text-danger">*</span>
                                        <div class="form-icon-user">
                                            {{ Form::number('sale_price', !empty($product_service['hs_cost_of_goods_sold']) ? $product_service['hs_cost_of_goods_sold'] : '', ['class' => 'form-control', 'step' => '0.01']) }}
                                        </div>
                                    </div>
                                    <input type="hidden" class="form-control" name="hubspot_product_service_id"
                                        value="{{ !empty($product_services['id']) ? $product_services['id'] : '' }}">
                                    <input type="hidden" class="form-control" name="hubspot_product_image_url"
                                        value="{{ !empty($product_service['product_image']) ? $product_service['product_image'] : '' }}">

                                    <div class="row">
                                        <div class="row form-group row-gap">
                                            {{-- MAIN INFORMATIONS --}}
                                            <div class="form-group col-md-6">
                                                <h5 class="mb-3">{{ __('Main Informations') }}</h5>
                                                <div class="row row-gap align-items-center">
                                                    <div class="col-xl-3 col-lg-6 col-sm-6 col-12">
                                                        <div class="stock-main-opts align-items-center d-flex">
                                                            <div class="form-check form-switch">
                                                                <input type="hidden" name="variant_product"
                                                                    value="0">
                                                                <input type="checkbox"
                                                                    class="form-check-input enable_product_variant"
                                                                    name="variant_product" id="enable_product_variant"
                                                                    value="1">
                                                                <label class="form-check-label"
                                                                    for="enable_product_variant"></label>
                                                            </div>
                                                            {!! Form::label('enable_product_variant', __('Display Variants'), ['class' => 'form-label']) !!}
                                                        </div>
                                                    </div>
                                                    <div class="col-xl-3 col-lg-6 col-sm-6 col-12">
                                                        <div class="stock-main-opts align-items-center d-flex">
                                                            <div class="form-check form-switch">
                                                                <input type="hidden" name="trending" value="0">
                                                                <input type="checkbox" class="form-check-input"
                                                                    name="trending" id="trending_product" value="1">
                                                                <label class="form-check-label"
                                                                    for="trending_product"></label>
                                                            </div>
                                                            {!! Form::label('', __('Trending'), ['class' => 'form-label']) !!}
                                                        </div>
                                                    </div>
                                                    <div class="col-xl-3 col-lg-6 col-sm-6 col-12">
                                                        <div class="stock-main-opts align-items-center d-flex">
                                                            <div class="form-check form-switch">
                                                                <input type="hidden" name="status" value="0">
                                                                <input type="checkbox" class="form-check-input"
                                                                    name="status" id="status" value="1">
                                                                <label class="form-check-label" for="status"></label>
                                                            </div>
                                                            {!! Form::label('', __('Display Product'), ['class' => 'form-label']) !!}
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="row row-gap">
                                                    <div class="col-md-12 mt-3">
                                                        {!! Form::label('', __('Product Attribute'), ['class' => 'form-label']) !!}
                                                        {!! Form::select('attribute_id[]', $ProductAttribute, null, [
                                                            'class' => 'form-control attribute_option attribute_option_data',
                                                            'multiple' => 'multiple',
                                                            'data-role' => 'tagsinput',
                                                            'id' => 'attribute_id',
                                                        ]) !!}
                                                        <small>{{ __('Choose Existing Attribute') }}</small>
                                                    </div>
                                                    <div class="attribute_options" id="attribute_options">
                                                    </div>
                                                    <div class="attribute_combination" id="attribute_combination">
                                                    </div>
                                                </div>
                                            </div>
                                            {{-- STATUS --}}
                                            <div class="col-md-6 product-stock-div">
                                                <h4>{{ __('Product Stock') }}</h4>
                                                @if ($stock_management == 'on')
                                                    <div class=" col-md-6 col-12">
                                                        <div class="product-stock-top d-flex ">
                                                            <div class="form-check form-switch">
                                                                <input type="hidden" name="track_stock" value="0">
                                                                <input type="checkbox"
                                                                    class="form-check-input enable_product_stock"
                                                                    name="track_stock" id="enable_product_stock"
                                                                    value="1">
                                                                <label class="form-check-label"
                                                                    for="enable_product_stock"></label>
                                                            </div>
                                                            {!! Form::label('', __('Stock Management'), ['class' => 'form-label']) !!}
                                                        </div>
                                                    </div>
                                                @else
                                                    <div class="col-md-6 col-12 product_stock">
                                                        {!! Form::label('', __('Stock Management'), ['class' => 'form-label']) !!}<br>
                                                        <label name="trending" value=""><small>Disabled in <a
                                                                    href="{{ route('setting.index') . '#Brand_Setting ' }}">
                                                                    store
                                                                    setting</a></small></label>
                                                    </div>
                                                @endif
                                                <div class="product-stock-top-inner px-3">
                                                    @if ($stock_management == 'on')
                                                        <div class="row row-gap" id="options">
                                                            <div class="col-md-6 col-12 product_stock">
                                                                {!! Form::label('', __('Stock'), ['class' => 'form-label']) !!}
                                                                {!! Form::number('product_stock', null, ['class' => 'form-control productStock']) !!}
                                                            </div>
                                                            <div class="col-md-6 col-12">
                                                                {!! Form::label('', __('Low stock threshold'), ['class' => 'form-label']) !!}
                                                                {!! Form::number('low_stock_threshold', $low_stock_threshold, ['class' => 'form-control', 'min' => '0']) !!}
                                                            </div>
                                                            <div class="col-12 mb-3">
                                                                {!! Form::label('', __('Allow BackOrders:'), ['class' => 'form-label']) !!}
                                                                <div class="form-check m-1">
                                                                    <input type="radio" id="not_allow"
                                                                        value="not_allow" name="stock_order_status"
                                                                        class="form-check-input code" checked="checked">
                                                                    <label class="form-check-label"
                                                                        for="not_allow">{{ __('Do Not Allow') }}</label>
                                                                </div>
                                                                <div class="form-check m-1">
                                                                    <input type="radio" id="notify_customer"
                                                                        value="notify_customer" name="stock_order_status"
                                                                        class="form-check-input code">
                                                                    <label class="form-check-label"
                                                                        for="notify_customer">{{ __('Allow, But notify customer') }}</label>
                                                                </div>
                                                                <div class="form-check m-1">
                                                                    <input type="radio" id="allow" value="allow"
                                                                        name="stock_order_status"
                                                                        class="form-check-input code">
                                                                    <label class="form-check-label"
                                                                        for="allow">{{ __('Allow') }}</label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    @endif
                                                    {{-- @stack('addCartQuantityControlFilds') --}}
                                                </div>
                                                <div class="form-group mt-2">
                                                    {!! Form::label('', __('Stock Status:'), ['class' => 'form-label f-w-800']) !!}
                                                    <div class="col-mb-9 d-flex flex-wrap row-gap">
                                                        <div class="form-check form-check-inline">
                                                            <input class="form-check-input code" type="radio"
                                                                id="in_stock" value="in_stock" name="stock_status"
                                                                checked="checked">
                                                            <label class="form-check-label" for="   ">
                                                                {{ __('In Stock') }}
                                                            </label>
                                                        </div>
                                                        <div class="form-check form-check-inline">
                                                            <input class="form-check-input code" type="radio"
                                                                id="out_of_stock" value="out_of_stock"
                                                                name="stock_status">
                                                            <label class="form-check-label" for="out_of_stock">
                                                                {{ __('Out of stock') }}
                                                            </label>
                                                        </div>
                                                        <div class="form-check form-check-inline">
                                                            <input class="form-check-input code" type="radio"
                                                                id="on_backorder" value="on_backorder"
                                                                name="stock_status">
                                                            <label class="form-check-label" for="on_backorder">
                                                                {{ __('On Backorder') }}
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <input type="hidden" name="variant_product" value="0">
                                    <input type="hidden" name="hs_object_id"
                                        value="{{ $product_service['hs_object_id'] }}">
                                </div>
                                <div class="row mt-3">
                                    <div class="col-6">
                                        <button class="btn btn-outline-secondary d-inline-flex align-items-center"
                                            onClick="changetab('#about-tab')" type="button"><i
                                                class="ti ti-chevron-left me-2"></i>{{ __('Previous') }}</button>
                                    </div>
                                    <div class="col-6 text-end">
                                        <button class="btn btn-primary d-inline-flex align-items-center"
                                            onClick="changetab('#media-tab')" type="button">{{ __('Next') }}<i
                                                class="ti ti-chevron-right ms-2"></i></button>
                                    </div>
                                </div>
                            </div>

                            {{-- Media --}}
                            <div class="tab-pane fade" id="media-tab" role="tabpanel"
                                aria-labelledby="pills-user-tab-4">
                                <div class="row col-12">
                                    {{-- PRODUCT IMAGE --}}
                                    <div class="col-6 form-group">
                                        <label for="product_image"
                                            class="form-label">{{ __('Upload Product Image') }}</label><span
                                            class="text-danger">*</span>
                                        <input type="file" name="product_image[]" id="product_image"
                                            class="form-control custom-input-file" multiple
                                            onchange="previewImages(this);">
                                        @php
                                            $productImagePath = asset(
                                                'packages/workdo/HubSpot/src/resources/assets/image/img01.jpg',
                                            );
                                        @endphp
                                        <div id="imagePreviewContainer" class="mt-2">
                                            <img id="dropzone" src="{{ $productImagePath }}" width="20%"
                                                class="mt-2" alt="Product Image Preview" />
                                        </div>
                                    </div>

                                    {{-- COVER IMAGE --}}
                                    <div class="col-6 form-group">
                                        <label for="cover_image"
                                            class="form-label">{{ __('Upload Cover Image') }}</label><span
                                            class="text-danger">*</span>
                                        <input type="file" name="cover_image" id="cover_image"
                                            class="form-control custom-input-file"
                                            onchange="document.getElementById('upcoverImg').src = window.URL.createObjectURL(this.files[0]);">
                                        @php
                                            $coverImagePath = asset(
                                                'packages/workdo/HubSpot/src/resources/assets/image/img01.jpg',
                                            );
                                        @endphp
                                        <img id="upcoverImg" src="{{ $coverImagePath }}" width="20%" class="mt-2"
                                            alt="Cover Image Preview" />
                                    </div>

                                    {{-- Download product Image --}}
                                    <div class="form-group col-12" id="downloadable-product-div">
                                        <div class="choose-file">
                                            <label for="downloadable_product"
                                                class="form-label">{{ __('Downloadable Product') }}</label>
                                            <input type="file" class="form-control" name="downloadable_product"
                                                id="downloadable_product"
                                                onchange="document.getElementById('downloadable_product').src = window.URL.createObjectURL(this.files[0]);"
                                                multiple>
                                            <div class="invalid-feedback">{{ __('invalid form file') }}</div>
                                        </div>
                                    </div>

                                    {{-- Preview Type --}}
                                    <div class="form-group col-md-6 col-12" id="preview_type">
                                        {{ Form::label('preview_type', __('Preview Type'), ['class' => 'form-label']) }}
                                        {{ Form::select('preview_type', $preview_type, null, ['class' => 'form-control font-style', 'id' => 'preview_type']) }}
                                    </div>
                                    <div class="form-group col-md-6 col-12" id="preview-video-div">
                                        <div class="choose-file">
                                            <label for="preview_video"
                                                class="form-label">{{ __('Preview Video') }}</label>
                                            <input type="file" class="form-control" name="preview_video"
                                                id="preview_video"
                                                onchange="document.getElementById('preview_video').src = window.URL.createObjectURL(this.files[0]);"
                                                multiple>
                                            <div class="invalid-feedback">{{ __('invalid form file') }}</div>
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
                                <div class="row">
                                    <div class="col-6">
                                        <button class="btn btn-outline-secondary d-inline-flex align-items-center"
                                            onClick="changetab('#pricing-tab')" type="button"><i
                                                class="ti ti-chevron-left me-2"></i>{{ __('Previous') }}</button>
                                    </div>

                                    <div class=" col-6 d-flex justify-content-end text-end">
                                        <a class="btn btn-secondary btn-light btn-submit me-3"
                                            href="{{ route('hubspot.product') }}">{{ __('Cancel') }}</a>
                                        <div
                                            class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
                                            <a href="#" class="btn btn-badge btn-primary" id="submit-all"
                                                data-title="{{ __('Create Product') }}" data-toggle="tooltip"
                                                title="{{ __('Create Product') }}">
                                                <i class="ti ti-plus drp-icon"></i> <span
                                                    class="ms-2 me-2">{{ __('Save') }}</span> </a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>
    {{ Form::close() }}
@endsection
@push('scripts')
    <script src="{{ asset('js/jquery-ui.min.js') }}"></script>
    <script>
        // Tab Button
        function changetab(tabname) {
            var someTabTriggerEl = document.querySelector('button[data-bs-target="' + tabname + '"]');
            var actTab = new bootstrap.Tab(someTabTriggerEl);
            actTab.show();
        }

        $(document).ready(function() {

            // Tag
            $('.select2').select2({
                tags: true,
                createTag: function(params) {
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

            // Stock
            $('#options').hide();
            $('.stock_stats').show();
            $(document).on("change", "#enable_product_stock", function() {
                $('#options').prop('checked', false);
                if ($(this).prop('checked')) {
                    $('.stock_stats').hide();
                    $('#options').show();
                } else {
                    $('.stock_stats').show();
                    $('#options').hide();
                }

            });

            // Preview video
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

        // Varient Enable Disable
        $(document).on("change", "#enable_product_variant", function() {
            $('.product-price-div').show();
            $('.product-stock-div').show();
            $('.product-weight').show();
            $('#use_for_variation').addClass("d-none");
            $('.product_price input').prop('readOnly', false);
            $('.product_discount_amount input').prop('readOnly', false);
            $('.product_discount_type input').prop('readOnly', false);
            $('.attribute_options_datas').hide();

            if ($(this).prop('checked') == true) {
                $('.product-price-div').hide();
                $('.product-stock-div').hide();
                $('.product-weight').hide();
                $("#use_for_variation").removeClass("d-none");
                $('.product_price input').prop('readOnly', true);
                $('.product_discount_amount input').prop('readOnly', true);
                $('.product_discount_type input').prop('readOnly', true);
                $('.attribute_options_datas').show();

            }
        });

        // Attribute Input
        $(document).on('change', '#attribute_id', function() {
            $('#attribute_options').html("<h3 class='d-none'>Variation</h3>");
            var selectedOptions = $("#attribute_id option:selected");
            selectedOptions.each(function() {
                var optionValue = $(this).val();
                var optionText = $(this).text();

                add_more_choice_option(optionValue, optionText);

                var attribute_id = optionValue;

                $.ajax({
                    url: '{{ route('products.attribute_option') }}',
                    type: 'POST',
                    data: {
                        "attribute_id": attribute_id,
                        "_token": "{{ csrf_token() }}",
                    },
                    success: function(data) {
                        $('#loader').fadeOut();
                        $('.attribute').empty();
                        $.each(data, function(key, value) {
                            $('.attribute_options_datas').empty();

                            $(".attribute").append(
                                '<option class="option-item" value="' + key + '">' +
                                value + '</option>');
                        });

                        var multipleCancelButton = new Choices('#attribute' + attribute_id, {
                            removeItemButton: true,
                        });
                    }
                });
            });
        });

        function update_attribute() {
            var variant_val = $('.attribute option:selected')
                .toArray().map(item => item.text).join();
            if (variant_val == '') {
                return;
            }
            $.ajax({
                type: "POST",
                url: '{{ route('products.attribute_combination') }}',
                data: $('#choice_form').serialize() + '&_token=' + $('meta[name="csrf-token"]').attr('content'),
                success: function(data) {
                    $('#loader').fadeOut();
                    $('#attribute_combination').html(data);
                    if (data.length > 1) {
                        $('#quantity').hide();
                    } else {
                        $('#quantity').show();
                    }


                }
            });
        }

        $(document).on("change", ".attribute_option_data", function() {
            var inputValue = $('.attribute_option_data').val();
            if (inputValue != []) {
                var b = $('.attribute_option_data').closest('.parent-clase').find('.input-options');
                var enableVariationValue = b.data('enable-variation');
                var dataid = b.attr('data-id');
                $('.enable_variation_' + dataid).on('change', function() {
                    if ($('.enable_variation_' + dataid).prop('checked') == true) {
                        $('.attribute_combination').show();
                        update_attribute();
                    } else {
                        $('.attribute_options_datas').empty();
                    }
                });
                if ($('.enable_variation_' + dataid).prop('checked') != true) {
                    $('.attribute_options_datas').empty();
                }

            }
        });

        $(document).on("change", "#enable_product_variant", function() {
            if ($(this).prop('checked') == true) {

                $(document).on('change', '.attribute', function() {
                    var b = $(this).closest('.parent-clase').find('.input-options');
                    var enableVariationValue = b.data('enable-variation');
                    var dataid = b.attr('data-id');
                    if ($('.enable_variation_' + dataid).prop('checked') == true) {
                        update_attribute();
                    }
                });
                var b = $(this).closest('.parent-clase').find('.input-options');
                var enableVariationValue = b.data('enable-variation');
                var dataid = b.attr('data-id');
                if ($('.enable_variation_' + dataid).prop('checked') == true) {
                    update_attribute();
                }
            }
        });

        $(document).on('change', '#attribute_id', function() {
            $('#attribute_options').html("<h3 class='d-none'>Variation</h3>");
            $.each($("#attribute_id option:selected"), function() {
                add_more_choice_option($(this).val(), $(this).text());
            });
        });

        // Preview Image
        function previewImages(input) {
            var previewContainer = document.getElementById('imagePreviewContainer');
            previewContainer.innerHTML = ''; // Clear any existing previews

            if (input.files) {
                Array.from(input.files).forEach(function(file) {
                    var imgElement = document.createElement('img');
                    imgElement.classList.add('mt-2');
                    imgElement.style.width = '20%';
                    imgElement.src = window.URL.createObjectURL(file);
                    previewContainer.appendChild(imgElement);
                });
            }
        }

        $('#submit-all').on('click', function() {
            var submitButton = $(this).attr('disabled', true);
            var fd = new FormData();

            var downloadable_product = document.getElementById('downloadable_product').files[0];
            var inputs = $(".downloadable_product_variant");
            var downloadable_product_variant = [];
            for (var i = 0; i < inputs.length; i++) {
                var files = $(inputs[i]).prop('files');
                var dataValue = $(inputs[i]).data('value');
                downloadable_product_variant.push({
                    key: dataValue,
                    file: files
                });
                if (files && files.length > 0) {
                    for (var j = 0; j < files.length; j++) {
                        fd.append(dataValue, files[j]);
                    }
                }
            }

            var other_data = $('#choice_form').serializeArray();
            console.log(other_data);

            $.each(other_data, function(key, input) {
                fd.append(input.name, input.value);
            });

            $('#cover_image')[0].files[0] && fd.append('cover_image', $('#cover_image')[0].files[0]);
            var productImages = $('#product_image')[0].files;
            if (productImages.length > 0) {
                Array.from(productImages).forEach(function(file) {
                    fd.append('product_image[]', file);
                });
            }

            var taxIds = $('#tax_id').val(); // Get selected tax IDs
            if (taxIds && taxIds.length > 0) {
                taxIds.forEach(function(taxId) {
                    fd.append('tax_id[]', taxId); // Append each selected tax_id
                });
            }

            $.ajax({
                url: "{{ route('product.store') }}",
                type: 'POST',
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                data: fd,
                contentType: false,
                processData: false,
                success: function(data) {
                    data.flag === "success" ? window.location.href =
                        "{{ route('product.index') }}?id=1" : show_toastr('Error', data.msg, 'error');
                },
                error: function(xhr) {
                    show_toastr('Error', xhr.responseJSON?.msg || 'An error occurred.', 'error');
                }
            });
        });
    </script>
@endpush
