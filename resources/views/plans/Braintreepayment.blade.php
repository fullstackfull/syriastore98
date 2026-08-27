<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="author" content="Braintree">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0" />
    <title>{{ __('Braintree') }}</title>
    <meta name="description" content="Braintree">
    <meta name="keywords" content="Braintree">
    {{-- <link rel="shortcut icon" href="{{ get_module_img('Braintree') }}" type="image/x-icon"> --}}
    <link
        href="https://fonts.googleapis.com/css2?family=Noto+Sans:ital,wght@0,100..900;1,100..900&family=Readex+Pro:wght@160..700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('public/assets/Braintree_payment/css/main-style.css') }}">
    <link rel="stylesheet" href="{{ asset('public/assets/Braintree_payment/css/responsive.css') }}">
    <link rel="icon" href="{{ asset('public/assets/Braintree_payment/images/favicon.png') }}">
    <script src="https://js.braintreegateway.com/web/dropin/1.31.0/js/dropin.min.js"></script>
</head>

<body>

    <div class="payment">
        <div class="payment-form">
            <div class="payment-logo">
                <img src="{{ asset('public/assets/Braintree_payment/images/favicon.png') }}" alt="logo" loading="lazy">
                <h1>{{ Module_Alias_Name('Braintree') }}</h1>
            </div>


            <form id="checkout-form" method="post" action="{{ $action }}">
                @csrf
                <div class="row">
                    <div class="col-12">
                        <div class="form-group user d-flex align-items-center justify-content-between">
                            @if ($user != ' ')
                                <div class="user-name">
                                    <label><small>{{ __('Business') }}</small></label>
                                    <h6 class="h6">{{ $user }}</h6>
                                </div>
                            @endif
                            <div class="amount">
                                <label><small>{{ __('Amount') }}</small></label>
                                <div class="Payment-amount">
                                    <span>{{ $amount }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <input type="hidden" id="nonce" name="payment_method_nonce">
                @if (!empty($orderID))
                    <input type="hidden" value="{{ $orderID }}" name="order_id">
                @endif
            </form>
            <div id="bt-dropin" class="checkout-form-auto"></div>
            <a href="{{ $return_url }}" class="btn cancel-btn">{{ __('Back') }}</a>
        </div>
    </div>


    <script src="{{ asset('public/assets/Braintree_payment/js/custom.js') }}"></script>
    <script>
        let isSubmitting = false;

        braintree.dropin.create({
            authorization: "{{ $clientToken }}",
            container: '#bt-dropin'
        }, function(createErr, instance) {
            if (createErr) {
                console.error(createErr);
                return;
            }

            instance.on('paymentMethodRequestable', function(event) {
                if (isSubmitting) return;

                instance.requestPaymentMethod(function(err, payload) {
                    if (err) {
                        console.error(err);
                        return;
                    }

                    document.querySelector('#nonce').value = payload.nonce;
                    var formData = new FormData(document.querySelector('#checkout-form'));

                    if (!isSubmitting) {
                        isSubmitting = true;

                        fetch(document.querySelector('#checkout-form').action, {
                                method: 'POST',
                                body: formData
                            })
                            .then(response => response.json())
                            .then(data => {
                            console.log(data);
                                window.location.href = data.return_url ??
                                    "{{ $return_url }}";
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                isSubmitting = false; // Allow resubmission if an error occurs
                            });
                    }
                });
            });
        });
    </script>
</body>

</html>
