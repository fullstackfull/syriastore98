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

    // How long a recovery window stays open. One provider health check takes milliseconds, so
    // this is deliberately short -- it is the blast radius of the only authorisation this
    // integration grants to a link that is not active. Floored at 5s in the model, so setting it
    // to 0 cannot disable the expiry.
    'recovery_window_seconds' => (int) env('LYNOMIA_RECOVERY_WINDOW_SECONDS', 60),

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
     * The Automation Studio's own budget, per PERSON rather than per store.
     *
     * Higher than it looks because one Studio page is several requests: opening the builder is
     * the flow, the references and the condition vocabulary, and a merchant moving between
     * flows pays that each time. Low enough that one stuck poll cannot sit on Lynomia all
     * afternoon -- which is the case this exists for, rather than a hostile one.
     */
    'studio_rate_per_minute' => (int) env('LYNOMIA_STUDIO_RATE_PER_MINUTE', 240),

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

    /*
     * STORE SYRIA'S OWN LYNOMIA WORKSPACE (P6D), from which the platform talks to its
     * merchants about their accounts -- onboarding, a broken integration, a security notice.
     *
     * NOT A MERCHANT'S, and never discovered. Lynomia marks it `platform_owned` and leaves it
     * out of the account listing this app reads, so provisioning cannot stumble on it and
     * adopt it for a store. It is addressed deliberately, by the id recorded here, and
     * nothing else in this module may be pointed at it.
     *
     * Null until an operator provisions the workspace on the Lynomia side and records its id.
     * Null is the correct default and the correct state for an installation that has not set
     * one up: platform communications are simply unavailable, which is better than a
     * misconfigured id silently addressing a merchant's workspace.
     */
    'platform_workspace_account_id' => ($platformWorkspace = env('LYNOMIA_PLATFORM_WORKSPACE_ACCOUNT_ID')) !== null
        && $platformWorkspace !== ''
            ? (int) $platformWorkspace
            : null,

    /*
     * The service identity Store Syria acts as inside that workspace, and the WhatsApp inbox
     * it sends from. Both are recorded by an operator after provisioning, alongside the
     * account id above.
     *
     * ALL THREE OR NOTHING. PlatformWorkspaceGateway refuses to act unless every one is set:
     * a half-configured workspace is the state in which a notice goes somewhere nobody
     * intended, and there is no sensible default for any of them.
     */
    'platform_workspace_sender_user_id' => ($platformSender = env('LYNOMIA_PLATFORM_WORKSPACE_SENDER_USER_ID')) !== null
        && $platformSender !== ''
            ? (int) $platformSender
            : null,

    'platform_workspace_inbox_id' => ($platformInbox = env('LYNOMIA_PLATFORM_WORKSPACE_INBOX_ID')) !== null
        && $platformInbox !== ''
            ? (int) $platformInbox
            : null,

    /*
     * The approved templates an automated notice uses, by occasion.
     *
     * NAMED HERE RATHER THAN IN THE CODE because a template name is Meta's and changes on
     * Meta's schedule, not on a deploy's. What the code decides is WHICH OCCASION warrants a
     * notice; which approved template carries it is an operational setting.
     *
     * It is still not a way round the rules: the platform workspace refuses anything Meta
     * does not classify as UTILITY, so pointing one of these at a marketing template makes
     * the notice fail with a reason rather than making marketing operational.
     */
    'platform_notice_templates' => [
        'integration_suspended' => (string) env('LYNOMIA_NOTICE_INTEGRATION_SUSPENDED', 'integration_suspended'),

        // B4. The code a merchant answers to prove they hold the number they gave us.
        //
        // MUST BE AN APPROVED **UTILITY** TEMPLATE WITH ONE BODY PARAMETER. The platform
        // workspace refuses anything Meta does not classify as UTILITY, which is the owner's
        // Decision 2 and is not relaxed for this: Meta's own AUTHENTICATION category, which is
        // what an OTP template would ordinarily be, is refused by that gate. Admitting it is an
        // owner decision, not a configuration one.
        'contact_verification' => (string) env('LYNOMIA_NOTICE_CONTACT_VERIFICATION', 'contact_verification'),
    ],

    // Deliveries a minute on the platform feed, across the whole installation. One producer,
    // so this is a backstop against a loop rather than a fairness budget.
    'platform_ingress_rate_per_minute' => (int) env('LYNOMIA_PLATFORM_INGRESS_RATE_PER_MINUTE', 600),

    // B4. How many codes one merchant may ask for in an hour, across every number they try.
    'contact_verify_rate_per_hour' => (int) env('LYNOMIA_CONTACT_VERIFY_RATE_PER_HOUR', 10),

    // The language platform notices are sent in. One setting, because every notice goes to a
    // merchant of this installation rather than to their shoppers, and the template has to
    // exist in whatever is named here.
    'platform_notice_language' => (string) env('LYNOMIA_NOTICE_LANGUAGE', 'en_US'),
];
