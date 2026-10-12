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
    
    
    
    'zid' => [
    'client_id' => 7858,
    'client_secret' => 'WU8VxKPSdSWKi8Lhrwobxx1YNDDnGcmxZyvhmVOI',
    'redirect_uri' => 'https://storesyria.com/zid/oauth/callback',
    'hook_user' => 'zidhook',
'hook_pass' => 'StoreSyria2026HookXyz',
],
    
    
    
    
    
    
        'deliverysyria' => [
        'url'     => 'https://deliverysyria.com',
        'api_key' => 'aaaasdjfjdkffffffffffffskkkkkkkkkkkkkkkkkkkkk',
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


    'delivery_firebase' => [
        'project_id' => env('DELIVERY_FIREBASE_PROJECT_ID', 'store-syria-delivery-62e52'),
        'credentials' => env('DELIVERY_FIREBASE_CREDENTIALS', 'storage/app/firebase/service-account-deliveryMan.json'),
    ],

'webuzo' => [
    'host'       => env('WEBUZO_HOST'),
    'username'   => env('WEBUZO_USERNAME'),
    'api_key'    => env('WEBUZO_API_KEY'),
    'port'       => env('WEBUZO_PORT', 2003),
    'verify_ssl' => env('WEBUZO_VERIFY_SSL', true),
],

    /*
    |--------------------------------------------------------------------------
    | Paymera
    |--------------------------------------------------------------------------
    |
    | EVERY VALUE HERE IS env()-SOURCED WITH A SAFE DEFAULT. No credential is
    | written in this file and none may be. This block exists so that the keys
    | the Paymera code already READS are declared somewhere, which they were
    | not: `git grep -in paymera -- config/` returned nothing at all, so every
    | config('services.paymera.*') lookup in the codebase resolved to null.
    |
    | CREDENTIAL-SOURCE ANALYSIS, the reason this block changes no behaviour
    | ---------------------------------------------------------------------
    | There are TWO different credential sources in play, and they disagree:
    |
    |   - The per-store value the merchant actually fills in is a SETTING, not
    |     an env var: Utility::GetValueByName('PAYMERA_USERNAME', $store->id).
    |     That is what PaymentService::processPaymera() uses to authorise the
    |     create-payment call (PaymentService.php:383, and :471 in the dead
    |     processPaymera00000 twin), and it is what the settings screen writes
    |     (PaymentSettingController.php:177-178 lists PAYMERA_TERMINAL_ID and
    |     PAYMERA_USERNAME as the Paymera required_fields, and
    |     Setting.php:1059-1074 reads them back per store).
    |
    |   - The status POLL reads a global config key instead:
    |     PaymentController::paymeraStatusCredential() reads
    |     config('services.paymera.username'). That key has never existed, and
    |     it dates to the initial commit (3c39c160).
    |
    | So the poll could never authenticate, in any deployment, ever. Paymera
    | checkout cannot complete today and could not before either. `env()` does
    | not change that: PAYMERA_USERNAME is absent from .env, .env.example and
    | .env.testing (verified by name, never by value), so this key still
    | resolves to null and pollPaymentStatus() still fails closed on the
    | PAYMERA_STATUS_UNAUTHENTICATED sentinel. That is deliberate.
    |
    | THE CORRECT MINIMAL CHANGE, which is an OWNER decision and is NOT made
    | here: point paymeraStatusCredential() at the same per-store setting the
    | checkout call already uses --
    |
    |     Utility::GetValueByName('PAYMERA_USERNAME', $store->id)
    |
    | -- because the credential is per store, and a single global env var cannot
    | express that on a shared-database multi-tenant platform. It is not done
    | here because pollPaymentStatus() is the sole arbiter of whether a Paymera
    | order is created, so repointing it changes live payment behaviour for a
    | real merchant's money. It also needs the store in scope at that call site,
    | which it currently is not.
    |
    */
    'paymera' => [

        // Bearer credential for the status API. Null today; see above.
        'username' => env('PAYMERA_USERNAME'),

        /*
        | The response field carrying the captured amount, used by
        | PaymentVerificationService::verifyPaymeraCapture(). Left unset so the
        | existing candidate-name probe (PAYMERA_AMOUNT_FIELD_CANDIDATES) keeps
        | running exactly as it does now. GATE G(4) supplies the real name.
        */
        'callback_amount_field' => env('PAYMERA_CALLBACK_AMOUNT_FIELD'),

        /*
        | Callback signature scheme. All four must be configured before
        | verifyPaymeraSignature() can verify anything; with any of them missing
        | it returns UNVERIFIABLE, which is unchanged fail-closed behaviour.
        | The field names, the algorithm and the secret exist only in Paymera's
        | own integration document (GATE G(4)) and are not guessed here.
        */
        'callback_signature_field' => env('PAYMERA_CALLBACK_SIGNATURE_FIELD'),
        'callback_signature_algorithm' => env('PAYMERA_CALLBACK_SIGNATURE_ALGORITHM'),
        'callback_signature_secret' => env('PAYMERA_CALLBACK_SIGNATURE_SECRET'),

        /*
        | Ordered field names forming the signed string. verifyPaymeraSignature()
        | requires a non-empty ARRAY, so a comma-separated env value is split
        | here and an absent one stays an empty array -- i.e. unconfigured, i.e.
        | still fail-closed. Plain values only: this file must stay cacheable, so
        | no closure.
        */
        'callback_signature_payload' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PAYMERA_CALLBACK_SIGNATURE_PAYLOAD', ''))
        ), static fn ($field) => $field !== '')),

        /*
        | THE DOCUMENTED ESCAPE HATCH, and the only reason this block is needed
        | now. PaymentVerificationService::paymeraSignatureIsRequired() reads
        | this key and DEFAULTS TO TRUE, which means an unconfigured signature
        | REJECTS every Paymera callback and no order is created. That is the
        | intended fail-closed posture, and the default is preserved here
        | unchanged -- absent env var still yields true.
        |
        | Declaring the key is what makes the hatch reachable: the owner can set
        | PAYMERA_REQUIRE_CALLBACK_SIGNATURE=false to restore the previous
        | (unverified) behaviour while GATE G(4) is answered, instead of having
        | to edit verification code to do it. No verification logic changes.
        */
        'require_callback_signature' => env('PAYMERA_REQUIRE_CALLBACK_SIGNATURE', true),

        /*
        | Reported, NOT changed: the status-poll host is hardcoded to
        | `egate-t.paymera.cc` at PaymentController::pollPaymentStatus(), and
        | `egate-t` is Paymera's TEST gateway. There is no way to point
        | production at the live gateway without editing that line. Declaring a
        | key for it here would not help, because nothing reads one; making the
        | code read it is a deployment decision for the owner.
        */

    ],

];