<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
  </head>
  @php


      $payment_setting = getSuperAdminAllSetting();
        $paymentwall_public_key = isset($payment_setting['paymentwall_public_key']) ? $payment_setting['paymentwall_public_key'] : '';
        // $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
        $currency = 'USD';
        $user=\Auth::user();

  @endphp

  <script src="https://api.paymentwall.com/brick/build/brick-default.1.5.0.min.js"> </script>
  <div id="payment-form-container"> </div>
  <script>
      var urls = "{{ route('promote.success') }}";
      var brick = new Brick({
      public_key: '{{ $paymentwall_public_key }}', // please update it to Brick live key before launch your project
      amount: '{{$amount}}' ,
      currency: '{{$currency}}',
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
