<?php

/**
 * Lynomia Chat integration configuration.
 *
 * WHY EVERY CREDENTIAL HERE COMES FROM THE ENVIRONMENT AND NEVER FROM THE DATABASE.
 *
 * The retired Chatwoot integration stored its agent token in
 * `chatwoot_integrations.api_access_token` behind Laravel's `encrypted` cast. That cast
 * derives its key from APP_KEY, and this installation's APP_KEY was published in a tracked
 * .env in a public repository and has not been rotated
 * (docs/security/SECRET-ROTATION-CHECKLIST.md). An `encrypted` column therefore provides no
 * confidentiality here at all: anyone holding a database copy and the public key can read it.
 *
 * So the platform token is read from the process environment on each use and is never
 * persisted, never cast, never logged and never serialised to a response. The only values
 * this integration stores in the database are non-secret identifiers -- account ids, user
 * ids, link state.
 *
 * ACTIVATION IS OFF BY DEFAULT AND IS NOT A COMMERCIAL SWITCH. `enabled` is the engineering
 * kill switch for the whole integration. It stays false until the owner has resolved the
 * secret-exposure prerequisite and supplied non-production credentials for a test
 * environment. Merchant entitlement is a separate question answered by the plan's module
 * list; see LynomiaChatServiceProvider and the PlanModuleCheck middleware on the routes.
 */

return [
    // Engineering kill switch. Nothing in this module performs a remote call while false.
    'enabled' => env('LYNOMIA_INTEGRATION_ENABLED', false),

    // Base URL of the Lynomia installation. No default is supplied on purpose: the retired
    // integration hardcoded https://chat.lynomia.com in three places, which is how an
    // environment-specific endpoint ends up in a public repository. An unset value is a
    // configuration error, reported as one.
    'base_url' => env('LYNOMIA_BASE_URL'),

    // A Platform App access token, sent as the `api_access_token` header. Issued in Lynomia
    // Super Admin -> Platform Apps. It is scoped by platform_app_permissibles, so it can only
    // reach accounts this app created or was granted. Use a NON-PRODUCTION token until the
    // security prerequisite is closed.
    'platform_token' => env('LYNOMIA_PLATFORM_TOKEN'),

    'http' => [
        'timeout' => (int) env('LYNOMIA_HTTP_TIMEOUT', 10),
        'connect_timeout' => (int) env('LYNOMIA_HTTP_CONNECT_TIMEOUT', 5),
        'retries' => (int) env('LYNOMIA_HTTP_RETRIES', 2),
        'retry_delay_ms' => (int) env('LYNOMIA_HTTP_RETRY_DELAY_MS', 250),
    ],

    // How long a merchant has to complete an existing-account link before the pending row
    // expires and the verification code stops being accepted.
    'link_verification_ttl_minutes' => (int) env('LYNOMIA_LINK_TTL_MINUTES', 60),


    /*
     * How long one provisioning attempt may hold a store before another may take over.
     *
     * This is a crash window, not a timeout: a process killed mid-provision leaves its claim
     * behind, and without an expiry the store could never be connected again without an
     * operator clearing the row by hand. Five minutes is comfortably longer than three HTTP
     * calls at the timeouts above, so a live attempt is never stolen from, and short enough
     * that a merchant who hits a crash is not locked out for the afternoon.
     *
     * Taking over early would be safe anyway -- every step is either idempotent or guarded by
     * the adopt-before-create lookup -- but it would waste calls racing a request that is
     * still running.
     */
    'provisioning_lock_minutes' => (int) env('LYNOMIA_PROVISIONING_LOCK_MINUTES', 5),
];
