{{Form::model($coupon, array('route' => array('coupon.update', $coupon->id), 'method' => 'PUT', 'enctype' => 'multipart/form-data')) }}

@if (isset(auth()->user()->currentPlan) && auth()->user()->currentPlan->enable_chatgpt == 'on')
<div class="d-flex justify-content-end mb-1">
    <a href="#" class="btn btn-primary me-2 ai-btn btn-badge" data-size="lg" data-ss-panel-open="ai-generate" data-ss-panel-url="{{ route('generate',['coupan']) }}" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Generate') }}" data-title="{{ __('Generate Content With AI') }}">
        <i class="ti ti-wand"></i> {{ __('Generate with AI') }}
    </a>
</div>
@endif
<div class="row">
    <div class="form-group col-md-12">
        {{ Form::label('coupon_name', __('Name'), ['class' => 'form-label']) }}<span class="ss-label__required" aria-hidden="true">*</span>
        {{ Form::text('coupon_name', null, ['class' => 'form-control font-style', 'required' => 'required']) }}
    </div>

    <div class="form-group col-md-12">
        {{-- `for="category"` because the select's explicit `'id' => 'category'` wins in
             getIdAttribute(); ten JS references depend on it. --}}
        {!! Form::label('category', __('Type'), ['class' => 'form-label']) !!}
        {!! Form::select('coupon_type', ['percentage' => 'Percentage', 'flat' => 'Flat' , 'fixed product discount' =>'Fixed product discount'], null, ['class' => 'form-control','id' => 'category']) !!}
    </div>
    <div class="form-group col-md-6 format">
        {!! Form::label('applied_product', __('Products'), ['class' => 'form-label']) !!}
        {!! Form::select('applied_product[]', $product, $applied_product, ['class' => 'form-control select2','id' => 'applied_product', 'multiple','data-role'=>'tagsinput']) !!}
    </div>

    <div class="form-group col-md-6 format">
        {!! Form::label('exclude_product', __('Exclude products'), ['class' => 'form-label']) !!}
        {!! Form::select('exclude_product[]', $product, $exclude_product, ['class' => 'form-control select2','id' => 'exclude_product', 'multiple','data-role'=>'tagsinput']) !!}
    </div>

    <div class="form-group col-md-6 format">
        {!! Form::label('applied_categories', __('Product categories'), ['class' => 'form-label']) !!}
        {!! Form::select('applied_categories[]', $category, $applied_categories,['class' => 'form-control select2','id' => 'applied_categories', 'multiple','data-role'=>'tagsinput']) !!}
    </div>
    <div class="form-group col-md-6 format">
        {!! Form::label('exclude_categories', __('Exclude categories'), ['class' => 'form-label']) !!}
        {!! Form::select('exclude_categories[]', $category, $exclude_categories,['class' => 'form-control select2','id' => 'exclude_categories', 'multiple','data-role'=>'tagsinput']) !!}
    </div>
    <div class="form-group col-md-6">
        {{ Form::label('minimum_spend', __('Minimum spend'), ['class' => 'form-label']) }}
        {{ Form::number('minimum_spend', null, ['class' => 'form-control']) }}
    </div>
    <div class="form-group col-md-6">
        {{ Form::label('maximum_spend', __('Maximum spend'), ['class' => 'form-label']) }}
        {{ Form::number('maximum_spend', null, ['class' => 'form-control']) }}
    </div>
    <div class="form-group col-md-6">
        {{ Form::label('discount_amount', __('Discount'), ['class' => 'form-label']) }}<span class="ss-label__required" aria-hidden="true">*</span>
        {{ Form::number('discount_amount', null, ['class' => 'form-control discount_amount', 'required' => 'required', 'min' => '1', 'max' => '100', 'step' => '0.01']) }}
    </div>
    <div class="form-group col-md-6">
        {{ Form::label('coupon_limit', __('Limit'), ['class' => 'form-label']) }}<span class="ss-label__required" aria-hidden="true">*</span>
        {{ Form::number('coupon_limit', null, ['class' => 'form-control', 'min' => '1', 'required' => 'required']) }}
    </div>
    <div class="form-group col-md-6">
        {{ Form::label('coupon_limit_user', __('Usage limit per user'), ['class' => 'form-label']) }}
        {{ Form::number('coupon_limit_user', null, ['class' => 'form-control', 'min' => '1']) }}
    </div>
    <div class="form-group col-md-6 format">
        {{ Form::label('coupon_limit_x_item', __('Limit usage to X items'), ['class' => 'form-label']) }}
        {{ Form::number('coupon_limit_x_item', null, ['class' => 'form-control', 'min' => '1']) }}
    </div>
    <div class="form-group col-md-6">
        {{ Form::label('coupon_expiry_date', __('Expiry Date'), ['class' => 'form-label']) }}<span class="ss-label__required" aria-hidden="true">*</span>
        {{ Form::date('coupon_expiry_date', null, ['class' => 'form-control', 'required' => 'required', 'placeholder' => 'Select date']) }}
    </div>
    <div class="form-group">
        <div class="d-flex radio-check">
            <div class="form-check m-1">
                <input type="radio" id="manual_code" value="manual" name="icon-input" class="form-check-input code"
                    checked="checked">
                <label class="form-check-label" for="manual_code">{{ __('Manual') }}</label>
            </div>
            <div class="form-check m-1">
                <input type="radio" id="auto_code" value="auto" name="icon-input" class="form-check-input code">
                <label class="form-check-label" for="auto_code">{{ __('Auto Generate') }}</label>
            </div>
        </div>
    </div>
    <div class="form-group col-md-12">
        {{-- P2.5 a11y -- THE CREATE VIEW HAS THIS LABEL AND THE EDIT VIEW DID NOT.
             `coupon/create.blade.php:121` carries `Form::label('auto-code', __('Coupon Code'))`,
             added by an earlier sweep whose own comment calls it "the single real one" -- and the
             sweep only looked at create. The edit form carried a PLACEHOLDER and nothing else.

             A placeholder is not an accessible name: it disappears the moment a merchant types,
             and neither WCAG nor the ARIA practices accept it as a label. The modal harness used
             to count one, which is why this stayed invisible; it no longer does, and this was the
             ONLY field in the whole modal set that depended on it. --}}
        {{ Form::label('auto-code', __('Coupon Code'), ['class' => 'form-label']) }}
        <div class="row">
            <div class="col-md-10" id="code_text">
                {{ Form::text('coupon_code', null, ['class' => 'form-control', 'required' => 'required', 'id' => 'auto-code', 'placeholder' => __('Generate Code')]) }}
            </div>
            <div class="col-md-2" id="autogerate_button">
                <a href="#" class="btn btn-primary" id="code-generate"><i class="ti ti-history"></i></a>
            </div>
        </div>
    </div>
    <div class="form-group col-md-4">
        {{-- THE id RENAME CAME FIRST, AND IT IS NOT COSMETIC. `customCheckdef1` was on BOTH
             this switch and the sale-items one below, in one document. A `for` pointing at a
             duplicated id resolves to the FIRST match, so making the "Exclude sale items"
             caption clickable while the duplicate stood would have toggled "Status" instead:
             an accessibility fix introducing a functional bug invisible to any
             for-resolves assertion. Captions now live inside the already-associated
             `.form-check-label`, which is the shape category/create.blade.php:123 ships. --}}
        <div class="form-check form-switch">
            <input type="hidden" name="status" value="0">
            {!! Form::checkbox('status', 1, null, ["class" => "form-check-input input-primary", "id" => "customCheckdef1"]) !!}
            <label class="form-check-label" for="customCheckdef1">{{ __('Status') }}</label>
        </div>
    </div>
    <div class="form-group col-md-4">
        <div class="form-check form-switch">
            <input type="hidden" name="sale_items" value="0">
            {!! Form::checkbox('sale_items', 1, null, ["class" => "form-check-input input-primary", "id" => "coupon_sale_items"]) !!}
            <label class="form-check-label" for="coupon_sale_items">{{ __('Exclude sale items') }}</label>
        </div>
    </div>
    <div class="form-group col-md-4">
        <div class="form-check form-switch">
            <input type="hidden" name="free_shipping_coupon" value="0">
            {!! Form::checkbox('free_shipping_coupon', 1, null, ["class" => "form-check-input input-primary", "id" => "customCheckdef2"]) !!}
            <label class="form-check-label" for="customCheckdef2">{{ __('Free Shipping Status') }}</label>
        </div>
    </div>

    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Update')}}" class="btn btn-badge btn-primary  mx-1">
    </div>
</div>
{!! Form::close() !!}
<script>
    if ($("#category").val() != "fixed product discount") {
        $('.format').hide();
    }
</script>
<script>
    $(document).ready(function () {
        $("#category").change(function () {
            var ff = $("#category").val()
            if ($("#category").val() == "fixed product discount") {
                $('.format').show();
            } else {
                $('.format').hide();
            }
        });

        // On form submit
        $('form').on('submit', function(e) {
            // Remove any existing error message
            $('.error-message').remove();

            // Get the selected coupon type
            var couponType = $('#category').val();

            // Get the minimum spend amount and discount amount
            var minimumSpend = parseFloat($('input[name="minimum_spend"]').val());
            var discountAmount = parseFloat($('input[name="discount_amount"]').val());

            // Check if the coupon type is "Flat" and if the discount amount is greater than the minimum spend
            if (couponType !== 'percentage' && discountAmount > minimumSpend) {
                // Prevent form submission
                e.preventDefault();

                // Show error message
                var errorMessage = '<div class="alert alert-danger error-message">{{__('Discount amount cannot be greater than the minimum spend amount for flat type coupons.')}}</div>';
                $(this).prepend(errorMessage);
            }
        });
  });

    $(document).ready(function() {
        $('#category').on('change', function() {
            var selectedType = $(this).val();
            if (selectedType != 'percentage') {
                // Remove min and max attributes
                $('.discount_amount').removeAttr('min');
                $('.discount_amount').removeAttr('max');
            } else {
                // Add min and max attributes
                $('.discount_amount').attr({'min': '1', 'max': '100'});
            }
        });
    });
</script>
