<?php

/**
 * PHASE 03 M0 — THE STOREFRONT FIXTURE, starting with the thing that blocks everything else.
 * =================================================================================================
 *
 * WHY THIS EXISTS. Phase 03's acceptance criteria assume the Phase 2.5 evidence standard, and the
 * Phase 2.5 harness cannot produce it for a customer-facing page. Measured on this branch,
 * against the merged main:
 *
 *     128 .cjs harnesses under docs/development/phase-2 tool directories
 *       6 of them mention a customer at all
 *       0 of them drive {storeSlug}/login
 *
 * Every one of those six navigates to the MERCHANT `/login` (the `web` guard). The storefront
 * sign-in is a different route on a different guard -- `routes/customer.php:106-109`,
 * `Customer\Auth\CustomerLoginController`, guard `customers` -- and nothing in the repository has
 * ever driven it. So "capture the storefront as a customer" was not a thing that could be done,
 * and that is gap 4 of the eight the Phase 03 inventory recorded.
 *
 * A harness needs credentials before it needs anything else, and the fixture had none: the four
 * seeded customers carry whatever password hash an earlier seeder wrote, which nothing records.
 * This tool makes that a known, repeatable fact instead.
 *
 * WHAT IT DOES, and all of it is store-scoped because the login is:
 *
 *   0. Populates the SECOND tenant's catalogue. `aleppo-soap` had no products, categories or
 *      brands at all, so there was nothing to lay out, nothing to compare against store 1 and no
 *      way to show that one storefront renders only its own rows. Its imagery is generated and
 *      committed under `uploads/2/p3fixt/` -- deliberately synthetic tiles, because a fixture must
 *      never be mistaken for a real product photograph.
 *   1. Gives every P3 fixture customer the same known development password. `CustomerLoginController`
 *      resolves by `email` AND `store_id` (`:104`, `:139`), so the same address in two stores is two
 *      different principals -- which is exactly what the cross-tenant negative tests need.
 *   2. Creates a customer in the SECOND store. `aleppo-soap` had no customer at all, so there was
 *      no way to sign in to it, and therefore no way to prove that store 1's customer cannot reach
 *      store 2's storefront as itself.
 *   3. Leaves `status` alone on rows it did not create. `nour.saleh` is deliberately `status = 0`;
 *      a disabled customer is a test case, not a defect to tidy away.
 *
 * DEV FIXTURE ONLY, IDEMPOTENT, REVERSIBLE. Rows this tool creates carry `P3FIXT` in their
 * `register_type`, which is what makes them findable and removable:
 *
 *     php seed-storefront-fixture.php --remove
 *
 * It never deletes a customer it did not create. A password reset on a PRE-EXISTING row is
 * reversible only in the sense that the row survives `--remove`; the old hash is not recoverable,
 * which is stated here rather than discovered later. That is acceptable for a synthetic fixture
 * and would not be for anything else, which is why the guard below exists.
 *
 * usage:
 *   DB_CONNECTION=sqlite DB_DATABASE=<repo>/database/p2dev.sqlite CACHE_STORE=file \
 *     php docs/development/phase-3/tools/seed-storefront-fixture.php [--remove] [--report]
 */

require __DIR__ . '/../../../../vendor/autoload.php';

/**
 * FAIL CLOSED BEFORE THE APPLICATION BOOTS -- the same control every Phase 2.5 fixture tool uses,
 * and for a sharper reason here: this one writes PASSWORD HASHES. Run against a real database it
 * would reset live customer credentials and print success.
 *
 * `Tests\` is mapped by composer's autoload-dev, so on a production install the guard class is
 * simply absent and this tool cannot start.
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

use App\Models\Customer;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

const TAG = 'P3FIXT';

/**
 * NOT A SECRET, AND DELIBERATELY OBVIOUS. This is a synthetic fixture on a gitignored sqlite file;
 * the value is published here precisely so a harness and a reader agree on it without a side
 * channel. It is never written to a real database -- the guard above refuses.
 */
const DEV_PASSWORD = 'storefront-dev-password';

$remove = in_array('--remove', $argv, true);
$report = in_array('--report', $argv, true);

$stores = Store::withoutGlobalScopes()->orderBy('id')->get(['id', 'name', 'slug', 'theme_id']);

if ($stores->count() < 2) {
    fwrite(STDERR, "REFUSING: the storefront fixture needs at least TWO stores to prove tenant\n"
        . "isolation, and this database has {$stores->count()}. A single-tenant fixture cannot\n"
        . "show that store A's storefront refuses store B's customer.\n");
    exit(1);
}

echo "stores in this fixture:\n";
foreach ($stores as $s) {
    printf("  id=%-3s slug=%-20s theme_id=%s\n", $s->id, $s->slug, var_export($s->theme_id, true));
}
echo "\n";

/* ------------------------------------------------------------------------------------------- */

if ($report) {
    echo "customers per store, and whether each can sign in:\n";
    foreach ($stores as $s) {
        $rows = Customer::withoutGlobalScopes()->where('store_id', $s->id)->get();
        printf("  store %-3s (%s): %d customer(s)\n", $s->id, $s->slug, $rows->count());
        foreach ($rows as $c) {
            printf("      %-34s status=%s  password known=%s  %s\n",
                $c->email,
                $c->status,
                Hash::check(DEV_PASSWORD, (string) $c->password) ? 'YES' : 'no ',
                $c->register_type === TAG ? '[' . TAG . ']' : '');
        }
    }
    exit(0);
}

/* ------------------------------------------------------------------------------------------- */

if ($remove) {
    // Catalogue first: products reference categories and brands.
    foreach ([\App\Models\Product::class => 'products',
              \App\Models\ProductBrand::class => 'product_brands',
              \App\Models\Category::class => 'categories'] as $model => $table) {
        $ids = DB::table($table)->where('slug', 'like', 'p3fixt-%')->pluck('id');
        if ($ids->isEmpty()) {
            continue;
        }
        DB::table('product_images')->whereIn('product_id', $ids)->delete();
        // Eloquent, not DB::table: every one of these models is Cachable, and a raw delete leaves
        // Eloquent reads -- route-model binding included -- serving rows that are gone.
        foreach ($model::withoutGlobalScopes()->whereIn('id', $ids)->get() as $row) {
            $row->delete();
        }
        echo "  removed {$ids->count()} row(s) from {$table}\n";
    }

    $gone = 0;
    foreach (Customer::withoutGlobalScopes()->where('register_type', TAG)->get() as $c) {
        // Eloquent delete, not DB::table: Customer is Cachable, and a raw delete leaves every
        // Eloquent read -- route-model binding included -- serving the row that is no longer there.
        $c->delete();
        $gone++;
        echo "  removed {$c->email} (store {$c->store_id})\n";
    }
    echo "\nremoved {$gone} " . TAG . " customer(s). Pre-existing rows, and any password this tool\n"
        . "reset on them, are left as they are.\n";
    exit(0);
}

/* ------------------------------------------------------------------------------------------- */

$hash = Hash::make(DEV_PASSWORD);
$reset = 0;
$made = 0;

foreach ($stores as $store) {
    $existing = Customer::withoutGlobalScopes()->where('store_id', $store->id)->get();

    // 1. Every customer in this store gets the known password, so ANY of them can drive a harness.
    foreach ($existing as $c) {
        if (Hash::check(DEV_PASSWORD, (string) $c->password)) {
            continue;
        }
        $c->password = $hash;
        $c->save();
        $reset++;
        printf("  password set  store=%-3s %s\n", $store->id, $c->email);
    }

    // 2. A store with no customer cannot be signed in to, so it cannot take part in the negative
    //    tests at all. Measured before this tool: aleppo-soap had zero.
    if ($existing->isEmpty()) {
        $email = 'p3-shopper@' . $store->slug . '.test';

        $c = new Customer();
        $c->first_name = 'P3';
        $c->last_name = 'Shopper';
        $c->email = $email;
        $c->password = $hash;
        $c->store_id = $store->id;
        $c->status = 1;
        $c->type = 'customer';
        $c->register_type = TAG;
        $c->created_by = $store->created_by ?? 1;
        // `billable_*` mirror what the existing fixture customers carry; the storefront does not
        // read them, but a null billable_type has broken an unrelated report query before.
        $c->billable_id = $store->created_by ?? 1;
        $c->billable_type = \App\Models\User::class;
        $c->save();

        $made++;
        printf("  CREATED       store=%-3s %s\n", $store->id, $email);
    }
}

echo "\n{$reset} password(s) set, {$made} customer(s) created.\n";

/* ------------------------------------------------------------------------------------------- */

/**
 * THE SECOND TENANT'S CATALOGUE.
 *
 * Measured before this: `aleppo-soap` had 0 products, 0 categories and 0 brands, while
 * `damascus-textiles` had 32 / 4 / 3. A storefront with nothing in it cannot be laid out, cannot
 * be compared, and -- the part that matters for Phase 03 -- cannot show that store A's storefront
 * renders ONLY store A's rows. Its zero broken images were not health; there was nothing to break.
 *
 * Every row carries `store_id` and a `p3fixt-` slug prefix, which is what makes the set findable,
 * removable, and impossible to confuse with the demo content that ships with WorkDo.
 */
$second = $stores->firstWhere('slug', 'aleppo-soap') ?? $stores->get(1);
$IMG = 'uploads/' . $second->id . '/p3fixt';

$CATEGORIES = [
    ['Soap Bars',   'p3fixt-soap-bars',   'cat-bars',  1],
    ['Gift Sets',   'p3fixt-gift-sets',   'cat-gifts', 1],
    ['Hair & Body', 'p3fixt-hair-body',   'cat-care',  0],
];
$BRANDS = [
    ['Aleppo House', 'p3fixt-aleppo-house', 'brand-aleppo-house', 1],
    ['Levant Co',    'p3fixt-levant-co',    'brand-levant',       0],
];
// name, slug, image, price, sale, stock, category index, brand index
$PRODUCTS = [
    ['Classic Aleppo Soap', 'p3fixt-aleppo-classic', 'aleppo-classic', 8.00,  6.50,  48, 0, 0],
    ['Laurel 20% Bar',      'p3fixt-laurel-20',      'laurel-20',      14.00, 12.00, 26, 0, 0],
    ['Pure Olive Bar',      'p3fixt-olive-pure',     'olive-pure',     6.50,  6.50,  90, 0, 0],
    ['Rose Clay Bar',       'p3fixt-rose-clay',      'rose-clay',      9.50,  8.00,  12, 0, 1],
    ['Black Seed Bar',      'p3fixt-black-seed',     'black-seed',     11.00, 11.00, 0,  0, 1],
    ['Gift Trio Box',       'p3fixt-gift-trio',      'gift-trio',      29.00, 24.00, 7,  1, 0],
    ['Travel Tin',          'p3fixt-travel-tin',     'travel-tin',     5.00,  5.00,  64, 1, 1],
    ['Olive Shampoo Bar',   'p3fixt-shampoo-bar',    'shampoo-bar',    13.00, 10.50, 31, 2, 0],
];

$catIds = [];
foreach ($CATEGORIES as [$name, $slug, $img, $trending]) {
    $row = \App\Models\Category::withoutGlobalScopes()->where('slug', $slug)->first();
    if (! $row) {
        $row = new \App\Models\Category();
        $row->slug = $slug;
    }
    $row->name = $name;
    $row->image_path = "{$IMG}/{$img}.png";
    $row->image_url = "{$IMG}/{$img}.png";
    $row->parent_id = 0;
    $row->trending = $trending;
    $row->status = 1;
    $row->store_id = $second->id;
    $row->save();
    $catIds[] = $row->id;
}

$brandIds = [];
foreach ($BRANDS as [$name, $slug, $img, $popular]) {
    $row = \App\Models\ProductBrand::withoutGlobalScopes()->where('slug', $slug)->first();
    if (! $row) {
        $row = new \App\Models\ProductBrand();
        $row->slug = $slug;
    }
    $row->name = $name;
    $row->logo = "{$IMG}/{$img}.png";
    $row->status = 1;
    $row->is_popular = $popular;
    $row->store_id = $second->id;
    $row->created_by = $second->created_by ?? 1;
    $row->save();
    $brandIds[] = $row->id;
}

$prodMade = 0;
foreach ($PRODUCTS as [$name, $slug, $img, $price, $sale, $stock, $ci, $bi]) {
    $row = \App\Models\Product::withoutGlobalScopes()->where('slug', $slug)->first();
    if (! $row) {
        $row = new \App\Models\Product();
        $row->slug = $slug;
        $prodMade++;
    }
    $row->name = $name;
    $row->category_id = $catIds[$ci] ?? null;
    $row->brand_id = $brandIds[$bi] ?? null;
    $row->price = $price;
    $row->sale_price = $sale;
    $row->cost_price = round($price * 0.45, 2);
    $row->product_stock = $stock;
    // ONE DELIBERATE ZERO-STOCK ROW. An out-of-stock card is a state the storefront has to render
    // and a baseline that contains only happy rows cannot show it.
    $row->stock_status = $stock > 0 ? 'in_stock' : 'out_of_stock';
    $row->track_stock = 1;
    $row->low_stock_threshold = 10;
    $row->status = 1;
    $row->is_active = 1;
    $row->variant_product = 0;
    $row->product_type = 'simple';
    $row->cover_image_path = "{$IMG}/{$img}.png";
    $row->cover_image_url = "{$IMG}/{$img}.png";
    $row->description = "Traditional {$name} from Aleppo, cured slowly and cut by hand.";
    $row->detail = "P3FIXT development fixture row. Not a real product.";
    $row->store_id = $second->id;
    $row->created_by = $second->created_by ?? 1;
    $row->save();

    // A gallery row as well, so the product detail page has more than its cover to show.
    if (! DB::table('product_images')->where('product_id', $row->id)->exists()) {
        DB::table('product_images')->insert([
            'product_id' => $row->id,
            'image_path' => "{$IMG}/{$img}.png",
            'image_url' => "{$IMG}/{$img}.png",
            'store_id' => $second->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

printf("\n  store %s catalogue: %d categories, %d brands, %d products (%d new)\n",
    $second->id, count($catIds), count($brandIds), count($PRODUCTS), $prodMade);

/* ------------------------------------------------------------------------------------------- */

/**
 * THE PREMISE, ASSERTED RATHER THAN ASSUMED. A fixture tool that prints success without checking
 * what it produced is how a harness ends up driving a login that cannot succeed. Every store must
 * now have at least one ENABLED customer whose password this tool knows.
 */
$problems = [];

foreach ($stores as $store) {
    $usable = Customer::withoutGlobalScopes()
        ->where('store_id', $store->id)
        ->where('status', 1)
        ->get()
        ->first(fn ($c) => Hash::check(DEV_PASSWORD, (string) $c->password));

    if ($usable === null) {
        $problems[] = "store {$store->id} ({$store->slug}) has no enabled customer with the known password";
        continue;
    }

    printf("  store %-3s (%-20s) can sign in as %s\n", $store->id, $store->slug, $usable->email);
}

if ($problems !== []) {
    fwrite(STDERR, "\nFIXTURE INCOMPLETE:\n  - " . implode("\n  - ", $problems) . "\n");
    exit(1);
}

echo "\nstorefront sign-in fixture ready. The password is " . DEV_PASSWORD . " and it is not a secret.\n";
echo "VERIFY, do not assume: a storefront route that answers 200 may still be the LOGIN page.\n";
