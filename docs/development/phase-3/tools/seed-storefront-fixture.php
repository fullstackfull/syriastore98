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
use App\Models\ThemeCustomize;
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

/**
 * A THIRD STORE WITH NO THEME CHOSEN, because neither of the other two can exercise that path.
 *
 * Both `damascus-textiles` and `aleppo-soap` carry `theme_id = 'stylique'`. The M0 inventory
 * recorded "empty `stores.theme_id` skips `Theme::set()` -> base layout, no `dir`, `themes//...`
 * asset path" as OPEN BUT UNREPRODUCED, and it could not be reproduced without a store that
 * actually has one.
 *
 * `stores.theme_id` is `$table->string('theme_id')` -- NOT nullable -- so the empty case is the
 * empty STRING, which is why `forceCreate`/a direct assignment is used rather than a factory.
 *
 * WHAT IT FOUND, once it existed: not degradation but a crash. `/home` could not resolve
 * `main_file` and the other ELEVEN page routes answered 500, because `getThemeSetting($id, '')`
 * built `themes//default_data/settings.php`, found nothing, and returned an array with no keys for
 * views that index ~525 of them. Fixed in `app/Helper/helper.php`,
 * `app/Http/Middleware/ActiveTheme.php` and `app/Providers/ViewServiceProvider.php`; pinned by
 * `tests/Feature/Storefront/EmptyThemeIdFallsBackTest.php`.
 *
 * It is created BEFORE the customer loop below so it gets a shopper like the others and can be
 * captured signed-in.
 */
const THEMELESS_SLUG = 'p3fixt-no-theme';

$themeless = Store::withoutGlobalScopes()->where('slug', THEMELESS_SLUG)->first();

if ($remove) {
    if ($themeless) {
        Customer::withoutGlobalScopes()->where('store_id', $themeless->id)->delete();
        $themeless->delete();
        echo "removed the themeless store (" . THEMELESS_SLUG . ") and its customers\n\n";
    }
} else {
    if ($themeless === null) {
        $owner = $stores->first()->created_by ?? 1;

        $themeless = new Store();
        $themeless->name = 'P3 Fixture - No Theme';
        $themeless->email = 'p3fixt-no-theme@example.test';
        $themeless->slug = THEMELESS_SLUG;
        $themeless->theme_id = '';          // the whole point of this row
        $themeless->created_by = $owner;
        $themeless->is_active = 1;
        $themeless->default_language = 'en';
        $themeless->save();

        printf("  CREATED       themeless store id=%s slug=%s theme_id=''\n", $themeless->id, $themeless->slug);
    }

    // A PREMISE CHECK, not a formality. If something normalises `''` to a theme name on save, this
    // row silently stops testing what it is for and every assertion about it becomes vacuous.
    $fresh = Store::withoutGlobalScopes()->find($themeless->id);

    if ((string) $fresh->theme_id !== '') {
        fwrite(STDERR, "REFUSING: the themeless store came back with theme_id="
            . var_export($fresh->theme_id, true) . ", not ''. Something normalises it on save, so\n"
            . "this row cannot exercise the empty-theme path and must not be relied on.\n");
        exit(1);
    }

    echo "  themeless store ready: id={$fresh->id} slug={$fresh->slug} theme_id=''\n\n";

    // Re-read so the loops below include it.
    $stores = Store::withoutGlobalScopes()->orderBy('id')->get(['id', 'name', 'slug', 'theme_id', 'created_by']);
}

/* ------------------------------------------------------------------------------------------- */

/**
 * ONE STORE PER THEME, because M1 has to measure all THREE and two of them had no fixture at all.
 *
 * `themes/` ships `stylique`, `techzonix` and `greentic` -- 13, 15 and 16 blade files, each with
 * its own `default_data/settings.php`. Every fixture store carried `theme_id = 'stylique'`, so the
 * M0 baseline measured ONE theme three times and said nothing about the other two. A theme is not
 * a skin here: each has its own `main_file`, its own layout, its own section markup and its own
 * ~530 default settings, so a defect in one is invisible from the others.
 *
 * These two are created with the SAME shape as the existing stores -- a customer, a catalogue,
 * per-store copy and a logo -- so a difference between captures is a difference between THEMES
 * rather than between fixtures.
 */
const THEME_STORES = [
    ['p3fixt-homs-tech',      'P3 Fixture - Homs Tech',      'techzonix', 'en'],
    ['p3fixt-latakia-green',  'P3 Fixture - Latakia Green',  'greentic',  'ar'],
];

foreach (THEME_STORES as [$slug, $name, $theme, $lang]) {
    $row = Store::withoutGlobalScopes()->where('slug', $slug)->first();

    if ($remove) {
        if ($row) {
            Customer::withoutGlobalScopes()->where('store_id', $row->id)->delete();
            $row->delete();
            printf("  removed theme store %s\n", $slug);
        }
        continue;
    }

    if ($row === null) {
        $row = new Store();
        $row->slug = $slug;
        $row->email = $slug . '@example.test';
        $row->created_by = $stores->first()->created_by ?? 1;
    }

    $row->name = $name;
    $row->theme_id = $theme;
    $row->is_active = 1;
    // One store defaults to Arabic so an RTL capture exists that did not need the language switch
    // driven -- the store default and the switch are different code paths.
    $row->default_language = $lang;
    $row->save();

    // A PREMISE CHECK. A theme name that does not exist on disk silently falls back to the
    // configured theme, and the capture would then be of `stylique` under another store's name --
    // exactly the kind of row that looks like evidence and is not.
    if (! is_file(base_path("themes/{$theme}/views/main_file.blade.php"))) {
        fwrite(STDERR, "REFUSING: theme '{$theme}' has no views/main_file.blade.php, so store "
            . "{$slug} would render the FALLBACK theme and its captures would be mislabelled.\n");
        exit(1);
    }

    printf("  theme store ready: %-22s theme=%-10s lang=%s\n", $row->slug, $theme, $lang);
}

if (! $remove) {
    $stores = Store::withoutGlobalScopes()->orderBy('id')->get(['id', 'name', 'slug', 'theme_id', 'created_by']);
}

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
/**
 * ONE CATALOGUE PER STORE, driven from a spec rather than hardcoded to one store.
 *
 * This was written against `aleppo-soap` alone. M1 needs three themes with comparable content, so
 * the loops moved into a function and each store brings its own spec. Three consequences worth
 * knowing:
 *
 *   - PRODUCT NAMES DIFFER PER STORE, so the slugs differ and nothing collides. That matters
 *     because the lookups below are `where('slug', ...)` with NO store predicate -- `Product` is
 *     absent from `config/tenancy.php`'s `scoped_models`, so a shared slug would make this tool
 *     update ANOTHER store's row instead of creating its own.
 *   - EVERY IMAGE PATH IS `uploads/<store id>/p3fixt/`, AND IT HAS TO BE. The first draft used an
 *     id-independent `uploads/p3fixt/<store-slug>/` so committed paths could not go stale when the
 *     fixture is rebuilt in a different order -- and `App\Services\StoredPathPolicy` REFUSED every
 *     row with "The file path is not accepted." That policy is P1-SEC-28: a stored path's first
 *     segment under `uploads/` must be the owning store's NUMERIC folder (or a sibling store of the
 *     same owner), so that a poisoned row cannot name another tenant's file for one of the vendored
 *     deleters to unlink. The policy is right and the path choice was wrong.
 *
 *     The id fragility is therefore real and unavoidable, so it is made LOUD instead of silent: the
 *     premise check below refuses to seed a catalogue whose image directory is missing, naming the
 *     directory it wanted. Only the LOGOS stay id-independent, at `uploads/p3fixt/logo-*.png` --
 *     they are `theme_customizes` values, which no path column policy judges.
 *   - EACH SPEC KEEPS ONE ZERO-STOCK ROW. An out-of-stock card is a state the storefront has to
 *     render and a baseline of only happy rows cannot show it.
 */
$seedCatalogue = function (Store $store, string $imgDir, array $spec) {
    $catIds = [];
    foreach ($spec['categories'] as [$name, $slug, $img, $trending]) {
        $row = \App\Models\Category::withoutGlobalScopes()->where('slug', $slug)->first();
        if (! $row) {
            $row = new \App\Models\Category();
            $row->slug = $slug;
        }
        $row->name = $name;
        $row->image_path = "{$imgDir}/{$img}.png";
        $row->image_url = "{$imgDir}/{$img}.png";
        $row->parent_id = 0;
        $row->trending = $trending;
        $row->status = 1;
        $row->store_id = $store->id;
        $row->save();
        $catIds[] = $row->id;
    }

    $brandIds = [];
    foreach ($spec['brands'] as [$name, $slug, $img, $popular]) {
        $row = \App\Models\ProductBrand::withoutGlobalScopes()->where('slug', $slug)->first();
        if (! $row) {
            $row = new \App\Models\ProductBrand();
            $row->slug = $slug;
        }
        $row->name = $name;
        $row->logo = "{$imgDir}/{$img}.png";
        $row->status = 1;
        $row->is_popular = $popular;
        $row->store_id = $store->id;
        $row->created_by = $store->created_by ?? 1;
        $row->save();
        $brandIds[] = $row->id;
    }

    $made = 0;
    foreach ($spec['products'] as [$name, $slug, $img, $price, $sale, $stock, $ci, $bi]) {
        $row = \App\Models\Product::withoutGlobalScopes()->where('slug', $slug)->first();
        if (! $row) {
            $row = new \App\Models\Product();
            $row->slug = $slug;
            $made++;
        }
        $row->name = $name;
        $row->category_id = $catIds[$ci] ?? null;
        $row->brand_id = $brandIds[$bi] ?? null;
        $row->price = $price;
        $row->sale_price = $sale;
        $row->cost_price = round($price * 0.45, 2);
        $row->product_stock = $stock;
        $row->stock_status = $stock > 0 ? 'in_stock' : 'out_of_stock';
        $row->track_stock = 1;
        $row->low_stock_threshold = 10;
        $row->status = 1;
        $row->is_active = 1;
        $row->variant_product = 0;
        $row->product_type = 'simple';
        $row->cover_image_path = "{$imgDir}/{$img}.png";
        $row->cover_image_url = "{$imgDir}/{$img}.png";
        $row->description = str_replace('{name}', $name, $spec['blurb']);
        $row->detail = 'P3FIXT development fixture row. Not a real product.';
        $row->store_id = $store->id;
        $row->created_by = $store->created_by ?? 1;
        $row->save();

        // A gallery row as well, so the product detail page has more than its cover to show.
        if (! DB::table('product_images')->where('product_id', $row->id)->exists()) {
            DB::table('product_images')->insert([
                'product_id' => $row->id,
                'image_path' => "{$imgDir}/{$img}.png",
                'image_url' => "{$imgDir}/{$img}.png",
                'store_id' => $store->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    printf("  store %-3s %-22s %d categories, %d brands, %d products (%d new)\n",
        $store->id, $store->slug, count($catIds), count($brandIds), count($spec['products']), $made);
};

/** slug => [image dir, spec]. `name, slug, image, price, sale, stock, category index, brand index` */
$CATALOGUES = [
    'aleppo-soap' => ['uploads/{id}/p3fixt', [
        'blurb' => 'Traditional {name} from Aleppo, cured slowly and cut by hand.',
        'categories' => [
            ['Soap Bars',   'p3fixt-soap-bars',   'cat-bars',  1],
            ['Gift Sets',   'p3fixt-gift-sets',   'cat-gifts', 1],
            ['Hair & Body', 'p3fixt-hair-body',   'cat-care',  0],
        ],
        'brands' => [
            ['Aleppo House', 'p3fixt-aleppo-house', 'brand-aleppo-house', 1],
            ['Levant Co',    'p3fixt-levant-co',    'brand-levant',       0],
        ],
        'products' => [
            ['Classic Aleppo Soap', 'p3fixt-aleppo-classic', 'aleppo-classic', 8.00,  6.50,  48, 0, 0],
            ['Laurel 20% Bar',      'p3fixt-laurel-20',      'laurel-20',      14.00, 12.00, 26, 0, 0],
            ['Pure Olive Bar',      'p3fixt-olive-pure',     'olive-pure',     6.50,  6.50,  90, 0, 0],
            ['Rose Clay Bar',       'p3fixt-rose-clay',      'rose-clay',      9.50,  8.00,  12, 0, 1],
            ['Black Seed Bar',      'p3fixt-black-seed',     'black-seed',     11.00, 11.00, 0,  0, 1],
            ['Gift Trio Box',       'p3fixt-gift-trio',      'gift-trio',      29.00, 24.00, 7,  1, 0],
            ['Travel Tin',          'p3fixt-travel-tin',     'travel-tin',     5.00,  5.00,  64, 1, 1],
            ['Olive Shampoo Bar',   'p3fixt-shampoo-bar',    'shampoo-bar',    13.00, 10.50, 31, 2, 0],
        ],
    ]],
    'p3fixt-homs-tech' => ['uploads/{id}/p3fixt', [
        'blurb' => 'A {name} assembled in Homs. Twelve-month warranty.',
        'categories' => [
            ['Audio',  'p3fixt-ht-audio',  'cat-audio',  1],
            ['Power',  'p3fixt-ht-power',  'cat-power',  1],
            ['Mobile', 'p3fixt-ht-mobile', 'cat-mobile', 0],
        ],
        'brands' => [
            ['Orontes', 'p3fixt-ht-orontes', 'brand-orontes', 1],
            ['Citadel', 'p3fixt-ht-citadel', 'brand-citadel', 0],
        ],
        'products' => [
            ['Smart Kettle',  'p3fixt-ht-kettle',  'kettle-smart',  42.00, 36.00, 18, 1, 0],
            ['Desk Lamp',     'p3fixt-ht-lamp',    'lamp-desk',     24.00, 24.00, 55, 1, 1],
            ['Power Bank',    'p3fixt-ht-bank',    'bank-power',    31.00, 27.50, 40, 1, 0],
            ['Earbuds Pro',   'p3fixt-ht-buds',    'buds-pro',      68.00, 59.00, 9,  0, 0],
            ['USB-C Cable',   'p3fixt-ht-cable',   'cable-usbc',    7.00,  7.00,  200, 2, 1],
            ['7-Port Hub',    'p3fixt-ht-hub',     'hub-seven',     38.00, 33.00, 0,  2, 0],
            ['Mini Speaker',  'p3fixt-ht-speaker', 'speaker-mini',  29.00, 25.00, 22, 0, 1],
            ['Phone Stand',   'p3fixt-ht-stand',   'stand-phone',   11.00, 9.50,  76, 2, 1],
        ],
    ]],
    'p3fixt-latakia-green' => ['uploads/{id}/p3fixt', [
        'blurb' => '{name}, grown on the Latakia coast and potted the week it ships.',
        'categories' => [
            ['Herbs', 'p3fixt-lg-herbs', 'cat-herbs', 1],
            ['Pots',  'p3fixt-lg-pots',  'cat-pots',  0],
            ['Seeds', 'p3fixt-lg-seeds', 'cat-seeds', 1],
        ],
        'brands' => [
            ['Coast Grown', 'p3fixt-lg-coast',   'brand-coast',   1],
            ['Terrace',     'p3fixt-lg-terrace', 'brand-terrace', 0],
        ],
        'products' => [
            ['Mint in Pot',    'p3fixt-lg-mint',    'mint-pot',      4.50,  4.00,  120, 0, 0],
            ['Basil in Pot',   'p3fixt-lg-basil',   'basil-pot',     4.50,  4.50,  85,  0, 0],
            ['Olive Sapling',  'p3fixt-lg-olive',   'olive-sapling', 22.00, 19.00, 14,  0, 1],
            ['Jasmine Vine',   'p3fixt-lg-jasmine', 'jasmine-vine',  17.00, 15.00, 0,   0, 1],
            ['Clay Pot L',     'p3fixt-lg-pot',     'clay-pot-l',    13.00, 13.00, 48,  1, 1],
            ['Seed Mix',       'p3fixt-lg-seedmix', 'seed-mix',      6.00,  5.00,  160, 2, 0],
            ['Watering Can',   'p3fixt-lg-can',     'watering-can',  15.00, 12.50, 30,  1, 0],
            ['Trowel Set',     'p3fixt-lg-trowel',  'trowel-set',    19.00, 16.00, 11,  1, 1],
        ],
    ]],
];

echo "\ncatalogues:\n";
foreach ($CATALOGUES as $slug => [$imgDir, $spec]) {
    $target = Store::withoutGlobalScopes()->where('slug', $slug)->first();

    if ($target === null) {
        printf("  no store with slug %s, skipped\n", $slug);
        continue;
    }

    $dir = str_replace('{id}', (string) $target->id, $imgDir);

    // THE PREMISE, because the path has to be id-keyed (see above) and ids move. A missing
    // directory would otherwise seed rows pointing at nothing and the capture would show a page
    // full of placeholders that looks like a product defect.
    if (! is_dir(base_path($dir))) {
        fwrite(STDERR, "REFUSING: store {$target->slug} (id {$target->id}) needs its images at "
            . "{$dir}, which does not exist. StoredPathPolicy requires the store's own numeric\n"
            . "folder, so the fixture images must be moved there -- they cannot live under a\n"
            . "slug-keyed path. Seeding would produce rows pointing at missing files.\n");
        exit(1);
    }

    $seedCatalogue($target, $dir, $spec);
}

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

/* ------------------------------------------------------------------------------------------- */

/**
 * THEME CUSTOMISATIONS, so the storefront stops being a photograph of the THEME's demo content.
 *
 * `theme_customizes` had ZERO rows -- measured -- which is M0 gap 5. With none, `getThemeSetting()`
 * returns the theme's own `default_data/settings.php` verbatim, so both tenants' home pages read
 * "Shop by Category / Featured Products / Accessorize with Elegance". Two different merchants'
 * storefronts were therefore BYTE-IDENTICAL above the product grid, which makes a capture useless
 * for the one thing Phase 03 has to show: that a storefront reflects ITS OWN store.
 *
 * Only the 24 keys `main_file.blade.php` actually reads are set, and only the TEXT ones. The
 * repeaters (`slider_repeater`, `service_repeater`, `logo_repeater`) keep the theme's own images,
 * which exist on disk; inventing image paths here would re-create the broken-image problem the
 * previous commit fixed.
 *
 * `theme_customizes` is NOT in `config/tenancy.php`'s `scoped_models`, so it adds no store
 * predicate for anyone. Every row written here carries an explicit `store_id`, and every read in
 * the editor/preview/publish work of M3 and M4 will have to do the same -- that is recorded as an
 * open Phase 03 finding, not solved by this fixture.
 *
 * Written through Eloquent deliberately: `ThemeCustomize` is `Cachable`, and a raw
 * `DB::table()->insert()` would leave Eloquent reads -- route-model binding included -- serving the
 * pre-write state.
 */
$CUSTOMISE = [
    'damascus-textiles' => [
        // THE HEADER AND FOOTER LOGO. Without these, every store on the platform showed
        // `themes/stylique/assets/images/logo.png` -- the THEME's logo -- and two different
        // merchants' pages were BYTE-IDENTICAL screenshots. Measured: 28 of 126 captures collided
        // across stores, and the 99 differing HTML lines were all csrf-token and og:url, i.e.
        // nothing visible.
        //
        // The storefront behaviour is CORRECT: a merchant who has uploaded no logo should get the
        // theme's, which is why these images are not forced in from code. It is the FIXTURE that
        // was unrealistic, so the fixture sets them.
        'logo'                  => 'uploads/p3fixt/logo-damascus-textiles.png',
        'header_logo'           => 'uploads/p3fixt/logo-damascus-textiles.png',
        'footer_logo'           => 'uploads/p3fixt/logo-damascus-textiles.png',
        'category_title'        => 'Fabrics by the Metre',
        'category_description'  => 'Damask, brocade and raw silk, cut to length in our Damascus workshop.',
        'product_title'         => 'This Season\'s Weaves',
        'product_description'   => 'Hand-finished cloth, photographed as it arrives off the loom.',
        'bestseller_title'      => 'Most Ordered Cloth',
        'testimonial_title'     => 'What Our Tailors Say',
        'testimonial_sub_title' => 'Feedback from the workshops we supply.',
        'blog_title'            => 'From the Workshop',
        'blog_sub_title'        => 'Notes on dyeing, weaving and caring for natural cloth.',
    ],
    'aleppo-soap' => [
        'logo'                  => 'uploads/p3fixt/logo-aleppo-soap.png',
        'header_logo'           => 'uploads/p3fixt/logo-aleppo-soap.png',
        'footer_logo'           => 'uploads/p3fixt/logo-aleppo-soap.png',
        'category_title'        => 'Soap by Type',
        'category_description'  => 'Laurel and olive oil soap, cured the slow way.',
        'product_title'         => 'Fresh From the Cure',
        'product_description'   => 'Each batch is stamped, stacked and left to harden for months.',
        'bestseller_title'      => 'Best-Loved Bars',
        'testimonial_title'     => 'What Our Customers Say',
        'testimonial_sub_title' => 'Notes from households and hammams.',
        'blog_title'            => 'The Curing Room',
        'blog_sub_title'        => 'How laurel oil, time and air make a bar of soap.',
    ],
    'p3fixt-homs-tech' => [
        'logo'                  => 'uploads/p3fixt/p3fixt-homs-tech/logo.png',
        'header_logo'           => 'uploads/p3fixt/p3fixt-homs-tech/logo.png',
        'footer_logo'           => 'uploads/p3fixt/p3fixt-homs-tech/logo.png',
        'category_title'        => 'Shop by Department',
        'category_description'  => 'Audio, power and mobile accessories, assembled in Homs.',
        'product_title'         => 'New This Week',
        'product_description'   => 'Every unit bench-tested before it leaves the workshop.',
        'bestseller_title'      => 'Best Sellers',
        'testimonial_title'     => 'What Our Customers Say',
        'testimonial_sub_title' => 'Reviews from twelve months of warranty claims and none.',
        'blog_title'            => 'Workbench Notes',
        'blog_sub_title'        => 'Teardowns, repairs and what we would buy again.',
    ],
    'p3fixt-latakia-green' => [
        'logo'                  => 'uploads/p3fixt/p3fixt-latakia-green/logo.png',
        'header_logo'           => 'uploads/p3fixt/p3fixt-latakia-green/logo.png',
        'footer_logo'           => 'uploads/p3fixt/p3fixt-latakia-green/logo.png',
        'category_title'        => 'Browse the Nursery',
        'category_description'  => 'Herbs, pots and seed mixes from the Latakia coast.',
        'product_title'         => 'Potted This Week',
        'product_description'   => 'Grown outdoors and potted the week it ships.',
        'bestseller_title'      => 'Most Planted',
        'testimonial_title'     => 'From Our Growers',
        'testimonial_sub_title' => 'Notes from balconies, terraces and courtyards.',
        'blog_title'            => 'The Growing Year',
        'blog_sub_title'        => 'When to sow, when to water, when to leave it alone.',
    ],
    // The themeless store gets rows too, keyed on its EMPTY theme_id. `getThemeSetting()` looks
    // stored customisations up on the store's OWN theme_id by design, so this proves that path is
    // reachable for a store with no theme -- defaults from the configured theme, overrides from
    // the store's own rows.
    THEMELESS_SLUG => [
        'logo'                  => 'uploads/p3fixt/logo-p3fixt-no-theme.png',
        'header_logo'           => 'uploads/p3fixt/logo-p3fixt-no-theme.png',
        'footer_logo'           => 'uploads/p3fixt/logo-p3fixt-no-theme.png',
        'category_title'        => 'Browse the Shelves',
        'product_title'         => 'In Stock Today',
    ],
];

$written = 0;
$cleared = 0;

foreach ($CUSTOMISE as $slug => $pairs) {
    $target = Store::withoutGlobalScopes()->where('slug', $slug)->first();

    if ($target === null) {
        printf("  theme_customizes: no store with slug %s, skipped\n", $slug);
        continue;
    }

    foreach ($pairs as $name => $value) {
        $existing = ThemeCustomize::withoutGlobalScopes()
            ->where('store_id', $target->id)
            ->where('theme_id', (string) $target->theme_id)
            ->where('name', $name)
            ->first();

        if ($remove) {
            if ($existing) {
                $existing->delete();
                $cleared++;
            }
            continue;
        }

        if ($existing) {
            if ((string) $existing->value !== $value) {
                $existing->value = $value;
                $existing->save();
                $written++;
            }
            continue;
        }

        $row = new ThemeCustomize();
        $row->store_id = $target->id;
        $row->theme_id = (string) $target->theme_id;
        $row->name = $name;
        $row->value = $value;
        $row->save();
        $written++;
    }
}

if ($remove) {
    printf("\n  theme_customizes: %d row(s) removed\n", $cleared);
} else {
    printf("\n  theme_customizes: %d row(s) written, %d total now\n",
        $written, ThemeCustomize::withoutGlobalScopes()->count());

    // A PREMISE CHECK. Rows that exist but are not READ prove nothing, and the read goes through a
    // store_id + theme_id pair that is easy to get wrong.
    foreach ($CUSTOMISE as $slug => $pairs) {
        $target = Store::withoutGlobalScopes()->where('slug', $slug)->first();
        if ($target === null) {
            continue;
        }

        $resolved = getThemeSetting($target->id, (string) $target->theme_id);
        $firstKey = array_key_first($pairs);

        if (($resolved[$firstKey] ?? null) !== $pairs[$firstKey]) {
            fwrite(STDERR, "REFUSING: wrote theme_customizes for {$slug} but getThemeSetting() "
                . "returns " . var_export($resolved[$firstKey] ?? null, true) . " for {$firstKey}, "
                . "not the seeded value. The rows are not being read, so they are not a fixture.\n");
            exit(1);
        }

        printf("  %-20s reads its own %s: %s\n", $slug, $firstKey, $resolved[$firstKey]);
    }
}

/* ------------------------------------------------------------------------------------------- */

if ($problems !== []) {
    fwrite(STDERR, "\nFIXTURE INCOMPLETE:\n  - " . implode("\n  - ", $problems) . "\n");
    exit(1);
}

echo "\nstorefront sign-in fixture ready. The password is " . DEV_PASSWORD . " and it is not a secret.\n";
echo "VERIFY, do not assume: a storefront route that answers 200 may still be the LOGIN page.\n";
