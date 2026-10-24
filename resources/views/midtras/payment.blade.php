<html>
  <head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700" rel="stylesheet">
    {{-- P1-SEC-17 F2: no `data-client-key`. This used to print the merchant's SERVER key there. Midtrans'
         snap.js reads the attribute only for custom-UI configuration and runs without it; snap.pay(token) below
         does not need it. A real client key needs its own setting, which does not exist yet. --}}
    <script type="text/javascript"
      src="https://app.sandbox.midtrans.com/snap/snap.js"></script>
    <!-- Note: replace with src="https://app.midtrans.com/snap/snap.js" for Production environment -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
  </head>

  <body>


    <form action="{{ route($data['fallback_url'],$data) }}" id="submit_form" method="POST">
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
