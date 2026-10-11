<div class="card">
    <div class="card-header">
        <h5>{{ __('LYNOMIA OTP Settings') }}</h5>
    </div>

    <div class="card-body">

        <form method="POST" action="{{ route('otp.integration.save') }}">
            @csrf

            <div class="row">

                {{-- Base URL --}}
                <div class="col-md-12 mb-3">
                    <label class="form-label">
                        {{ __('Base URL') }}
                    </label>

                    <input
                        type="url"
                        name="base_url"
                        class="form-control"
                        value="{{ old('base_url', $otpIntegration->base_url ?? 'https://otp.lynomia.com') }}"
                        required
                    >
                </div>

                {{-- API Secret --}}
                <div class="col-md-12 mb-3">
                    <label class="form-label">
                        {{ __('API Secret') }}
                    </label>

                    <input
                        type="password"
                        name="api_secret"
                        class="form-control"
                        autocomplete="new-password"
                        placeholder="{{ $otpIntegration ? __('Leave empty to keep current secret') : __('Enter API Secret') }}"
                    >

                    @if($otpIntegration)
                        <small class="text-muted">
                            {{ __('Leave empty if you do not want to change the saved API Secret.') }}
                        </small>
                    @endif
                </div>

                {{-- OTP Channel --}}
                <div class="col-md-6 mb-3">
                    <label class="form-label">
                        {{ __('OTP Channel') }}
                    </label>

                    <select
                        name="otp_channel"
                        id="otp_channel"
                        class="form-control"
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
                    <div class="form-check form-switch mb-2">
                        <input
                            type="checkbox"
                            class="form-check-input"
                            name="is_active"
                            value="1"
                            id="is_active"
                            {{ old('is_active', $otpIntegration->is_active ?? false) ? 'checked' : '' }}
                        >

                        <label class="form-check-label" for="is_active">
                            {{ __('Enable LYNOMIA OTP') }}
                        </label>
                    </div>
                </div>

                {{-- ============================= --}}
                {{-- SMS SETTINGS --}}
                {{-- ============================= --}}

                <div id="sms_settings" class="col-12">
                    <div class="row">

                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                {{ __('SMS Mode') }}
                            </label>

                            <select
                                name="sms_mode"
                                id="sms_mode"
                                class="form-control"
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
                            <label class="form-label">
                                {{ __('SIM') }}
                            </label>

                            <select name="sms_sim" class="form-control">
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
                            <label class="form-label">
                                {{ __('Device ID') }}
                            </label>

                            <input
                                type="text"
                                name="sms_device"
                                class="form-control"
                                value="{{ old('sms_device', $otpIntegration->sms_device ?? '') }}"
                                placeholder="{{ __('Enter Device ID') }}"
                            >
                        </div>

                        {{-- Gateway --}}
                        <div class="col-md-12 mb-3" id="gateway_box">
                            <label class="form-label">
                                {{ __('Gateway ID') }}
                            </label>

                            <input
                                type="text"
                                name="sms_gateway"
                                class="form-control"
                                value="{{ old('sms_gateway', $otpIntegration->sms_gateway ?? '') }}"
                                placeholder="{{ __('Enter Gateway ID') }}"
                            >
                        </div>

                        {{-- Priority --}}
                        <div class="col-md-6 mb-3" id="priority_box">
                            <label class="form-label">
                                {{ __('Priority') }}
                            </label>

                            <select name="sms_priority" class="form-control">

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
                            <label class="form-label">
                                {{ __('WhatsApp Account ID') }}
                            </label>

                            <input
                                type="text"
                                name="whatsapp_account"
                                class="form-control"
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
                    <button type="submit" class="btn btn-primary">
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
            <label class="form-label">
                {{ __('Phone Number') }}
            </label>

            <input
                type="text"
                id="otp_test_phone"
                class="form-control"
                placeholder="+9639XXXXXXXX"
            >
        </div>

        <div class="col-md-4">
            <button
                type="button"
                id="send_test_otp"
                class="btn btn-info w-100"
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