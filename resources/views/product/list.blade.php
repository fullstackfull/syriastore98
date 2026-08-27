@extends('layouts.app')

@section('page-title')
{{ __('Product') }}
@endsection
@php
$logo = asset(Storage::url('uploads/profile/'));
$admin = getAdminAllSetting();
@endphp
@section('breadcrumb')
<li class="breadcrumb-item" aria-current="page">{{ __('Product') }}</li>
@endsection

@section('action-button')
@permission('Create Product')
<div class="text-end gap-1 d-flex all-button-box justify-content-md-end justify-content-center">
    @if (module_is_active('ImportExport'))
    @permission('product import')
    @include('import-export::import.button', ['module' => 'product'])
    @endpermission
    @permission('product export')
    @include('import-export::export.button', ['module' => 'product'])
    @endpermission
    @endif
    <a href="{{ route('product.index') }}" data-bs-toggle="tooltip" data-bs-original-title="{{ __('List View') }}" class="btn btn-sm btn-primary btn-icon ">
        <i class="ti ti-list"></i>
    </a>
    <a href="{{ route('product.create') }}" class="btn btn-sm btn-primary" data-title="{{ __('Create New Product') }}"
        data-bs-toggle="tooltip" title="{{ __('Add New Product') }}">
        <i class="ti ti-plus"></i>
    </a>
</div>
@endpermission
@endsection

@section('content')
<div class="row grid product-grid">
    @isset($products)
    @foreach ($products as $product)
    <div class="col-sm-6 col-xl-4 col-xxl-3 col-12 All mb-4">
        <div class="card grid-card h-100 mb-0">
            <div class="card-header  border-bottom p-3 d-flex h-100 justify-content-between align-items-start gap-1">
                <div class="d-flex align-items-center gap-2">
                    <td>
                        @if (isset($product->cover_image_path) && !empty($product->cover_image_path))
                        <a href="{{ get_file($product->cover_image_path) }}" target="_blank" class="rounded border-2 border border-primary card-image" style="width: 50px ; height:50px">
                            <img src="{{ get_file($product->cover_image_path) }}" class="rounded h-100 w-100 cover_img{{ $product->id }}"
                                style="object-fit:cover;">
                        </a>
                        @endif
                    </td>

                    <h6 class="mb-0 flex-1 text-break">
                        <a title="{{ $product->name }}" class="">{{ $product->name }}</a>
                    </h6>
                </div>
                <div class="card-header-right">
                    <div class="btn-group card-option">
                        <button type="button" class="btn dropdown-toggle" data-bs-toggle="dropdown"
                            aria-haspopup="true" aria-expanded="false">
                            <i class="feather icon-more-vertical"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a class="dropdown-item"
                                href="{{ route('product.edit', $product->id) }}">
                                <i class="ti ti-pencil me-1"></i> <span>{{ __('Edit') }}</span>
                            </a>
                            <form id="delete-form-{{ $product->id }}"
                                action="{{ route('product.destroy', $product->id) }}"
                                method="POST">
                                @csrf
                                <a class="dropdown-item text-danger delete-popup bs-pass-para show_confirm"
                                    data-confirm="{{ __('Are You Sure?') }}"
                                    data-text="{{ __('This action can not be undone. Do you want to continue?') }}"
                                    data-confirm-yes="delete-form-{{ $product->id }}">
                                    <i class="ti ti-trash me-1"></i><span>{{ __('Delete') }}</span>
                                </a>
                                @method('DELETE')
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body p-3">
                <div class="row g-2 justify-content-between">
                    <div class="col-auto"><span
                            class="badge rounded-pill bg-success">{{ !empty($product->ProductData) ? $product->ProductData->name : '-' }}</span>
                    </div>
                </div>
                <div class="card mb-0 mt-3">
                    <div class="card-body p-2">
                        <div class="row">
                            <div class="col-6">
                                <h6 >{{ $product->variant_product == 1 ? 'has variant' : 'no variant' }}</h6>
                                <p class="text-muted text-sm mb-0">{{ __('Variant') }}</p>
                            </div>
                            <div class="col-6 text-end">
                                <h6 >
                                    @if ($product->variant_product == 1)
                                    -
                                    @else
                                    {{ $product->product_stock > 0 ? $product->product_stock : '-' }}
                                    @endif
                                </h6>
                                <p class="text-muted text-sm mb-0">{{ __('Stock Quantity') }}</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card mb-0 mt-3">
                    <div class="card-body p-2">
                        <div class="row">
                            <div class="col-6">
                                <h6>
                                    @if ($product->track_stock == 0)
                                    @if ($product->stock_status == 'out_of_stock')
                                    <span class="badge rounded-pill bg-danger">{{ __('Out of stock') }}</span>
                                    @elseif($product->stock_status == 'on_backorder')
                                    <span class="badge rounded-pill bg-warning">{{__('On Backorder') }}</span>
                                    @else
                                    <span class="badge rounded-pill bg-primary">{{ __('In stock') }}</span>
                                    @endif
                                    @else
                                    @if ($product->product_stock <= (isset($settings['out_of_stock_threshold']) ? $settings['out_of_stock_threshold'] : 0))
                                        <span class="badge rounded-pill bg-danger">{{ __('Out of stock') }}</span>
                                        @else
                                        <span class="badge rounded-pill bg-primary">{{ __('In stock') }}</span>
                                        @endif
                                        @endif
                                </h6>
                                <p class="text-muted text-sm mb-0">{{ __('Stock Status') }}</p>
                            </div>
                            <div class="col-6 text-end">
                                <h6>
                                    @if ($product->variant_product == 1)
                                    {{__('In Variant')}}
                                    @else
                                    {{ currency_format_with_sym($product->price, getCurrentStore()) ?? SetNumberFormat($product->price) }}
                                    @endif
                                </h6>
                                <p class="text-muted text-sm mb-0">{{ __('Price') }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endforeach
    @endisset
    @permission('Create Product')
    <div class="col-md-6 col-xl-4 col-xxl-3 All mb-4">
        <a class="btn-addnew-project  border-primary" href="{{ route('product.create') }}">
            <div class="bg-primary proj-add-icon">
                <i class="ti ti-plus"></i>
            </div>
            <h6 class="my-2 text-center">{{ __('Add Product') }}</h6>
            <p class="text-muted text-center">{{ __('Click here to add New Product') }}</p>
        </a>
    </div>
    @endpermission

</div>
{!! $products->links('layouts.global-pagination') !!}
@endsection
@push('custom-script')
<script>
    $(document).ready(function() {
        var successMsg = localStorage.getItem('success_msg');
        if (successMsg) {
            show_toastr('Success', successMsg, 'success');
            localStorage.removeItem('success_msg');
        }
    });
</script>
@endpush