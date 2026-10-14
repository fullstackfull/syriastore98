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

    /*
     * HOW LONG A PER-PERSON LYNOMIA CREDENTIAL LIVES, and where a copy of it may be kept.
     *
     * The BFF acts on a merchant's behalf with a bearer confined to their own workspace and
     * carrying their own role. Lynomia clamps the lifetime to [5 minutes, 30 days]; an hour is
     * short enough that a leaked copy dies on its own and long enough that a session of work
     * does not re-mint constantly.
     *
     * THE CACHE STORE DEFAULTS TO `array`, WHICH IS NOT A CACHE AT ALL -- it lives for the
     * request and is then gone. That costs one extra call to Lynomia per merchant request and
     * it is the right default here: this installation's default cache driver is `file`, so any
     * other default would write a live Lynomia bearer to disk in plaintext. That is the mistake
     * the retired Chatwoot integration made with its agent token, and the reason nothing in
     * this module is persisted (see the note at the top of this file about APP_KEY).
     *
     * An operator who has provisioned Redis, or anything else that is not a file on disk, can
     * point this at it and get the round trip back.
     */
    'credential_lifetime_seconds' => (int) env('LYNOMIA_CREDENTIAL_TTL_SECONDS', 3600),

    /*
     * THE MASTER KEY EVERY STORE'S WEBHOOK SECRET IS DERIVED FROM.
     *
     * Lynomia signs each delivery with a per-webhook secret. Accepting the one it generates would
     * mean storing a live signing key in this database -- and APP_KEY is published here, so
     * "encrypted at rest" means nothing (see the note at the top of this file). Store Syria
     * therefore CHOOSES the secret instead: HMAC(master, "lynomia-webhook:{store}"), recomputed
     * from the environment on every delivery and never written down.
     *
     * Per store rather than one shared key, so a secret that does leak forges deliveries for one
     * store rather than all of them. Unset is a hard failure, not a default: a predictable
     * fallback would leave the signature check apparently passing while verifying nothing.
     */
    'webhook_master_secret' => env('LYNOMIA_WEBHOOK_MASTER_SECRET'),

    /*
     * How far out of date a delivery's timestamp may be. The signature covers the timestamp, so
     * a replay cannot move it; this bounds how long a captured delivery stays replayable, and the
     * delivery-id uniqueness behind it makes a replay inside the window a no-op anyway. Generous
     * relative to normal webhook latency, because a queue backlog is ordinary and must not look
     * like an attack.
     */
    /**
     * The per-store ceiling on inbound event deliveries, per minute.
     *
     * AN ABUSE CEILING, NOT A QUEUE. A refused delivery is GONE -- Lynomia swallows an account
     * webhook failure and never retries it -- so this number must sit far above anything honest
     * traffic reaches. A busy merchant generates an event per message and per status change; 600
     * a minute is ten a second for one store, sustained. The installation-wide ceiling is ten
     * times this. Below 60 is refused outright, because a value that low would make the limiter
     * a data-loss device rather than a guard.
     *
     * Whatever is dropped here is recoverable by the reconciliation sweep, which re-reads state
     * from Lynomia rather than waiting for a re-delivery that never comes.
     */
    /**
     * The ceiling on UNVERIFIED inbound requests, per caller IP, per minute. This is the only
     * limit a request without a valid signature can consume -- the per-store budget below is
     * spent exclusively by deliveries that have already proved they came from Lynomia.
     */
    'ingress_unverified_rate_per_minute' => (int) env('LYNOMIA_INGRESS_UNVERIFIED_RATE_PER_MINUTE', 300),

    'ingress_rate_per_minute' => (int) env('LYNOMIA_INGRESS_RATE_PER_MINUTE', 600),

    /**
     * How long an accepted delivery row is kept. The table is an append-only cursor source, not
     * a record anybody reads twice: the browser polls forward from its own cursor and never asks
     * for history. Without pruning it is the one unbounded resource in the ingress path.
     */
    'event_retention_days' => (int) env('LYNOMIA_EVENT_RETENTION_DAYS', 14),

    /*
     * THE OUTBOUND COMMERCE EVENT FEED: order changes going to Lynomia so an agent's panel and
     * the automation triggers see them. Its own switch, separate from `enabled`, because the
     * inbox can be live while commerce is not -- a store can be connected for conversations
     * long before its commerce connection exists on the Lynomia side.
     *
     * While it is off, NOTHING IS RECORDED rather than recorded and held. An outbox that fills
     * up while switched off is a queue of deliveries nobody asked for, aimed at a workspace
     * that may not exist by the time it is switched on.
     */
    'commerce_events_enabled' => env('LYNOMIA_COMMERCE_EVENTS_ENABLED', false),

    /*
     * How long a DELIVERED commerce event is kept. Undelivered rows are never pruned whatever
     * their age: they are the evidence that a store stopped syncing, and deleting them is how
     * that becomes invisible to the operator who needs to see it.
     */
    'commerce_event_retention_days' => (int) env('LYNOMIA_COMMERCE_EVENT_RETENTION_DAYS', 14),

    'webhook_tolerance_seconds' => (int) env('LYNOMIA_WEBHOOK_TOLERANCE_SECONDS', 300),

    /* How often the open inbox asks what has changed. */
    'inbox_poll_seconds' => (int) env('LYNOMIA_INBOX_POLL_SECONDS', 10),
    'credential_cache_store' => env('LYNOMIA_CREDENTIAL_CACHE_STORE', 'array'),

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

    /*
     * WHICH INSTALLATION THIS IS. It is stamped on every workspace this installation creates
     * and folded into the service address of every user it creates.
     *
     * WHY IT CANNOT BE LEFT OUT. Recovery works by asking Lynomia "does a workspace for store
     * 42 already exist?", and store ids are per-database autoincrements. Restore a production
     * dump into staging -- an ordinary thing to do -- and staging's store 42 is production's
     * store 42. Pointed at the same Platform App, staging's first provisioning attempt would
     * find production's live workspace, see exactly one candidate, and ADOPT it: a staging
     * merchant signed into a production merchant's conversations, through a path whose whole
     * job is to avoid creating something twice.
     *
     * It is in the service address for the same reason one layer down. Upstream resolves
     * `User.from_email(...) || User.new(...)`, so a shared address does not create a second
     * user -- it hands this installation the OTHER installation's service user, which is an
     * administrator of the other installation's workspace.
     *
     * Defaulted from APP_URL's host because that is the one value a clone almost always
     * changes, and set explicitly where it does not. Two installations that genuinely share
     * this value are, as far as this integration is concerned, one installation.
     */
    /*
     * THE REGION A LOCAL PHONE NUMBER IS READ IN, as an ISO 3166-1 alpha-2 code.
     *
     * Lynomia matches a WhatsApp conversation to a shopper by E.164 phone number, and a number
     * stored as "0955111222" is only a number once you know which country it is in: parsed with
     * no region at all it is not a valid international number and matches nobody. Lynomia
     * refuses to guess one -- "a local number with no region returns nil rather than a guess" --
     * so the region has to come from here.
     *
     * IT IS AN INSTALLATION DEFAULT RATHER THAN A PER-STORE SETTING BECAUSE STORE SYRIA RECORDS
     * NO COUNTRY CODE FOR A STORE. The only country a store has is the free-text `store_country`
     * app setting, typed by the merchant ("Syria", "سوريا", "SY"), and `countries` carries a
     * name and nothing else -- no iso2, no dialling code. Resolving a region out of that would
     * be a guess wearing a lookup's clothes.
     *
     * A store whose shoppers are somewhere else still matches every number its customers
     * entered in international form ("+965...", "00965..."); only its locally-written numbers
     * go unmatched. That is the honest failure: a customer not found, rather than the wrong
     * customer found.
     */
    'commerce_phone_region' => strtoupper((string) env('LYNOMIA_COMMERCE_PHONE_REGION', 'SY')),

    'installation_ref' => (string) env(
        'LYNOMIA_INSTALLATION_REF',
        parse_url((string) env('APP_URL', 'localhost'), PHP_URL_HOST) ?: 'localhost'
    ),
];
