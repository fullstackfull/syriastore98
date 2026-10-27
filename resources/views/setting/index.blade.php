@extends('layouts.app')

@section('page-title', __('Settings'))

@section('action-button')
<!-- Search Input -->
<div class="admin-setting-search d-flex justify-content-end">
    {{-- It had a placeholder and NOTHING else: no label, so a screen reader announced an
         unnamed edit box, and the hint vanished the moment anything was typed. --}}
    <label class="ss-visually-hidden" for="tab-search">{{ __('Search settings') }}</label>
    <input type="text" id="tab-search" class="ss-input ss-setting-search" placeholder="{{ __('Search...') }}">
</div>
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Settings') }}</li>
@endsection

@section('content')
<style>
    .eco-main-tab{
        margin-top: 30px;
    }
    .eco-main-tab .nav-pills{
        gap: 15px;
    }
</style>
@php
$activeTab = session()->get('setting_tab') ?? 'email_setting';
@endphp
<div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="list-group list-group-flush app-seeting-tab" id="useradd-sidenav">
                    <ul class="nav nav-pills w-100  row store-setting-tab" id="pills-tab" role="tablist">
                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'email_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="email_setting" id="pills-home-tab" data-bs-toggle="pill" data-bs-target="#eco-1" type="button" role="tab" aria-controls="pills-home" aria-selected="{{ $activeTab == 'email_setting' ? 'true' : 'false' }}">
                                {{ __('Email Settings') }}
                            </a>

                        </li>

                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'brand_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="brand_setting" id="pills-brand-tab" data-bs-toggle="pill" data-bs-target="#eco-2" type="button" role="tab" aria-controls="pills-brand" aria-selected="{{ $activeTab == 'brand_setting' ? 'true' : 'false' }}">
                            {{ __('Brand Settings') }}
                            </a>
                        </li>
                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'system_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="system_setting" id="pills-system-tab" data-bs-toggle="pill" data-bs-target="#eco-3" type="button" role="tab" aria-controls="pills-system" aria-selected="{{ $activeTab == 'system_setting' ? 'true' : 'false' }}">
                                {{ __('System Settings') }}
                            </a>
                        </li>

                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'payment_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="payment_setting" id="pills-payment-tab" data-bs-toggle="pill" data-bs-target="#eco-5" type="button" role="tab" aria-controls="pills-payment" aria-selected="{{ $activeTab == 'payment_setting' ? 'true' : 'false' }}">
                                {{ __('Payment Settings') }}
                            </a>
                        </li>

                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'currency_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="currency_setting" id="pills-currency-tab" data-bs-toggle="pill" data-bs-target="#eco-5" type="button" role="tab" aria-controls="pills-currency" aria-selected="{{ $activeTab == 'currency_setting' ? 'true' : 'false' }}">
                                {{ __('Currency Settings') }}
                            </a>
                        </li>
<li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center" role="presentation">
                        <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'delivery_syria_setting' ? 'active' : '' }}" 
                           data-tab="delivery_syria_setting" 
                           id="pills-delivery-tab" 
                           data-bs-toggle="pill" 
                           data-bs-target="#delivery-syria-content" 
                           type="button" role="tab" aria-selected="{{ $activeTab == 'delivery_syria_setting' ? 'true' : 'false' }}">
                           {{ __('Delivery Syria') }}
                        </a>
                    </li>
                        @if (auth()->user() && auth()->user()->type == 'super admin')
                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'cookie_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="cookie_setting" id="pills-cookie-tab" data-bs-toggle="pill" data-bs-target="#eco-5" type="button" role="tab" aria-controls="pills-cookie" aria-selected="{{ $activeTab == 'cookie_setting' ? 'true' : 'false' }}">
                                {{ __('Cookie Settings') }}
                            </a>
                        </li>

                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'cache_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="cache_setting" id="pills-cache-tab" data-bs-toggle="pill" data-bs-target="#eco-5" type="button" role="tab" aria-controls="pills-cache" aria-selected="{{ $activeTab == 'cache_setting' ? 'true' : 'false' }}">
                                {{ __('Cache Settings') }}
                            </a>
                        </li>

                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'storage_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="storage_setting" id="pills-storage-tab" data-bs-toggle="pill" data-bs-target="#eco-5" type="button" role="tab" aria-controls="pills-storage" aria-selected="{{ $activeTab == 'storage_setting' ? 'true' : 'false' }}">
                                {{ __('Storage Settings') }}
                            </a>
                        </li>

                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'recaptcha_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="recaptcha_setting" id="pills-recaptcha-tab" data-bs-toggle="pill" data-bs-target="#eco-5" type="button" role="tab" aria-controls="pills-recaptcha" aria-selected="{{ $activeTab == 'recaptcha_setting' ? 'true' : 'false' }}">
                                {{ __('ReCaptcha Settings') }}
                            </a>
                        </li>

                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'chat_gpt_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="chat_gpt_setting" id="pills-chatgpt-tab" data-bs-toggle="pill" data-bs-target="#eco-5" type="button" role="tab" aria-controls="pills-chatgpt" aria-selected="{{ $activeTab == 'chat_gpt_setting' ? 'true' : 'false' }}">
                                {{ __('Chat GPT Key Settings') }}
                            </a>
                        </li>

                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'style_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="style_setting" id="pills-style-tab" data-bs-toggle="pill" data-bs-target="#eco-5" type="button" role="tab" aria-controls="pills-style" aria-selected="{{ $activeTab == 'style_setting' ? 'true' : 'false' }}">
                                {{ __('Style Settings') }}
                            </a>
                        </li>
                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'seo_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="seo_setting" id="pills-seo-tab" data-bs-toggle="pill" data-bs-target="#eco-5" type="button" role="tab" aria-controls="pills-seo" aria-selected="{{ $activeTab == 'seo_setting' ? 'true' : 'false' }}">
                                {{ __('SEO Settings') }}
                            </a>
                        </li>
                        @endif

                        @if (auth()->user() && auth()->user()->type == 'admin')
                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'email_notify_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="email_notify_setting" id="pills-email_notify-tab" data-bs-toggle="pill" data-bs-target="#eco-4" type="button" role="tab" aria-controls="pills-email_notify" aria-selected="{{ $activeTab == 'email_notify_setting' ? 'true' : 'false' }}">
                                {{ __('Email Notification Settings') }}
                            </a>
                        </li>

                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'shopify_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="shopify_setting" id="pills-shopify-tab" data-bs-toggle="pill" data-bs-target="#eco-4" type="button" role="tab" aria-controls="pills-shopify" aria-selected="{{ $activeTab == 'shopify_setting' ? 'true' : 'false' }}">
                                {{ __('Shopify Settings') }}
                            </a>
                        </li>

                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'woocom_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="woocom_setting" id="pills-woocom-tab" data-bs-toggle="pill" data-bs-target="#eco-4" type="button" role="tab" aria-controls="pills-woocom" aria-selected="{{ $activeTab == 'woocom_setting' ? 'true' : 'false' }}">
                                {{ __('Woocommerce Settings') }}
                            </a>
                        </li>

                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'webhook_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="webhook_setting" id="pills-webhook-tab" data-bs-toggle="pill" data-bs-target="#eco-6" type="button" role="tab" aria-controls="pills-webhook" aria-selected="{{ $activeTab == 'webhook_setting' ? 'true' : 'false' }}">
                                {{ __('Webhook Settings') }}
                            </a>
                        </li>
                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'loyality_pro_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="loyality_pro_setting" id="pills-loyality_pro-tab" data-bs-toggle="pill" data-bs-target="#eco-7" type="button" role="tab" aria-controls="pills-loyality_pro" aria-selected="{{ $activeTab == 'loyality_pro_setting' ? 'true' : 'false' }}">
                                {{ __('Loyality Program Settings') }}
                            </a>
                        </li>
                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'whatsapp_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="whatsapp_setting" id="pills-whatsapp-tab" data-bs-toggle="pill" data-bs-target="#eco-8" type="button" role="tab" aria-controls="pills-whatsapp" aria-selected="{{ $activeTab == 'whatsapp_setting' ? 'true' : 'false' }}">
                                {{ __('Whatsapp Settings') }}
                            </a>
                        </li>




{{-- The "lynomia chat" settings tab is retired with the legacy Chatwoot integration.
     Its replacement belongs to the Store Syria x Lynomia Chat project and is not built here. --}}







<li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center" role="presentation">
    <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'otp_setting' ? 'active' : '' }}"
       data-url="{{ route('setting.form') }}"
       data-tab="otp_setting"
       id="pills-otp-tab"
       data-bs-toggle="pill"
       data-bs-target="#eco-otp"
       type="button"
       role="tab" aria-selected="{{ $activeTab == 'otp_setting' ? 'true' : 'false' }}">
        {{ __('LYNOMIA OTP') }}
    </a>
</li>





                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'whatsapp_msg_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="whatsapp_msg_setting" id="pills-contact-tab" data-bs-toggle="pill" data-bs-target="#eco-9" type="button" role="tab" aria-controls="pills-contact" aria-selected="{{ $activeTab == 'whatsapp_msg_setting' ? 'true' : 'false' }}">
                                {{ __('Whatsapp Message Settings') }}
                            </a>
                        </li>
                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'twilio_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="twilio_setting" id="pills-twilio-tab" data-bs-toggle="pill" data-bs-target="#eco-twilio" type="button" role="tab" aria-controls="pills-twilio" aria-selected="{{ $activeTab == 'twilio_setting' ? 'true' : 'false' }}">
                                {{ __('Twilio Settings') }}
                            </a>
                        </li>
                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'pixel_field_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="pixel_field_setting" id="pills-pixel_field-tab" data-bs-toggle="pill" data-bs-target="#eco-pixel_field" type="button" role="tab" aria-controls="pills-pixel_field" aria-selected="{{ $activeTab == 'pixel_field_setting' ? 'true' : 'false' }}">
                                {{ __('Pixel Fields Settings') }}
                            </a>
                        </li>
                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'stock_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="stock_setting" id="pills-stock-tab" data-bs-toggle="pill" data-bs-target="#eco-stock" type="button" role="tab" aria-controls="pills-stock" aria-selected="{{ $activeTab == 'stock_setting' ? 'true' : 'false' }}">
                            {{ __('Stock Settings') }}
                            </a>
                        </li>
                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'tax_opt_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="tax_opt_setting" id="pills-tax_opt-tab" data-bs-toggle="pill" data-bs-target="#eco-tax_opt" type="button" role="tab" aria-controls="pills-tax_opt" aria-selected="{{ $activeTab == 'tax_opt_setting' ? 'true' : 'false' }}">
                            {{ __('Tax Option Settings') }}
                            </a>
                        </li>
                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'pwa_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="pwa_setting" id="pills-pwa-tab" data-bs-toggle="pill" data-bs-target="#eco-pwa" type="button" role="tab" aria-controls="pills-pwa" aria-selected="{{ $activeTab == 'pwa_setting' ? 'true' : 'false' }}">
                            {{ __('PWA Settings') }}
                            </a>
                        </li>
                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'refund_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="refund_setting" id="pills-refund-tab" data-bs-toggle="pill" data-bs-target="#eco-refund" type="button" role="tab" aria-controls="pills-refund" aria-selected="{{ $activeTab == 'refund_setting' ? 'true' : 'false' }}">
                            {{ __('Refund Settings') }}
                            </a>
                        </li>
                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                            <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'whatsapp_notify_setting' ? 'active' : '' }}" data-url="{{ route('setting.form') }}" data-tab="whatsapp_notify_setting" id="pills-whatsapp_notify-tab" data-bs-toggle="pill" data-bs-target="#eco-whatsapp_notify" type="button" role="tab" aria-controls="pills-whatsapp_notify" aria-selected="{{ $activeTab == 'whatsapp_notify_setting' ? 'true' : 'false' }}">
                            {{ __('WhatsApp Business API') }}
                            </a>
                        </li>

                        @stack('settingTab')
                        @endif
                    {{-- This `</ul>` was MISSING. The list opened at the top of the rail and was
                         closed by the wrapper `</div>`, so `role="tablist"` held no list and the
                         browser's recovery decided where the tabs ended. --}}
                    </ul>
                </div>
            </div>
        </div>

        {{-- The one panel every tab loads into. `role="tabpanel"` and `aria-labelledby` were both
             absent, so the tabs controlled nothing a screen reader could follow; the JS keeps the
             label pointing at whichever tab is current.

             THERE WERE TWO OF THESE, both `id="pills-tabContent"`. A duplicate id is invalid, and
             it had a visible consequence: `$('#pills-tabContent')` returns only the first, so the
             AJAX wrote into one and `hide()`/`show()` moved one, while the second sat empty
             forever. The dead one is gone. --}}
        <div class="col-xl-12">
            <div class="tab-content" id="pills-tabContent" role="tabpanel"
                 aria-labelledby="pills-home-tab" tabindex="0">
            </div>
        </div>







<!-- ضع الـ HTML الخاص بـ Delivery Syria هنا (لكن بدون كلاسات إضافية) -->
<div id="delivery-syria-content" style="display: none;">
    <div class="card p-3">
        <form action="{{ route('delivery.syria.update') }}" method="POST">
            @csrf
            <div class="row">
                <div class="col-md-12">
                    <div class="ss-field">
                        {{-- F117: the stored token never reaches the page. It lives on the USER row
                             (`users.deliverysyria_secret`), not in `settings`, so the handler falls back to the
                             model rather than to `Setting` — the same rule, a different store. --}}
                        <x-ss.secret-field
                            name="delivery_syria_token"
                            :label="__('Delivery Syria Secret Token')"
                            :stored="auth()->user()->deliverysyria_secret" />
                    </div>
                </div>
            </div>
            <div class="col-md-12 text-right">
                <button type="submit" class="btn btn-primary">{{ __('Save Changes') }}</button>
            </div>
        </form>
    </div>
</div>

    </div>
@endsection

@push('custom-script')
<script>
    var currentActiveTab = $('.nav-link.active').data('tab');
    var currentActiveUrl = $('.nav-link.active').data('url');

    $(document).ready(function() {
        initializePwaListeners();

        if (currentActiveTab) {
            getSettingForm(currentActiveTab, currentActiveUrl);
        } else {
            getSettingForm('email_setting', "{{ route('setting.form') }}");
        }

        // The Chat GPT key repeater was implemented TWICE in this file, with its own
        // `maxField`, `fieldHTML` and counter each. The copy that used to sit here bound with
        // `$('.add_button').click(...)` -- a DIRECT binding, evaluated on ready, when the
        // settings form has not been fetched yet and `.add_button` matches nothing. So it bound
        // to zero elements and the delegated copy in `initRepeater()` did all the work. Both
        // definitions also had to be edited in step whenever the row markup changed, which is
        // how one of them came to inject `class="form-control"` into a panel that no longer uses
        // it. One implementation now, in `initRepeater()`, called on load and after each tab
        // fetch.
    });

 $(document).on('click', '.nav-link', function (e){
    e.preventDefault();
    var newTab = $(this).data('tab');
    
    // 1. تحديث الكلاسات للأزرار
    // `active` is the VISUAL state and `aria-selected` the accessible one; this moved only the
    // first, so after any tab click a screen reader still reported Email as the selected tab
    // while the merchant was looking at Storage. Both move together now, and the panel's
    // `aria-labelledby` follows the tab that filled it.
    $('.nav-link').removeClass('active').attr('aria-selected', 'false');
    $(this).addClass('active').attr('aria-selected', 'true');
    if (this.id) {
        $('#pills-tabContent').attr('aria-labelledby', this.id);
    }

    // 2. إذا ضغطت على Delivery Syria
    if (newTab === 'delivery_syria_setting') {
        $('#pills-tabContent').hide(); // إخفاء منطقة الـ AJAX
        $('#delivery-syria-content').show(); // إظهار منطقة الديلفري
        return;
    }

    // 3. إذا ضغطت على أي تاب آخر
    $('#delivery-syria-content').hide(); // إخفاء منطقة الديلفري
    $('#pills-tabContent').show(); // إظهار منطقة الـ AJAX
    
    getSettingForm(newTab, $(this).data('url'));
});

 function getSettingForm(tab_type, url) {
    $.ajax({
        url: url,
        type: "POST",
        data: { tab_type: tab_type, _token: "{{ csrf_token() }}" },
        success: function(result) {
            $('#pills-tabContent').html(result.data.content); // استبدال المحتوى بالكامل
            reinitializeEventListeners();
        }
    });
}

    $(document).on('change', '#email_setting', function() {
        var emailSetting = $(this).val();
        getEmailSettingFields(emailSetting);
    });

    function myFunction() {
        var copyText = document.getElementById("myInput");
        copyText.select();
        copyText.setSelectionRange(0, 99999)
        document.execCommand("copy");
        show_toastr('Success', "{{ __('Link copied') }}", 'success');
    }

    function AppFunction() {
        var copyText = document.getElementById("AppInput");
        copyText.select();
        copyText.setSelectionRange(0, 99999)
        document.execCommand("copy");
        show_toastr('Success', "{{ __('Link copied') }}", 'success');
    }

    $(document).on("click", '.send_email', function(e) {
        e.preventDefault();
        var title = $(this).attr('data-title');
        var size = 'md';
        var url = $(this).attr('data-url');
        $("#commanModel .modal-title").html(title);
        $("#commanModel .modal-dialog").addClass('modal-' + size);
        $("#commanModel").modal('show');

        if (typeof url != 'undefined') {
            var data = {
                mail_driver: $("input[name='mail_driver']").val(),
                mail_host: $("input[name='mail_host']").val(),
                mail_port: $("input[name='mail_port']").val(),
                mail_username: $("input[name='mail_username']").val(),
                mail_password: $("input[name='mail_password']").val(),
                mail_encryption: $("input[name='mail_encryption']").val(),
                mail_from_address: $("input[name='mail_from_address']").val(),
                mail_from_name: $("input[name='mail_from_name']").val(),
            }

            $.ajax({
                url: url,
                method: 'POST',
                data: data,
                context: this,
                success: function(response) {
                    $('#loader').fadeOut();
                    $('#commanModel .modal-body').html(response);
                }
            });
        }
    });

    $(document).on("click", '.test-whatsapp-massage', function(e) {
        e.preventDefault();
        var title = $(this).attr('data-title');
        var size = 'md';
        var url = $(this).attr('data-url');
        $("#commanModel .modal-title").html(title);
        $("#commanModel .modal-dialog").addClass('modal-' + size);
        $("#commanModel").modal('show');

        if (typeof url != 'undefined') {
            var data = {
                whatsapp_phone_number_id: $("#whatsapp_phone_number_id").val(),
                whatsapp_access_token: $("#whatsapp_access_token").val(),
            }

            $.ajax({
                url: url,
                method: 'POST',
                data: data,
                context: this,
                success: function(response) {
                    $('#loader').fadeOut();
                    $('#commanModel .modal-body').html(response);
                }
            });
        }
    });


    $(document).on('change', '[name=storage_setting]', function() {
        if ($(this).val() == 's3') {
            $('.s3-setting').removeClass('d-none');
            $('.wasabi-setting').addClass('d-none');
            $('.local-setting').addClass('d-none');
        } else if ($(this).val() == 'wasabi') {
            $('.s3-setting').addClass('d-none');
            $('.wasabi-setting').removeClass('d-none');
            $('.local-setting').addClass('d-none');
        } else {
            $('.s3-setting').addClass('d-none');
            $('.wasabi-setting').addClass('d-none');
            $('.local-setting').removeClass('d-none');
        }
    });

    $(document).on('submit', '#test_email', function(e) {
        e.preventDefault();
        /* The two test modals used to share `id="email_sending"`, which is a duplicate id the
           moment both are in the DOM — and `getElementById` returns whichever came first. Each
           modal carries its own now. */
        $("#test_mail_sending").show();
        var post = $(this).serialize();
        var url = $(this).attr('action');
        $.ajax({
            type: "post",
            url: url,
            data: post,
            cache: false,
            beforeSend: function() {
                $('#test_email .btn-create').attr('disabled', 'disabled');
            },
            success: function(data) {
                $('#loader').fadeOut();
                if (data.is_success) {
                    show_toastr('Success', data.message, 'success');
                } else {
                    show_toastr('Error', data.message, 'error');
                }
                $("#test_mail_sending").hide();
                $('#commanModel').modal('hide');
            },
            complete: function() {
                $('#loader').fadeOut();
                $('#test_email .btn-create').removeAttr('disabled');
            },
        });
    });

    $(document).on('change', '.whatsapp-notification', function() {

        var status = $(this).prop('checked') == true ? 1 : 0;
        var notification_id = $(this).attr('id');
        $.ajax({
            type: "GET",
            dataType: "json",
            url: "{{ route('update.whatsapp.notification') }}",
            data: {
                'status': status,
                'notification_id': notification_id
            },
            success: function(data) {
                $('#loader').fadeOut();
                if (data.success) {
                    show_toastr('Success', data.success, 'success');
                } else {
                    show_toastr('Error', "{{ __('Something went wrong') }}", 'error');
                }
            },
        });
    });

    function check_theme(color_val) {
        $('.theme-color').prop('checked', false);
        $('input[value="' + color_val + '"]').prop('checked', true);
    }


    $(document).on('change', '.currency_note', function() {
        sendData();
    });

    function sendData(selectedValue, type) {
        var formData = $('#setting-currency-form').serialize();
        $.ajax({
            type: 'POST',
            url: '{{ route('update.note.value') }}',
            data: formData,
            success: function(response) {
                $('#loader').fadeOut();
                var formattedPrice = response.formatted_price;
                $('#formatted_price_span').text(formattedPrice);
            }
        });
    }

    function getEmailSettingFields(emailSetting) {
        $.ajax({
            url: '{{ route('get.email.fields') }}',
            type: 'POST',
            data: {
                "_token": "{{ csrf_token() }}",
                "emailsetting": emailSetting,
            },
            success: function(data) {
                $('#loader').fadeOut();
                $('#getfields').empty();
                $('#getfields').append(data.html)
                $('.email').append(data.html)
            },
        });
    }
    </script>

<script>
    $(document).on('click','.colorPicker', function(e) {
        $('body').removeClass('custom-color');
        if (/^theme-\d+$/) {
            $('body').removeClassRegex(/^theme-\d+$/);
        }
        $('body').addClass('custom-color');
        $('.themes-color-change').removeClass('active_color');
        $(this).addClass('active_color');
        const input = document.getElementById("color-picker");
        setColor();
        input.addEventListener("input", setColor);

        function setColor() {
            $(':root').css('--color-customColor', input.value);
        }

        $(`input[name='color_flag`).val('true');
    });

    $(document).on('click','.themes-color-change', function() {

        $(`input[name='color_flag`).val('false');

        var color_val = $(this).data('value');
        $('body').removeClass('custom-color');
        if (/^theme-\d+$/) {
            $('body').removeClassRegex(/^theme-\d+$/);
        }
        $('body').addClass(color_val);
        $('.theme-color').prop('checked', false);
        $('.themes-color-change').removeClass('active_color');
        $('.colorPicker').removeClass('active_color');
        $(this).addClass('active_color');
        $(`input[value=${color_val}]`).prop('checked', true);
    });

    $.fn.removeClassRegex = function(regex) {
        return $(this).removeClass(function(index, classes) {
            return classes.split(/\s+/).filter(function(c) {
                return regex.test(c);
            }).join(' ');
        });
    };

    document.addEventListener('DOMContentLoaded', function() {
        if (typeof initializePaynowListeners === 'function') {
            initializePaynowListeners();
        }
    });

    function reinitializeEventListeners() {
       // Example: Re-initialize plugins here
        feather.replace(); // Feather icons
        $('[data-toggle="popover"]').popover(); // Bootstrap Popover
        $('[data-toggle="tooltip"]').tooltip(); // Bootstrap Tooltip
       // $('.selectpicker').selectpicker('refresh'); // Bootstrap Select

        // Re-initialize Bootstrap Switch Buttons
        document.querySelectorAll('input[type=checkbox][data-toggle="switchbutton"]').forEach(function(t) {
            t.switchButton();
        });

        // Re-initialize Choices.js
        if (typeof comman_function === 'function') {
            comman_function();
        }

        // SimpleBar initialization
        $('.simplebar').each(function() {
            // Destroy previous instance if exists
            if (this.SimpleBar) {
                this.SimpleBar.unMount();
            }
            new SimpleBar(this);
        });

        if (typeof initializePaynowListeners === 'function') {
            initializePaynowListeners();
        }
        if (typeof initializePwaListeners === 'function') {
            initializePwaListeners();
        }
        if (typeof updateBadgeStyles === 'function') {
            updateBadgeStyles();
        }

        if ($('.select2').length > 0) { 
            $('.select2').select2({
                tags: true,
                createTag: function (params) {
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
        }

        // Refresh the wrapper selector each time content is loaded
        var wrapper = $('.field_wrapper');

        var maxField = 100;
        // Mirrors the `x-ss.secret-field` component's markup so a row the merchant adds looks
        // like the rows
        // (component name written WITHOUT angle brackets on purpose: Blade compiles a
        // component tag wherever it appears, and it does not know this is a JS comment --
        // an unclosed component tag here breaks the whole view. Writing one with a placeholder
        // name does too: Blade resolves whatever follows the prefix and throws "Unable to locate
        // a class or view for component". So the prefix is not written in this file at all
        // outside a real tag. Third time in this phase that a component name inside a comment
        // has broken a view.)
        // the server rendered -- it was still emitting `class="form-control"` after the panel
        // moved to the design system, so a new key box was a different shape and colour from
        // the one above it. `name="api_key[]"` (no id) is deliberate: a row that does not exist
        // yet has no row id to post, and PHP merges the bare-array entries with the
        // `api_key[e<id>]` ones on save.
        var fieldHTML =
            '<div class="d-flex gap-1 mb-4 align-items-start">' +
                '<div class="flex-grow-1"><div class="ss-field">' +
                    '<input type="password" class="ss-input" autocomplete="new-password" spellcheck="false" aria-label="{{__('Chat GPT Key')}}" name="api_key[]" value="" placeholder="{{__('Enter Chatgpt Key Here')}}"/>' +
                '</div></div>' +
                '<a href="javascript:void(0);" class="ss-btn ss-btn--danger ss-btn--icon remove_button" title="{{__('Remove field')}}"><i class="ti ti-trash" aria-hidden="true"></i><span class="ss-visually-hidden">{{__('Remove this key')}}</span></a>' +
            '</div>';
        var x = 1; // Reset counter if needed

        // Unbind any previous click events for .add_button and .remove_button
        $(document).off('click', '.add_button');
        $(document).off('click', '.remove_button');

        $(document).on('click', '.add_button', function() {
            if (x < maxField) {
                x++;
                $(wrapper).append(fieldHTML);
            }
        });

        $(document).on('click', '.remove_button', function() {
            // `.parent('div')` is still the ROW: the field gained wrapper divs above, but the
            // button is a direct child of the row in both the server-rendered and cloned shapes.
            $(this).parent('div').remove();
            x--;
        });
    }

    function initializePaynowListeners() {
        const paynowsandboxRadio = document.querySelector('input[name="paynow_mode"][value="sandbox"]');
        const paynowproductionRadio = document.querySelector('input[name="paynow_mode"][value="production"]');
        const paynowemailInputContainer = document.getElementById('paynow_pay_merchant_email');

        function toggleUrlInput() {
            if (paynowemailInputContainer) {
                if (paynowsandboxRadio && paynowsandboxRadio.checked) {
                    paynowemailInputContainer.style.display = 'block';
                } else {
                    paynowemailInputContainer.style.display = 'none';
                }
            }
        }

        // Initial toggle on page load or reinitialization
        toggleUrlInput();

        // Remove any existing event listeners to avoid duplication
        if (paynowsandboxRadio) {
            paynowsandboxRadio.removeEventListener('change', toggleUrlInput);
            paynowsandboxRadio.addEventListener('change', toggleUrlInput);
        }

        if (paynowproductionRadio) {
            paynowproductionRadio.removeEventListener('change', toggleUrlInput);
            paynowproductionRadio.addEventListener('change', toggleUrlInput);
        }
    }

    function initializePwaListeners() {
        if ($('.enable_pwa_store').length > 0) {
            if ($('.enable_pwa_store').is(':checked')) {
                $('.pwa_is_enable').removeClass('disabledPWA');
            } else {
                $('.pwa_is_enable').addClass('disabledPWA');
            }
        }
    }

    $(document).on('change', '#pwa_store', function() {
        if ($('.enable_pwa_store').is(':checked')) {

            $('.pwa_is_enable').removeClass('disabledPWA');
        } else {

            $('.pwa_is_enable').addClass('disabledPWA');
        }
    });

    document.getElementById('tab-search').addEventListener('input', function () {
        var searchValue = this.value.toLowerCase();
        var navItems = document.querySelectorAll('#pills-tab .nav-item');

        navItems.forEach(function (item) {
            var tabText = item.querySelector('.nav-link').textContent.toLowerCase();
            if (tabText.includes(searchValue)) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
    });
    
    // Payment gateway search functionality
    $(document).on("keyup", "#payment-search", function() {
        var value = $(this).val().toLowerCase();
        $("#payment-gateways .accordion-item").filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });
</script>
@endpush