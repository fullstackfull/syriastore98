<?php

/**
 * Put the MERCHANT ADMIN into a known theme / direction / language, server-side.
 *
 * WHY THIS EXISTS BESIDE set-preview-state.php. That one writes `cust_darklayout` and `SITE_RTL`,
 * which is the whole story for the storefront. The merchant admin layout is different:
 * `resources/views/layouts/app.blade.php:42-50` derives the direction from the USER'S LANGUAGE and
 * OVERRIDES the setting --
 *
 *     $setting['currantLang'] = auth()->user()->language;
 *     if ($setting['currantLang'] == 'ar' || $setting['currantLang'] == 'he') {
 *         $setting['SITE_RTL'] = 'on';
 *     }
 *
 * -- so for a merchant whose `users.language` is 'ar', writing `SITE_RTL = off` changes nothing at
 * all. Measured: flipping that setting on, off and on again produced three identical renders, all
 * `dir="rtl"`. A measurement run on that state control reported five en/ltr failures that were the
 * harness being wrong, not the page -- it had measured ar/rtl twice and en/ltr never.
 *
 * AND EVERY WRITE GOES THROUGH ELOQUENT. `Setting` and `User` both use
 * GeneaLabs\LaravelModelCaching's `Cachable`, so a `DB::table()->update()` leaves Eloquent reads
 * serving the pre-write row -- the trap CLAUDE.md records under "Model caching", and the reason
 * the first attempt at this appeared to do nothing.
 *
 * usage: php set-admin-preview-state.php <storeId> <light|dark> <ltr|rtl> <lang> <email>
 */

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

require __DIR__ . '/../../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$self, $storeId, $theme, $dir, $lang, $email] = array_pad($argv, 6, null);

if (! $storeId || ! $theme || ! $dir || ! $lang || ! $email) {
    fwrite(STDERR, "usage: set-admin-preview-state.php <storeId> <light|dark> <ltr|rtl> <lang> <email>\n");
    exit(2);
}

$write = function (string $name, string $value) use ($storeId): void {
    $row = Setting::where('name', $name)->where('store_id', $storeId)->first();

    if (! $row) {
        fwrite(STDERR, "warning: no `{$name}` row for store {$storeId}; nothing written\n");

        return;
    }

    $row->value = $value;
    $row->save();
};

$write('cust_darklayout', $theme === 'dark' ? 'on' : 'off');
$write('SITE_RTL', $dir === 'rtl' ? 'on' : 'off');

$user = User::where('email', $email)->first();

if (! $user) {
    fwrite(STDERR, "error: no user {$email}\n");
    exit(3);
}

$user->language = $lang;
$user->save();

(new Setting)->flushCache();
(new User)->flushCache();
Cache::flush();

echo "store={$storeId} cust_darklayout=" . ($theme === 'dark' ? 'on' : 'off')
   . ' SITE_RTL=' . ($dir === 'rtl' ? 'on' : 'off')
   . " users.language={$lang} ({$email})\n";
