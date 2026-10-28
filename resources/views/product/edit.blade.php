@extends('layouts.app')

@section('page-title')
{{ __('Product') }}
@endsection

@php
$logo = asset(Storage::url('uploads/profile/'));

/* P2-03-4 / F75. THE UPLOAD RULES THE SERVER WILL ACTUALLY APPLY -- see the same block in
   create.blade.php. `UploadLimits` takes the MINIMUM of the platform setting and PHP's own
   `upload_max_filesize`, because the setting said 2 GB, PHP allows 2 MB per file and 8 MB per
   request, and the Dropzone was advertising its own 256 MB default. */
$ssUpload = \App\Services\UploadLimits::forStore();
@endphp

@section('breadcrumb')
<li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('product.index') }}">{{ __('Product') }}</a></li>
<li aria-current="page" class="ss-breadcrumb__item">{{ __('Edit') }}</li>
@endsection
@section('action-button')
<div class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
    <a href="#" class="btn  btn-primary " id="submit-all" data-title="{{ __('Update Product') }}" data-toggle="tooltip"
        title="{{ __('Update Product') }}">
        <i class="ti ti-plus drp-icon"></i> <span class="ms-2 me-2">{{ __('Update') }}</span> </a>
</div>
@endsection
@php
$plan = \App\Models\Plan::find(\Auth::user()->plan_id);
$stock_management = \App\Models\Utility::GetValueByName('stock_management');
$low_stock_threshold = \App\Models\Utility::GetValueByName('low_stock_threshold');
@endphp
@section('content')
{{-- P2-03-3. `data-ss-dirty-track` opts this form into StoreSyriaDS.wireDirtyTracking, which
     arms on the first real input and reveals the unsaved-changes marker in the action bar below.
     `ss-has-actionbar` is the scroll offset that keeps the sticky bar off a focused field (WCAG
     2.2 "Focus Not Obscured"). The existing `choice_form_edit` class is KEPT: `edit.blade.php`
     selects on it. --}}
{{ Form::model($product, ['route' => ['product.update', $product->id], 'method' => 'PUT', 'enctype' => 'multipart/form-data', 'id' => 'choice_form', 'class' => 'choice_form_edit ss-has-actionbar', 'data-ss-dirty-track' => true]) }}

  <x-ss.error-summary :errors="$errors" class="mb-3" />
{{--
    P2-03-5. THE CONCURRENT-EDIT TOKEN.

    Two people editing the same product is a last-writer-wins race with no signal: the second
    save silently discards the first person's work -- a price, a stock figure -- and both see a
    success message. This carries the `updated_at` the form LOADED, and ProductController::update
    refuses the save if the row has moved since.

    `->toJSON()` rather than the default cast, so the value survives the round trip at full
    precision; the controller parses it back to an instant and compares with `->eq()`, so a
    formatting difference is never read as a conflict.

    No migration: `updated_at` is already there, and Workstream G's rule is not to add a column
    when an existing one answers the question.
--}}
<input type="hidden" name="ss_last_seen_at" value="{{ optional($product->updated_at)->toJSON() }}">

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
                                    {!! Form::text('slug', null, ['class' => 'form-control slug col-12', 'aria-describedby' => 'slug-help']) !!}
                                    {{-- P2-06-10: this carried `title='Sku or Slug'` as a tooltip -- hover-only,
                                         a restatement of its own label, and associated with nothing. --}}
                                    <x-ss.field-help for="slug">{{ __("Part of the product's web address. Leave it blank and it is generated from the name.") }}</x-ss.field-help>
                                </div>
                            </div>
                            <div class="col-sm-6 col-12">
                                <label class="form-label">{{ __('Category') }}</label><span class="text-danger">*</span>
                                <select name="category_id" class="form-control" data-role="tagsinput" id="category_id">
                                    <option value="">{{ __('Select Category') }}</option>
                                    @foreach ($categoryTree as $cat)
                                        <option value="{{ $cat['id'] }}" {{ $cat['id'] ==  $product->category_id ? 'selected' : ''}}>
                                            {!! $cat['name'] !!}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 col-12">
                                {!! Form::label('', __('Tags'), ['class' => 'form-label']) !!}
                                <select name="tag_id[]" class="select2 form-control" id="tag_id" multiple required>
                                    @foreach ($tag as $key => $t)
                                    <option @if (in_array($key, $get_tags)) selected @endif value="{{ $key }}">
                                        {{ $t }}</option>
                                    @endforeach
                                </select>
                            </div>
                            {{-- P2-03-8. Mirrors create.blade.php: the six catalogue-level fields behind
                                 one disclosure. Nothing left the form, no name, value or rule changed. A
                                 closed <details> still submits -- see the component for why that matters,
                                 why `tag_id[]` (which carries `required`) is deliberately outside, and
                                 why that `required` rule is a guard against a browser behaviour this
                                 AJAX-submitted editor does not currently trip. --}}
                            <div class="col-12">
                              <x-ss.advanced-fields
                                  :title="__('Advanced options')"
                                  :hint="__('Taxes, brand, label, shipping and weight. These are usually set once for the whole catalogue.')"
                                  :fields="['tax_id[]', 'tax_status', 'brand_id', 'label_id', 'shipping_id', 'product_weight']">
                                <div class="row row-gap">
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
                                    'data-role' => 'tagsinput', 'id' => 'tax_id', ]) !!}   {{-- duplicate id: F68, see create.blade.php --}}
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
                                    'step' => '0.01', 'aria-describedby' => 'product_weight-help']) !!}
                                    <x-ss.field-help for="product_weight">{{ __('Used to work out shipping. Leave blank if your shipping does not depend on weight.') }}</x-ss.field-help>
                                </div>
                                </div>
                              </x-ss.advanced-fields>
                            </div>
                            <div class="col-md-6 col-12 product_price product-weight">
                                {!! Form::label('', __('Price'), ['class' => 'form-label']) !!}<span
                                    class="text-danger">*</span>
                                {!! Form::number('price', null, ['class' => 'form-control', 'min' => '0', 'step' =>
                                '0.01']) !!}
                            </div>
                            <div class="col-md-6 col-12 product-weight">
                                {!! Form::label('', __('Sale Price'), ['class' => 'form-label']) !!}<!--<span
                                    class="text-danger">*</span>-->
                                {!! Form::number('sale_price', null, ['class' => 'form-control', 'min' => '0',
                                'step' => '0.01', 'aria-describedby' => 'sale_price-help']) !!}
                                <x-ss.field-help for="sale_price">{{ __('The discounted price customers pay. It must be lower than the price.') }}</x-ss.field-help>
                            </div>
                        </div>
                 
                              <div class="col-md-6 col-12 product-weight">
    {!! Form::label('', __('Cost Price'), ['class' => 'form-label']) !!}
    <span class="text-danger">*</span>
    {!! Form::number('cost_price', null, [
        'class' => 'form-control',
        'min' => '0',
        'step' => '0.01',
        'required' => true,
        'aria-describedby' => 'cost_price-help',
    ]) !!}
    <x-ss.field-help for="cost_price">{{ __('What the item costs you. Required, cannot exceed the price, and never shown to customers.') }}</x-ss.field-help>
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
                                <x-ss.choice-group name="stock_status" :legend="__('Stock Status:')"
                                  :hint= + HINT1 + >
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
                                </x-ss.choice-group>
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
                                        'form-control', 'min' => '0', 'aria-describedby' => 'low_stock_threshold-help']) !!}
                                        <x-ss.field-help for="low_stock_threshold">{{ __('The product list shows Low stock at or below this number. Leave 0 to use the store default.') }}</x-ss.field-help>
                                    </div>
                                    <div class="col-12 mb-3">
                                        <x-ss.choice-group name="stock_order_status" :legend="__('Allow BackOrders:')"
                                          :hint= + HINT2 + >
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
                                        </x-ss.choice-group>
                                    </div>
                                </div>
                            @endif
                            @stack('editCartQuantityControlFilds')
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
                                                        <input type="hidden" name="variant_product" value="0">
                                                        {!! Form::checkbox('variant_product', 1, null, [ 'class' =>
                                                                                    'form-check-input enable_product_variant', 'id' =>
                                                                                    'enable_product_variant', ]) !!}
                                                        <label class="form-check-label" for="enable_product_variant"></label>
                                                    </div>
                                                    {!! Form::label('enable_product_variant', __('Display Variants'), ['class' => 'form-label']) !!}
                                                </div>
                                            </div>
                                            <div class="col-xl-3 col-lg-6 col-sm-6 col-12">
                                                <div class="stock-main-opts align-items-center d-flex">
                                                    <div class="form-check form-switch">
                                                        <input type="hidden" name="trending" value="0">
                                                        {!! Form::checkbox('trending', 1, null, ['class' =>
                                                            'form-check-input', 'id' => 'trending_product']) !!}
                                                        <label class="form-check-label" for="trending_product"></label>
                                                    </div>
                                                    {!! Form::label('trending_product', __('Trending'), ['class' => 'form-label']) !!}
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
                                                    {!! Form::label('status', __('Display Product'), ['class' => 'form-label']) !!}
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
                                                    {!! Form::label('enable_custom_field', __('Custom  Field'), ['class' => 'form-label']) !!}
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
                                            {{-- P2-03-4: this string was hardcoded English. --}}
                                            <span>{{ __('Drop files here to upload') }}</span>
                                            {{-- P2-03-4 / F75: state what the SERVER accepts. --}}
                                            <span class="ss-upload-hint ss-upload-hint--on-light" id="ss-gallery-upload-hint"
                                                data-ss-upload-hint>
                                                @if (!empty($ssUpload['extensions']))
                                                    {{ __('Accepted formats') }}:
                                                    {{ strtoupper(implode(', ', $ssUpload['extensions'])) }}.
                                                @endif
                                                {{ __('Up to :size per file', ['size' => $ssUpload['max_label']]) }}.
                                                {{ __('Up to :total for all files together', ['total' => $ssUpload['request_max_label']]) }}.
                                            </span>
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
                                    {{-- P2-03-4 / F75: `accept` is the server's own configured list, verbatim --
                                         narrowing it here would newly block a `pdf` or `csv` that works today. --}}
                                    <input type="file" name="cover_image" id="cover_image"
                                        class="form-control custom-input-file"
                                        @if (!empty($ssUpload['accept'])) accept="{{ $ssUpload['accept'] }}" @endif
                                        aria-describedby="ss-cover-upload-hint"
                                        onchange="document.getElementById('upcoverImg').src = window.URL.createObjectURL(this.files[0]);"
                                        multiple>
                                    <span class="ss-upload-hint" id="ss-cover-upload-hint" data-ss-upload-hint>
                                        @if (!empty($ssUpload['extensions']))
                                            {{ __('Accepted formats') }}:
                                            {{ strtoupper(implode(', ', $ssUpload['extensions'])) }}.
                                        @endif
                                        {{ __('Up to :size per file', ['size' => $ssUpload['max_label']]) }}.
                                    </span>
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
                
                <div class="card border">
                        <div class="card-body p-3 pb-0 ">
                            <div class="row row-gap">
                                <div class="col-md-12">
                                    {!! Form::label('', __('Product Attribute'), ['class' => 'form-label']) !!}
                                    {!! Form::select('attribute_id[]', $ProductAttribute, $get_datas, [ 'class' =>
                                    'form-control product_attribute attribute_id', 'multiple' => 'multiple', 'data-role'
                                    => 'tagsinput', 'id' => 'attribute_id', ]) !!}
                                    <small>{{ __('Choose the attributes of this product and then input values of each attribute') }}</small>
                                </div>
                                <div class="attribute_options" id="attribute_options">
                                    @if ($product->product_attribute == 'NULL')
                                    @else
                                    @if (isset($product->product_attribute))
                                    @foreach (json_decode($product->product_attribute) as $key => $choice_option)
                                    @php

                                    $value = implode(',', $choice_option->values);
                                    $idsArray = explode('|', $value);
                                    $get_datas = \App\Models\ProductAttributeOption::whereIn(
                                    'id',
                                    $idsArray,
                                    )
                                    ->get()
                                    ->pluck('terms')
                                    ->toArray();
                                    $get_data = implode(',', $get_datas);
                                    $option = \App\Models\ProductAttributeOption::where(
                                    'attribute_id',
                                    $choice_option->attribute_id,
                                    )
                                    ->get()
                                    ->pluck('terms')
                                    ->toArray();

                                    $attribute_id = $choice_option->attribute_id;

                                    $visible_attribute = isset(
                                    $choice_option->{'visible_attribute_' . $attribute_id},
                                    )
                                    ? $choice_option->{'visible_attribute_' . $attribute_id}
                                    : 0;
                                    $for_variation = isset(
                                    $choice_option->{'for_variation_' . $attribute_id},
                                    )
                                    ? $choice_option->{'for_variation_' . $attribute_id}
                                    : 0;
                                    @endphp

                                    <div class="card">
                                        <div class="card-body">
                                            <input type="hidden" name="attribute_no[]"
                                                value="{{ $choice_option->attribute_id }}">
                                            <div class="form-group row col-12">
                                                <div class="form-group col-md-6">
                                                    <label
                                                        for="attribute_id">{{ \App\Models\ProductAttribute::find($choice_option->attribute_id)->name }}:</label>
                                                </div>
                                                <div
                                                    class="form-group col-md-6 text-end d-flex all-button-box justify-content-md-end justify-content-center">
                                                    <a href="#" class="btn btn-sm btn-primary add_attribute btn-badge"
                                                        data-ajax-popup="true"
                                                        data-title="{{ __('Add Attribute Option') }}" data-size="md"
                                                        data-url="{{ route('product-attribute-option.create', $choice_option->attribute_id) }}"
                                                        data-toggle="tooltip">
                                                        <i class="ti ti-plus">{{ __('Add Attribute Option') }}</i></a>
                                                </div>
                                            </div>
                                            <div class="form-group row col-12 parent-clase">
                                                <div class="form-group col-md-5">
                                                    <div class="form-chec1k form-switch">
                                                        {!! Form::hidden('visible_attribute_' .
                                                        $choice_option->attribute_id, 0) !!}
                                                        {!! Form::checkbox('visible_attribute_' .
                                                        $choice_option->attribute_id, 1, $visible_attribute == 1, [
                                                        'class' => 'form-check-input',
                                                        'id' => 'visible_attribute_' . $choice_option->attribute_id,
                                                        ]) !!}
                                                        {!! Form::label('visible_attribute_' . $choice_option->attribute_id, __('Visible on the product page'), [
                                                        'class' => 'form-check-label',
                                                        ]) !!}
                                                    </div>
                                                    <div style="margin-top: 9px;"></div>
                                                    <div class="use_for_variation form-chec1k form-switch">

                                                        {!! Form::hidden('for_variation_' .
                                                        $choice_option->attribute_id, 0) !!}
                                                        {!! Form::checkbox('for_variation_' .
                                                        $choice_option->attribute_id, 1, $for_variation == 1, [
                                                        'class' => 'form-check-input input-options enable_variation_' .
                                                        $choice_option->attribute_id,
                                                        'id' => 'for_variation_' . $choice_option->attribute_id,
                                                        'data-enable-variation' => 'enable_variation_' .
                                                        $choice_option->attribute_id,
                                                        'data-id' => $choice_option->attribute_id,
                                                        ]) !!}
                                                        {!! Form::label('for_variation_' . $choice_option->attribute_id, __('Used for variations'), [
                                                        'class' => 'form-check-label',
                                                        ]) !!}
                                                    </div>
                                                </div>
                                                <div class="form-group col-md-7">
                                                    <select
                                                        name="attribute_options_{{ $choice_option->attribute_id }}[]"
                                                        data-role="tagsinput"
                                                        id="attribute_options_{{ $choice_option->attribute_id }}"
                                                        multiple class="attribute_option_data">
                                                        @foreach ($option as $key => $f)
                                                        <option @if (in_array($f, $get_datas)) selected @endif>
                                                            {{ $f }}
                                                        </option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    @endforeach
                                    @endif
                                    @endif
                                </div>
                                <div class="attribute_combination" id="attribute_combination">
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
                <div class="price-info-title p-3 border-bottom d-flex align-items-center  justify-content-between">
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
                            @php
                                $customFieldData = json_decode($product->custom_field, true);
                                $customFieldData = is_array($customFieldData) ? $customFieldData : [];
                            @endphp
                            @foreach ($customFieldData as $item)
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
                        @stack('CustomFieldsEditView')

                    </div>
                </div>
            </div>
            
            @stack('editsizeguidefields')
            @stack('editwholesalefields')
            @stack('EditProductPageSetting')
        </div>
      </div>
    </div>
  </div>
  {{--
      P2-03-3 -- STICKY SAVE, for the same measured reason as the create form: the only Save is
      `#submit-all` in the page header, outside this form, and it scrolls off the top.

      IT CLICKS THE EXISTING CONTROL rather than submitting. `edit.blade.php` binds its own
      FormData assembly to `#submit-all` -- including the `ss_last_seen_at` concurrency token
      P2-03-5 added -- and a second submit path would have to keep that in step. Proxying means
      there is one implementation and the conflict detection cannot be bypassed by using the new
      button instead of the old one.
  --}}
  <x-ss.actionbar :label="__('Save or discard your changes')">
    <a href="#" class="btn btn-primary ss-btn"
       data-ss-click-proxy="#submit-all"
       data-title="{{ __('Update Product') }}">
      <i class="ti ti-device-floppy" aria-hidden="true"></i>
      <span class="ms-2 me-2">{{ __('Save') }}</span>
    </a>
  </x-ss.actionbar>

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

            if ($('#enable_custom_field').prop('checked') == true) {
                $('#custom_value').show();
            }

            if ($('#enable_product_variant').prop('checked') == true) {
                $('.product-price-div').hide();
                $('.product-stock-div').hide();
                $('.product-weight').hide();
            }

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

        function type() {
            if ($('#enable_product_stock').is(":checked") == true) {
                $("#options").show();
                $('.stock_div_status').hide();
            } else {
                $("#options").hide();
                $('.stock_div_status').show();
            }
        }

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
        
        $(document).on("change", ".product_attribute", function() {
            if ($('.enable_product_variant').prop('checked') == true) {
                $(".use_for_variation").removeClass("d-none");
            } else {
                $(".use_for_variation").addClass("d-none");
            }
        });

        $(document).on("change", "#enable_custom_field", function() {
            $('#custom_value').hide();
            if ($(this).prop('checked') == true) {
                $('#custom_value').show();
            }
        });

        $('#custom_field_repeater_basic').repeater({
                initEmpty: false,
                defaultValues: {
                    'text-input': 'foo'
                },
                show: function () {
                    $(this).slideDown();
                },
                hide: function (deleteElement) {
                    // Check if there is more than one field before allowing deletion
                    if ($('#custom_field_repeater_basic [data-repeater-item]').length > 1) {
                        $(this).slideUp(deleteElement);
                    } else {
                        // Show an error message if attempting to delete the last field
                        show_toastr('Error', 'At least one field is required.', 'error');
                    }
                }
        });

        // Manually add a new item when the "Add More" button is clicked
        $('.custom_field_repeater').on('click', function (e) {
            e.preventDefault();

            // Clone the last repeater item, clear its values, and append it to the repeater list
            var $repeaterList = $('#custom_field_repeater_basic [data-repeater-list]');
            var $items = $repeaterList.children();
            var index = $items.length;

            // Clone the last item
            var $newItem = $items.last().clone();

            // Clear input values and update name attributes with new index
            $newItem.find('input, select, textarea').each(function () {
                var $input = $(this);

                // Clear the value
                $input.val('');

                // Update the name attribute
                var name = $input.attr('name');
                if (name) {
                    // Replace the index inside square brackets
                    var updatedName = name.replace(/\[\d+\]/, '[' + index + ']');
                    $input.attr('name', updatedName);
                }
            });

            // Append the new item to the repeater list
            $repeaterList.append($newItem);
        });

        $(document).on('click', '.deleteRecord', function() {
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

        // display variant hide show
        $(document).on("change", "#enable_product_variant", function() {
            $('.product-weight').show();
            $('.product-stock-div').show();
            $('#Product_Variant_Select').hide();
            $('.Product_Variant_atttt').hide();
            $('.attribute_combination').hide();
            $('.product-price-div').show();
            if ($(this).prop('checked') == true) {
                $('.product-price-div').hide();
                $('.product-stock-div').hide();
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
                url: '{{ route('products.attribute_combination_data') }}',
                type: "POST",
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
                    /* P2-03-4 / F75. maxFilesize is MEGABYTES in Dropzone and its default is 256 --
                       128x the real per-file ceiling. addRemoveLinks lets a merchant take a queued
                       file back out, which was previously impossible without reloading the page. */
                    maxFilesize: {{ max(1, (int) round($ssUpload['max_kb'] / 1024)) }},
                    addRemoveLinks: true,
                    dictRemoveFile: @json(__('Remove')),
                    dictFileTooBig: @json(__('That file is too large. The maximum is :max.', ['max' => $ssUpload['max_label']])),
                    dictInvalidFileType: @json(__('That file type is not accepted.')),
                    autoProcessQueue: false,
                    uploadMultiple: true,
                    /* P2-03-4 / F75. WAS `a ? null : "image/*"`, and this element carries
                       `data-dropzone-multiple`, so it took the `null` branch and accepted ANY file
                       type while the server refuses everything outside its configured list. */
                    acceptedFiles: @json($ssUpload['accept'] !== '' ? $ssUpload['accept'] : 'image/*'),
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
                    .getAcceptedFiles()[key]); // attach dropzone image element
            });

            var other_data = $('#choice_form').serializeArray();

            $.each(other_data, function(key, input) {
                fd.append(input.name, input.value);
            });

            var checkCartQuantityModule = "{{ module_is_active('CartQuantityControl') ? 'yes' : 'no' }}";

            if (checkCartQuantityModule == 'yes') {

                var cartQuantityValidationCheck = $(".cartQuantityValidationCheck").val();

                if (cartQuantityValidationCheck == 'false') {
                    show_toastr('Error', 'Please correct the error message before submitting the form.');
                    return false;
                } else {
                    $.ajax({
                        url: "{{ route('product.update', $product->id) }}",
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
                                localStorage.setItem('success_msg', data.msg);
                                window.location.href = "{{ route('product.index') }}" + '?id=2';
                            } else {
                                show_toastr('Error', data.msg, 'error');
                                /* F70. The toast says WHAT is wrong; this says WHERE. It marks each failed
                                   field, writes the message beside it, OPENS the collapsed section the field
                                   lives in, and builds the summary -- none of which the server-rendered
                                   path could do, because this save never re-renders the page.
                                   The strings are passed in because store-syria-ds.js has no translator;
                                   omit them and an Arabic merchant reads English. */
                                if (window.StoreSyriaDS && data.errors) {
                                    StoreSyriaDS.applyFormErrors(data.errors, {
                                        form: '#choice_form',
                                        needsAttentionText: @json(__('Needs attention')),
                                        titleText: @json(__('Please correct the highlighted fields'))
                                    });
                                }
                                $('#submit-all').attr('disabled', false);
                            }
                        },
                        error: function(data) {
                            $('#loader').fadeOut();

                            $('#submit-all').attr('disabled', false);
                            // Dropzones.removeFile(file);
                            if (data.error) {
                                show_toastr('Error', data.error, 'error');
                            } else {
                                show_toastr('Error', data, 'error');
                            }
                        },
                    });
                }
            } else {
                $.ajax({
                    url: "{{ route('product.update', $product->id) }}",
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

                            /* P2-03-4 / F75. THE DEFAULT SAVE PATH. There are TWO near-identical AJAX calls
                               here, chosen by `module_is_active('CartQuantityControl')`, and THIS is the `else`
                               -- the one that runs when that module is not enabled, which is the ordinary case.
                               The first version of this fix patched only the other one and measured nothing:
                               the server's warning was in the response (asserted separately as B1) and the
                               merchant still saw no toast, because this branch navigated away without
                               displaying `data.msg` at all.
                               `edit.blade.php` had the same pair and the pre-existing `success_msg` write was
                               likewise on the module-only branch, so by default an edit save showed the
                               merchant nothing either. Both defaults now carry the message. */
                            try { localStorage.setItem('success_msg', data.msg); } catch (e) { /* private mode */ }
                            window.location.href = "{{ route('product.index') }}" + '?id=2';


                        } else {
                            show_toastr('Error', data.msg, 'error');
                            /* F70. The toast says WHAT is wrong; this says WHERE. It marks each failed
                               field, writes the message beside it, OPENS the collapsed section the field
                               lives in, and builds the summary -- none of which the server-rendered
                               path could do, because this save never re-renders the page.
                               The strings are passed in because store-syria-ds.js has no translator;
                               omit them and an Arabic merchant reads English. */
                            if (window.StoreSyriaDS && data.errors) {
                                StoreSyriaDS.applyFormErrors(data.errors, {
                                    form: '#choice_form',
                                    needsAttentionText: @json(__('Needs attention')),
                                    titleText: @json(__('Please correct the highlighted fields'))
                                });
                            }
                            $('#submit-all').attr('disabled', false);
                        }
                    },
                    error: function(data) {
                        $('#loader').fadeOut();

                        $('#submit-all').attr('disabled', false);
                        // Dropzones.removeFile(file);
                        if (data.error) {
                            show_toastr('Error', data.error, 'error');
                        } else {
                            show_toastr('Error', data, 'error');
                        }
                    },
                });
            }
        });  
        
    </script>
@endpush