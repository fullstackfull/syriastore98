<?php
/**
 * Enables the WorkDo modules the Phase 02 browser harnesses need, on the p2dev dev instance.
 *
 * WHY THIS FILE EXISTS. `check-pinned-column-live.cjs` carries a premise case -- "the BulkDelete
 * select-all column is present, so the blank-entry cases actually met a markup-titled column" --
 * and it FAILS LOUDLY, by design, when the column is absent:
 *
 *     FAIL  premise: the BulkDelete select-all column is present ...
 *           [NOT MEASURED -- no add_on_managers row for BulkDelete on this instance, so the two
 *            cases above passed without exercising the column that produced the defect]
 *
 * That behaviour is right. What was missing was any documented way to SATISFY it:
 * `seed-p2dev.php` creates no `add_on_managers` row and activates no module, and the handoff's
 * "Bringing the environment up" did not say to either -- so following the procedure exactly
 * produced 57/58, the one failure being the harness correctly reporting an unmet premise.
 *
 * `tests/Support/Security/EnablesWorkdoModules` does the equivalent INSIDE a PHPUnit run, where
 * it can reach `$this->app` and re-invoke `loadAddons()`. This script is its counterpart for the
 * long-running dev server, which reads the rows on its own next request. The conventions here
 * are deliberately the trait's: `module.json` supplies the name and package, and the write goes
 * through Eloquent.
 *
 * BOTH ROWS ARE REQUIRED, and that is measured rather than assumed. `module_is_active()` for a
 * merchant of type `admin` resolves `activated_modules_for_owner()`, which reads
 * `user_active_modules` -- so with `add_on_managers.is_enable = 1` alone the premise case still
 * failed with `count=0` and the column did not render. (A `super admin` short-circuits to true
 * before that check, so enabling only the first row and testing as the operator would prove
 * nothing about a merchant.)
 *
 *   `add_on_managers.is_enable = 1`  -> PackageServiceProvider::loadAddons registers the module's
 *                                       provider, so `Module::has()` / `isEnabled()` become true
 *   `user_active_modules`            -> entitles THIS owner, which is what `module_is_active()`
 *                                       checks for a type `admin` merchant
 *
 * DEV FIXTURE ONLY: it refuses to run against anything but the p2dev sqlite file, and it names no
 * real person or store.
 *
 * usage: DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file \
 *            php artisan tinker --execute="require base_path('database/enable-p2dev-modules.php');"
 */

use App\Models\AddOnManager;
use App\Models\User;
use App\Models\userActiveModule;
use Illuminate\Support\Facades\Cache;

$connection = config('database.default');
$database = (string) config("database.connections.{$connection}.database");

if (! str_ends_with($database, 'p2dev.sqlite')) {
    fwrite(STDERR, "REFUSED: this script only runs against database/p2dev.sqlite (got: {$database})\n");

    return;
}

$modules = ['BulkDelete'];

$merchant = User::where('email', 'merchant@example.test')->first();

if (! $merchant) {
    fwrite(STDERR, "REFUSED: the p2dev fixture merchant is absent -- run seed-p2dev.php first\n");

    return;
}

foreach ($modules as $module) {
    $metaPath = base_path("packages/workdo/{$module}/module.json");
    $meta = is_file($metaPath)
        ? (json_decode((string) file_get_contents($metaPath), true) ?: [])
        : [];

    $addon = AddOnManager::firstOrCreate(['module' => $module], [
        'name' => $meta['name'] ?? $module,
        'monthly_price' => $meta['monthly_price'] ?? 0,
        'yearly_price' => $meta['yearly_price'] ?? 0,
        'is_enable' => 1,
        'package_name' => $meta['package_name'] ?? strtolower($module),
    ]);

    // firstOrCreate does not update an existing row, and a previous run may have left it off.
    if (! $addon->is_enable) {
        $addon->is_enable = 1;
        $addon->save();
    }

    userActiveModule::firstOrCreate(['user_id' => $merchant->id, 'module' => $module]);
}

/*
 * `User` is `Cachable` and several handlers read the platform admin through
 * `Cache::remember('admin_details', ...)`. CLAUDE.md records two measured false readings caused
 * by a write that was not flushed, so this flushes rather than hoping.
 */
(new User)->flushCache();
Cache::flush();

foreach ($modules as $module) {
    printf(
        "%s: add_on_managers.is_enable=%s user_active_modules=%d owner=%d (type=%s)\n",
        $module,
        AddOnManager::where('module', $module)->value('is_enable'),
        userActiveModule::where('user_id', $merchant->id)->where('module', $module)->count(),
        $merchant->id,
        $merchant->type
    );
}
