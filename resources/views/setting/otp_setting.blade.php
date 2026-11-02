{{--
    LYNOMIA OTP, on the design system.
    =============================================================================================

    NINE OF ITS TEN CONTROLS HAD NO ACCESSIBLE NAME. Every field was a bare
    `<label class="form-label">` with no `for`, over an input or select with a `name` and no
    `id` — so the text sat beside the control and was associated with nothing. Only `is_active`
    was wired. Each label now names the control that follows it, and each control carries an
    `id` derived from its `name`, which is also what the panel's own script looks them up by.

    THE API-SECRET FIELD IS THE PRECEDENT F117 COPIED, and it is deliberately unchanged in
    substance: `type="password"`, `autocomplete="new-password"`, NO `value`, and a placeholder
    that says an empty box keeps the stored secret — paired with
    `OtpIntegrationController`'s `if ($request->filled('api_secret'))`, which is what makes an
    empty save safe. `SettingsCredentialExposureTest` pins that pair, so neither half can be
    removed without the other failing.
--}}
<div class="ss-card">
    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('LYNOMIA OTP Settings') }}</h2>
        </div>
    </div>

    <div class="ss-card__body">

        <form method="POST" action="{{ route('otp.integration.save') }}">
            @csrf

            <div class="row">

                {{-- Base URL --}}
                <div class="col-md-12 mb-3">
                    <label class="ss-label" for="base_url">
                        {{ __('Base URL') }}
                    </label>

                    <input id="base_url"
                        type="url"
                        name="base_url"
                        class="ss-input"
                        value="{{ old('base_url', $otpIntegration->base_url ?? 'https://otp.lynomia.com') }}"
                        required
                    >
                </div>

                {{-- API Secret --}}
                <div class="col-md-12 mb-3">
                    <label class="ss-label" for="api_secret">
                        {{ __('API Secret') }}
                    </label>

                    <input id="api_secret"
                        type="password"
                        name="api_secret"
                        class="ss-input"
                        autocomplete="new-password"
                        placeholder="{{ $otpIntegration ? __('Leave empty to keep current secret') : __('Enter API Secret') }}"
                    >

                    @if($otpIntegration)
                        <p class="ss-field__hint">
                            {{ __('Leave empty if you do not want to change the saved API Secret.') }}
                        </p>
                    @endif
                </div>

                {{-- OTP Channel --}}
                <div class="col-md-6 mb-3">
                    <label class="ss-label" for="otp_channel">
                        {{ __('OTP Channel') }}
                    </label>

                    <select id="otp_channel"
                        name="otp_channel"
                        id="otp_channel"
                        class="ss-input"
                        required
                    >
                        <option value="sms"
                            {{ old('otp_channel', $otpIntegration->otp_channel ?? 'sms') == 'sms' ? 'selected' : '' }}>
                            {{ __('SMS') }}
                        </option>

                        <option value="whatsapp"
                            {{ old('otp_channel', $otpIntegration->otp_channel ?? '') == 'whatsapp' ? 'selected' : '' }}>
                            {{ __('WhatsApp') }}
                        </option>
                    </select>
                </div>

                {{-- Integration Status --}}
                <div class="col-md-6 mb-3 d-flex align-items-end">
                    <x-ss.switch name="is_active" id="is_active" value="1"
                                 :label="__('Enable LYNOMIA OTP')"
                                 :checked="(bool) old('is_active', $otpIntegration->is_active ?? false)" />
                </div>

                {{-- ============================= --}}
                {{-- SMS SETTINGS --}}
                {{-- ============================= --}}

                <div id="sms_settings" class="col-12">
                    <div class="row">

                        <div class="col-md-6 mb-3">
                            <label class="ss-label" for="sms_mode">
                        {{ __('SMS Mode') }}
                    </label>

                            <select id="sms_mode"
                                name="sms_mode"
                                id="sms_mode"
                                class="ss-input"
                            >
                                <option value="">
                                    {{ __('Select Mode') }}
                                </option>

                                <option value="devices"
                                    {{ old('sms_mode', $otpIntegration->sms_mode ?? '') == 'devices' ? 'selected' : '' }}>
                                    {{ __('Devices') }}
                                </option>

                                <option value="credits"
                                    {{ old('sms_mode', $otpIntegration->sms_mode ?? '') == 'credits' ? 'selected' : '' }}>
                                    {{ __('Credits') }}
                                </option>
                            </select>
                        </div>

                        {{-- SIM --}}
                        <div class="col-md-6 mb-3" id="sim_box">
                            <label class="ss-label" for="sms_sim">
                        {{ __('SIM') }}
                    </label>

                            <select id="sms_sim" name="sms_sim" class="ss-input">
                                <option value="">
                                    {{ __('Select SIM') }}
                                </option>

                                <option value="1"
                                    {{ (string) old('sms_sim', $otpIntegration->sms_sim ?? '') === '1' ? 'selected' : '' }}>
                                    SIM 1
                                </option>

                                <option value="2"
                                    {{ (string) old('sms_sim', $otpIntegration->sms_sim ?? '') === '2' ? 'selected' : '' }}>
                                    SIM 2
                                </option>
                            </select>
                        </div>

                        {{-- Device ID --}}
                        <div class="col-md-12 mb-3" id="device_box">
                            <label class="ss-label" for="sms_device">
                        {{ __('Device ID') }}
                    </label>

                            <input id="sms_device"
                                type="text"
                                name="sms_device"
                                class="ss-input"
                                value="{{ old('sms_device', $otpIntegration->sms_device ?? '') }}"
                                placeholder="{{ __('Enter Device ID') }}"
                            >
                        </div>

                        {{-- Gateway --}}
                        <div class="col-md-12 mb-3" id="gateway_box">
                            <label class="ss-label" for="sms_gateway">
                        {{ __('Gateway ID') }}
                    </label>

                            <input id="sms_gateway"
                                type="text"
                                name="sms_gateway"
                                class="ss-input"
                                value="{{ old('sms_gateway', $otpIntegration->sms_gateway ?? '') }}"
                                placeholder="{{ __('Enter Gateway ID') }}"
                            >
                        </div>

                        {{-- Priority --}}
                        <div class="col-md-6 mb-3" id="priority_box">
                            <label class="ss-label" for="sms_priority">
                        {{ __('Priority') }}
                    </label>

                            <select id="sms_priority" name="sms_priority" class="ss-input">

                                <option value="0"
                                    {{ (string) old('sms_priority', $otpIntegration->sms_priority ?? 0) === '0' ? 'selected' : '' }}>
                                    0 - {{ __('High Priority') }}
                                </option>

                                <option value="1"
                                    {{ (string) old('sms_priority', $otpIntegration->sms_priority ?? 0) === '1' ? 'selected' : '' }}>
                                    1 - {{ __('High Priority') }}
                                </option>

                                <option value="2"
                                    {{ (string) old('sms_priority', $otpIntegration->sms_priority ?? 0) === '2' ? 'selected' : '' }}>
                                    2 - {{ __('Normal Priority') }}
                                </option>

                            </select>
                        </div>

                    </div>
                </div>

                {{-- ============================= --}}
                {{-- WHATSAPP SETTINGS --}}
                {{-- ============================= --}}

                <div id="whatsapp_settings" class="col-12">

                    <div class="row">

                        <div class="col-md-12 mb-3">
                            <label class="ss-label" for="whatsapp_account">
                        {{ __('WhatsApp Account ID') }}
                    </label>

                            <input id="whatsapp_account"
                                type="text"
                                name="whatsapp_account"
                                class="ss-input"
                                value="{{ old('whatsapp_account', $otpIntegration->whatsapp_account ?? '') }}"
                                placeholder="{{ __('Enter WhatsApp Account ID') }}"
                            >
                        </div>

                        <div class="col-md-12">
                            <div class="alert alert-info">
                                {{ __('The WhatsApp Account ID can be found in your LYNOMIA dashboard.') }}
                            </div>
                        </div>

                    </div>

                </div>

                {{-- Save --}}
                <div class="col-md-12 text-end mt-3">
                    <button type="submit" class="ss-btn ss-btn--primary">
                        {{ __('Save Changes') }}
                    </button>
                </div>

            </div>
        </form>



<div class="col-md-12 mt-4">
    <hr>

    <h5 class="mb-3">{{ __('Test OTP') }}</h5>

    <div class="row align-items-end">
        <div class="col-md-8">
            <label class="ss-label" for="otp_test_phone">
                {{ __('Phone Number') }}
            </label>

            <input
                type="text"
                id="otp_test_phone"
                class="ss-input"
                placeholder="+9639XXXXXXXX"
            >
        </div>

        <div class="col-md-4">
            <button
                type="button"
                id="send_test_otp"
                class="ss-btn ss-btn--secondary w-100"
            >
                {{ __('Send Test OTP') }}
            </button>
        </div>
    </div>

    <div id="otp_test_result" class="mt-3"></div>
</div>







    </div>








</div>

<script>
    function toggleOtpChannel() {

        let channel = $('#otp_channel').val();

        if (channel === 'sms') {

            $('#sms_settings').show();
            $('#whatsapp_settings').hide();

            toggleSmsMode();

        } else if (channel === 'whatsapp') {

            $('#sms_settings').hide();
            $('#whatsapp_settings').show();

        }
    }

    function toggleSmsMode() {

        let mode = $('#sms_mode').val();

        if (mode === 'devices') {

            $('#device_box').show();
            $('#sim_box').show();
            $('#priority_box').show();

            $('#gateway_box').hide();

        } else if (mode === 'credits') {

            $('#device_box').hide();
            $('#sim_box').hide();
            $('#priority_box').hide();

            $('#gateway_box').show();

        } else {

            $('#device_box').hide();
            $('#gateway_box').hide();
            $('#sim_box').hide();
            $('#priority_box').hide();

        }
    }

    toggleOtpChannel();

    $(document).on('change', '#otp_channel', function () {
        toggleOtpChannel();
    });

    $(document).on('change', '#sms_mode', function () {
        toggleSmsMode();
    });
</script>


<script>
$(document).on('click', '#send_test_otp', function () {

    let phone = $('#otp_test_phone').val();

    if (!phone) {
        $('#otp_test_result').html(
            '<div class="alert alert-danger">Please enter a phone number.</div>'
        );
        return;
    }

    let button = $(this);

    button.prop('disabled', true);
    button.text('Sending...');

    $.ajax({
        url: "{{ route('otp.integration.test') }}",
        type: "POST",

        data: {
            _token: "{{ csrf_token() }}",

            phone: phone,

           

            base_url: $('input[name="base_url"]').val(),

            api_secret: $('input[name="api_secret"]').val(),

            whatsapp_account: $('input[name="whatsapp_account"]').val(),

            sms_mode: $('#sms_mode').val(),
            sms_device: $('input[name="sms_device"]').val(),
            sms_gateway: $('input[name="sms_gateway"]').val(),
            sms_sim: $('select[name="sms_sim"]').val(),
            sms_priority: $('select[name="sms_priority"]').val()
        },

        success: function(response) {

            let type = response.success ? 'success' : 'danger';

            $('#otp_test_result').html(
                '<div class="alert alert-' + type + '">' +
                response.message +
                '</div>'
            );
        },

        error: function(xhr) {

            let message = 'Something went wrong.';

            if (xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            }

            $('#otp_test_result').html(
                '<div class="alert alert-danger">' +
                message +
                '</div>'
            );
        },

        complete: function() {
            button.prop('disabled', false);
            button.text("{{ __('Send Test OTP') }}");
        }
    });
});
</script>