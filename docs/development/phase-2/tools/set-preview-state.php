<?php
/**
 * Sets the SERVER-SIDE state the admin layout actually reads, so a screenshot of "dark" or
 * "RTL" is of the real thing.
 *
 * WHY THIS EXISTS. The first version of shoot-orders-live.cjs set `data-theme` on <html> from
 * the browser and called the result a dark-mode screenshot. The admin layout does not read that
 * attribute: `layouts/app.blade.php:116-124` chooses between style.css, style-dark.css,
 * rtl-style.css and rtl-style-dark.css SERVER-SIDE from the store's `cust_darklayout` and
 * `SITE_RTL` settings. So all four "dark" shots were of the light stylesheet with an attribute
 * nothing consulted -- a false reading of exactly the kind ledger finding F8 records twice.
 *
 * usage: php set-preview-state.php <storeId> <light|dark> <ltr|rtl>
 */
require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$self, $storeId, $theme, $dir] = array_pad($argv, 4, null);

// `settings.created_by` is NOT NULL and is not mass-assignable, so the owner is resolved from
// the store rather than left to the model's defaults.
$ownerId = \App\Models\Store::where('id', $storeId)->value('created_by');

if (! $storeId || ! $theme || ! $dir) {
    fwrite(STDERR, "usage: set-preview-state.php <storeId> <light|dark> <ltr|rtl>\n");
    exit(2);
}

foreach ([
    'cust_darklayout' => $theme === 'dark' ? 'on' : 'off',
    'SITE_RTL' => $dir === 'rtl' ? 'on' : 'off',
] as $name => $value) {
    \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(
        ['name' => $name, 'store_id' => $storeId],
        ['value' => $value, 'created_by' => $ownerId, 'updated_at' => now(), 'created_at' => now()]
    );
}

// getAdminAllsetting() memoises, and the settings are read through the cache.
\Illuminate\Support\Facades\Cache::flush();

echo "store={$storeId} cust_darklayout=" . ($theme === 'dark' ? 'on' : 'off')
   . " SITE_RTL=" . ($dir === 'rtl' ? 'on' : 'off') . "\n";
