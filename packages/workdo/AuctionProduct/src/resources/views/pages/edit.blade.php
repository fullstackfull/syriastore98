@extends('layouts.app')

@section('page-title')
    {{ __('Auction Product') }}
@endsection

@php
    $logo = asset(Storage::url('uploads/profile/'));
@endphp

@section('breadcrumb')
    <li class="breadcrumb-item" aria-current="page"><a
            href="{{ route('auctionproduct.index') }}">{{ __('Auction Product') }}</a></li>
    <li class="breadcrumb-item" aria-current="page">{{ __('Edit') }}</li>
@endsection
@section('action-button')
    <div class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
        <a href="#" class="btn  btn-primary " id="submit-all" data-title="{{ __('Update Auction Product') }}"
            data-toggle="tooltip" title="{{ __('Update Auction Product') }}">
            <i class="ti ti-plus drp-icon"></i> <span class="ms-2 me-2">{{ __('Update') }}</span> </a>
    </div>
@endsection
@php
    $plan = \App\Models\Plan::find(\Auth::user()->plan_id);
    $theme_name = !empty(APP_THEME()) ? APP_THEME() : env('DATA_INSERT_APP_THEME');
    $stock_management = \App\Models\Utility::GetValueByName('stock_management', $theme_name);
    $low_stock_threshold = \App\Models\Utility::GetValueByName('low_stock_threshold', $theme_name);
@endphp
@section('content')
    {{ Form::model($product, ['route' => ['auctionproduct.update', $product->id], 'method' => 'PUT', 'enctype' => 'multipart/form-data', 'id' => 'choice_form', 'class' => 'choice_form_edit']) }}
        <div class="row product-page-info">
            <div class="col-md-12">
                <div class="row">
                    <div class="col-lg-7 col-md-12 col-12">
                        <div class="border rounded">
                            <h5 class="mb-0 p-3 border-bottom">{{__('Main Informations')}}</h5>
                            <div class="card-body">
                                <div class="product-info-top p-3 border-bottom">
                                    <div class="row row-gap">
                                        <div class="col-12">
                                            <input type="hidden" name="id" value="{{ $product->id }}">
                                            {!! Form::label('', __('Name'), ['class' => 'form-label']) !!}<span
                                                class="text-danger">*</span>
                                            {!! Form::text('name', null, ['class' => 'form-control name']) !!}
                                        </div>
                                        <div class="col-12 parmalink ">
                                            {!! Form::label('', __('parmalink'), ['class' => 'form-label col-md-3']) !!}
                                            <div class="d-flex flex-wrap gap-3">
                                                <input class="input-group-text col-12" readonly id="basic-addon2"
                                                    value="{{ $link }}">
                                                {!! Form::text('slug', null, ['class' => 'form-control slug col-12']) !!}
                                            </div>
                                        </div>
                                        <div class="col-sm-6 col-12">
                                            {!! Form::label('', __('Category'), ['class' => 'form-label']) !!}<span
                                                class="text-danger">*</span>
                                            {!! Form::select('category_id', $Category, null, [ 'class' =>
                                            'form-control', 'data-role' => 'tagsinput', 'id' => 'category_id', ]) !!}
                                        </div>
                                        <div class="col-md-6 col-12 switch-width">
                                            {{ Form::label('tax_id', __('Taxs'), ['class' => ' form-label']) }}
                                            <select name="tax_id[]" data-role="tagsinput" id="tax_id" multiple>
                                                @foreach ($Tax as $Key => $tax)
                                                <option @if (in_array($Key, $get_tax)) selected @endif value="{{ $Key }}">
                                                    {{ $tax }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-6 col-12">
                                            {!! Form::label('', __('Tax Status'), ['class' => 'form-label']) !!}
                                            {!! Form::select('tax_status', $Tax_status, null, [ 'class' => 'form-control',
                                            'data-role' => 'tagsinput', 'id' => 'tax_id', ]) !!}
                                        </div>
                                        <div class=" col-sm-6 col-12" data_val='0'>
                                            {!! Form::label('', __('Brand'), ['class' => 'form-label']) !!}
                                            <span>
                                                {!! Form::select('brand_id', $brands, null, [ 'class' => 'form-control',
                                                'data-role' => 'tagsinput', 'id' => 'brand-dropdown', ]) !!}
                                            </span>
                                        </div>
                                        <div class=" col-sm-6 col-12" data_val='0'>
                                            {!! Form::label('', __('Label'), ['class' => 'form-label']) !!}
                                            <span>
                                                {!! Form::select('label_id', $labels, null, [ 'class' => 'form-control',
                                                'data-role' => 'tagsinput', 'id' => 'label-dropdown', ]) !!}
                                            </span>
                                        </div>
                                        <div class="col-12">
                                            {!! Form::label('', __('Tags'), ['class' => 'form-label']) !!}
                                            <select name="tag_id[]" class="select2 form-control" id="tag_id" multiple required>
                                                @foreach ($tag as $key => $t)
                                                <option @if (in_array($key, $get_tags)) selected @endif value="{{ $key }}">
                                                    {{ $t }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-sm-6 col-12">
                                            {!! Form::label('', __('Shipping'), ['class' => 'form-label']) !!}
                                            {!! Form::select('shipping_id', $Shipping, null, [
                                            'class' => 'form-control',
                                            'data-role' => 'tagsinput',
                                            'id' => 'shipping_id',
                                            ]) !!}
                                        </div>
                                        <div class="col-sm-6 col-12 product-weight">
                                            {!! Form::label('', __('Weight(Kg)'), ['class' => 'form-label ']) !!}
                                            {!! Form::number('product_weight', null, ['class' => 'form-control', 'min' => '0',
                                            'step' => '0.01']) !!}
                                        </div>
                                        <div class="col-md-6 col-12 product_price">
                                            {!! Form::label('', __('Price'), ['class' => 'form-label']) !!}<span
                                                class="text-danger">*</span>
                                            {!! Form::number('price', null, ['class' => 'form-control', 'min' => '0', 'step' =>
                                            '0.01']) !!}
                                        </div>
                                        <div class="col-md-6 col-12">
                                            {!! Form::label('', __('Sale Price'), ['class' => 'form-label']) !!}<span
                                                class="text-danger">*</span>
                                            {!! Form::number('sale_price', null, ['class' => 'form-control', 'min' => '0',
                                            'step' => '0.01']) !!}
                                        </div>
                                    </div>
                                </div>
                                <!--Stock code-->
                                <div class="product-stock-div p-3 pb-0">
                                    <h4>{{ __('Product Stock') }}</h4>
                                    <div class="row form-group row-gap">
                                        @if ($stock_management == 'on')
                                            <div class=" col-md-6 col-12">
                                                <div class="product-stock-top d-flex ">
                                                    <div class="form-check form-switch">
                                                        <input type="hidden" name="track_stock" value="0">
                                                        {!! Form::checkbox('track_stock', 1, null, [ 'class' => 'form-check-input enable_product_stock', 'id' => 'enable_product_stock', ]) !!}
                                                        <label class="form-check-label" for="enable_product_stock"></label>
                                                    </div>
                                                    {!! Form::label('', __('Stock Management'), ['class' => 'form-label']) !!}
                                                </div>
                                            </div>
                                        @else
                                            <div class="col-md-6 col-12 product_stock">
                                                {!! Form::label('', __('Stock Management'), ['class' => 'form-label']) !!}<br>
                                                <label name="trending" value="">
                                                    <small>{{ __('Disabled in') }} 
                                                        <a href="{{ route('setting.index') . '#Brand_Setting ' }}"> {{ __('store') }} {{ __('setting') }}</a>
                                                    </small>
                                                </label>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="row row-gap">
                                        <div class="col-12 stock_stats">
                                            {!! Form::label('', __('Stock Status:'), ['class' => 'form-label f-w-800']) !!}
                                            <div class="col-mb-9 d-flex flex-wrap row-gap">
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input code" type="radio" id="in_stock" value="in_stock" name="stock_status" {{ $product->stock_status == 'in_stock' ? 'checked' : '' }}>
                                                    <label class="form-check-label" for="in_stock">
                                                    {{ __('In Stock') }}
                                                    </label>
                                                </div>
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input code" type="radio" id="out_of_stock" value="out_of_stock" name="stock_status" {{ $product->stock_status == 'out_of_stock' ? 'checked' : '' }}>
                                                    <label class="form-check-label" for="out_of_stock">
                                                    {{ __('Out of stock') }}
                                                    </label>
                                                </div>
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input code" type="radio" id="on_backorder" value="on_backorder" name="stock_status" {{ $product->stock_status == 'on_backorder' ? 'checked' : '' }}>
                                                    <label class="form-check-label" for="on_backorder">
                                                    {{ __('On Backorder') }}
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="product-stock-top-inner px-3">
                                    @if ($stock_management == 'on')
                                        <div class="row row-gap" id="options">
                                            <div class="col-md-6 col-12 product_stock">
                                                {!! Form::label('', __('Stock'), ['class' => 'form-label']) !!}
                                                {!! Form::number('product_stock', null, ['class' => 'form-control
                                                    productStock']) !!}
                                            </div>
                                            <div class="col-md-6 col-12">
                                                {!! Form::label('', __('Low stock threshold'), ['class' => 'form-label']) !!}
                                                {!! Form::number('low_stock_threshold', $low_stock_threshold, ['class' =>
                                                'form-control', 'min' => '0']) !!}
                                            </div>
                                            <div class="col-12 mb-3">
                                                {!! Form::label('', __('Allow BackOrders:'), ['class' => 'form-label']) !!}
                                                <div class="form-check m-1">
                                                    <input type="radio" id="not_allow" value="not_allow" name="stock_order_status" class="form-check-input code" {{ $product->stock_order_status == 'not_allow' ? 'checked' : '' }}>
                                                    <label class="form-check-label" for="not_allow">{{ __('Do Not Allow') }}</label>
                                                </div>
                                                <div class="form-check m-1">
                                                    <input type="radio" id="notify_customer" value="notify_customer" name="stock_order_status" class="form-check-input code" {{ $product->stock_order_status == 'notify_customer' ? 'checked' : '' }}>
                                                    <label class="form-check-label"
                                                    for="notify_customer">{{ __('Allow, But notify customer') }}</label>
                                                </div>
                                                <div class="form-check m-1">
                                                    <input type="radio" id="allow" value="allow" name="stock_order_status" class="form-check-input code" {{ $product->stock_order_status == 'allow' ? 'checked' : '' }}>
                                                    <label class="form-check-label" for="allow">{{ __('Allow') }}</label>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                                <div class="product-stock-bottom p-3 pb-0 border-top">
                                    <div class="col-12">
                                        <h5 class="mb-3">{{ __('Main Informations') }}</h5>
                                        <div class="card">
                                            <div class="card-body ms-2">
                                                <div class="row row-gap align-items-center">
                                                    <div class="col-xl-3 col-lg-6 col-sm-6 col-12">
                                                        <div class="stock-main-opts align-items-center d-flex">
                                                            <div class="form-check form-switch">
                                                                <input type="hidden" name="trending" value="0">
                                                                {!! Form::checkbox('trending', 1, null, ['class' =>
                                                                    'form-check-input', 'id' => 'trending_product']) !!}
                                                                <label class="form-check-label" for="trending_product"></label>
                                                            </div>
                                                            {!! Form::label('', __('Trending'), ['class' => 'form-label']) !!}
                                                        </div>
                                                    </div>
                                                    <div class="col-xl-3 col-lg-6 col-sm-6 col-12">
                                                        <div class="stock-main-opts align-items-center d-flex">
                                                            <div class="form-check form-switch">
                                                                <input type="hidden" name="status" value="0">
                                                                {!! Form::checkbox('status', 1, null, ['class' =>
                                                                    'form-check-input', 'id' => 'status']) !!}
                                                                <label class="form-check-label" for="status"></label>
                                                            </div>
                                                            {!! Form::label('', __('Display Product'), ['class' => 'form-label']) !!}
                                                        </div>
                                                    </div>
                                                    <div class="col-xl-3 col-lg-6 col-sm-6 col-12">
                                                        <div class="stock-main-opts align-items-center d-flex">
                                                            <div class="form-check form-switch">
                                                                <input type="hidden" name="custom_field_status" value="0">
                                                                {!! Form::checkbox('custom_field_status', 1, null, [ 'class'
                                                                    => 'form-check-input', 'id' => 'enable_custom_field', ]) !!}
                                                                <label class="form-check-label" for="enable_custom_field"></label>
                                                            </div>
                                                            {!! Form::label('', __('Custom  Field'), ['class' => 'form-label']) !!}
                                                        </div>
                                                    </div>
                                                </div>

                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    
                        <!--Image code-->
                        <div class="product-image-sec border rounded mb-4 mt-4">
                            <h5 class="mb-3 p-3 border-bottom">{{ __('Product Image') }}</h5>
                            <div class="card p-3 pt-0">
                                <div class="card-body">
                                    <div class="row row-gap">
                                        <div class="col-12">
                                            {{ Form::label('sub_images', __('Upload Product Images'), ['class' => 'form-label f-w-800']) }}<span
                                                class="text-danger">*</span>
                                            <div class="dropzone dropzone-multiple" data-toggle="dropzone1"
                                                data-dropzone-url="http://" data-dropzone-multiple>
                                                <!-- Dropzone message with icon -->
                                                <div class="dz-message d-flex flex-column mb-2">
                                                    <img src="{{ asset('assets/images/notification/upload_icon.png') }}"
                                                        alt="Upload Icon"
                                                        style="width: 50px; height: 50px; margin:0 auto 10px;">
                                                    <span>Drop files here to upload</span>
                                                </div>

                                                <div class="fallback">
                                                    <div class="custom-file">
                                                        <input type="file" name="file" id="dropzone-1"
                                                            class="custom-file-input"
                                                            onchange="document.getElementById('dropzone').src = window.URL.createObjectURL(this.files[0])"
                                                            multiple>
                                                        <img id="dropzone" src="" width="20%" class="mt-2" />
                                                        <label class="custom-file-label"
                                                            for="customFileUpload">{{ __('Choose file') }}</label>
                                                    </div>
                                                </div>

                                                <ul
                                                    class="dz-preview dz-preview-multiple list-group list-group-lg list-group-flush">
                                                    <li class="list-group-item px-0">
                                                        <div class="row align-items-center">
                                                            <div class="col-auto">
                                                                <div class="avatar">
                                                                    <img class="rounded" src="" alt="Image placeholder"
                                                                        data-dz-thumbnail>
                                                                </div>
                                                            </div>
                                                            <div class="col">
                                                                <h6 class="text-sm mb-1" data-dz-name>...</h6>
                                                                <p class="small text-muted mb-0" data-dz-size></p>
                                                            </div>
                                                            <div class="col-auto">
                                                                <a href="#" class="dropdown-item btn-badge" data-dz-remove>
                                                                    <i class="fas fa-trash-alt"></i>
                                                                </a>
                                                            </div>
                                                        </div>
                                                    </li>
                                                </ul>
                                            </div>

                                            <div class="form-group pt-3">
                                                <div class="row gy-3 gx-3">
                                                    @foreach ($product_image as $file)
                                                    <div class="col-sm-4 product_Image {{ 'delete_img_' . $file->id }}"
                                                        data-id="{{ $file->id }}">
                                                        <div
                                                            class="position-relative p-2 border rounded border-primary overflow-hidden rounded">
                                                            <img src="{{ get_file($file->image_path) }}" alt=""
                                                                class="w-100">
                                                            <div
                                                                class="position-absolute text-center top-50 end-0 start-0 pb-3">
                                                                <a href="{{ get_file($file->image_path) }}"
                                                                    download="" data-original-title="{{ __('Download') }}"
                                                                    class="btn btn-sm btn-primary me-2"><i
                                                                        class="ti ti-download"></i></a>
                                                                <a href="javascript::void(0)"
                                                                    class="btn btn-sm btn-danger deleteRecord"
                                                                    name="deleteRecord" data-id="{{ $file->id }}"><i
                                                                        class="ti ti-trash"></i></a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <label for="cover_image"
                                                class="form-label">{{ __('Upload Cover Image') }}</label><span
                                                class="text-danger">*</span>
                                            <input type="file" name="cover_image" id="cover_image"
                                                class="form-control custom-input-file"
                                                onchange="document.getElementById('upcoverImg').src = window.URL.createObjectURL(this.files[0]);"
                                                multiple>
                                            <img id="upcoverImg"
                                                src="{{ get_file($product->cover_image_path) }}" width="20%"
                                                class="mt-2" />
                                        </div>

                                        <div class=" col-12" id="downloadable-product-div">
                                            <div class="choose-file">
                                                <label for="downloadable_product"
                                                    class="form-label">{{ __('Downloadable Product') }}</label>
                                                <input type="file" class="form-control" name="downloadable_product"
                                                    id="downloadable_product"
                                                    onchange="document.getElementById('downloadable_product').src = window.URL.createObjectURL(this.files[0]);"
                                                    multiple>
                                                @if ($product->downloadable_product != '')
                                                <img src="{{ get_file($product->downloadable_product) }} "
                                                    width="20%">
                                                @endif
                                                <div class="invalid-feedback">{{ __('invalid form file') }}</div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 col-12" id="preview_type">
                                            {{ Form::label('preview_type', __('Preview Type'), ['class' => 'form-label']) }}
                                            {{ Form::select('preview_type', $preview_type, null, ['class' => 'form-control font-style', 'id' => 'preview_type']) }}
                                        </div>
                                        <div class=" col-md-6 col-12" id="preview-video-div">
                                            <div class="choose-file">
                                                <label for="preview_video"
                                                    class="form-label">{{ __('Preview Video') }}</label>
                                                <input type="file" class="form-control" name="preview_video"
                                                    id="preview_video"
                                                    value="{{ $product->preview_type == 'Video File' ? $product->preview_content : '' }}"
                                                    onchange="document.getElementById('preview_video').src = window.URL.createObjectURL(this.files[0]);"
                                                    multiple>
                                                @if ($product->preview_content != '')
                                                <a href="{{ get_file($product->preview_content) }}"
                                                    target="_blank">
                                                    <video height="100px" controls="" class="mt-2">
                                                        <source id="preview_video"
                                                            src="{{ get_file($product->preview_content) }}"
                                                            type="video/mp4">
                                                    </video>
                                                </a>
                                                @endif
                                                <div class="invalid-feedback">{{ __('invalid form file') }}</div>
                                            </div>
                                        </div>

                                        <div class=" col-md-6 col-12 ml-auto d-none" id="preview-iframe-div">
                                            {{ Form::label('preview_iframe', __('Preview iFrame'), ['class' => 'form-label']) }}
                                            <textarea name="preview_iframe" id="preview_iframe"
                                                class="form-control font-style" rows="2"
                                                value="">{{ $product->preview_type == 'iFrame' ? $product->preview_content : '' }}</textarea>
                                        </div>

                                        <div class=" col-md-6 col-12" id="video_url_div">
                                            {{ Form::label('video_url', __('Video URL'), ['class' => 'form-label']) }}
                                            <input class="form-control font-style" name="video_url" type="text"
                                                id="video_url"
                                                value="{{ $product->preview_type == 'Video Url' ? $product->preview_content : '' }}">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5 col-12">
                        <div class="product-info-right border mb-4 rounded">
                            <h5 class="mb-0 p-3 border-bottom">{{ __('About product') }}</h5>
                            <div class="card p-3 pb-0">
                                <div class="card-body">
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
                        </div>
                        <div class="product-price-info border rounded mb-4" style="display: none" id="custom_value">
                            <div
                                class="price-info-title p-3 border-bottom d-flex align-items-center  justify-content-between">
                                <h5 class="mb-0">{{__('Custom Field')}}</h5>
                                <a href="javascript:;" data-repeater-create
                                    class="custom_field_repeater btn-badge btn-sm btn btn-light-primary">
                                    <i class="ti ti-plus"></i>
                                </a>
                            </div>
                            <div class="card-body p-3">
                                <div id="custom_field_repeater_basic">
                                    <div data-repeater-list="custom_field_repeater_basic">
                                        @if (!empty($product->custom_field))
                                            @foreach (json_decode($product->custom_field, true) as $item)
                                                <div data-repeater-item class="mt-1">
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            {!! Form::label('', __('Custom Field'), ['class' => 'form-label']) !!}
                                                            {!! Form::text('custom_field', $item['custom_field'], ['class' =>
                                                            'form-control', 'placeholder' => __('Enter Custom Field')]) !!}
                                                        </div>
                                                        <div class="col-md-5">
                                                            {!! Form::label('', __('Custom Value'), ['class' => 'form-label']) !!}
                                                            {!! Form::text('custom_value', $item['custom_value'], [ 'id' =>
                                                            'answer', 'rows' => 4, 'class' => 'form-control', 'placeholder' =>
                                                            __('Enter Custom Value') ]) !!}
                                                        </div>
                                                        <div class="col-md-1">
                                                            <label></label>
                                                            <a href="javascript:;" data-repeater-delete
                                                                class="btn field-trash-btn btn-sm btn-light-danger mt-3 mt-md-8 btn-badge"
                                                                data-bs-toggle="tooltip" title="{{__('Delete')}}">
                                                                <i class="ti ti-trash"></i>
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        @else
                                            <div data-repeater-item class="mt-1">
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        {!! Form::label('', __('Field'), ['class' => 'form-label']) !!}
                                                        {!! Form::text('custom_field', null, ['class' => 'form-control',
                                                        'placeholder' => __('Enter Custom Field')]) !!}
                                                    </div>
                                                    <div class="col-md-5">
                                                        {!! Form::label('', __('Value'), ['class' => 'form-label']) !!}
                                                        {!! Form::text('custom_value', null, ['id' => 'answer', 'rows' => 2,
                                                        'class' => 'form-control','placeholder' => __('Enter Custom Value')])
                                                        !!}
                                                    </div>
                                                    <div class="col-md-1">
                                                        <label></label>
                                                        <a href="javascript:;" data-repeater-delete
                                                            class="btn field-trash-btn btn-sm btn-light-danger mt-3 mt-md-8 btn-badge"
                                                            data-bs-toggle="tooltip" title="{{__('Delete')}}">
                                                            <i class="ti ti-trash"></i>
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" name="variant_product" value="0">
                    </div>
                </div>
            </div>
        </div>
    {!! Form::close() !!}
@endsection

@push('custom-script')
    <script src="{{ asset('js/jquery-ui.min.js') }}"></script>
    <script src="{{ asset('js/repeater.js') }}"></script>
    <script src="{{ asset('assets/css/summernote/summernote-bs4.js') }}"></script>
    <script>
        $(document).ready(function() {
            attribute_option_data();
            type();

            // tag
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

            //stock
            $(document).on("change", "#enable_product_stock", function() {
                if ($("#enable_product_stock").prop("checked")) {
                    $("#options").show();
                    $('.stock_div_status').hide();
                } else {
                    $("#options").hide();
                    $('.stock_div_status').show();
                }
            });

            function type() {
                if ($('#enable_product_stock').is(":checked") == true) {
                    $("#options").show();
                    $('.stock_div_status').hide();
                } else {
                    $("#options").hide();
                    $('.stock_div_status').show();
                }
            }
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

        $(document).ready(function() {
            if ($('#enable_custom_field').prop('checked') == true) {
                $('#custom_value').show();
            }

            $(document).on("change", "#enable_custom_field", function() {
                $('#custom_value').hide();
                if ($(this).prop('checked') == true) {
                    $('#custom_value').show();
                }
            });
        });
        $('#custom_field_repeater_basic').repeater({
            initEmpty: false,
            defaultValues: {
                'text-input': 'foo'
            },

            show: function() {
                $(this).slideDown();
            },

            hide: function(deleteElement) {
                $(this).slideUp(deleteElement);
            }
        });

        $('.deleteRecord').on('click', function() {
            var id = $(this).data("id");
            $.ajax({

                url: '{{ route('products.file.detele', '__product_id') }}'.replace('__product_id', id),
                type: 'DELETE',
                data: {
                    id: id,
                },
                success: function(data) {
                    $('#loader').fadeOut();
                    $('.delete_img_' + id).hide();
                    if (data.success) {
                        show_toastr('success', data.success, 'success');
                    }
                }

            });
        });
    </script>
    <script>
        // display variant hide show
        $(document).on("change", "#enable_product_variant", function() {
            $('.product-weight').show();
            $('#Product_Variant_Select').hide();
            $('.Product_Variant_atttt').hide();
            $('.attribute_combination').hide();
            $('.product-price-div').show();
            if ($(this).prop('checked') == true) {
                $('.product-price-div').hide();
                $('.product-weight').hide();
                $('#Product_Variant_Select').show();
                $('.Product_Variant_atttt').show();
                $(".use_for_variation").removeClass("d-none");
                var inputValue = $('.attribute_option_data').val();
                if (inputValue != []) {
                    $('.attribute_combination').show();
                }
                attribute_option_data();
            }
        });

        //variation option on off
        if ($('.enable_product_variant').prop('checked') == true) {
            var inputValue = $('.attribute_option_data').val();
            if (inputValue != []) {
                var b = $('.attribute_option_data').closest('.parent-clase').find('.input-options');
                var enableVariationValue = b.data('enable-variation');
                var dataid = b.attr('data-id');
                $('.enable_variation_' + dataid).on('change', function() {
                    if ($('.enable_variation_' + dataid).prop('checked') == true) {
                        update_attribute();
                    } else {
                        $('.attribute_options_datas').empty();
                    }
                });

            }
        }
        //variation option on off
        $(document).on("change", "#enable_product_variant", function() {
            if ($('.enable_product_variant').prop('checked') == true) {

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
            }
        });
        // edit attribute data
        function attribute_option_data() {
            $.ajax({
                type: "PUT",
                url: '{{ route('products.attribute_combination_data') }}',
                data: $('#choice_form').serialize(),
                success: function(data) {
                    $('#loader').fadeOut();
                    $('.attribute_combination').html(data);
                    if (data.length > 1) {
                        $('#quantity').hide();
                    } else {
                        $('#quantity').show();
                    }
                }
            });
        }
        $(document).on('change', '#attribute_id', function() {
            $('#attribute_options').html("<h3 class='d-none'>Variation</h3>");
            var option = $('.attribute_option').val();

            $.each($("#attribute_id option:selected"), function() {
                add_more_choice_option($(this).val(), $(this).text());
                var attribute_id = $(this).val();
                $.ajax({
                    url: '{{ route('products.attribute_option') }}',
                    type: 'POST',
                    data: {
                        "attribute_id": attribute_id,
                        "_token": "{{ csrf_token() }}",
                    },
                    success: function(data) {
                        $('#loader').fadeOut();
                        $.each(data, function(key, value) {
                            $('.attribute_options_datas').empty();
                            $(".attribute").append('<option value="' + value + '">' +
                                value + '</option>');

                        });

                        var multipleCancelButton = new Choices('#attribute' + attribute_id, {
                            removeItemButton: true,
                        });
                    }
                });
            });
        });

        // variations form
        function update_attribute() {
            $.ajax({
                type: "PUT",
                url: '{{ route('products.attribute_combination_edit') }}',
                data: $('#choice_form').serialize(),
                success: function(data) {
                    $('#loader').fadeOut();
                    $('.attribute_combination').html(data);
                    if (data.length > 1) {
                        $('#quantity').hide();
                    } else {
                        $('#quantity').show();
                    }
                }
            });
        }
        $(document).on('change', '.attribute_option_data', function() {
            var b = $(this).closest('.parent-clase').find('.input-options');
            var enableVariationValue = b.data('enable-variation');
            var dataid = b.attr('data-id');
            if ($('.enable_variation_' + dataid).prop('checked') == true) {
                update_attribute();
            }
        });
    </script>

    {{-- Dropzones  --}}
    <script>
        var Dropzones = function() {
            var e = $('[data-toggle="dropzone1"]'),
                t = $(".dz-preview");

            var CSRF_TOKEN = $('meta[name="csrf-token"]').attr('content');
            e.length && (Dropzone.autoDiscover = !1, e.each(function() {
                var e, a, n, o, i;
                e = $(this), a = void 0 !== e.data("dropzone-multiple"), n = e.find(t), o = void 0, i = {
                    url: "{{ route('product.store') }}",
                    headers: {
                        'x-csrf-token': CSRF_TOKEN,
                    },
                    thumbnailWidth: null,
                    thumbnailHeight: null,
                    previewsContainer: n.get(0),
                    previewTemplate: n.html(),
                    maxFiles: 10,
                    parallelUploads: 10,
                    autoProcessQueue: false,
                    uploadMultiple: true,
                    acceptedFiles: a ? null : "image/*",
                    success: function(file, response) {
                        if (response.flag == "success") {
                            show_toastr('success', response.msg, 'success');
                            window.location.href = "{{ route('product.create') }}";
                        } else {
                            show_toastr('Error', response.msg, 'error');
                        }
                    },
                    error: function(file, response) {
                        // Dropzones.removeFile(file);
                        if (response.error) {
                            show_toastr('Error', response.error, 'error');
                        } else {
                            show_toastr('Error', response, 'error');
                        }
                    },
                    init: function() {
                        var myDropzone = this;

                        this.on("addedfile", function(e) {
                            !a && o && this.removeFile(o), o = e
                        })
                    }
                }, n.html(""), e.dropzone(i)
            }))
        }()

        $('#submit-all').on('click', function() {

            $('#submit-all').attr('disabled', true);
            var fd = new FormData();

            var file = document.getElementById('cover_image').files[0];
            var preview_video = document.getElementById('preview_video').files[0];

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
            // Append Summernote content to FormData

            if (file) {
                fd.append('cover_image', file);
            }
            if (preview_video) {
                fd.append('preview_video', preview_video);
            }
            if (downloadable_product) {
                fd.append('downloadable_product', downloadable_product);
            }
            var files = $('[data-toggle="dropzone1"]').get(0).dropzone.getAcceptedFiles();
            $.each(files, function(key, file) {
                fd.append('product_image[' + key + ']', $('[data-toggle="dropzone1"]')[0].dropzone
                    .getAcceptedFiles()[key]);
            });

            var other_data = $('#choice_form').serializeArray();

            $.each(other_data, function(key, input) {
                fd.append(input.name, input.value);
            });
            $.ajax({
                url: "{{ route('auctionproduct.update', $product->id) }}",
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                data: fd,
                contentType: false,
                processData: false,
                type: 'POST',
                success: function(data) {
                    $('#loader').fadeOut();
                    if (data.flag == "success") {
                        $('#submit-all').attr('disabled', true);
                        window.location.href = "{{ route('auctionproduct.index') }}" + '?id=2';
                    } else {
                        show_toastr('Error', data.msg, 'error');
                        $('#submit-all').attr('disabled', false);
                    }
                },
                error: function(data) {
                    $('#loader').fadeOut();
                    $('#submit-all').attr('disabled', false);
                    if (data.error) {
                        show_toastr('Error', data.error, 'error');
                    } else {
                        show_toastr('Error', data, 'error');
                    }
                },
            });
        });
        // Product Attribute
        $(document).ready(function() {
            $(document).on("change", ".product_attribute", function() {
                if ($('.enable_product_variant').prop('checked') == true) {
                    $(".use_for_variation").removeClass("d-none");
                } else {
                    $(".use_for_variation").addClass("d-none");
                }
            });
        });
    </script>
@endpush
