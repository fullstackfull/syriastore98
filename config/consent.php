<?php

return [

    /*
    |---------------------------------------------------------------------------------------------
    | F205 — where a server-issued consent record lives
    |---------------------------------------------------------------------------------------------
    |
    | NOT the default cache store, and that is measured rather than cautious. `laravel-model-caching`
    | flushes on every model save, and because `DatabaseStore` is not taggable that flush is
    | `Cache::flush()` over the WHOLE default store — one `Setting::updateOrCreate()` erased 4 of 4
    | seeded keys on MariaDB 10.11.14 in this repository's own verification script. `Product` and
    | `ProductVariant` are both `Cachable`, so a consent record in the default store would be erased
    | by any model save anywhere in the installation between issue and verify, and the merchant
    | would be refused on a correct retry with no diagnosable cause.
    |
    | `file` is a separate store and is not touched when the default one is flushed.
    |
    | THE RESIDUAL, STATED RATHER THAN HIDDEN: a file-backed record is per-server. In a
    | multi-server deployment without sticky sessions the issue and the verify can land on
    | different machines, and the merchant is refused on a correct retry. Set CONSENT_STORE to a
    | shared store (Redis) where one exists. The alternatives are activating
    | MODEL_CACHE_STORE=model_cache so the default store stops being flushed wholesale (see the
    | F194 runbook), or a dedicated table — which is a persistent migration and therefore an owner
    | escalation under this project's standing rules.
    |
    */

    'store' => env('CONSENT_STORE', 'file'),

    /*
    | How long a consent stays valid. The merchant reads a refusal, looks at what would be deleted,
    | and confirms — minutes, not hours. Fifteen matches `CustomerPasswordResetService`, the
    | existing server-held-token precedent in this codebase.
    */

    'ttl_minutes' => (int) env('CONSENT_TTL_MINUTES', 15),

];
