<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Login key
    |--------------------------------------------------------------------------
    |
    | This is your authorization key which you get from your profile.
    | Retrieve your key from https://www.larabug.com
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Outbound reporting -- OFF unless deliberately enabled
    |--------------------------------------------------------------------------
    |
    | OWNER DECISION, P1-SEC-14: outbound LaraBug reporting is DISABLED until the
    | sensitive-data protection has been verified against a real payload. Local
    | error logging is unaffected -- the `single` channel still records everything,
    | so nothing is lost; only the transmission to the third party stops.
    |
    | DEFAULT FALSE, AND IT HAS TO BE A SEPARATE SWITCH rather than "unset the key".
    | `LaraBug\Http\Client::report()` POSTs whether or not a key is set (it checks
    | neither, and sends `Authorization: Bearer ` with an empty token), so an unset
    | key is not an off switch -- it is an unauthenticated send. And once the keys
    | ARE restored for a verified deployment, "keyed" must not silently mean "send":
    | this flag is the thing an operator turns on, once, on purpose.
    |
    | Turning it on is a deliberate act with a precondition. See
    | docs/security/P01-SEC-14-ERROR-TRACKER-EXPOSURE.md for what must be verified
    | first, and for the historical-exposure question only server access can settle.
    */

    'outbound_enabled' => (bool) env('LARABUG_OUTBOUND_ENABLED', false),

    'login_key' => env('LB_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Project key
    |--------------------------------------------------------------------------
    |
    | This is your project key which you receive when creating a project
    | Retrieve your key from https://www.larabug.com
    |
    */

    'project_key' => env('LB_PROJECT_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Environment setting
    |--------------------------------------------------------------------------
    |
    | This setting determines if the exception should be send over or not.
    |
    */

    'environments' => [
        'production',
    ],

    /*
    |--------------------------------------------------------------------------
    | Project version
    |--------------------------------------------------------------------------
    |
    | Set the project version, default: null.
    | For git repository: shell_exec("git log -1 --pretty=format:'%h' --abbrev-commit")
    |
    */
    'project_version' => null,

    /*
    |--------------------------------------------------------------------------
    | Lines near exception
    |--------------------------------------------------------------------------
    |
    | How many lines to show near exception line. The more you specify the bigger
    | the displayed code will be. Max value can be 50, will be defaulted to
    | 12 if higher than 50 automatically.
    |
    */

    'lines_count' => 12,

    /*
    |--------------------------------------------------------------------------
    | Prevent duplicates
    |--------------------------------------------------------------------------
    |
    | Set the sleep time between duplicate exceptions. This value is in seconds, default: 60 seconds (1 minute)
    |
    */

    'sleep' => 60,

    /*
    |--------------------------------------------------------------------------
    | Skip exceptions
    |--------------------------------------------------------------------------
    |
    | List of exceptions to skip sending.
    |
    */

    'except' => [
        'Symfony\Component\HttpKernel\Exception\NotFoundHttpException',
    ],

    /*
    |--------------------------------------------------------------------------
    | Key filtering
    |--------------------------------------------------------------------------
    |
    | Filter out these variables before sending them to LaraBug
    |
    */

    'blacklist' => [
        '*authorization*',
        '*password*',
        '*token*',
        '*auth*',
        '*verification*',
        '*credit_card*',
        'cardToken', // mollie card token
        '*cvv*',
        '*iban*',
        '*name*',
        '*email*',
        // P1-SEC-14: what the first list missed -- cookies and sessions (the session id, `remember_web_*`, the raw `cookie` header), every
        // gateway secret and key (`stripe_secret_key`, `google2fa_secret`, `*_api_key`), card numbers and expiries under every spelling the
        // payment forms use (`nmi_card_number`, `cardNumber`, `PowerTranz_card_number`, `cc_number`, `exp_month`, `expiry`), one-time codes
        // and pins, signatures and CSRF values.
        '*cookie*',
        '*session*',
        '*remember*',
        '*secret*',
        '*key*',
        '*card*',
        '*cc_*',
        '*number*',
        '*expir*',
        '*exp_month*',
        '*exp_year*',
        '*otp*',
        'pin',
        '*_pin',
        '*signature*',
        '*csrf*',
        '*bearer*',
        '*login*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Release git hash
    |--------------------------------------------------------------------------
    |
    |
    */

    // 'release' => trim(exec('git --git-dir ' . base_path('.git') . ' log --pretty="%h" -n1 HEAD')),

    /*
    |--------------------------------------------------------------------------
    | Server setting
    |--------------------------------------------------------------------------
    |
    | This setting allows you to change the server.
    |
    */

    'server' => env('LB_SERVER', 'https://www.larabug.com/api/log'),

    /*
    |--------------------------------------------------------------------------
    | Verify SSL setting
    |--------------------------------------------------------------------------
    |
    | Enables / disables the SSL verification when sending exceptions to LaraBug
    | Never turn SSL verification off on production instances
    |
    */
    'verify_ssl' => env('LB_VERIFY_SSL', true),

];
