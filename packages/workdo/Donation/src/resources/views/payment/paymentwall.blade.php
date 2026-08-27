<head>
  <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
@php
    $paymentwall_public_key = \App\Models\Utility::GetValueByName('paymentwall_public_key',$store->id);
    $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME',$theme_name);

@endphp


<script src="https://api.paymentwall.com/brick/build/brick-default.1.5.0.min.js"> </script>
<div id="payment-form-container"> </div>
<script>
    var urls = "{{ route('store.donation.payment', $store->slug) }}";
    var brick = new Brick({
    public_key: '{{ $paymentwall_public_key }}', // please update it to Brick live key before launch your project
    amount: '{{$donation_amount}}' ,
    currency: '{{$CURRENCY_NAME}}',
    container: 'payment-form-container',
    form: {
        merchant: 'Paymentwall',
        product: '{{$store->name}}',
        pay_button: 'Pay',
        show_zip: true, // show zip code
        show_cardholder: true // show card holder name
    }
    });
    brick.showPaymentForm(function(data) {
        if(data.flag == 1){
        window.location.href =urls;
        }else{
        window.location.href =urls;
        }
    }, function(errors) {
        if(errors.flag == 1){
            window.location.href =urls;
        }else{
            window.location.href =urls;
        }
    });
</script>
