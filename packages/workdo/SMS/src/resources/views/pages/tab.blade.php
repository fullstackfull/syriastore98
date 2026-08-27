
<li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
    <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'sms_setting' ? 'active' : '' }}" data-url="{{ route('sms-setting.form') }}" data-tab="sms_setting" id="pills-sms_setting-tab" data-bs-toggle="pill" data-bs-target="#eco-sms_setting" type="button" role="tab" aria-controls="pills-sms_setting" aria-selected="false">
    {{ __('SMS Settings') }}
    </a>
    <input type="hidden" name="sms_setting" value="{{ session()->get('sms_setting') }}" id="sms_setting_session">
</li>


@push('custom-script')
<script>
    $(document).ready(function() {
        var sms_setting = $('#sms_setting_session').val();
        getSMSInput(sms_setting);
    });
    $(window).on('load', function () {
        var sms_setting = $('#sms_setting_session').val();
        getSMSInput(sms_setting)
    });

    $(document).on('change', '#sms_setting', function() {
        var sms_setting = $('#sms_setting').val();
        $('#loader').fadeIn();
        getSMSInput(sms_setting);
    });

    function getSMSInput(sms_setting) {        
        $.ajax({
            url: '{{ route("get.sms.fields") }}',
            type: 'POST',

            data: {
                "_token": "{{ csrf_token() }}",
                "sms_setting": sms_setting,
            },
            success: function(data) {
                $('#loader').fadeOut();
                $('#getsmsfields').empty();
                $('#getsmsfields').append(data.html)
                $('.email').append(data.html)
            },

        });
    }
</script>
@endpush   