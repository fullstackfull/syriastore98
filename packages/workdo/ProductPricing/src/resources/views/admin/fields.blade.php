<script src="{{asset('js/jquery-ui.min.js')}}"></script>
<script src="{{asset('js/repeater.js')}}"></script>
<script>
    $(document).ready(function() {
        // bulk pricing repeater
        $('#bulk_pricing_repeater').repeater({
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

        var product_json_check = "{{ isset($productPricingRule) && $productPricingRule != null && $productPricingRule->product_json != null ? false : true }}";
        if (product_json_check == false) {
            $('#product_repeater').repeater({
                initEmpty: false,

                defaultValues: {
                    'text-input': 'foo'
                },

                show: function() {
                    var id = $('.selected_products').attr('id');
                    var dataId = parseInt($('.selected_products').attr('data-id'));

                    $(this).find('.selected_products').attr('id', id + (dataId + 1));
                    $('.selected_products').attr('data-id', dataId + 1);
                    multiSelection();
                    $(this).slideDown();
                },

                hide: function(deleteElement) {
                    $(this).slideUp(deleteElement);
                }
            });
        } else {
            $('#product_repeater').repeater({
                initEmpty: true,

                defaultValues: {
                    'text-input': 'foo'
                },

                show: function() {
                    var id = $('.selected_products').attr('id');
                    var dataId = parseInt($('.selected_products').attr('data-id'));

                    $(this).find('.selected_products').attr('id', id + (dataId + 1));
                    $('.selected_products').attr('data-id', dataId + 1);
                    multiSelection();
                    $(this).slideDown();
                },

                hide: function(deleteElement) {
                    $(this).slideUp(deleteElement);
                }
            });
        }

        // product get repeater
        var product_get_json_check = "{{ isset($productPricingRule) && $productPricingRule != null && $productPricingRule->product_get_json != null ? false : true }}";
        if (product_get_json_check == false) {
            $('#product_get_repeater').repeater({
                initEmpty: false,

                defaultValues: {
                    'text-input': 'foo'
                },

                show: function() {
                    var id = $('.get_selected_products').attr('id');
                    var dataId = parseInt($('.get_selected_products').attr('data-id'));

                    $(this).find('.get_selected_products').attr('id', id + (dataId + 1));
                    $('.get_selected_products').attr('data-id', dataId + 1);
                    multiSelection();
                    $(this).slideDown();
                },

                hide: function(deleteElement) {
                    $(this).slideUp(deleteElement);
                }
            });
        } else {
            $('#product_get_repeater').repeater({
                initEmpty: true,

                defaultValues: {
                    'text-input': 'foo'
                },

                show: function() {
                    var id = $('.get_selected_products').attr('id');
                    var dataId = parseInt($('.get_selected_products').attr('data-id'));

                    $(this).find('.get_selected_products').attr('id', id + (dataId + 1));
                    $('.get_selected_products').attr('data-id', dataId + 1);
                    multiSelection();
                    $(this).slideDown();
                },

                hide: function(deleteElement) {
                    $(this).slideUp(deleteElement);
                }
            });
        }

        // conditions repeater
        var conditions_check = "{{ isset($productPricingRule) && $productPricingRule != null && $productPricingRule->conditions != null ? false : true }}";
        if (conditions_check == false) {
            $('#conditions_repeater').repeater({
                initEmpty: false,

                defaultValues: {
                    'text-input': 'foo'
                },

                show: function() {

                    var condition_type_div_id = $('.condition_type_div').attr('id');
                    var condition_type_div_dataId = parseInt($('.condition_type_div').attr('data-id'));

                    var condition_type_id = $('.condition_type').attr('id');
                    var condition_type_dataId = parseInt($('.condition_type').attr('data-id'));

                    var conditions_repeater_fields_id = $('.conditions_repeater_fields').attr('id');
                    var conditions_repeater_fields_dataId = parseInt($('.conditions_repeater_fields').attr('data-id'));

                    $(this).find('.condition_type_div').attr('id', condition_type_div_id + (condition_type_div_dataId + 1));
                    var condition_type_div_id = $(this).find('.condition_type_div').attr('id');
                    $('.condition_type_div').attr('data-id', condition_type_div_dataId + 1);

                    $(this).find('.condition_type').attr('id', condition_type_id + (condition_type_dataId + 1));
                    var condition_type_id = $(this).find('.condition_type').attr('id');
                    $('.condition_type').attr('data-id', condition_type_dataId + 1);

                    $(this).find('.conditions_repeater_fields').attr('id', conditions_repeater_fields_id + (conditions_repeater_fields_dataId + 1));
                    var conditions_repeater_fields_id = $(this).find('.conditions_repeater_fields').attr('id');
                    $('.conditions_repeater_fields').attr('data-id', conditions_repeater_fields_dataId + 1);

                    conditionFields(condition_type_div_id, condition_type_id, conditions_repeater_fields_id);
                    multiSelection();
                    $(this).slideDown();
                },

                hide: function(deleteElement) {
                    $(this).slideUp(deleteElement);
                }
            });
        } else {
            $('#conditions_repeater').repeater({
                initEmpty: true,

                defaultValues: {
                    'text-input': 'foo'
                },

                show: function() {

                    var condition_type_div_id = $('.condition_type_div').attr('id');
                    var condition_type_div_dataId = parseInt($('.condition_type_div').attr('data-id'));

                    var condition_type_id = $('.condition_type').attr('id');
                    var condition_type_dataId = parseInt($('.condition_type').attr('data-id'));

                    var conditions_repeater_fields_id = $('.conditions_repeater_fields').attr('id');
                    var conditions_repeater_fields_dataId = parseInt($('.conditions_repeater_fields').attr('data-id'));

                    $(this).find('.condition_type_div').attr('id', condition_type_div_id + (condition_type_div_dataId + 1));
                    var condition_type_div_id = $(this).find('.condition_type_div').attr('id');
                    $('.condition_type_div').attr('data-id', condition_type_div_dataId + 1);

                    $(this).find('.condition_type').attr('id', condition_type_id + (condition_type_dataId + 1));
                    var condition_type_id = $(this).find('.condition_type').attr('id');
                    $('.condition_type').attr('data-id', condition_type_dataId + 1);

                    $(this).find('.conditions_repeater_fields').attr('id', conditions_repeater_fields_id + (conditions_repeater_fields_dataId + 1));
                    var conditions_repeater_fields_id = $(this).find('.conditions_repeater_fields').attr('id');
                    $('.conditions_repeater_fields').attr('data-id', conditions_repeater_fields_dataId + 1);

                    conditionFields(condition_type_div_id, condition_type_id, conditions_repeater_fields_id);
                    multiSelection();
                    $(this).slideDown();
                },

                hide: function(deleteElement) {
                    $(this).slideUp(deleteElement);
                }
            });
        }

        multiSelection();
        // Multiple selection
        function multiSelection() {
            if ($('[data-role="tagsinput"]').length > 0) {
                $('[data-role="tagsinput"]').each(function(index, element) {
                    var obj_id = $(this).attr('id');
                    var textRemove = new Choices(
                        document.getElementById(obj_id), {
                            delimiter: ',',
                            editItems: true,
                            removeItemButton: true,
                        }
                    );
                });
            }
        };
    });
</script>

<script>
    // $(document).ready(function(){
        // conditionFields();
        function conditionFields(condition_type_div_id,condition_type_id,conditions_repeater_fields_id){
            if (condition_type_div_id == undefined) {
                var condition_type_div_id = '#condition_type_div';
            } else {
                var condition_type_div_id = '#'+condition_type_div_id;
            }
            
            if (condition_type_id == undefined) {
                var condition_type_id = '#condition_type';
            } else {
                var condition_type_id = '#'+condition_type_id;
            }

            if (conditions_repeater_fields_id == undefined) {
                var conditions_repeater_fields_id = '#conditions_repeater_fields';
            } else {
                var conditions_repeater_fields_id = '#'+conditions_repeater_fields_id;
            }

            var condition_type = $(condition_type_id).val();
            getConditionsFieldData(condition_type_div_id,condition_type_id,conditions_repeater_fields_id,condition_type);

            $(document).on("change",condition_type_id, function(){
                var condition_type = $(this).val();
                getConditionsFieldData(condition_type_div_id,condition_type_id,conditions_repeater_fields_id,condition_type);
            });

            function getConditionsFieldData(condition_type_div_id,condition_type_id,conditions_repeater_fields_id,condition_type) {
                if (condition_type == 'cart_item_quantity_products') {
                    $(condition_type_div_id).removeClass('col-md-3');
                    $(condition_type_div_id).removeClass('col-md-10');
                    $(condition_type_div_id).addClass('col-md-3');
                    $(conditions_repeater_fields_id).removeClass('col-md-1');
                    $(conditions_repeater_fields_id).removeClass('col-md-8');
                    $(conditions_repeater_fields_id).addClass('col-md-8');
                } else if (condition_type == 'guest' || condition_type == 'is_logged_in') {
                    $(condition_type_div_id).removeClass('col-md-3');
                    $(condition_type_div_id).removeClass('col-md-3');
                    $(condition_type_div_id).addClass('col-md-10');
                    $(conditions_repeater_fields_id).addClass('col-md-1');
                    $(conditions_repeater_fields_id).removeClass('col-md-8');
                    $(conditions_repeater_fields_id).removeClass('col-md-8');
                } else {
                    $(condition_type_div_id).removeClass('col-md-3');
                    $(condition_type_div_id).removeClass('col-md-10');
                    $(condition_type_div_id).addClass('col-md-3');
                    $(conditions_repeater_fields_id).removeClass('col-md-1');
                    $(conditions_repeater_fields_id).removeClass('col-md-8');
                    $(conditions_repeater_fields_id).addClass('col-md-8');
                }

                var selectedId              = $(condition_type_id).attr('id');
                var dataId                  = selectedId.replace('condition_type','');
                var repeater_name           = $(condition_type_id).attr('name');
                var replaced_repeater_name  = repeater_name.replace('conditions_repeater[','');
                var repeater_id             = replaced_repeater_name.replace('][condition_type]','');
                var conditionRuleId         = "{{ $ruleId ?? null }}";

                $.ajax({
                    url: '{{ route('productpricing.conditionFields') }}',
                    type: 'POST',
                    data: {
                        "condition_type": condition_type,
                        "dataId": dataId,
                        "repeater_id": repeater_id,
                        "conditionRuleId": conditionRuleId,
                    },
                    success: function(data){
                        if (data.is_success == true) {
                            $(conditions_repeater_fields_id).empty();
                            $(conditions_repeater_fields_id).append(data.conditionFieldHTML);
                        }
                    },
                });
            }
        }

        if (condition_type_id == undefined) {
            var condition_type_id = '#condition_type';
        } else {
            var condition_type_id = '#' + condition_type_id;
        }

        if (conditions_repeater_fields_id == undefined) {
            var conditions_repeater_fields_id = '#conditions_repeater_fields';
        } else {
            var conditions_repeater_fields_id = '#' + conditions_repeater_fields_id;
        }

        var condition_type = $(condition_type_id).val();
        getConditionsFieldData(condition_type_div_id, condition_type_id, conditions_repeater_fields_id, condition_type);

        $(document).on("change", condition_type_id, function() {
            var condition_type = $(this).val();
            getConditionsFieldData(condition_type_div_id, condition_type_id, conditions_repeater_fields_id, condition_type);
        });

        function getConditionsFieldData(condition_type_div_id, condition_type_id, conditions_repeater_fields_id, condition_type) {
            if (condition_type == 'cart_item_quantity_products') {
                $(condition_type_div_id).removeClass('col-md-4');
                $(condition_type_div_id).removeClass('col-md-12');
                $(condition_type_div_id).addClass('col-md-3');
                $(conditions_repeater_fields_id).removeClass('col-md-8');
                $(conditions_repeater_fields_id).addClass('col-md-9');
            } else if (condition_type == 'guest' || condition_type == 'is_logged_in') {
                $(condition_type_div_id).removeClass('col-md-4');
                $(condition_type_div_id).removeClass('col-md-3');
                $(condition_type_div_id).addClass('col-md-12');
                $(conditions_repeater_fields_id).removeClass('col-md-8');
                $(conditions_repeater_fields_id).removeClass('col-md-9');
            } else {
                $(condition_type_div_id).removeClass('col-md-3');
                $(condition_type_div_id).removeClass('col-md-12');
                $(condition_type_div_id).addClass('col-md-4');
                $(conditions_repeater_fields_id).removeClass('col-md-9');
                $(conditions_repeater_fields_id).addClass('col-md-8');
            }

            var selectedId = $(condition_type_id).attr('id');
            var dataId = selectedId.replace('condition_type', '');
            var repeater_name = $(condition_type_id).attr('name');
            var replaced_repeater_name = repeater_name.replace('conditions_repeater[', '');
            var repeater_id = replaced_repeater_name.replace('][condition_type]', '');
            var conditionRuleId = "{{ $ruleId ?? null }}";

            $.ajax({
                url: '{{ route('productpricing.conditionFields') }}',
                type: 'POST',
                data: {
                    "condition_type": condition_type,
                    "dataId": dataId,
                    "repeater_id": repeater_id,
                    "conditionRuleId": conditionRuleId,
                },
                success: function(data) {
                    if (data.is_success == true) {
                        $(conditions_repeater_fields_id).empty();
                        $(conditions_repeater_fields_id).append(data.conditionFieldHTML);
                    }
                },
            });
        }
    }
    // });
</script>

@if ($pricing_method == 'simple_pricing')
<div class="form-group col-md-12 bg-dark">
    {!! Form::label('adjustment', __('Adjustment'), ['class' => 'form-label mt-2']) !!}
</div>
{{-- <hr style="border:1px solid #dfdfdf;"> --}}
<div class="form-group col-md-6">
    {!! Form::label('discount_type', __('Discount Type'), ['class' => 'form-label']) !!}
    {!! Form::select('discount_type', $discount_types, $decodeFieldJson[0]->discount_type ?? null, ['class' => 'form-control discount_type', 'required' => 'required']) !!}
</div>
<div class="form-group col-md-6">
    {!! Form::label('discount_value', __('Discount value'), ['class' => 'form-label']) !!}
    {!! Form::number('discount_value', $decodeFieldJson[0]->discount_value ?? null, ['class' => 'form-control discount_value', 'placeholder' => __('Enter Discount value'), 'required' => 'required', 'min' => '0']) !!}
</div>
@elseif ($pricing_method == 'bulk_pricing')
    <div class="form-group col-md-12 bg-dark">
        {!! Form::label('ranges', __('Ranges'), ['class' => 'form-label mt-2']) !!}
    </div>
    <div id="bulk_pricing_repeater">
        <div class="form-group text-end">
            <a href="javascript:;" data-repeater-create class="btn btn-sm btn-light-primary">
                <i class="ti ti-plus"></i>{{__('Add Range')}}
            </a>
        </div>
        <div data-repeater-list="bulk_pricing_repeater">
            @if (isset($decodeFieldJson) && !empty($decodeFieldJson))
                @foreach ($decodeFieldJson as $item)
                    <div class="row" data-repeater-item>
                        <div class="col-md-11 row">
                            <div class="form-group col-md-3">
                                {!! Form::label('min_quantity', __('Minimum Quantity'), ['class' => 'form-label']) !!}
                                {!! Form::number('min_quantity', $item->min_quantity ?? null, ['class' => 'form-control', 'placeholder' => __('Enter Minimum Quantity'), 'required' => 'required', 'min' => '0']) !!}
                            </div>
                            <div class="form-group col-md-3">
                                {!! Form::label('max_quantity', __('Maximum Quantity'), ['class' => 'form-label']) !!}
                                {!! Form::number('max_quantity', $item->max_quantity ?? null, ['class' => 'form-control', 'placeholder' => __('Enter Maximum Quantity'), 'required' => 'required', 'min' => '0']) !!}
                            </div>
                            <div class="form-group col-md-3">
                                {!! Form::label('discount_type', __('Discount Type'), ['class' => 'form-label']) !!}
                                {!! Form::select('discount_type', $discount_types,$item->discount_type ?? null, ['class' => 'form-control', 'required' => 'required']) !!}
                            </div>
                            <div class="form-group col-md-3">
                                {!! Form::label('discount_value', __('Discount value'), ['class' => 'form-label']) !!}
                                {!! Form::number('discount_value', $item->discount_value ?? null, ['class' => 'form-control', 'placeholder' => __('Enter Discount value'), 'required' => 'required', 'min' => '0']) !!}
                            </div>
                        </div>
                        <div class="col-md-1 form-group d-flex align-items-center">
                            <a href="javascript:;" data-repeater-delete class="btn btn-sm btn-light-danger">
                                <i class="ti ti-trash"></i>
                            </a>
                        </div>
                    </div>
                @endforeach
            @else
                <div class="row" data-repeater-item>
                    <div class="col-md-11 row">
                        <div class="form-group col-md-3">
                            {!! Form::label('min_quantity', __('Minimum Quantity'), ['class' => 'form-label']) !!}
                            {!! Form::number('min_quantity', null, ['class' => 'form-control', 'placeholder' => __('Enter Minimum Quantity'), 'required' => 'required', 'min' => '0']) !!}
                        </div>
                        <div class="form-group col-md-3">
                            {!! Form::label('max_quantity', __('Maximum Quantity'), ['class' => 'form-label']) !!}
                            {!! Form::number('max_quantity', null, ['class' => 'form-control', 'placeholder' => __('Enter Maximum Quantity'), 'required' => 'required', 'min' => '0']) !!}
                        </div>
                        <div class="form-group col-md-3">
                            {!! Form::label('discount_type', __('Discount Type'), ['class' => 'form-label']) !!}
                            {!! Form::select('discount_type', $discount_types,null, ['class' => 'form-control', 'required' => 'required']) !!}
                        </div>
                        <div class="form-group col-md-3">
                            {!! Form::label('discount_value', __('Discount value'), ['class' => 'form-label']) !!}
                            {!! Form::number('discount_value', null, ['class' => 'form-control', 'placeholder' => __('Enter Discount value'), 'required' => 'required', 'min' => '0']) !!}
                        </div>
                    </div>
                    <div class="col-md-1 form-group d-flex align-items-center">
                        <a href="javascript:;" data-repeater-delete class="btn btn-sm btn-light-danger">
                            <i class="ti ti-trash"></i>
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </div>
    <div class="form-group text-end">
        <a href="javascript:;" data-repeater-create class="btn btn-sm btn-light-primary">
            <i class="ti ti-plus"></i>{{__('Add Range')}}
        </a>
    </div>
</div>
@elseif ($pricing_method == 'buy_x_get_x' || $pricing_method == 'buy_x_get_y')
<div class="form-group col-md-12 bg-dark">
    {!! Form::label('quantities_and_discount', __('Quantities & Discount'), ['class' => 'form-label mt-2']) !!}
</div>
<div class="form-group col-md-3">
    {!! Form::label('buy_quantity', __('Buy Quantity'), ['class' => 'form-label']) !!}
    {!! Form::number('buy_quantity', $decodeFieldJson[0]->buy_quantity ?? null, ['class' => 'form-control', 'placeholder' => __('Enter Minimum Quantity'), 'required' => 'required', 'min' => '0']) !!}
</div>
<div class="form-group col-md-3">
    {!! Form::label('get_quantity', __('Get Quantity'), ['class' => 'form-label']) !!}
    {!! Form::number('get_quantity', $decodeFieldJson[0]->get_quantity ?? null, ['class' => 'form-control', 'placeholder' => __('Enter Maximum Quantity'), 'required' => 'required', 'min' => '0']) !!}
</div>
@endif
@if ($pricing_method != 'bulk_pricing' && $pricing_method != 'simple_pricing')
<div class="form-group col-md-3">
    {!! Form::label('discount_type', __('Discount Type'), ['class' => 'form-label']) !!}
    {!! Form::select('discount_type', $discount_types,$decodeFieldJson[0]->discount_type ?? null, ['class' => 'form-control discount_type', 'required' => 'required']) !!}
</div>
<div class="form-group col-md-3">
    {!! Form::label('discount_value', __('Discount value'), ['class' => 'form-label']) !!}
    {!! Form::number('discount_value', $decodeFieldJson[0]->discount_value ?? null, ['class' => 'form-control discount_value', 'placeholder' => __('Enter Discount value'), 'required' => 'required', 'min' => '0']) !!}
</div>
@endif

<div class="form-group col-md-12 bg-dark">
    @if ($pricing_method == 'buy_x_get_y')
    {!! Form::label('products_buy', __('Products - Buy'), ['class' => 'form-label mt-2']) !!}
    @else
    {!! Form::label('products', __('Products'), ['class' => 'form-label mt-2']) !!}
    @endif
</div>
<div id="product_repeater">
    <div class="form-group text-end">
        <a href="javascript:;" data-repeater-create class="btn btn-sm btn-light-primary">
            <i class="ti ti-plus"></i>{{__('Add Product')}}
        </a>
    </div>
    <div data-repeater-list="product_repeater">
        @if (isset($productPricingRule->product_json) && !empty($productPricingRule->product_json) && (is_array(json_decode($productPricingRule->product_json)) || is_object(json_decode($productPricingRule->product_json))))
            @php
            $product_repeater_data_id = 1;
            @endphp
            @foreach (json_decode($productPricingRule->product_json) as $item)
                @php
                    $selected_products = explode(',',$item->selected_products ?? null);
                @endphp
                <div class="row" data-repeater-item>
                    <div class="form-group col-md-3 product_repeater_data">
                        {!! Form::label('condition_type', __('Product Type'), ['class' => 'form-label']) !!}
                        {!! Form::select('condition_type', $product_types,$item->condition_type ?? null, ['class' => 'form-control', 'required' => 'required']) !!}
                    </div>
                    <div class="form-group col-md-3">
                        {!! Form::label('type', __('Condition'), ['class' => 'form-label']) !!}
                        {!! Form::select('type', $inOrNotConditions,$item->type ?? null, ['class' => 'form-control', 'required' => 'required']) !!}
                    </div>
                    <div class="form-group col-md-5">
                        {!! Form::label('selected_products', __('Products'), ['class' => 'form-label']) !!}
                        {!! Form::select('selected_products', $products, $selected_products ?? null, ['class' => 'form-control selected_products', 'id' => 'selected_products'.$product_repeater_data_id, 'multiple','data-role'=>'tagsinput', 'data-id' => $product_repeater_data_id]) !!}
                    </div>
                    <div class="form-group col-md-1 d-flex align-items-center">
                        <a href="javascript:;" data-repeater-delete class="btn btn-sm btn-light-danger">
                            <i class="ti ti-trash"></i>
                        </a>
                    </div>
                </div>
                @php
                    $product_repeater_data_id++;
                @endphp                
            @endforeach
        @else
            <div class="row" data-repeater-item>
                <div class="form-group col-md-4 product_repeater_data">
                    {!! Form::label('condition_type', __('Product Type'), ['class' => 'form-label']) !!}
                    {!! Form::select('condition_type', $product_types,null, ['class' => 'form-control', 'required' => 'required']) !!}
                </div>
                <div class="form-group col-md-4">
                    {!! Form::label('type', __('Condition'), ['class' => 'form-label']) !!}
                    {!! Form::select('type', $inOrNotConditions,null, ['class' => 'form-control', 'required' => 'required']) !!}
                </div>
                <div class="form-group col-md-4">
                    {!! Form::label('selected_products', __('Products'), ['class' => 'form-label']) !!}
                    {!! Form::select('selected_products', $products, null, ['class' => 'form-control selected_products', 'id' => 'selected_products', 'multiple','data-role'=>'tagsinput', 'data-id' => 0]) !!}
                </div>
                <div class="form-group text-end">
                    <a href="javascript:;" data-repeater-delete class="btn btn-sm btn-light-danger">
                    <i class="ti ti-trash"></i>{{ __('Delete') }}
                    </a>
                </div>
            </div>
        @endif
    </div>
</div>

@if ($pricing_method == 'buy_x_get_y')
    <div class="form-group col-md-12 bg-dark">
        {!! Form::label('products_get', __('Products - Get'), ['class' => 'form-label mt-2']) !!}
    </div>
    <div id="product_get_repeater">
        <div class="form-group text-end">
            <a href="javascript:;" data-repeater-create class="btn btn-sm btn-light-primary">
                <i class="ti ti-plus"></i>{{__('Add Product')}}
            </a>
        </div>
        <div data-repeater-list="product_get_repeater">
            @if (isset($productPricingRule->product_get_json) && !empty($productPricingRule->product_get_json) && (is_array(json_decode($productPricingRule->product_get_json)) || is_object(json_decode($productPricingRule->product_get_json))))
                @php
                    $product_get_repeater_data_id = 1;
                @endphp
                @foreach (json_decode($productPricingRule->product_get_json) as $item)
                    @php
                        $get_selected_products = explode(',',$item->get_selected_products ?? null);
                    @endphp
                    <div class="row" data-repeater-item>
                        <div class="form-group col-md-3 product_get_repeater_data">
                            {!! Form::label('condition_type', __('Product Type'), ['class' => 'form-label']) !!}
                            {!! Form::select('condition_type', $product_types,$item->condition_type ?? null, ['class' => 'form-control', 'required' => 'required']) !!}
                        </div>
                        <div class="form-group col-md-3">
                            {!! Form::label('type', __('Condition'), ['class' => 'form-label']) !!}
                            {!! Form::select('type', $inOrNotConditions,$item->type ?? null, ['class' => 'form-control', 'required' => 'required']) !!}
                        </div>
                        <div class="form-group col-md-5">
                            {!! Form::label('get_selected_products', __('Products'), ['class' => 'form-label']) !!}
                            {!! Form::select('get_selected_products', $products, $get_selected_products ?? null, ['class' => 'form-control get_selected_products', 'id' => 'get_selected_products'.$product_get_repeater_data_id, 'multiple','data-role'=>'tagsinput', 'data-id' => $product_get_repeater_data_id]) !!}
                        </div>
                        <div class="form-group col-md-1 d-flex align-items-center">
                            <a href="javascript:;" data-repeater-delete class="btn btn-sm btn-light-danger">
                                <i class="ti ti-trash"></i>
                            </a>
                        </div>
                    </div>
                    @php
                        $product_get_repeater_data_id++;
                    @endphp
                @endforeach
            @else
                <div class="row" data-repeater-item>
                    <div class="form-group col-md-3 product_get_repeater_data">
                        {!! Form::label('condition_type', __('Product Type'), ['class' => 'form-label']) !!}
                        {!! Form::select('condition_type', $product_types,null, ['class' => 'form-control', 'required' => 'required']) !!}
                    </div>
                    <div class="form-group col-md-3">
                        {!! Form::label('type', __('Condition'), ['class' => 'form-label']) !!}
                        {!! Form::select('type', $inOrNotConditions,null, ['class' => 'form-control', 'required' => 'required']) !!}
                    </div>
                    <div class="form-group col-md-5">
                        {!! Form::label('get_selected_products', __('Products'), ['class' => 'form-label']) !!}
                        {!! Form::select('get_selected_products', $products, null, ['class' => 'form-control get_selected_products', 'id' => 'get_selected_products', 'multiple','data-role'=>'tagsinput', 'data-id' => 0]) !!}
                    </div>
                    <div class="form-group col-md-1 d-flex align-items-center">
                        <a href="javascript:;" data-repeater-delete class="btn btn-sm btn-light-danger">
                            <i class="ti ti-trash"></i>
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </div>
    <div class="form-group text-end">
        <a href="javascript:;" data-repeater-create class="btn btn-sm btn-light-primary">
            <i class="ti ti-plus"></i>{{__('Add Product')}}
        </a>
    </div>
</div>
@endif
<div class="form-group col-md-12 bg-dark">
    {!! Form::label('conditions', __('Conditions'), ['class' => 'form-label mt-2']) !!}
</div>
<div id="conditions_repeater">
    <div class="text-end">
        <a href="javascript:;" data-repeater-create class="btn btn-sm btn-light-primary">
            <i class="ti ti-plus"></i>{{__('Add Condition')}}
        </a>
    </div>
    <div data-repeater-list="conditions_repeater">
        @php
            $conditions_repeater_data_id = 1;
            $repeater_count = isset($productPricingRule) && $productPricingRule && isset($productPricingRule->conditions) ? count(json_decode($productPricingRule->conditions)) : 0;
            $conditions_repeater_id = 0;
        @endphp
        @if (isset($productPricingRule) && $productPricingRule && isset($productPricingRule->conditions) && !empty($productPricingRule->conditions) && (is_array(json_decode($productPricingRule->conditions)) || is_object(json_decode($productPricingRule->conditions))))
            @foreach (json_decode($productPricingRule->conditions) as $item)
                <div class="row" data-repeater-item>
                    <div class="form-group condition_type_div" id="condition_type_div{{$conditions_repeater_data_id}}" data-id={{$repeater_count}}>
                        {!! Form::label('condition_type', __('Condition Type'), ['class' => 'form-label']) !!}
                        {!! Form::select('conditions_repeater['.$conditions_repeater_id.'][condition_type]', $condition_types,$item->condition_type ?? null, ['class' => 'form-control condition_type', 'id' => 'condition_type'.$conditions_repeater_data_id, 'data-id' => $repeater_count, 'required' => 'required']) !!}
                    </div>
                    <div class="row conditions_repeater_fields" id="conditions_repeater_fields{{$conditions_repeater_data_id}}" data-id={{$repeater_count}}></div>
                    <div class="form-group col-md-1 d-flex align-items-center">
                        <a href="javascript:;" data-repeater-delete class="btn btn-sm btn-light-danger">
                            <i class="ti ti-trash"></i>
                        </a>
                    </div>
                </div>
                <script>
                    var condition_type_div_id           = 'condition_type_div'+"{{$conditions_repeater_data_id}}";
                    var condition_type_id               = 'condition_type'+"{{$conditions_repeater_data_id}}";
                    var conditions_repeater_fields_id   = 'conditions_repeater_fields'+"{{$conditions_repeater_data_id}}";
                    conditionFields(condition_type_div_id,condition_type_id,conditions_repeater_fields_id);
                </script>
                @php
                    $conditions_repeater_data_id++;
                    $conditions_repeater_id++;
                @endphp
            @endforeach
        @else
            <div class="row" data-repeater-item>
                <div class="form-group condition_type_div" id="condition_type_div" data-id=0>
                    {!! Form::label('condition_type', __('Condition Type'), ['class' => 'form-label']) !!}
                    {!! Form::select('condition_type', $condition_types,null, ['class' => 'form-control condition_type', 'id' => 'condition_type', 'data-id' => 0, 'required' => 'required']) !!}
                </div>
                <div class="row conditions_repeater_fields" id="conditions_repeater_fields" data-id=0></div>
                <div class="form-group col-md-1 d-flex align-items-center">
                    <a href="javascript:;" data-repeater-delete class="btn btn-sm btn-light-danger">
                        <i class="ti ti-trash"></i>
                    </a>
                </div>
            </div>
            <div class="row conditions_repeater_fields" id="conditions_repeater_fields{{$conditions_repeater_data_id}}" data-id={{$repeater_count}}></div>
            <div class="form-group text-end">
                <a href="javascript:;" data-repeater-delete class="btn btn-sm btn-light-danger">
                    <i class="ti ti-trash"></i>{{__('Delete')}}
                </a>
            </div>
        @endif
    </div>
</div>
