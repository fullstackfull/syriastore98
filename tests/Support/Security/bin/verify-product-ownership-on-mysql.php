#!/usr/bin/env php
<?php

/**
 * P1-INV-01 / S37-1 — the merchant catalogue ownership guard, verified on REAL MYSQL.
 * =============================================================================================
 *
 * WHY THIS SCRIPT EXISTS AND THE FEATURE TEST IS NOT ENOUGH. The Feature suite runs on SQLite
 * `:memory:` (phpunit.xml), and SQLite MASKED this defect. `ProductController::update()` writes
 * `$product->stock_status = $request->stock_status` (:1066) and
 * `$product->trending = $request->trending` (:1127) straight through from request input. Both
 * columns are NOT NULL. On SQLite the resulting UPDATE raises
 *
 *     SQLSTATE[23000]: NOT NULL constraint failed: products.stock_status
 *
 * so a cross-store write FAILS -- and a test that asserts only "the victim did not change" passes
 * for the wrong reason. The repository's mysql connection sets `'strict' => false`
 * (config/database.php:55), which is the production condition, and under it MySQL COERCES the NULL
 * instead of refusing it. So the write succeeds there: **production is more exposed than the test
 * database**, which is the reverse of the direction this project has usually been bitten in.
 *
 * The Feature test neutralises the masking by posting both fields. This script establishes the
 * engine behaviour that made the masking possible, and then drives the guard itself on MySQL.
 *
 * WHY IT IS A SCRIPT AND NOT A TEST. `tests/Support/TestDatabaseGuard.php:27` aborts any PHPUnit
 * run whose connection is not sqlite, because `RefreshDatabase` would `migrate:fresh` and drop
 * every table in the target database. That guard is correct and is not to be weakened. Standalone
 * scripts that create and drop their OWN database are this project's answer, and this file follows
 * the shape of `verify-sec13-migration-on-mysql.php`.
 *
 * WHAT IT ESTABLISHES, by execution:
 *   1. the engine behaviour the masking rests on -- NULL into a NOT NULL column is COERCED under
 *      `strict => false`, where SQLite raises -- so the cross-store write really does land on
 *      production-shaped MySQL;
 *   2. that a merchant of store A is REFUSED `update()` on store B's product, on MySQL, and the
 *      victim's name, stock and owning store are unchanged;
 *   3. that the same merchant can still update their OWN product, on MySQL (the positive control
 *      without which point 2 proves nothing);
 *   4. that `show()` and `edit()` refuse the same binding;
 *   5. that the platform operator is not locked out by the guard.
 *
 * HOW TO RUN. Point it at any throwaway MySQL you control. It creates and drops its own database
 * and touches nothing else:
 *
 *   PRODOWN_MYSQL_SOCKET=/var/run/mysqld/mysqld.sock \
 *   PRODOWN_MYSQL_DATABASE=prodown_verify \
 *   PRODOWN_MYSQL_USERNAME=root \
 *   PRODOWN_MYSQL_PASSWORD= \
 *   php tests/Support/Security/bin/verify-product-ownership-on-mysql.php
 *
 * or with PRODOWN_MYSQL_HOST / PRODOWN_MYSQL_PORT instead of a socket.
 *
 * NEVER POINT IT AT THE DEVELOPMENT OR PRODUCTION SERVER. It issues CREATE DATABASE and
 * DROP DATABASE, and it refuses a database name that does not contain 'verify'.
 *
 * Exit code 0 means every assertion held.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$checks = 0;
$failures = [];

function check(bool $condition, string $what, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;
    if ($condition) {
        echo "  PASS  {$what}\n";
        return;
    }
    $failures[] = $what . ($detail !== '' ? " -- {$detail}" : '');
    echo "  FAIL  {$what}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

// --------------------------------------------------------------------------------------
// PRECONDITIONS
// --------------------------------------------------------------------------------------
$database = getenv('PRODOWN_MYSQL_DATABASE') ?: 'prodown_verify';

if (! str_contains($database, 'verify')) {
    fwrite(STDERR, "REFUSING: the database name '{$database}' does not contain 'verify'.\n"
        . "This script issues DROP DATABASE. Name the throwaway database accordingly.\n");
    exit(2);
}

$socket = getenv('PRODOWN_MYSQL_SOCKET') ?: '';
$host = getenv('PRODOWN_MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('PRODOWN_MYSQL_PORT') ?: '3306';
$username = getenv('PRODOWN_MYSQL_USERNAME') ?: 'root';
$password = getenv('PRODOWN_MYSQL_PASSWORD');
$password = $password === false ? '' : $password;

$base = [
    'driver' => 'mysql',
    'url' => null,
    'host' => $host,
    'port' => $port,
    'unix_socket' => $socket,
    'username' => $username,
    'password' => $password,
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'prefix_indexes' => true,
    // The production condition. This is the whole point: with strict mode ON, MySQL would
    // refuse the NULL like SQLite does, and the defect would not reach the row.
    'strict' => false,
    'engine' => null,
];

// The session store must not reach for the app's default connection: `Auth::logout()` deletes
// the session row, and SESSION_DRIVER=database would send that DELETE to a SQLite file that does
// not exist in this container. Nothing here needs a persisted session.
config(['session.driver' => 'array']);
config(['cache.default' => 'array']);

config(['database.connections.prodown_root' => array_merge($base, ['database' => null])]);
config(['database.connections.prodown' => array_merge($base, ['database' => $database])]);

echo "\nP1-INV-01 / S37-1 -- merchant catalogue ownership, on real MySQL\n";
echo str_repeat('=', 86) . "\n";

try {
    $version = DB::connection('prodown_root')->selectOne('SELECT VERSION() AS v')->v;
} catch (\Throwable $e) {
    fwrite(STDERR, "\nCannot reach MySQL: " . $e->getMessage() . "\n");
    exit(2);
}
echo "server: {$version}\n\n";

DB::connection('prodown_root')->statement("DROP DATABASE IF EXISTS `{$database}`");
DB::connection('prodown_root')->statement(
    "CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
);

config(['database.default' => 'prodown']);
DB::purge('prodown');

$exit = 0;

try {
    echo "[0] Schema\n";
    $migrate = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $migrate->call('migrate', ['--force' => true, '--database' => 'prodown']);
    check(DB::connection('prodown')->getSchemaBuilder()->hasTable('products'),
        'migrations ran against the throwaway database');

    // ----------------------------------------------------------------------------------
    echo "\n[1] The engine behaviour that masked this defect on SQLite\n";

    $probeId = DB::table('products')->insertGetId([
        'name' => 'probe', 'slug' => 'probe-' . uniqid(), 'product_stock' => 5,
        'variant_product' => 0, 'price' => 10, 'status' => 1, 'trending' => 0,
        'stock_status' => 'in_stock', 'store_id' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $coerced = null;
    try {
        DB::table('products')->where('id', $probeId)->update(['stock_status' => null]);
        $coerced = DB::table('products')->where('id', $probeId)->value('stock_status');
        check(true, 'NULL into the NOT NULL products.stock_status is COERCED, not refused'
            . " (stored: '" . (string) $coerced . "')");
    } catch (\Throwable $e) {
        check(false, 'NULL into the NOT NULL products.stock_status is COERCED, not refused',
            'MySQL refused it: ' . substr($e->getMessage(), 0, 120)
            . ' -- is strict mode on for this connection?');
    }
    echo "        (the same statement on SQLite raises"
        . " \"NOT NULL constraint failed: products.stock_status\" --\n"
        . "         measured in tests/Feature/Security/MerchantProductOwnershipTest.php, which is why"
        . " a\n         cross-store test there must post the field to be meaningful)\n";
    DB::table('products')->where('id', $probeId)->delete();

    // ----------------------------------------------------------------------------------
    echo "\n[2] Fixtures: two merchants, two stores, one victim product\n";

    $mk = function (string $label) {
        // Mirrors SecurityTestCase::makeMerchant()/makeStore(), which are the fixtures the
        // Feature suite already runs against this schema -- rather than guessing columns.
        $user = User::forceCreate([
            'name' => 'merchant-' . $label, 'email' => $label . '-' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-password'), 'type' => 'admin',
            'created_by' => 1, 'is_active' => 1, 'email_verified_at' => now(),
        ]);
        $store = Store::forceCreate([
            'name' => 'store-' . $label, 'slug' => 'store-' . $label . '-' . uniqid(),
            'email' => 'store-' . $label . '-' . uniqid() . '@example.test',
            'theme_id' => 'stylique', 'created_by' => $user->id, 'is_active' => 1,
        ]);
        $user->current_store = $store->id;
        $user->save();

        return [$user->fresh(), $store];
    };

    [$merchantA, $storeA] = $mk('a');
    [$merchantB, $storeB] = $mk('b');
    check($merchantA->current_store === $storeA->id, 'merchant A resolves store A as current');

    /*
     * THE PERMISSION MATTERS, AND ITS ABSENCE MADE [3] VACUOUS ON THE FIRST RUN.
     * `update()` does everything inside `if (isAbleTo('Edit Products'))`, so without the grant the
     * handler falls through and writes nothing -- for the attacker AND for the owner. The refusal
     * in [3] then "passed" because nothing could write at all. Mirrors
     * SecurityTestCase::grantPermission(); the positive control in [4] is what exposed it.
     */
    $grant = function (User $user) {
        $permission = App\Models\Permission::firstOrCreate(
            ['name' => 'Edit Products'], ['display_name' => 'Edit Products']
        );
        $role = App\Models\Role::forceCreate([
            'name' => 'granted-role-' . $user->id, 'display_name' => 'Granted Role',
            'guard_name' => 'web', 'created_by' => $user->creatorId(),
            'store_id' => $user->current_store,
        ]);
        $role->givePermission($permission);
        $user->addRole($role);
        $user->flushCache();

        return $user->fresh();
    };

    $merchantA = $grant($merchantA);
    check($merchantA->isAbleTo('Edit Products'), 'merchant A holds Edit Products');

    $categoryIn = fn ($store) => DB::table('categories')->insertGetId([
        'name' => 'cat', 'slug' => 'cat-' . uniqid(), 'status' => 1, 'store_id' => $store->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $productIn = fn ($store, $name) => Product::forceCreate([
        'name' => $name, 'slug' => 'p-' . uniqid(), 'product_stock' => 50,
        'track_stock' => 1, 'variant_product' => 0, 'price' => 100.00, 'sale_price' => 90.00,
        'status' => 1, 'trending' => 0, 'stock_status' => 'in_stock',
        'store_id' => $store->id, 'created_by' => $store->created_by,
    ]);

    $victim = $productIn($storeB, 'victim-original');
    $own = $productIn($storeA, 'own-original');
    $catB = $categoryIn($storeB);
    $catA = $categoryIn($storeA);

    // The payload WITHOUT the two NOT NULL fields -- the shape that fails on SQLite and lands on
    // MySQL. This is the production exposure, not a contrived one.
    $payload = fn (int $cat, string $name, int $stock) => [
        'name' => $name, 'category_id' => $cat, 'status' => 1, 'variant_product' => 0,
        'price' => 100.00, 'sale_price' => 90.00, 'cost_price' => 40.00,
        'product_stock' => $stock, 'track_stock' => 1,
    ];

    $controller = $app->make(App\Http\Controllers\ProductController::class);
    // setUser(), not login(): login() writes the session and logout() DELETES the session row,
    // which would reach the app's default session connection -- a SQLite file that does not exist
    // in this container. setUser() authenticates in memory, which is all `auth()->user()`,
    // `getCurrentStore()` and the guard under test need. (config('session.driver') cannot fix this
    // from here: the session manager is already resolved by the time this script runs.)
    $call = function (string $method, $user, $product, array $body = []) use ($controller) {
        Auth::guard('web')->setUser($user);
        $request = Illuminate\Http\Request::create('/product/' . $product->id, 'PUT', $body);
        app()->instance('request', $request);

        return $method === 'update'
            ? $controller->update($request, $product)
            : $controller->{$method}($product);
    };

    // ----------------------------------------------------------------------------------
    echo "\n[3] A merchant of store A is refused update() on store B's product\n";

    $call('update', $merchantA, $victim, $payload($catB, 'HIJACKED', 7));
    $after = Product::withoutGlobalScopes()->find($victim->id);

    check($after->name === 'victim-original', "the victim's name is unchanged",
        'name is now ' . var_export($after->name, true));
    check((int) $after->product_stock === 50, "the victim's stock is unchanged",
        'stock is now ' . $after->product_stock);
    check((int) $after->store_id === (int) $storeB->id, 'the victim still belongs to store B');

    // ----------------------------------------------------------------------------------
    echo "\n[4] The positive control: the same merchant CAN update their own product\n";

    $call('update', $merchantA, $own, $payload($catA, 'own-renamed', 12));
    $ownAfter = Product::withoutGlobalScopes()->find($own->id);

    check($ownAfter->name === 'own-renamed',
        "a merchant's own product still updates on MySQL -- so [3] measures the guard, not a broken"
        . ' write path', 'name is ' . var_export($ownAfter->name, true));
    check((int) $ownAfter->product_stock === 12, "a merchant's own stock still updates on MySQL");
    echo "        (and note this succeeded WITHOUT posting stock_status or trending: the same\n"
        . "         payload cannot complete on SQLite at all, which is the masking in [1])\n";

    // ----------------------------------------------------------------------------------
    echo "\n[5] show() and edit() refuse the same binding\n";

    foreach (['show', 'edit'] as $method) {
        $response = $call($method, $merchantA, $victim);
        $isRedirect = $response instanceof Illuminate\Http\RedirectResponse;
        check($isRedirect, "{$method}() refuses another store's product with a redirect",
            'got ' . get_class($response));
    }

    // ----------------------------------------------------------------------------------
    echo "\n[6] What the operator exemption actually does -- and where it is DEAD CODE\n";

    $operator = User::forceCreate([
        'name' => 'operator', 'email' => 'op-' . uniqid() . '@example.test',
        'password' => bcrypt('not-a-real-password'), 'type' => 'super admin',
        'created_by' => 1, 'is_active' => 1, 'email_verified_at' => now(),
    ]);

    /*
     * MEASURED, AND IT CORRECTS WHAT THIS SCRIPT FIRST ASSERTED. CLAUDE.md records that there is
     * no super-admin bypass in Laratrust: `isAbleTo()` alone REFUSES the platform operator. So in
     * `update()` -- whose whole body is inside `if (isAbleTo('Edit Products'))` -- the operator
     * never reaches the ownership guard at all, and the exemption there is DEFENCE IN DEPTH that
     * cannot currently fire. The same is true of the pre-existing exemption in `destroy()`.
     *
     * In `show()` and `edit()` there is no `isAbleTo` wrapper, so the exemption IS live: without
     * it the operator -- who has no `current_store` of their own in this fixture -- would be
     * refused their own platform screens.
     *
     * This is recorded rather than "fixed": making the operator able to edit any store's catalogue
     * would be a new capability, not a restoration, and it is not this task's to grant.
     */
    check(! $operator->isAbleTo('Edit Products'),
        'the operator does NOT hold Edit Products -- Laratrust has no super-admin bypass'
        . ' (CLAUDE.md), so update()\'s operator exemption is unreachable defence in depth');

    $call('update', $operator, $victim, $payload($catB, 'operator-edit', 9));
    $opAfter = Product::withoutGlobalScopes()->find($victim->id);
    check($opAfter->name === 'victim-original',
        'and so the operator writes nothing through update() -- refused by the permission check,'
        . ' before ownership is considered',
        'name is ' . var_export($opAfter->name, true));

    $opShow = $call('show', $operator, $victim);
    check(! ($opShow instanceof Illuminate\Http\RedirectResponse),
        'but the operator IS exempted on show(), where no permission check stands in front of the'
        . ' ownership guard -- the exemption is live there',
        'got ' . get_class($opShow));
} catch (\Throwable $e) {
    echo "\nUNCAUGHT: " . get_class($e) . ' -- ' . $e->getMessage() . "\n"
        . '  at ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $failures[] = 'uncaught exception: ' . $e->getMessage();
} finally {
    echo "\n[7] Tear down\n";
    try {
        config(['database.default' => 'prodown_root']);
        DB::purge('prodown');
        DB::connection('prodown_root')->statement("DROP DATABASE IF EXISTS `{$database}`");
        check(true, "dropped the throwaway database `{$database}`");
    } catch (\Throwable $e) {
        check(false, "dropped the throwaway database `{$database}`", $e->getMessage());
    }
}

echo "\n" . str_repeat('=', 86) . "\n";
echo "checks: {$checks}   failures: " . count($failures) . "\n";

if ($failures) {
    echo "\nFAILED:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "\nALL ASSERTIONS HELD on {$version}.\n";
exit(0);
