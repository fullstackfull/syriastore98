@extends('layouts.app')

@section('page-title')
    {{ __('HubSpot Product') }}
@endsection


@section('breadcrumb')
    <li class="breadcrumb-item" aria-current="page"><a href="{{route('product.index')}}">{{ __('Product') }}</a></li>
    <li class="breadcrumb-item" aria-current="page">{{ __('Manage HubSpot Product') }}</li>
@endsection

@section('content')

    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-body table-border-style">
                    <h5>{{ __('HubSpot Product List') }}</h5>
                    <div class="table-responsive">
                        <table class="table mt-3 pc-dt-simple" id="products">
                            <thead class="text-center">
                                <tr>
                                    <th>{{ __('Image') }}</th>
                                    <th>{{ __('Name') }}</th>
                                    <th>{{ __('Sale Price') }}</th>
                                    <th>{{ __('Purchase Price') }}</th>
                                    <th>{{ __('Type') }}</th>
                                    <th class="d-none">{{ __('Description') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    @if (Laratrust::hasPermission('hubspot product sync'))
                                        <th>{{ __('Action') }}</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="text-center">
                                @foreach ($productServices as $productService)
                                    <tr class="font-style">
                                        @php
                                            $path = filter_var(
                                                $productService->properties->hs_images,
                                                FILTER_VALIDATE_URL,
                                            )
                                                ? $productService->properties->hs_images
                                                : asset('packages/workdo/HubSpot/src/resources/assets/image/img01.jpg');
                                        @endphp
                                        <td>
                                            <span class="d-none hubsopt_img_url">{{ $path }}</span>
                                            <span
                                                class="d-none hubspot_product_service_id">{{ $productService->id }}</span>
                                            <a href="{{ $path }}" target="_blank" class="image-fixsize">
                                                <img src="{{ $path }}"
                                                    class="rounded border-2 border border-primary">
                                            </a>
                                        </td>
                                        <td class="hubspot_product_name">{{ $productService->properties->name }}</td>
                                        <td>{{ currency_format_with_sym($productService->properties->price) }} <span
                                                class="d-none hubspot_product_sale_price">{{ $productService->properties->price }}</span>
                                        </td>
                                        <td>{{ currency_format_with_sym($productService->properties->hs_cost_of_goods_sold) }}<span
                                                class="d-none hubspot_product_purchase_price">{{ $productService->properties->hs_cost_of_goods_sold }}</span>
                                        </td>
                                        <td class="hubspot_product_type">{{ $productService->properties->hs_product_type }}
                                        </td>
                                        <td class="hubspot_product_description d-none">
                                            {{ $productService->properties->description }}</td>
                                        <td>
                                            @if ($productService->status == 1)
                                                <span
                                                    class="status_badge p-2 px-3 badge bg-primary">{{ __('Matched') }}</span>
                                            @else
                                                <span
                                                    class="status_badge p-2 px-3 badge bg-danger">{{ __('Unmatched') }}</span>
                                            @endif
                                        </td>
                                        <td class="Action">
                                            <div class=" text-end d-flex gap-2 all-button-box justify-content-center">
                                                @if ($productService->status == 0)
                                                    <a class="btn-info btn btn-sm"
                                                       href="{{ route('hubspot.create', ['productData' => json_encode($productService)]) }}"
                                                       data-bs-toggle="tooltip" title="{{ __('Add Product') }}"
                                                       data-bs-original-title="{{ __('Add Product') }}">
                                                        <i class="ti ti-plus text-info"></i>
                                                    </a>
                                                @endif
                                            
                                                <!-- Synchronized Button -->
                                                @if ($productService->status == 1 && !$productService->has_changes)
                                                    <div class="action-btn">
                                                        <a class="btn-primary btn btn-sm align-items-center"
                                                           data-bs-toggle="tooltip" title="{{ __('Synchronized') }}"
                                                           data-bs-original-title="{{ __('Synchronized') }}">
                                                            <i class="ti ti-checks text-success"></i>
                                                        </a>
                                                    </div>
                                                @endif
                                            
                                                <!-- Sync Product Button -->
                                                @if ($productService->status == 1 && $productService->has_changes)
                                                    <a class="btn-warning btn btn-sm"
                                                       href="{{ route('hubspot.sync', ['productId' => $productService->id]) }}"
                                                       data-bs-toggle="tooltip" title="{{ __('Sync Product') }}"
                                                       data-bs-original-title="{{ __('Sync Product') }}">
                                                        <i class="ti ti-refresh text-warning"></i>
                                                    </a>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer">
                    {!! $productServices->links('hub-spot::pagination.global-pagination') !!}
                </div>
            </div>
        </div>
    </div>
@endsection
@push('custom-script')
@endpush
