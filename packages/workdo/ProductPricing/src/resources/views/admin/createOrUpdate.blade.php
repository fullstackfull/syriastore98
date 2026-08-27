@extends('layouts.app')

@section('page-title')
    {{ __('Product Pricing Rule') }}
@endsection


@section('breadcrumb')
    <li class="breadcrumb-item" aria-current="page"><a href="{{route('app-setting.index')}}">{{ __('Store Setting') }}</a></li>
    <li class="breadcrumb-item" aria-current="page"><a href="{{route('app-setting.index')}}">{{ __('Product Pricing Rule') }}</a></li>
    <li class="breadcrumb-item" aria-current="page">{{ isset($productPricingRule) && !empty($productPricingRule) ? __('Edit') : __('Add') }}</li>
@endsection

@section('content')

    <div class="card mt-4">
        <div class="card-header">
            <div class="d-flex align-items-center justify-content-between flex-wrap g-2">
                <h3 class="h4 m-0">{{ isset($productPricingRule) && !empty($productPricingRule) ? __('Edit Product Pricing Rule') : __('Add Product Pricing Rule') }}</h3>
                {{-- <a href="{{ route('app-setting.index') }}"class="btn btn-secondary btn-light me-2 ai-btn">{{__('Back')}} </a> --}}
            </div>
        </div>
        <div class="card-body">
            {{ Form::open(['route' => 'productpricing.store', 'method' => 'post', 'id' => 'product-pricing-form']) }}
            <input type="hidden" name="app_setting_tab" value="pills-product-pricing-tab">
            <div class="row">
                <div class="form-group pricing_method_div">
                    {!! Form::label('pricing_method', __('Pricing Method'), ['class' => 'form-label']) !!}
                    {!! Form::select('pricing_method', $pricing_methods, $productPricingRule->pricing_method ?? null, [
                        'class' => 'form-control',
                        'required',
                        'id' => 'pricing_method',
                    ]) !!}
                </div>
                <div class="form-group col-md-6 quantity_type_field"></div>
            </div>
            <div class="row product_pricing_fields"></div>



        </div>
        <div class="card-footer d-flex justify-content-end flex-wrap">
            <a href="{{ route('app-setting.index') }}"class="btn btn-secondary">{{__('Back')}} </a>
            <input type="submit" value="{{ isset($productPricingRule) && !empty($productPricingRule) ? __('Update') : __('Create') }}" class="btn btn-primary ms-2 submit-product-pricing-form">
        </div>
        {!! Form::close() !!}
    </div>
@endsection
@push('custom-script')
    <script>
        $(document).ready(function(){
            var pricing_method  = $('#pricing_method').val();
            var ruleId          = "{{ $productPricingRule->id ?? null }}";
            getFieldData(pricing_method,ruleId);

            $(document).on("change","#pricing_method", function(){
                var pricing_method  = $(this).val();
                var ruleId          = 0;
                getFieldData(pricing_method,ruleId);
            });

            function getFieldData(pricing_method,ruleId){
                if (pricing_method != 'undefined') {
                    if (pricing_method != 'simple_pricing' && pricing_method != '') {
                        $('.pricing_method_div').removeClass('col-md-12');
                        $('.pricing_method_div').addClass('col-md-6');
                    } else {
                        $('.pricing_method_div').removeClass('col-md-6');
                        $('.pricing_method_div').addClass('col-md-12');
                    }
                    $.ajax({
                        url: '{{ route('productpricing.productPricingFields') }}',
                        type: 'POST',
                        data: {
                            "pricing_method": pricing_method,
                            "ruleId": ruleId,
                        },
                        success: function(data){
                            if (data.is_success == true) {
                                $('.product_pricing_fields').empty();
                                $('.quantity_type_field').empty();
                                $('.product_pricing_fields').append(data.html);
                                $('.quantity_type_field').append(data.quantityFieldHTML);
                            }
                        },
                    });
                }
            }
        });
    </script>
    <script>
        $(document).ready(function() {
            $('#product-pricing-form').on('submit', function(e) {
                var productPricingRuleId = "{{ $productPricingRule->id ?? 0 }}";
                $('<input>').attr({
                    type: 'hidden',
                    name: 'productPricingRuleId',
                    value: productPricingRuleId
                }).appendTo('#product-pricing-form');

                // Validate repeater fields
                function validateRepeater(repeaterName, inputName) {
                    $(`.${repeaterName}`).each(function(index) {
                        if (inputName == 'product_get_repeater') {
                            var valueId = $(`[name="${inputName}[${index}][get_selected_products][]"]`).attr('id');
                        } else {
                            var valueId = $(`[name="${inputName}[${index}][selected_products][]"]`).attr('id');
                        }
                        var value = $('#' + valueId).val();
                        if (valueId && (!value || value.length <= 0)) {
                            $('#' + valueId).next().html('<span class="text-danger product_pricing_validation_massage">{{__('Please select at least one item.')}}</span>');
                        }
                    });
                }

                // Validate conditions
                function validateCondition(index, condition, inputName) {
                    var valueId = $(`[name="conditions_repeater[${index}][${inputName}][]"]`).attr('id');
                    var value = $('#' + valueId).val();
                    if (valueId && (!value || value.length <= 0)) {
                        $('#' + valueId).next().html('<span class="text-danger product_pricing_validation_massage">{{__('Please select at least one item.')}}</span>');
                    }
                }

                validateRepeater('product_repeater_data', 'product_repeater');
                validateRepeater('product_get_repeater_data', 'product_get_repeater');

                $('.condition_type_div').each(function(index) {
                    var conditionType = $(`[name="conditions_repeater[${index}][condition_type]"]`).val();
                    switch (conditionType) {
                        case 'cart_items_products':
                            validateCondition(index, conditionType, 'cart_items_products_selected');
                            break;
                        case 'cart_item_quantity_products':
                            validateCondition(index, conditionType, 'cart_items_quantity_products_selected');
                            break;
                        case 'customer':
                            validateCondition(index, conditionType, 'condition_customers');
                            break;
                    }
                });

                if ($('.product_pricing_validation_massage').length > 0) {
                    show_toastr('error', '{{ __('All fields are required.') }}', 'error');
                    return false;
                }

                // for all input
                var formAllData = new FormData();
                var formData = $('#product-pricing-form').serializeArray();
                $.each(formData, function(key, input) {
                    formAllData.append(input.name, input.value);
                });
                var formSubmit = true;
                $(formData).each(function(index, input){
                    if ((input.value == null || input.value == '') && input.name != '_token') {
                        formSubmit = false;
                    }
                });
                if (formSubmit == false) {
                    show_toastr('error', '{{ __('All fields are required.') }}', 'error');
                    return false;
                }
            });
        });
    </script>
@endpush
