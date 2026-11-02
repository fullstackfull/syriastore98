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

/**
 * THE ROW IS CREATED WHEN IT IS ABSENT, AND THE SUMMARY SAYS WHICH HAPPENED.
 *
 * This used to warn on STDERR and return — while the success line at the bottom printed
 * `cust_darklayout=on` regardless. A rebuilt `p2dev.sqlite` has NO `settings` rows at all
 * (`seed-p2dev.php` seeds products, orders and categories, not settings), so every "dark" run of
 * every dashboard harness silently measured the LIGHT theme and reported it as dark: eight
 * configurations that were really four, each measured twice. Caught only when a theme PREMISE
 * assertion was added to `shoot-wsc-dashboard-live.cjs` — the same assertion the P2-01-14 ledger
 * row says had to be added for the same reason, to a different harness, a fortnight earlier.
 *
 * A warning nobody reads, next to a summary that contradicts it, is worse than no warning.
 *
 * Creating a row is correct here and nowhere else: this script exists only to put a DEV fixture
 * into a known state, and it refuses to run against anything it did not create (the caller passes
 * the sqlite path). `updateOrCreate` through Eloquent, never `DB::table()`, because `Setting` is
 * `Cachable`.
 *
 * @return string what actually happened, for the summary
 */
$write = function (string $name, string $value) use ($storeId): string {
    $row = Setting::where('name', $name)->where('store_id', $storeId)->first();

    if (! $row) {
        Setting::create([
            'name' => $name,
            'value' => $value,
            'store_id' => $storeId,
            'created_by' => \App\Models\User::where('type', 'admin')->value('id') ?? 1,
        ]);

        return "{$name}={$value} (row created)";
    }

    $row->value = $value;
    $row->save();

    return "{$name}={$value}";
};

$written = [];
$written[] = $write('cust_darklayout', $theme === 'dark' ? 'on' : 'off');
$written[] = $write('SITE_RTL', $dir === 'rtl' ? 'on' : 'off');

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

/* THE SUMMARY REPORTS WHAT WAS WRITTEN, not what was asked for. The previous version echoed
   the ARGUMENTS, so it said `cust_darklayout=on` on a run that had written nothing at all. */
echo "store={$storeId} " . implode(' ', $written)
   . " users.language={$lang} ({$email})\n";
