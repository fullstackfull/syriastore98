{{--
    HOST OVERRIDE of packages/workdo/LotteryPrizes/src/resources/views/payment/midtrans.blade.php. P1-SEC-17 F2.

    The module's own view printed `$data['midtrans_secret']` -- the merchant's Midtrans SERVER key -- as
    `data-client-key`, and then passed all of `$data` to route(), which put it in the form action's query
    string as well. packages/workdo/ cannot be edited, so Laravel's package-view override (this file is
    found first because `loadViewsFrom()` consults resources/views/vendor/lottery-prizes before the module's
    own directory) replaces it.

    TWO DIFFERENCES FROM THE MODULE'S FILE, and nothing else: no `data-client-key` attribute (snap.js
    reads it only for custom-UI configuration and runs without it), and the route() call drops the key.
    tests/Feature/Security/ModuleCredentialDisclosureTest::test_the_override_is_the_modules_view_with_
    only_those_two_changes fails if the module's view changes upstream, which is the signal to re-derive
    this file -- an override that silently freezes the module's page is a maintenance trap.
--}}
<html>
  <head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700" rel="stylesheet">
    <script type="text/javascript"
      src="https://app.sandbox.midtrans.com/snap/snap.js"></script>
    <!-- Note: replace with src="https://app.midtrans.com/snap/snap.js" for Production environment -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
  </head>

  <body>
    <form action="{{ route($data['fallback_url'], \Illuminate\Support\Arr::except($data, ['midtrans_secret'])) }}" id="submit_form" method="POST">
        @csrf
        <input type="hidden" name="json" id="json_callback">
    </form>

    <script type="text/javascript">
      // For example trigger on button clicked, or any time you need

        // Trigger snap popup. @TODO: Replace TRANSACTION_TOKEN_HERE with your transaction token
        window.snap.pay('{{$data['snap_token']}}', {
          onSuccess: function(result){
            /* You may add your own implementation here */
            send_response_to_form(result);
          },
          onPending: function(result){
            /* You may add your own implementation here */
            send_response_to_form(result);
          },
          onError: function(result){
            /* You may add your own implementation here */
            send_response_to_form(result);
          },
          onClose: function(){
            /* You may add your own implementation here */
            alert('you closed the popup without finishing the payment');
          }
        })


      function send_response_to_form(result){
        document.getElementById('json_callback').value = JSON.stringify(result);
        $('#submit_form').submit();
      }
    </script>
  </body>
</html>
