<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'cashfree' => [
        'key' => '',
        'secret' => '',
        'url' => 'https://sandbox.cashfree.com/pg/orders',
    ],
    'google' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect' => '',
    ],
    'github' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect' => '',
    ],
    'linkedin-openid' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect' => '',
    ],
    'twitter' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect' => '',
    ],
    'bitbucket'=> [
        'client_id' => '',
        'client_secret' => '',
        'redirect' => '',
    ],
    'slack'=> [
        'client_id' => '',
        'client_secret' => '',
        'redirect' => '',
    ],
    'facebook' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect' => '',
    ],
    'nmi' => [
        'endpoint' => env('NMI_API_ENDPOINT'),
        'username' => env('NMI_API_USERNAME'),
        'password' => env('NMI_API_PASSWORD'),
    ],



'firebase' => [
    'project_id' => env('FIREBASE_PROJECT_ID'),
    'credentials' => env('FIREBASE_CREDENTIALS'),
],
];