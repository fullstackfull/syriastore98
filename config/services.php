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
    | Cash on delivery
    |--------------------------------------------------------------------------
    |
    | WHEN A COD ORDER COUNTS AS PAID.
    |
    |   'order'    -- recorded Paid the moment the order is placed. THE DEFAULT,
    |                 and the pre-existing behaviour, byte for byte.
    |   'delivery' -- recorded Pending when placed, Paid when a merchant marks it
    |                 delivered. The CORRECT machine: for cash on delivery no
    |                 money has moved at checkout.
    |
    | THE DEFAULT IS DELIBERATELY THE OLD BEHAVIOUR, not the correct one. When an
    | order counts as paid decides when a merchant's wallet is credited and which
    | orders appear in a revenue report, so flipping it is a business change the
    | owner has to choose -- not a defect fix to impose on a live shop. Set
    | COD_SETTLEMENT=delivery to opt in. Read by
    | App\Services\PaymentVerificationService::codSettlementPolicy(); an
    | unrecognised value falls back to 'order' and is logged, because a typo here
    | must not stop a shop taking orders.
    |
    | PER-STORE WOULD BE BETTER and is NOT done here. This is one global switch,
    | whereas on a shared-database multi-tenant platform each merchant may run
    | COD differently. Making it per store needs a field on the payment-settings
    | screen (PaymentSettingController plus its view), which this agent does not
    | own; recorded as a cross-agent dependency.
    |
    */
    'cod' => [
        // Owner decision D-06, 2026-10-22: settle on delivery, and settle-at-order is NO LONGER a
        // permitted value -- a COD order recorded Paid before collection is uncollected money reported
        // as paid. The key is retained so that a deployment still carrying COD_SETTLEMENT=order gets a
        // loud warning from PaymentVerificationService::codSettlementPolicy() instead of silently
        // getting behaviour it did not ask for.
        'settlement' => env('COD_SETTLEMENT', 'delivery'),
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
        | THE STATUS-POLL HOST, now configurable instead of hardcoded.
        |
        | pollPaymentStatus() had `https://egate-t.paymera.cc` written into the
        | call, and `egate-t` is Paymera's TEST gateway -- so there was no way to
        | point production at the live gateway without editing code. The default
        | below is that exact host, byte for byte, so nothing changes until the
        | owner sets PAYMERA_STATUS_BASE_URL. WHICH host is correct for
        | production is Paymera's to say and is not guessed here.
        */
        'status_base_url' => env('PAYMERA_STATUS_BASE_URL', 'https://egate-t.paymera.cc'),

        /*
        | WHERE THE STATUS CREDENTIAL IS READ FROM. Defaults to TRUE, which is
        | the CORRECTED behaviour: the per-store Setting PAYMERA_USERNAME first,
        | this file's global `username` key only as a fallback.
        |
        | THE DEFECT THIS FIXES. paymeraStatusCredential() read ONLY
        | config('services.paymera.username'), a key that has never existed in
        | any deployment and dates to the initial commit (3c39c160). So the
        | credential was always null, the poll always abandoned, and Paymera
        | checkout could never complete -- not recently broken, never able to
        | complete. Meanwhile the value the merchant actually fills in is the
        | per-store setting, which is what processPaymera() already uses for the
        | CREATE-payment call (PaymentService.php:340, :428) and what the
        | settings screen writes (PaymentSettingController.php:177-178). On a
        | shared-database multi-tenant platform a single global env var cannot
        | express a per-store credential, so the env key was never going to be
        | right.
        |
        | SET THIS TO FALSE to restore the global-only lookup without a code
        | change.
        |
        | THIS CANNOT BY ITSELF CAUSE A PAID ORDER. The callback polls first and
        | then verifies the amount AND the signature; require_callback_signature
        | above defaults TRUE and the scheme is unconfigured (GATE G(4)), so the
        | callback is still rejected after the poll. The net effect is that the
        | poll authenticates instead of sending an empty bearer token.
        |
        | NOT VERIFIED: that Paymera accepts this value as a bearer token on the
        | status API, or that PAYMERA_USERNAME is the right field for that API
        | rather than only for the create call. No Paymera host is reachable from
        | this container and none was contacted.
        */
        'status_credential_from_store_setting' => env(
            'PAYMERA_STATUS_CREDENTIAL_FROM_STORE_SETTING',
            true
        ),

    ],

];