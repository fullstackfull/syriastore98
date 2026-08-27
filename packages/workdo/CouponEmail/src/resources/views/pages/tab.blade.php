<li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
    <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'coupon_email_setting' ? 'active' : '' }}"
        data-url="{{ route('coupon-email.form') }}" data-tab="coupon_email_setting" id="pills-coupon_email-tab"
        data-bs-toggle="pill" data-bs-target="#eco-coupon_email" type="button" role="tab"
        aria-controls="pills-coupon_email" aria-selected="false">
        {{ __('Coupon Email Settings') }}
        </a>
    <input type="hidden" name="couponemail_setting" value="{{ session()->get('couponemail_setting') }}"
        id="couponemail_setting_session">
</li>
@push('custom-script')
    <script>
        $('#pills-coupon_email-tab').on('click', function() {
            var couponemail_setting = $('#couponemail_setting').val() || 0;
            getCouponEmailInputs(couponemail_setting)
        })
        $(document).ready(function() {
            var couponemail_setting = $('#couponemail_setting_session').val() || 0;
            getCouponEmailInputs(couponemail_setting)
        });
        $(document).on('change', '#couponemail_setting', function() {
            $('#loader').fadeIn();
            var couponemail_setting = $('#couponemail_setting').val() || 0;
            getCouponEmailInputs(couponemail_setting)
        });
        function getCouponEmailInputs(couponemail_setting) {
            $.ajax({
                url: '{{ route('get.couponemail.fields') }}',
                type: 'POST',

                data: {
                    "_token": "{{ csrf_token() }}",
                    "couponemail_setting": couponemail_setting,
                },
                success: function(data) {
                    setTimeout(() => {
                        $('#loader').fadeOut();
                        $('#getcouponemailfields').html(data.html);
                    }, 100);
                },

            });
        }
    </script>
@endpush
