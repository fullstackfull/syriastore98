<?php
return [
    /*
    |--------------------------------------------------------------------------
    | Default Driver
    |--------------------------------------------------------------------------
    |
    | This value determines which of the following driver to use.
    | You can switch to a different driver at runtime.
    |
    */
    'default' => 'jenssegers',

    // Paths whose visits are NOT logged. The package tests each with `$request->is($path)`
    // (Shetabit\Visitor\Visitor::visit), so a bare word matches only a first-segment path and a
    // storefront URL needs its own wildcard.
    //
    // THE UNSUBSCRIBE ENTRY IS A CONFIDENTIALITY CONTROL, NOT A NOISE FILTER. The unsubscribe token is
    // a CAPABILITY carried in the path: whoever holds it can withdraw that recipient's marketing
    // consent. The visit logger writes `$request->fullUrl()` verbatim, and `SanitisedVisitor`'s path
    // redaction only recognises a Laravel ciphertext (`/eyJ...`), so a 43-character url-safe-base64
    // token survives into `shetabit_visits.url` — a table the merchant reads through the analytics
    // "top URLs" panel (Api\DashboardController, ThemeAnalyticController) and which a database dump
    // exposes wholesale (incident P1-SEC-40 is open). That would void the property UnsubscribeTokens
    // is built on: "only the hash is stored, so a read of the table yields no working link."
    //
    // It is the same defect class this project already fixed in the order-details link (P1-ORD-09),
    // and StorefrontVisitLogTest records the rule: a capability in a path must not be written down.
    //
    // (Line comments, not a block: the wildcard pattern below contains the sequence that would close
    // a `/* ... */` comment, and writing it inside one is a parse error.)
    'except' =>  ['login', 'register', '*/unsubscribe/*'],


    //name of the table which visit records should save in
    'table_name' =>  'shetabit_visits',

    /*
    |--------------------------------------------------------------------------
    | List of Drivers
    |--------------------------------------------------------------------------
    |
    | This is the array of Classes that maps to Drivers above.
    | You can create your own driver if you like and add the
    | config in the drivers array and the class to use for
    | here with the same name. You will have to implement
    | Shetabit\Visitor\Contracts\UserAgentParser in your driver.
    |
    */
    'drivers' => [
        'jenssegers' => \Shetabit\Visitor\Drivers\JenssegersAgent::class,
        'UAParser' => \Shetabit\Visitor\Drivers\UAParser::class,
    ]
];
