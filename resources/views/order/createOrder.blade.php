@extends('layouts.app')

@section('page-title')
    {{ __('Create Order') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('order.index') }}">{{ __('Orders') }}</a></li>
    <li class="breadcrumb-item" aria-current="page">{{ __('Create Order') }}</li>
@endsection

@section('action-button')
    <div class="text-end">
        <a href="{{ route('order.index') }}" class="btn btn-sm btn-primary">
            <i class="ti ti-arrow-left"></i> {{ __('Back') }}
        </a>
    </div>
@endsection

@section('content')
<div class="row">
    <div class="col-sm-12">
        <div class="card">
            <div class="card-header">
                <h5>{{ __('Order Information') }}</h5>
            </div>
            <div class="card-body">
                {{ Form::open(['route' => 'order.store', 'method' => 'POST', 'id' => 'order-form']) }}
                @csrf

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            {{ Form::label('customer_id', __('Customer'), ['class' => 'form-label']) }}
                            <select name="customer_id" id="customer_id" class="form-control select2" required>
                                <option value="">{{ __('Select Customer') }}</option>
                                @foreach($customers as $customer)
                                    <option value="{{ $customer->id }}" 
                                        {{ (isset($preselectedCustomer) && $preselectedCustomer->id == $customer->id) ? 'selected' : '' }}>
                                        {{ $customer->first_name }} {{ $customer->last_name }} ({{ $customer->email }})
                                    </option>
                                @endforeach
                            </select>
                            @error('customer_id')
                                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            {{ Form::label('payment_type', __('Payment Type'), ['class' => 'form-label']) }}
                            <select name="payment_type" class="form-control select2" required>
                                <option value="">{{ __('Select Payment Method') }}</option>
                                @foreach($activePaymentMethods as $key => $name)
                                    <option value="{{ $key }}">{{ $name }}</option>
                                @endforeach
                            </select>
                            @error('payment_type')
                                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            {{ Form::label('payment_status', __('Payment Status'), ['class' => 'form-label']) }}
                            <select name="payment_status" class="form-control" required>
                                <option value="Unpaid">{{ __('Unpaid') }}</option>
                                <option value="Paid">{{ __('Paid') }}</option>
                            </select>
                            @error('payment_status')
                                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-12">
                        <div class="form-group">
                            {{ Form::label('additional_note', __('Additional Note'), ['class' => 'form-label']) }}
                            {{ Form::textarea('additional_note', null, ['class' => 'form-control', 'rows' => 2, 'placeholder' => __('Optional note...')]) }}
                            @error('additional_note')
                                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>
                    </div>
                </div>

                <hr>
                <h6 class="mb-3">{{ __('Products') }}</h6>

                <div id="products-container">
                    <div class="product-item row mb-3 p-3 border rounded">
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Product') }}</label>
                            <!-- لاحظ هنا الكلاس product-select-temp لمنع تعارض القالب -->
                            <select name="products[0][product_id]" class="form-control product-select-temp" required>
                                <option value="">{{ __('Select Product') }}</option>
                                @foreach($products as $product)
                                    <option value="{{ $product->id }}" data-price="{{ $product->price }}">
                                        {{ $product->name }} - {{ number_format($product->price, 2) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Quantity') }}</label>
                            <input type="number" name="products[0][quantity]" class="form-control product-qty" min="1" value="1" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Price') }}</label>
                            <input type="number" name="products[0][price]" class="form-control product-price" step="0.01" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('Subtotal') }}</label>
                            <input type="text" class="form-control product-subtotal" readonly>
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button type="button" class="btn btn-danger btn-sm remove-product">
                                <i class="ti ti-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <button type="button" class="btn btn-sm btn-success mb-3" id="add-product">
                    <i class="ti ti-plus"></i> {{ __('Add Product') }}
                </button>

                <hr>
                <h6 class="mb-3">{{ __('Billing Information') }}</h6>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            {{ Form::label('first_name', __('First Name'), ['class' => 'form-label']) }}
                            {{ Form::text('first_name', null, ['class' => 'form-control', 'required' => 'required']) }}
                            @error('first_name')
                                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            {{ Form::label('last_name', __('Last Name'), ['class' => 'form-label']) }}
                            {{ Form::text('last_name', null, ['class' => 'form-control', 'required' => 'required']) }}
                            @error('last_name')
                                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            {{ Form::label('email', __('Email'), ['class' => 'form-label']) }}
                            {{ Form::email('email', null, ['class' => 'form-control', 'required' => 'required']) }}
                            @error('email')
                                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            {{ Form::label('telephone', __('Phone'), ['class' => 'form-label']) }}
                            {{ Form::text('telephone', null, ['class' => 'form-control', 'required' => 'required']) }}
                            @error('telephone')
                                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-12">
                        <div class="form-group">
                            {{ Form::label('address', __('Address'), ['class' => 'form-label']) }}
                            {{ Form::text('address', null, ['class' => 'form-control', 'required' => 'required']) }}
                            @error('address')
                                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            {{ Form::label('city', __('City'), ['class' => 'form-label']) }}
                            {{ Form::text('city', null, ['class' => 'form-control', 'required' => 'required']) }}
                            @error('city')
                                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            {{ Form::label('state', __('State'), ['class' => 'form-label']) }}
                            {{ Form::text('state', null, ['class' => 'form-control', 'required' => 'required']) }}
                            @error('state')
                                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            {{ Form::label('country', __('Country'), ['class' => 'form-label']) }}
                            {{ Form::text('country', null, ['class' => 'form-control', 'required' => 'required']) }}
                            @error('country')
                                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            {{ Form::label('postcode', __('Postcode'), ['class' => 'form-label']) }}
                            {{ Form::text('postcode', null, ['class' => 'form-control']) }}
                            @error('postcode')
                                <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>
                    </div>
                </div>

                <hr>
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            {{ Form::label('delivery_price', __('Delivery Price'), ['class' => 'form-label']) }}
                            {{ Form::number('delivery_price', 0, ['class' => 'form-control', 'step' => '0.01', 'id' => 'delivery_price']) }}
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            {{ Form::label('tax_price', __('Tax Price'), ['class' => 'form-label']) }}
                            {{ Form::number('tax_price', 0, ['class' => 'form-control', 'step' => '0.01', 'id' => 'tax_price']) }}
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            {{ Form::label('final_price', __('Final Price'), ['class' => 'form-label']) }}
                            {{ Form::number('final_price', 0, ['class' => 'form-control', 'step' => '0.01', 'id' => 'final_price', 'required' => 'required']) }}
                        </div>
                    </div>
                </div>

                <div class="text-end mt-3">
                    {{ Form::submit(__('Create Order'), ['class' => 'btn btn-primary']) }}
                    <a href="{{ route('order.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                </div>

                {{ Form::close() }}
            </div>
        </div>
    </div>
</div>

@push('custom-styles')
<style>
    /* 1. حل مشكلة التداخل مع القائمة الجانبية */
    .card {
        position: relative;
        z-index: 10;
        background-color: #ffffff;
    }

    /* 2. ضمان ظهور حاوية Select2 فوق كل عناصر القالب */
    .select2-container {
        z-index: 99999 !important;
        width: 100% !important;
    }
    
    /* 3. مطابقة تصميم الحقل مع Bootstrap تماماً */
    .select2-container--default .select2-selection--single {
        border: 1px solid #d2d6da !important;
        border-radius: 0.375rem !important;
        height: calc(1.5em + 0.75rem + 2px) !important;
        padding: 0.375rem 0.75rem !important;
        display: flex !important;
        align-items: center !important;
        background-color: #ffffff !important;
    }

    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 1.5 !important;
        padding-left: 0 !important;
        color: #67748e !important;
        width: 100% !important;
    }

    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 100% !important;
        right: 10px !important;
        top: 0 !important;
    }

    /* 4. الحل السحري للتداخل: رفع القائمة المنسدلة فوق كل شيء */
    .select2-dropdown {
        z-index: 100000 !important;
        border: 1px solid #d2d6da !important;
        border-radius: 0.375rem !important;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05) !important;
        background-color: #ffffff !important;
    }

    .select2-results__option {
        padding: 8px 12px !important;
        font-size: 0.875rem !important;
    }

    .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background-color: #2152ff !important;
        color: #ffffff !important;
    }
</style>
@endpush

@push('custom-script')
<script>
    let productIndex = 1;

    $(window).on('load', function() {
        setTimeout(function() {
            // 1. تحويل العنصر الأول من temp إلى product-select وتهيئته
            $('.product-select-temp').each(function() {
                $(this).removeClass('product-select-temp').addClass('product-select');
                if ($(this).hasClass('select2-hidden-accessible')) {
                    $(this).select2('destroy');
                }
                $(this).select2({
                    placeholder: "{{ __('Select Product') }}",
                    allowClear: true,
                    width: '100%',
                    dropdownParent: $(document.body), // يمنع القص والتداخل
                    language: {
                        noResults: function() { return "{{ __('No products found') }}"; }
                    }
                });
            });

            // 2. تهيئة القوائم الأخرى (Customer و Payment)
            $('#customer_id, select[name="payment_type"]').each(function() {
                if ($(this).hasClass('select2-hidden-accessible')) {
                    $(this).select2('destroy');
                }
                $(this).select2({
                    placeholder: $(this).attr('placeholder') || "{{ __('Select an option') }}",
                    allowClear: true,
                    width: '100%',
                    dropdownParent: $(document.body)
                });
            });
        }, 300);
    });

    // إضافة منتج جديد
    $('#add-product').on('click', function() {
        const html = `
            <div class="product-item row mb-3 p-3 border rounded bg-light">
                <div class="col-md-4">
                    <label class="form-label">{{ __('Product') }}</label>
                    <select name="products[${productIndex}][product_id]" class="form-control product-select" required>
                        <option value="">{{ __('Select Product') }}</option>
                        @foreach($products as $product)
                            <option value="{{ $product->id }}" data-price="{{ $product->price }}">
                                {{ $product->name }} - {{ number_format($product->price, 2) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('Quantity') }}</label>
                    <input type="number" name="products[${productIndex}][quantity]" class="form-control product-qty" min="1" value="1" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('Price') }}</label>
                    <input type="number" name="products[${productIndex}][price]" class="form-control product-price" step="0.01" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('Subtotal') }}</label>
                    <input type="text" class="form-control product-subtotal" readonly>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="button" class="btn btn-danger btn-sm remove-product">
                        <i class="ti ti-trash"></i>
                    </button>
                </div>
            </div>
        `;

        $('#products-container').append(html);

        // تهيئة العنصر الجديد
        setTimeout(function() {
            var $newSelect = $('#products-container .product-select').last();
            if ($newSelect.hasClass('select2-hidden-accessible')) {
                $newSelect.select2('destroy');
            }
            $newSelect.select2({
                placeholder: "{{ __('Select Product') }}",
                allowClear: true,
                width: '100%',
                dropdownParent: $(document.body)
            });
        }, 100);

        productIndex++;
        calculateTotal();
    });

    // حذف منتج
    $(document).on('click', '.remove-product', function() {
        $(this).closest('.product-item').remove();
        calculateTotal();
    });

    // حساب الإجمالي الفرعي
    $(document).on('change input', '.product-qty, .product-price', function() {
        const row = $(this).closest('.product-item');
        const qty = parseFloat(row.find('.product-qty').val()) || 0;
        const price = parseFloat(row.find('.product-price').val()) || 0;
        row.find('.product-subtotal').val((qty * price).toFixed(2));
        calculateTotal();
    });

    // تعيين السعر تلقائياً
    $(document).on('change', '.product-select', function() {
        const price = $(this).find(':selected').data('price');
        if (price) {
            $(this).closest('.product-item').find('.product-price').val(price);
            $(this).closest('.product-item').find('.product-qty').trigger('change');
        }
    });

    // حساب الإجمالي النهائي
    function calculateTotal() {
        let total = 0;
        $('.product-subtotal').each(function() {
            total += parseFloat($(this).val()) || 0;
        });
        const delivery = parseFloat($('#delivery_price').val()) || 0;
        const tax = parseFloat($('#tax_price').val()) || 0;
        $('#final_price').val((total + delivery + tax).toFixed(2));
    }

    $('#delivery_price, #tax_price').on('input', calculateTotal);
    setTimeout(calculateTotal, 400);
</script>
@endpush
@endsection