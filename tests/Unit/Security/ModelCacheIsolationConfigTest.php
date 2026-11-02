<?php

namespace Tests\Unit\Security;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\TaggableStore;
use PHPUnit\Framework\TestCase;

/**
 * F194 / D-28a — the model-cache isolation ships CONFIGURED and INERT, and the isolation is at the
 * TABLE.
 *
 * The behavioural proof is `tests/Support/Security/bin/verify-model-cache-isolation-on-mysql.php`,
 * because `phpunit.xml` sets `CACHE_STORE=array` and `ArrayStore` is taggable — so in this suite
 * laravel-model-caching's flush is already scoped and there is nothing to see. What this file pins
 * is the three things a config file CAN be wrong about, each of which would silently undo the fix:
 *
 *   1. that the model store names a DIFFERENT TABLE from the application store, because
 *      `DatabaseStore::flush()` deletes the whole table and ignores any prefix — so two names over
 *      one table are one store, which is the owner's explicit condition on this decision;
 *   2. that `MODEL_CACHE_STORE` is UNSET by default, so merging changes no behaviour and no
 *      environment can break by pointing the model cache at a table that has not been migrated;
 *   3. that `enabled` is still true, because disabling model caching would also stop the flushes
 *      and D-28a names that as explicitly NOT the approved option.
 *
 * It is a Unit test and reads the config files directly rather than booting the application, so it
 * cannot be fooled by a cached `bootstrap/cache/config.php` — which is a hazard this session met
 * for real.
 */
class ModelCacheIsolationConfigTest extends TestCase
{
    private function config(string $file): array
    {
        $path = dirname(__DIR__, 3) . '/config/' . $file . '.php';
        $this->assertFileExists($path, $file . '.php is missing');

        return require $path;
    }

    /**
     * `config/cache.php` READ AS TEXT, NOT EXECUTED, and the reason is not squeamishness.
     *
     * That file calls `storage_path()` for the `file` driver stores, and `storage_path()` is
     * `app()->storagePath()` — so `require`-ing it outside a booted application dies with "Call to
     * undefined method Illuminate\Container\Container::storagePath()". Found by running this case.
     *
     * Booting the application instead would work, but it would read a cached
     * `bootstrap/cache/config.php` if one existed — and a stale one of those broke this suite for
     * real earlier in this session. The declaration in the file is what this case is about, so the
     * file is the right thing to read.
     */
    private function cacheStoreTable(string $store): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/config/cache.php');

        $at = strpos($source, "'" . $store . "' => [");
        $this->assertNotFalse($at, "config/cache.php declares no `{$store}` store");

        $block = substr($source, $at, 600);

        $this->assertSame(
            1,
            preg_match("/'table'\s*=>\s*env\('[A-Z_]+',\s*'([a-z_]+)'\)/", $block, $m),
            "the `{$store}` store's `table` is not an env() call with a literal default, so this"
            . ' case cannot read what it resolves to — re-derive it'
        );

        return $m[1];
    }

    public function test_the_two_store_classes_differ_in_taggability_which_is_the_whole_mechanism(): void
    {
        $this->assertTrue(
            is_subclass_of(ArrayStore::class, TaggableStore::class),
            'the array store stopped being taggable, so this suite would no longer be blind to the'
            . ' model flush and the MariaDB script may no longer be the only place to measure it'
        );
        $this->assertFalse(
            is_subclass_of(DatabaseStore::class, TaggableStore::class),
            'the database store became taggable, which would mean laravel-model-caching now scopes'
            . ' its flush in production too — F194 would be moot and the isolation could be'
            . ' reconsidered. Re-derive D-28a before changing anything.'
        );
    }

    public function test_the_model_store_names_its_own_table(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/config/cache.php');

        $this->assertStringContainsString("'model_cache' => [", $source,
            'the `model_cache` store is gone, so `MODEL_CACHE_STORE=model_cache` would resolve to'
            . ' nothing and the isolation cannot be activated');

        $application = $this->cacheStoreTable('database');
        $model = $this->cacheStoreTable('model_cache');

        $this->assertNotSame(
            $application,
            $model,
            'the model store and the application store default to the SAME TABLE ('
            . $application . ').  `DatabaseStore::flush()` is `$this->table()->delete()` — it'
            . ' deletes the whole table and ignores any key prefix — so two store names over one'
            . ' table are ONE store for a flush, and the isolation buys nothing. This is the'
            . " owner's explicit condition on D-28a."
        );

        $this->assertMatchesRegularExpression(
            "/'lock_table'\s*=>\s*env\('MODEL_CACHE_DB_LOCK_TABLE',\s*'model_cache_locks'\)/",
            $source,
            'the model store shares a lock table with the application store'
        );
    }

    public function test_the_isolation_ships_inert_so_merging_changes_nothing(): void
    {
        $modelCaching = $this->config('laravel-model-caching');

        $this->assertArrayHasKey('store', $modelCaching,
            'the published config lost its `store` key, so `Caching::cache()` has nothing to read'
            . ' and the isolation can never be activated');

        $this->assertNull(
            $modelCaching['store'],
            'the model cache is pointed at a store BY DEFAULT. That is not inert: any environment'
            . ' that deploys this code before running the `model_cache` migration will fail every'
            . ' model read, because `DatabaseStore` has no fallback for a missing table. Activation'
            . ' is an operator step — see F194-MODEL-CACHE-ISOLATION-RUNBOOK.md.'
        );

        $this->assertTrue(
            $modelCaching['enabled'],
            'model caching is disabled. D-28a names that as explicitly NOT the approved option: it'
            . ' would stop the flushes, but at the cost of whatever the caching buys, which is'
            . ' unmeasured.'
        );
    }

    /** The migration has to exist, or the activation step in the runbook cannot be taken. */
    public function test_the_model_cache_table_has_a_migration(): void
    {
        $matches = glob(dirname(__DIR__, 3) . '/database/migrations/*create_model_cache_table.php');

        $this->assertNotEmpty($matches,
            'there is no migration creating `model_cache`, so the runbook\'s first activation step'
            . ' cannot be performed and setting MODEL_CACHE_STORE would break every model read');

        $source = (string) file_get_contents($matches[0]);

        foreach (['model_cache', 'model_cache_locks'] as $table) {
            $this->assertStringContainsString("'" . $table . "'", $source,
                'the migration does not create `' . $table . '`');
        }
    }
}
