<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coupons</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }

        .container {
            text-align: center;
        }

        .center {
            text-align: center
        }

        .card {
            display: inline-block;
            width: 45%;
            /* Adjusted width to fit two cards per row */
            margin: 10px;
            padding: 15px;
            border-radius: 5px;
            box-sizing: border-box;
            vertical-align: top;
            page-break-inside: avoid;
            /* Prevent card break across pages */
        }

        .card-design {
            border: 2px dashed #007bff;
            background-color: #f8f9fa;
        }

        .card-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .card-title a {
            text-decoration: none;
            color: #007bff;
            font-size: 14px;
            margin-left: 10px;
        }

        .discount,
        .expiry,
        .description,
        .coupon-code {
            font-size: 14px;
            margin-bottom: 10px;
        }

        .discount {
            color: #28a745;
            font-weight: bold;
        }

        .coupon-code {
            font-size: 16px;
            font-weight: bold;
            background-color: #007bff;
            color: #fff;
            padding: 5px 10px;
            border-radius: 5px;
        }

        .coupon-code a {
            text-decoration: none;
            color: #fff;
            margin-left: 10px;
        }
    </style>
</head>

<body>

    <h2 class="center">Available Coupons</h2>
    <br>
    <section class="container">
        @foreach ($coupons as $coupon)
            <div class="card card-design">
                <div class="card-title">
                    {{ $coupon->coupon_name }}
                    <a href="https://ecom-demo.workdo.io/stylique/home">visit-store</a>
                </div>
                <div class="discount">
                    {{ $coupon->discount_amount }} @if ($coupon->coupon_type == 'flat')
                        $
                    @else
                        %
                    @endif OFF
                </div>
                <div class="expiry">
                    Expires on: {{ $coupon->coupon_expiry_date }}
                </div>
                <div class="description">
                    Get <strong>{{ $coupon->discount_amount }} @if ($coupon->coupon_type == 'flat')
                            $
                        @else
                            %
                        @endif OFF</strong> on total order price by using this coupon code.
                </div>
                <div class="coupon-code">
                    <a href="#!" data-link={{ $coupon->coupon_code }} title="Click to copy code">
                        code: <span>{{ $coupon->coupon_code }}</span>
                    </a>
                </div>
            </div>
        @endforeach
    </section>

</body>

</html>
