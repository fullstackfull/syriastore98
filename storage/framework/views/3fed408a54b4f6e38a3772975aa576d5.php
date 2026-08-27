<?php $__env->startSection('page-title'); ?>
<?php echo e(__('Checkout Page')); ?>

<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<main>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($themeSettings['checkout_banner_status'] && $themeSettings['checkout_banner_status'] == '1'): ?>
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center"
        style="background-image: url('<?php echo e(get_file($themeSettings['checkout_banner_image'] ?? '')); ?>');">
        <div class="md:container w-full mx-auto px-4">
            <div class="text-center relative z-[2]">
                <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">
                    <?php echo e($themeSettings['checkout_banner_title'] ?? __('Checkout Page')); ?></h2>
            </div>
        </div>
    </section>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($themeSettings['checkout_status'] && $themeSettings['checkout_status'] == '1'): ?>
   <section class="py-10 lg:py-20">
        <div class="md:container w-full mx-auto px-4">
            <?php echo Form::open([
                'route' => ['payment.process', $store->slug],
                'method' => 'post',
                'enctype' => 'multipart/form-data',
                'id' => 'formdata',
            ]); ?>

            <?php echo csrf_field(); ?>
                <div class="flex flex-col lg:flex-row gap-8">
                    <!-- Left Side - Checkout Forms -->
                    <div class="lg:w-2/3">
                        <div class="md:space-y-8 space-y-6">
                            <div>
                                <h2 class="font-semibold text-xl md:mb-4 mb-3">1. <?php echo e(__('Customer Information')); ?></h2>
                                <div class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <!-- First Name -->
                                        <div>
                                            <label for="first-name" class="block mb-2 font-medium md:text-base text-sm"><?php echo e(__('First Name')); ?> <span
                                                class="text-red-500">*</span></label>
                                            <?php echo Form::text('billing_info[firstname]', !empty(Auth::guard('customers')->user()) ? Auth::guard('customers')->user()->first_name : '', [
                                                'class' => 'form-input',
                                                'placeholder' => 'John',
                                                'id' => 'first-name',
                                                'required' => true
                                            ]); ?>

                                        </div>

                                        <!-- Last Name -->
                                        <div>
                                            <label for="last-name" class="block mb-2 font-medium md:text-base text-sm"><?php echo e(__('Last Name')); ?> <span
                                                class="text-red-500">*</span></label>
                                            <?php echo Form::text('billing_info[lastname]', !empty(Auth::guard('customers')->user()) ? Auth::guard('customers')->user()->last_name : '', [
                                                'class' => 'form-input',
                                                'placeholder' => 'Doe',
                                                'id' => 'last-name',
                                                'required' => true
                                            ]); ?>

                                        </div>

                                        <!-- Email Field -->
                                        <div>
                                            <label for="email" class="block mb-2 font-medium md:text-base text-sm"><?php echo e(__('Email Address')); ?> <span
                                                class="text-red-500">*</span></label>
                                            <?php echo Form::email('billing_info[email]', !empty(Auth::guard('customers')->user()) ? Auth::guard('customers')->user()->email : '', [
                                                'class' => 'form-input',
                                                'placeholder' => 'shop@company.com',
                                                'id' => 'email',
                                                'required' => true
                                            ]); ?>

                                        </div>

                                        <!-- Phone Number -->
                                        <div>
                                            <label for="phone" class="block mb-2 font-medium md:text-base text-sm"><?php echo e(__('Phone Number')); ?> <span
                                                class="text-red-500">*</span></label>
                                            <?php echo Form::number('billing_info[billing_user_telephone]', !empty(Auth::guard('customers')->user()) ? Auth::guard('customers')->user()->mobile : '', [
                                                'class' => 'form-input',
                                                'placeholder' => '1234567890',
                                                'id' => 'phone',
                                                'required' => true
                                            ]); ?>

                                        </div>
                                    </div>

                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(\App\Models\Utility::CustomerAuthCheck($store->slug) != true): ?>
                                        <!-- Account Section -->
                                        <div class="mb-4 mt-4">
                                            <div class="checkbox flex items-center mb-2 gap-2">
                                                <input type="checkbox" id="register" name="register" value="<?php echo e(old('register')); ?>"
                                                    class="rounded border-gray-300 text-primary focus:ring-primary">
                                                <label for="create-account" class="flex-1 font-medium"><?php echo e(__('Create an account?')); ?></label>
                                            </div>
                                            <p class="text-sm text-gray-500"><?php echo e(__('Create an account for faster checkout and to track your orders')); ?></p>
                                        </div>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                                    <!-- Password Fields (Initially Hidden) -->
                                    

                                    <!-- Delivery Method -->
                                    
                                </div>
                            </div>

                            <div class="mt-4">
                                <h2 class="font-semibold text-xl md:mb-4 mb-3">2. <?php echo e(__('Billing Information')); ?></h2>
                                <div class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm">

                                    <div class="selling_address_form col-12">
                                    </div>
                                </div>
                            </div>                          
                            
                            <!-- Shipping Address -->
                            <div class="mt-4">
                                <h2 class="font-semibold text-xl md:mb-4 mb-3">3. <?php echo e(__('Shipping Address')); ?></h2>
                                <div class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm">
                                    <div class="checkbox flex items-center gap-2">
                                        <input type="checkbox" id="same-address" name="delivery_not_same" class="rounded border-gray-300 text-primary focus:ring-primary"> 
                                        <label for="same-address" class="flex-1 font-medium"><?php echo e(__('Ship To A Different Address?')); ?></label>
                                    </div>

                                    <!-- Shipping address fields will be shown when "Same as billing address" is unchecked -->
                                    <div class="shipping_address_form col-12">
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4">
                                <h2 class="font-semibold text-xl md:mb-4 mb-3">4. <?php echo e(__('Payment Method')); ?></h2>
                                <div class="bg-gray-50 border rounded-lg shadow-sm">
                                    <!-- Payment Options -->
                                    <div class="space-y-3 md:p-6 p-4 max-h-[484px] overflow-y-auto">
                                        <input type="hidden" name="payment_type" class="payment_types" value="cod">
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $payments; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $payment): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($payment['status'] == 'on'): ?>
                                                <div class="radio-btn flex p-3 border rounded-md gap-3 flex-col">
                                                    <div class="flex ">
                                                        <input type="radio" id="<?php echo e($payment['name']); ?>" name="payment_setting_id" <?php echo e($key == 0 ? 'checked' : ''); ?> value="<?php echo e($payment['name']); ?>" onchange="document.getElementsByName('payment_type')[0].value = '<?php echo e($payment['name']); ?>'" class="text-primary focus:ring-primary mr-2 payment_change">
                                                        <label for="<?php echo e($payment['name']); ?>" class="flex items-center w-full flex-wrap">
                                                            <span class="font-medium mr-3"><?php echo e($payment['name_string']); ?></span>
                                                            <div class="center-descrp text-sm"> <?php echo e($payment['detail']); ?> </div>
                                                            
                                                        </label>
                                                    </div>

                                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($payment['name'] == 'whatsapp'): ?>
                                                        <form method="POST" action="<?php echo e(route('user.whatsapp',$slug)); ?>" class="payment-method-form">
                                                            <?php echo csrf_field(); ?>
                                                            <div class="form-group w-100">
                                                                <input name="wts_number" class="form-input max-w-md phone-number" value="<?php echo e(old('wts_number')); ?>"  id="wts_number" type="text" placeholder="<?php echo e(__('Enter Your Phone Number')); ?>">
                                                            </div>

                                                        </form>
                                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($payment['name'] == 'bank_transfer'): ?>
                                                        <div class="form-group w-100">
                                                            <input type="file" name="payment_receipt" class="form-input max-w-md mb-3 bank_transfer_receipt">
                                                        </div>
                                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($payment['name'] == 'Paiementpro'): ?>
                                                    <div class="flex gap-3 sm:flex-row flex-col">
                                                        <div class="form-group" id="mobile_div">
                                                            <input type="text" name="mobile_number" value="<?php echo e(old('mobile_number')); ?>"  class="form-input max-w-md font-style mobile_number" id="mobile_number" placeholder="<?php echo e(__('Enter Your Phone Number')); ?>">
                                                        </div>
                                                        <div class="form-group flex flex-col gap-2" id="channel_div">
                                                            <input type="text" name="channel" value="<?php echo e(old('channel')); ?>"  class="form-input max-w-md font-style channel" id="channel" placeholder="<?php echo e(__('Enter Your Phone Number')); ?>">
                                                            <small class="text-red-500"><?php echo e(__('Example')); ?> : OMCIV2,MOMO,CARD,FLOOZ ,PAYPAL</small>
                                                        </div>
                                                    </div>
                                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                </div>
                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4">
                                <h2 class="font-semibold text-xl md:mb-4 mb-3">5. <?php echo e(__('Order Notes')); ?></h2>
                                <div class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm">
                                    <label class="block mb-2 font-medium md:text-base text-sm"><?php echo e(__('Add Comments About Your Order')); ?></label>
                                    <textarea class="form-input" name="payment_comment" placeholder="Description" rows="3"></textarea>
                                </div>
                            </div>
                        
                            <?php echo $__env->yieldPushContent('CustomFieldView'); ?>
                            <?php echo $__env->yieldPushContent('addCheckoutAttachment'); ?>

                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($settings['additional_notes']) && $settings['additional_notes'] == 'on'): ?>
                                <div class="mt-4">
                                    <h2 class="font-semibold text-xl md:mb-4 mb-3">6. <?php echo e(__('Additional Notes')); ?></h2>
                                    <div class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm">
                                        <div class="acnav-list additional_notes">

                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    </div>

                    <?php echo $__env->make('front_end.hooks.checkout_form', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

                    <?php echo Form::hidden('coupon_code', null, ['id' => 'coupon_code']); ?>

                    <?php echo Form::hidden('sub_total', null, ['id' => 'sub_total_checkout_page']); ?>

                    <!-- Right Side - Order Summary -->
                    <div class="lg:w-1/3">
                        <div class="checkout_page_cart col-lg-3 col-12 ">
                            <div class="checkout-page-right">
        
                            </div>
                        </div>
                    </div>
                </div>
            <?php echo Form::close(); ?>

        </div>
    </section>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</main>
<?php $__env->stopSection(); ?>

<?php
    $payfast_mode = \App\Models\Utility::GetValueByName('payfast_mode', $store->id);
    $pfHost = $payfast_mode == 'sandbox' ? 'sandbox.payfast.co.za' : 'www.payfast.co.za';
?>

<?php $__env->startPush('page-script'); ?>
    
<script>
    'use strict';
    $(document).ready(function() {
        loadAddressForm("billing", ".selling_address_form");
    
        handleShippingAddress();

        loadAdditionalNote();
    });

    $(document).on("change", "#same-address", function() {
        handleShippingAddress();
    });

    function handleShippingAddress() {
        if ($("#same-address").is(":checked")) {
            loadAddressForm("delivery", ".shipping_address_form");
        } else {
            $(".shipping_address_form").empty();
        }
    }

    function loadAddressForm(type, targetElement) {
        $.ajax({
            url: "<?php echo e(route('order.address.form',$slug)); ?>",
            method: "POST",
            data: {
                type: type
            },
            headers: {
                "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
            },
            success: function(response) {

                $(targetElement).html(response.data.html);
                // common_event();
            },
            error: function(xhr, status, error) {
                try {
                    var res = JSON.parse(xhr.responseText);
                    show_toastr('Error', res.message || 'Something went wrong', 'error');
                } catch (e) {
                    show_toastr('Error', 'Something went wrong', 'error');
                }
            },
        });
    }

    function loadAdditionalNote(type, targetElement) {
        $.ajax({
            url: additionalnote,
            method: 'GET',
            context: this,
            headers: {
                "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
            },
            success: function (response) {
                $('.paymentlist_data_tab').removeClass('is-open');
                $('.paymentlist_data').hide();
                $('.additional_notes').html(response.html_data);
                $('.additional_notes_tab').addClass('is-open');
            },
            error: function(xhr, status, error) {
                try {
                    var res = JSON.parse(xhr.responseText);
                    show_toastr('Error', res.message || 'Something went wrong', 'error');
                } catch (e) {
                    show_toastr('Error', 'Something went wrong', 'error');
                }
            }
        });
    }
</script>

    <script src="https://cdn.jsdelivr.net/jquery.validation/1.16.0/jquery.validate.min.js"></script>
    <script src="https://cdn.jsdelivr.net/jquery.validation/1.16.0/additional-methods.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-steps/1.1.0/jquery.steps.min.js"></script>
    <script>
        $(document).ready(function() {
            setTimeout(() => {
                $("#formdata").append("<div id='get-payfast-input-data'></div>");
            }, 100);
        });
        $(document).on("click", ".payfast_form", function(event) {

            var payment_type = $('.payment_types').val();
            console.log(payment_type);
            
            if (payment_type == 'payfast') {
                get_payfast_status();
            }else{
                $('#formdata').submit();
            }
        });
        <?php if(\Auth::guard('customers')->user()): ?>
            function get_payfast_status(amount,coupon){
                var formdata = $('#formdata').serializeArray();
                var slug = '<?php echo e($store->slug); ?>';
                $.ajax({
                    url: payfast_payment,
                    method: 'POST',
                    data: formdata,
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    },
                    success: function (data) {
                        if (data.success == true) {
                            $('#get-payfast-input-data').append(data.inputs);
                            $('#formdata').submit();
                        }else{
                            show_toastr('Error', data.inputs, 'error')
                        }
                    }
                });
            }
        <?php else: ?>
            function get_payfast_status(amount,coupon){
                var formdata = $('#formdata').serializeArray();
                var slug = '<?php echo e($store->slug); ?>';
                $.ajax({
                    url: payfast_payment_guest,
                    method: 'POST',
                    data: formdata,
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    },
                    success: function (data) {
                        if (data.success == true) {
                            $('#get-payfast-input-data').append(data.inputs);
                            $('#formdata').submit();
                        }else{
                            show_toastr('Error', data.inputs, 'error')
                        }
                    }
                });
            }
        <?php endif; ?>
        $(".payfast_form").on("click", function(e) {
            var payment_type = $('#payment_type').val();

            if (payment_type == 'payfast') {
                $('#formdata').attr('action', "https://<?php echo e($pfHost); ?>/eng/process");
                e.preventDefault();
            }
        });
    </script>


<script>
(function() {

    var originalAjax = $.ajax;

    var pendingCityAjaxSettings = null;
    var lastValidCitySettings = null;
    var activeShippingState = null;
    var isInternalRetrigger = false;

    function injectShippingParams(data, val, label) {
        val = val || '';
        label = label || '';

        if (typeof data === 'string') {
            data = data.replace(/&?shipping_id=[^&]*/g, '').replace(/&?shipping_label=[^&]*/g, '');
            var prefix = data.length > 0 ? '&' : '';
            return data + prefix + 'shipping_id=' + encodeURIComponent(val) + '&shipping_label=' + encodeURIComponent(label);
        } else if (window.FormData && data instanceof FormData) {
            data.set('shipping_id', val);
            data.set('shipping_label', label);
            return data;
        } else if (typeof data === 'object' && data !== null) {
            data.shipping_id = val;
            data.shipping_label = label;
            return data;
        } else {
            return 'shipping_id=' + encodeURIComponent(val) + '&shipping_label=' + encodeURIComponent(label);
        }
    }

    function getCheckedShippingInfo($radios) {
        var $checked = $radios.filter(':checked');
        return {
            val: $checked.val(),
            label: $.trim($checked.closest('label').text()) || $.trim($checked.parent().text())
        };
    }

    $(document).on('change', '#state', function() {
        if (isInternalRetrigger) {
            isInternalRetrigger = false;
            return;
        }

        console.log("🔄 تغيرت المحافظة (فعلياً من المستخدم).. تختيم الشحن الحالي كـ stale");

        $('input[name="shipping_id"]').attr('data-stale', 'true').prop('checked', false);

        activeShippingState = null;
        pendingCityAjaxSettings = null;
        lastValidCitySettings = null;
    });

    // 🚨 الإصلاح الأساسي: بدل الاعتماد على $.ajaxSetup (اللي بينتجاهل مع global:false)
    // منعمل override مباشر لـ $.ajax نفسها، فمنمسك كل ريكويست 100% بغض النظر عن أي إعداد.
    $.ajax = function(settings) {

        var url = (typeof settings === 'string') ? settings : (settings && settings.url) || '';

        if (url.indexOf('city-list') !== -1) {

            var $freshRadios = $('input[name="shipping_id"]:not([data-stale="true"])');
            var $checkedFresh = $freshRadios.filter(':checked');

            if ($freshRadios.length === 0 || $checkedFresh.length === 0 || activeShippingState !== $('#state').val()) {
                console.warn("⏸️ [City Hold] تم اعتراض ريكويست city-list مباشرة (override) .. بانتظار شحن المحافظة الجديدة.");
                pendingCityAjaxSettings = $.extend(true, {}, settings);
                // منرجع Deferred مرفوض هادئ حتى ما ينكسر أي .then/.done/.fail معلق عليه بالكود الأصلي
                return $.Deferred().reject({}, 'abort', 'held').promise();
            }

            var info = getCheckedShippingInfo($freshRadios);
            console.log("🚀 [City Send]", info.label, "| ID:", info.val);

            settings.data = injectShippingParams(settings.data, info.val, info.label);
            lastValidCitySettings = $.extend(true, {}, settings);
        }

        var jqXHR = originalAjax.apply(this, arguments);

        if (url.indexOf('city-list') === -1 && url.indexOf('shipping-method') !== -1) {
            jqXHR.done(function() {
                var $freshRadios = $('input[name="shipping_id"]:not([data-stale="true"])');

                if ($freshRadios.length > 0 && activeShippingState !== $('#state').val()) {
                    console.log("✨ وصل شحن المحافظة الجديدة! تحديث + إطلاق المدن المحجوزة...");
                    activeShippingState = $('#state').val();

                    if ($freshRadios.filter(':checked').length === 0) {
                        $freshRadios.first().prop('checked', true);
                    }

                    var info = getCheckedShippingInfo($freshRadios);

                    if (pendingCityAjaxSettings) {
                        console.log("▶️ إطلاق طلب المدن المحجوز...");
                        var settingsToRun = $.extend(true, {}, pendingCityAjaxSettings);
                        settingsToRun.data = injectShippingParams(settingsToRun.data, info.val, info.label);

                        pendingCityAjaxSettings = null;
                        $.ajax(settingsToRun);
                    } else {
                        isInternalRetrigger = true;
                        $('#state').trigger('change');
                    }
                }
            });
        }

        return jqXHR;
    };

    $(document).on('change', 'input[name="shipping_id"]', function() {
        var $checked = $(this);

        if ($checked.attr('data-stale') !== 'true' && lastValidCitySettings) {
            var info = getCheckedShippingInfo($(this));

            console.log("🔄 تبديل وسيلة الشحن يدوياً.. تحديث المدن فقط");

            var newSettings = $.extend(true, {}, lastValidCitySettings);
            newSettings.data = injectShippingParams(newSettings.data, $checked.val(),
                $.trim($checked.closest('label').text()) || $.trim($checked.parent().text()));

            $.ajax(newSettings);
        }
    });

})();
</script>
<?php $__env->stopPush(); ?>
<?php echo $__env->make('front_end.layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/storesyria/public_html/resources/views/front_end/pages/checkout.blade.php ENDPATH**/ ?>