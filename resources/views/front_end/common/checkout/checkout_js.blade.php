@php
    $payfast_mode = \App\Models\Utility::GetValueByName('payfast_mode');
    $pfHost = $payfast_mode == 'sandbox' ? 'sandbox.payfast.co.za' : 'www.payfast.co.za';
@endphp
@push('page-script')
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
        if (payment_type == 'payfast') {
            get_payfast_status();
        } else {
            $('#formdata').submit();
        }
    });
    @if (\Auth::guard('customers')->user())
        function get_payfast_status(amount, coupon) {
            var formdata = $('#formdata').serializeArray();
            var slug = '{{ $store->slug }}';
            $.ajax({
                url: payfast_payment,
                method: 'POST',
                data: formdata,
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function(data) {
                    if (data.success == true) {
                        $('#get-payfast-input-data').append(data.inputs);
                        $('#formdata').submit();
                    } else {
                        show_toastr('Error', data.inputs, 'error')
                    }
                }
            });
        }
    @else
        function get_payfast_status(amount, coupon) {
            var formdata = $('#formdata').serializeArray();
            var slug = '{{ $store->slug }}';
            $.ajax({
                url: payfast_payment_guest,
                method: 'POST',
                data: formdata,
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function(data) {
                    if (data.success == true) {
                        $('#get-payfast-input-data').append(data.inputs);
                        $('#formdata').submit();
                    } else {
                        show_toastr('Error', data.inputs, 'error')
                    }
                }
            });
        }
    @endif
    $(".payfast_form").on("click", function(e) {
        var payment_type = $('#payment_type').val();

        if (payment_type == 'payfast') {
            $('#formdata').attr('action', "https://{{ $pfHost }}/eng/process");
            e.preventDefault();
        }
    });
</script>
@endpush