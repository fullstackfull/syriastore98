<!DOCTYPE html>
<html>

<head>
    <title>{{ __('Coupon Request') }}</title>
    <style>
        body {
            text-align: center;
        }
    </style>
</head>

<body>
    <h1>{{ $data['subject'] }}</h1>
    <p>Dear, {{ $data['customer_name'] }}</p>
    {!! $data['content'] !!}
    <p>From : {{ $data['store_name'] }}</p>
</body>

</html>
