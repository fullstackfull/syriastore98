<?php

return [

    /*
     * F217 — where a paid-download grant is stored, and for how long.
     *
     * NOT THE DEFAULT CACHE STORE, and the reason is measured rather than defensive.
     * `laravel-model-caching` flushes after every model save, and because `DatabaseStore` is not
     * taggable that flush is `Cache::flush()` over the WHOLE default store — one
     * `Setting::updateOrCreate()` erased 4 of 4 seeded keys on MariaDB 10.11.14 in this
     * repository's own verification script. A grant kept in the default store would be erased by
     * any model save anywhere in the installation between the mail going out and the buyer
     * clicking the link, and the buyer would meet a refusal on a legitimate download with no
     * diagnosable cause.
     *
     * `file` is a separate store and is untouched when the default one is flushed — asserted, not
     * assumed, by the F194 activation script's own check 8.
     *
     * THE RESIDUAL IS A DEPLOYMENT DECISION AND IS STATED RATHER THAN HIDDEN: a file-backed record
     * is per-server. In a multi-server deployment the grant can be issued on one machine and
     * verified on another, and the buyer is refused a download they are entitled to. Set
     * `DOWNLOAD_GRANT_STORE` to a shared store (Redis) where one exists. The same residual applies
     * to `config/consent.php`, and the F194 runbook §9.3 records that the server count still
     * matters for exactly these two settings even though it does not gate the model-cache
     * isolation itself.
     */
    'grant_store' => env('DOWNLOAD_GRANT_STORE', 'file'),

    /*
     * Seven days. A download is not a consent: a buyer may legitimately re-download, and a window
     * measured in minutes would turn a flaky connection or a mail read the next morning into a
     * lost purchase. The TTL, not a use count, is the limit — see `DownloadGrant`'s docblock.
     */
    'grant_ttl_minutes' => (int) env('DOWNLOAD_GRANT_TTL_MINUTES', 60 * 24 * 7),

];
