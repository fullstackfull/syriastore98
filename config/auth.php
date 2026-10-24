<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option controls the default authentication "guard" and password
    | reset options for your application. You may change these defaults
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | here which uses session storage and the Eloquent user provider.
    |
    | All authentication drivers have a user provider. This defines how the
    | users are actually retrieved out of your database or other storage
    | mechanisms used by this application to persist your user's data.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'customers' => [  // Change 'customer' to 'customers'
            'driver' => 'session',
            'provider' => 'customers',
        ],
		'deliveryboy' => [
            'driver' => 'session',
            'provider' => 'delivery_boys',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication drivers have a user provider. This defines how the
    | users are actually retrieved out of your database or other storage
    | mechanisms used by this application to persist your user's data.
    |
    | If you have multiple user tables or models you may configure multiple
    | sources which represent each model / table. These sources may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
        'customers' => [  // Change 'customer' to 'customers'
            'driver' => 'eloquent',
            'model' => App\Models\Customer::class,
        ],
		'delivery_boys' => [
            'driver' => 'eloquent',
            'model' => App\Models\DeliveryBoy::class,
        ],
        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | You may specify multiple password reset configurations if you have more
    | than one user table or model in the application and you want to have
    | separate password reset settings based on the specific user types.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],

        'customers' => [
            'provider' => 'customers', // Adjust the provider if necessary
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
		'delivery_boys' => [
            'provider' => 'delivery_boys',
            'table' => 'password_resets',
            'expire' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the amount of seconds before a password confirmation
    | times out and the user is prompted to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => 10800,

    /*
    |--------------------------------------------------------------------------
    | Storefront customer password recovery
    |--------------------------------------------------------------------------
    |
    | otp_digits -- how many digits the API recovery code
    | (POST api/{slug}/forgot-password-send-otp) has.
    |
    | The default is 4, which is what the unreleased storefront client has always been sent and
    | what packages/workdo/Api/src/documentation/theme.json documents ("otp": "1234"). The server
    | is length-agnostic, and tests/Feature/Security/CustomerOtpLengthCompatibilityTest drives the
    | whole flow at 6 digits to prove it -- so this is a one-line environment change, not a code
    | change, the moment someone can confirm that client's input accepts 6.
    |
    | Raising it is a real security improvement: with the per-account send cap and attempt limit in
    | App\Services\CustomerPasswordResetService, 4 digits leaves roughly a one-in-three chance to
    | an attacker sustaining the maximum rate for a week; 6 digits leaves about 0.4%.
    |
    | The value is clamped to [MIN_OTP_DIGITS, MAX_OTP_DIGITS] by that service, so a typo here
    | cannot produce a shorter, weaker code.
    |
    */

    'customer_password_reset' => [
        'otp_digits' => env('CUSTOMER_OTP_DIGITS', 4),
    ],

];
