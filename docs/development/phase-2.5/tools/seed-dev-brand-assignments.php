<?php

/**
 * GIVE THE ORDERED PRODUCTS A BRAND, so the brand sales report plots money instead of zeros.
 * =================================================================================================
 *
 * WHY. `/Brand-order-sale-reports` was captured in all four combinations and every card read
 * `$0.00` against an empty chart grid. That was not a reporting defect -- it was MEASURED to be
 * the fixture:
 *
 *     product_brands            3 rows   (Damascene Silk, Aleppo Weave, Homs Cotton)
 *     products                 32 rows
 *     products WITH a brand      0 rows   <-- so every brand legitimately totals zero
 *
 * `ReportController::order_brand_reports()` reads each order's `product_json`, looks the
 * `product_id` up in `products`, and groups by `products.brand_id`. With no product carrying a
 * brand there is nothing to group, so the report was telling the truth about an empty catalogue.
 *
 * A screenshot of four zeroes and a blank grid is coverage, not evidence: it exercises no bar, no
 * legend, no number formatting and no thousands separator, and it cannot show whether the chart
 * reads correctly right-to-left. This tool makes the screen say something.
 *
 * WHAT IT DOES. Assigns the three existing brands round-robin to exactly the products that appear
 * in an order, and nothing else. It invents no product, no order and no money -- the figures the
 * report then shows are the fixture's real order values, merely attributed.
 *
 * DEV FIXTURE ONLY, IDEMPOTENT, EXACTLY REVERSIBLE. Every product in this fixture starts at
 * `brand_id = 0/NULL`, so `--remove` restores that state precisely for the rows this tool set:
 *
 *     php seed-dev-brand-assignments.php --remove
 *
 * usage:
 *   DB_CONNECTION=sqlite DB_DATABASE=<repo>/database/p2dev.sqlite CACHE_STORE=file \
 *     php docs/development/phase-2.5/tools/seed-dev-brand-assignments.php [--remove]
 */

require __DIR__ . '/../../../../vendor/autoload.php';

/**
 * FAIL CLOSED BEFORE THE APPLICATION BOOTS -- the same guard every writing tool in this directory
 * carries. `Tests\` is mapped by composer's autoload-dev, so on a production install the guard
 * class is simply absent and this tool cannot start.
 */
if (! class_exists(\Tests\Support\DevFixtureDatabaseGuard::class)) {
    fwrite(STDERR, "REFUSING TO RUN: Tests\\Support\\DevFixtureDatabaseGuard is not autoloadable, "
        . "which means this is a --no-dev install. Development fixture tools do not run here.\n");
    exit(1);
}

try {
    \Tests\Support\DevFixtureDatabaseGuard::assertCurrentEnvironmentIsADevFixture();
} catch (\RuntimeException $e) {
    fwrite(STDERR, "\n" . $e->getMessage() . "\n\n");
    exit(1);
}

$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$remove = in_array('--remove', $argv, true);

foreach (['products', 'product_brands', 'orders'] as $t) {
    if (! Schema::hasTable($t)) {
        fwrite(STDERR, "REFUSING: table [$t] is missing from this fixture.\n");
        exit(1);
    }
}

if (! Schema::hasColumn('products', 'brand_id')) {
    fwrite(STDERR, "REFUSING: products.brand_id does not exist in this fixture.\n");
    exit(1);
}

// ---- the products that actually appear in an order ------------------------------------------
$orderedIds = [];
foreach (DB::table('orders')->pluck('product_json') as $json) {
    foreach ((array) json_decode((string) $json, true) as $line) {
        if (isset($line['product_id'])) {
            $orderedIds[(int) $line['product_id']] = true;
        }
    }
}
$orderedIds = array_values(array_filter(array_keys($orderedIds)));
sort($orderedIds);

if ($orderedIds === []) {
    fwrite(STDERR, "REFUSING: no order in this fixture names a product_id, so there is nothing to "
        . "attribute. The brand report would still read zero.\n");
    exit(1);
}

$brandIds = DB::table('product_brands')->orderBy('id')->pluck('id')->all();

if ($brandIds === []) {
    fwrite(STDERR, "REFUSING: product_brands is empty, so there is no brand to assign.\n");
    exit(1);
}

if ($remove) {
    $reset = DB::table('products')->whereIn('id', $orderedIds)->update(['brand_id' => 0]);
    printf("CLEANED: %d ordered product(s) returned to brand_id = 0\n", $reset);
} else {
    $assigned = [];
    foreach ($orderedIds as $i => $productId) {
        $brandId = $brandIds[$i % count($brandIds)];
        DB::table('products')->where('id', $productId)->update(['brand_id' => $brandId]);
        $assigned[$brandId][] = $productId;
    }

    printf("SEEDED: %d ordered product(s) across %d brand(s)\n\n", count($orderedIds), count($brandIds));
    foreach ($assigned as $brandId => $productIds) {
        $name = DB::table('product_brands')->where('id', $brandId)->value('name');
        printf("  %-18s products %s\n", $name, implode(', ', $productIds));
    }
}

/*
 * FLUSH. `Product` is one of the 79 `Cachable` models, and a raw write leaves Eloquent reads --
 * route-model binding included -- serving the pre-write row. Under the harness's CACHE_STORE=file
 * that staleness outlives this process, so the browser would render a report built from the
 * cached, brandless products and the capture would still show zeros.
 */
foreach (['App\Models\Product', 'App\Models\ProductBrand'] as $class) {
    if (class_exists($class) && method_exists($class, 'flushCache')) {
        (new $class)->flushCache();
    }
}

echo "\ncounts now:\n";
printf("  %-28s %d\n", 'products', DB::table('products')->count());
printf("  %-28s %d\n", 'products with a brand',
    DB::table('products')->where('brand_id', '>', 0)->count());
