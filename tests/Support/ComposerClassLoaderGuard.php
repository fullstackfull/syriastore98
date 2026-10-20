<?php

namespace Tests\Support;

use Composer\Autoload\ClassLoader;
use ReflectionProperty;

/**
 * Clears Composer's PROCESS-GLOBAL NEGATIVE CLASS CACHE for the `Workdo\` namespace.
 *
 * ============================================================================================
 *  WHY THIS EXISTS -- MEASURED, NOT INFERRED
 * ============================================================================================
 *
 * `vendor/composer/ClassLoader.php` keeps a private `$missingClasses` map (`:93`). `findFile()`
 * short-circuits on it (`:448`) and records a miss into it (`:471`). It is a property of the
 * ClassLoader SINGLETON, so it survives every Laravel application teardown -- the same scope as
 * the `addPsr4()` pollution `tests/Feature/Tenancy/ResidualTenancyExposureTest` already
 * documents, and the opposite direction.
 *
 * `Workdo\` is NOT in composer.json's psr-4 block. A module's namespace is registered at runtime
 * by `PackageServiceProvider::loadAddons()`. So any code that asks `class_exists()` for a module
 * model BEFORE that call records it as PERMANENTLY ABSENT -- and no later `addPsr4()` undoes it,
 * because `findFile()` never reaches the prefix map for a class already in `$missingClasses`.
 *
 * THE MEASUREMENT. `php artisan test tests/Feature/Tenancy tests/Feature/Security` reported
 * **5 failed**, while each file ALONE and the whole `--testsuite=Feature` run were green. All
 * five were POSITIVE controls of the form "a merchant can <verb> their OWN row", and all five
 * failed on **HTTP 500, not 403** -- so the guards were never the cause:
 *
 *     Error: Class "Workdo\AuctionProduct\app\Models\AuctionProductSetting" not found
 *       at packages/workdo/AuctionProduct/src/app/Http/Controllers/
 *          AuctionProductSettingController.php:142
 *
 * `BulkDeleteAuthorizationTest` enables the BulkDelete module, whose model map `class_exists()`-
 * checks every addon model it knows about -- all 23 `Workdo\` classes -- while only BulkDelete's
 * own prefix is registered. Probed at the point of failure: **184 entries in `$missingClasses`,
 * 23 of them `Workdo\`**, which is exactly that set. Clearing the `Workdo\` entries and changing
 * nothing else took the same request from **500 to 302** with no exception.
 *
 * ============================================================================================
 *  THIS IS A TEST-PROCESS ARTEFACT, AND SAYING SO IS PART OF THE RECORD
 * ============================================================================================
 *
 * Production boots once: `loadAddons()` registers every active module's prefix inside
 * `PackageServiceProvider`, before application code can ask about a module class. The negative
 * cache is primed only AFTER the prefixes exist, so it caches truths. A test process boots the
 * application dozens of times against one ClassLoader, which is the only way the order inverts.
 *
 * So this changes no production behaviour and weakens no assertion. It removes a cached ANSWER
 * that has become false, letting `class_exists()` ask the filesystem again -- which still returns
 * false for a prefix nobody registered, so the "resolves although no provider was registered"
 * ratchet in `ResidualTenancyExposureTest` keeps biting exactly as before.
 *
 * Scoped to `Workdo\` deliberately: those are the only classes whose resolvability changes
 * mid-process. The other 161 entries are genuinely absent and clearing them would only cost
 * filesystem lookups.
 */
final class ComposerClassLoaderGuard
{
    /** @var ClassLoader|null */
    private static $loader = null;

    /**
     * Drop every `Workdo\` entry from the loader's negative cache.
     *
     * @return int the number of entries dropped -- callers may assert on it
     */
    public static function forgetWorkdoMisses(): int
    {
        $loader = self::loader();

        if ($loader === null) {
            return 0;
        }

        $property = new ReflectionProperty($loader, 'missingClasses');
        $property->setAccessible(true);

        /** @var array<string, bool> $missing */
        $missing = $property->getValue($loader);

        $kept = array_filter(
            $missing,
            static fn ($class) => ! str_starts_with((string) $class, 'Workdo\\'),
            ARRAY_FILTER_USE_KEY
        );

        $dropped = count($missing) - count($kept);

        if ($dropped > 0) {
            $property->setValue($loader, $kept);
        }

        return $dropped;
    }

    /** The registered ClassLoader instance, or null if this build has none. */
    public static function loader(): ?ClassLoader
    {
        if (self::$loader instanceof ClassLoader) {
            return self::$loader;
        }

        foreach (spl_autoload_functions() ?: [] as $callable) {
            if (is_array($callable) && ($callable[0] ?? null) instanceof ClassLoader) {
                return self::$loader = $callable[0];
            }
        }

        return null;
    }
}
