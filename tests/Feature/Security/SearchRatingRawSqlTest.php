<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Api\ApiController;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/**
 * THE STOREFRONT SEARCH FILTER CONCATENATES REQUEST INPUT INTO RAW SQL.
 * =================================================================================================
 *
 * `ApiController::search()` (`app/Http/Controllers/Api/ApiController.php`, the `product_filter`
 * branch):
 *
 *     if (!empty($request->rating)) {
 *         $product_query->whereRaw('ROUND(average_rating) =' . $request->rating);
 *     }
 *
 * `$request->rating` goes into the statement AS TEXT. No binding, no cast, no validation anywhere
 * in the method. Every neighbouring filter in the same branch is safe -- `name` uses a bound
 * `like`, `tag` goes through `whereIn` -- so this is one line out of step with the code around it.
 *
 * HOW IT WAS FOUND, which is the part worth keeping. It was not found by looking at this file. It
 * came out of GAP-253: `RawQueryTenancyInventoryTest` counts `DB::` facade entry points only, so
 * raw SQL written as a builder method is invisible to it -- 113 such sites, measured by
 * `BuilderRawQueryVisibilityTest`. Censusing those 113 for concatenated input returned exactly ONE
 * hit, and this is it. A visibility gap is only interesting because of what sits inside it.
 *
 * REACHABILITY, MEASURED FROM THE ROUTER RATHER THAN ASSUMED. `POST api/{slug}/search` and
 * `api/{slug}/search-guest` both carry `custom.auth` and nothing else -- asserted below. Per
 * CLAUDE.md `custom.auth` resolves ANY Sanctum token and logs its owner into the guard matching the
 * tokenable class, Sanctum tokens in this platform NEVER expire, and every one carries `['*']`. So
 * the caller set is "anyone holding any token of any kind" -- a self-registered customer of any
 * store included. The `-guest` suffix does not make it public and does not make it safer.
 *
 * WHAT THIS ASSERTS, AND WHY IT IS THE SQL AND NOT THE RESULT SET. A result-set assertion depends
 * on how the engine coerces the payload: sqlite and MySQL disagree, and MySQL's own answer depends
 * on `strict` mode, which CLAUDE.md records is OFF in production. The property that holds
 * everywhere is that the attacker's text must never become part of the statement -- it must travel
 * as a BINDING. That is engine-independent, so it is what is asserted, captured with `DB::listen`.
 *
 * IT IS NOT ONLY AN INJECTION -- IT DEFEATS THE TENANT PREDICATE, and that is what makes it a
 * disclosure rather than a curiosity. MEASURED, the statement this endpoint actually emitted:
 *
 *     select * from "products" where "store_id" = ? and ROUND(average_rating) =4 OR 1=1
 *
 * SQL binds AND tighter than OR, so that reads `(store_id = ? AND rating = 4) OR (1=1)` -- true for
 * every row in `products`. The store predicate is still written in the query and is simply bypassed.
 * `Product` is one of the models CLAUDE.md lists as carrying NO effective global scope (it is in
 * `StoreScope`'s registry but NOT in `config/tenancy.php`'s `scoped_models`, so the scope
 * short-circuits for every principal), so nothing else narrows it either. One request returns every
 * product of every store on the platform. `test_the_payload_does_not_return_another_stores_products`
 * drives exactly that.
 *
 * NO PAYLOAD HERE DOES ANYTHING DESTRUCTIVE. `1=1` and a UNION fragment are enough to show the text
 * reaching the statement; proving that a `DROP` would also arrive adds nothing and risks a test
 * that damages its own database.
 */
class SearchRatingRawSqlTest extends SecurityTestCase
{
    use RefreshDatabase;

    private ?string $lastThrow = null;

    /** @return list<array{sql: string, bindings: array}> */
    private function statementsDuring(callable $drive): array
    {
        $seen = [];

        DB::listen(static function ($query) use (&$seen) {
            $seen[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        $drive();

        return $seen;
    }

    private function productIn(int $storeId, int $createdBy, float $rating): int
    {
        $id = (int) DB::table('products')->insertGetId([
            'name' => 'rated-' . $this->uniqueFixtureRef(),
            'slug' => 'rated-' . $this->uniqueFixtureRef(),
            'product_stock' => 5, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10, 'store_id' => $storeId, 'created_by' => $createdBy,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if (\Illuminate\Support\Facades\Schema::hasColumn('products', 'average_rating')) {
            DB::table('products')->where('id', $id)->update(['average_rating' => $rating]);
        }

        return $id;
    }

    /** @return array{0: string, 1: list<array{sql: string, bindings: array}>} */
    private function searchWithRating(string $slug, string $rating): array
    {
        $statements = $this->statementsDuring(function () use ($slug, $rating) {
            try {
                (new ApiController())->search(
                    Request::create('/api/' . $slug . '/search', 'POST', [
                        'type' => 'product_filter',
                        'rating' => $rating,
                    ]),
                    $slug
                );
            } catch (\Throwable $itBlewUp) {
                /* A malformed statement raising is itself evidence the text arrived; the assertion
                   is on the SQL that was attempted, which `DB::listen` has already recorded. The
                   message is kept so a premise failure says why nothing ran. */
                $this->lastThrow = $itBlewUp->getMessage();
            }
        });

        $ratingSql = '';

        foreach ($statements as $statement) {
            if (str_contains(strtolower($statement['sql']), 'round(average_rating)')) {
                $ratingSql = $statement['sql'];
            }
        }

        return [$ratingSql, $statements];
    }

    /**
     * THE PREMISE: this endpoint is reachable, and by a very wide caller set.
     */
    #[Test]
    public function test_premise_the_search_routes_are_reachable_with_any_sanctum_token(): void
    {
        foreach (['search', 'search-guest'] as $suffix) {
            $route = collect(Route::getRoutes()->getRoutes())->first(
                static fn ($r): bool => $r->uri() === 'api/{slug}/' . $suffix
                    && in_array('POST', $r->methods(), true)
            );

            $this->assertNotNull($route, "api/{slug}/{$suffix} is gone; re-point this premise");

            $this->assertContains(
                'custom.auth',
                $route->gatherMiddleware(),
                "api/{slug}/{$suffix} no longer carries custom.auth, so its caller set has changed "
                . 'and this file\'s reachability claim needs re-reading'
            );
        }
    }

    /**
     * THE DEFECT: the filter value must never become part of the statement.
     */
    #[Test]
    public function test_a_rating_filter_never_reaches_the_statement_as_text(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->productIn($store->id, $merchant->id, 4.0);

        $payload = '4 OR 1=1';

        [$ratingSql, $statements] = $this->searchWithRating($store->slug, $payload);

        $this->assertNotSame(
            '',
            $ratingSql,
            'premise: the rating filter produced a statement at all. Statements: '
            . json_encode(array_column($statements, 'sql'))
        );

        $this->assertStringNotContainsString(
            '1=1',
            $ratingSql,
            'The `rating` filter value was concatenated into the SQL text. Any holder of any '
            . 'Sanctum token can therefore write SQL into this statement through '
            . 'POST api/{slug}/search. Statement: ' . $ratingSql
        );

        /* A WORD-BOUNDARY MATCH, BECAUSE `OR` IS A SUBSTRING OF `"store_id"`. A plain
           `assertStringNotContainsString('OR', ...)` here reds against the CORRECT statement --
           measured: `... where "store_id" = ? and ROUND(average_rating) = ?` uppercases to
           `"STORE_ID"`, which contains OR between T and R. Same class of error as a census
           satisfied by prose about the thing it measures, and it cost a red run on a good fix. */
        $this->assertDoesNotMatchRegularExpression(
            '/\bOR\b/i',
            $ratingSql,
            'the statement carries a standalone boolean operator that came from the request. '
            . 'Statement: ' . $ratingSql
        );
    }

    /**
     * AND THE VALUE MUST ARRIVE AS A BINDING -- not merely be absent from the text, which stripping
     * the input would also achieve while silently dropping the filter.
     */
    #[Test]
    public function test_the_rating_travels_as_a_binding(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->productIn($store->id, $merchant->id, 4.0);

        [$ratingSql, $statements] = $this->searchWithRating($store->slug, '4');

        $this->assertStringContainsString(
            '?',
            $ratingSql,
            'the rating comparison has no placeholder, so the value is still being written into the '
            . 'statement. Statement: ' . $ratingSql
        );

        $bound = [];

        foreach ($statements as $statement) {
            if (str_contains(strtolower($statement['sql']), 'round(average_rating)')) {
                $bound = array_map('strval', $statement['bindings']);
            }
        }

        $this->assertContains(
            '4',
            $bound,
            'the rating was not passed as a binding, so the filter may have been dropped rather '
            . 'than parameterised. Bindings: ' . json_encode($bound)
        );
    }

    /**
     * THE CONSEQUENCE: the payload must not return another store's products.
     *
     * This is the assertion that makes the severity concrete. It is engine-independent -- operator
     * precedence is not a dialect feature -- so it holds on sqlite and on MySQL alike.
     */
    #[Test]
    public function test_the_payload_does_not_return_another_stores_products(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->productIn($storeA->id, $merchantA->id, 4.0);
        $foreign = $this->productIn($storeB->id, $merchantB->id, 1.0);

        $this->assertNotSame(
            $storeA->id,
            $storeB->id,
            'premise: two distinct stores, so there is something foreign to leak'
        );

        $returned = [];

        $this->statementsDuring(function () use ($storeA, &$returned) {
            $response = (new ApiController())->search(
                Request::create('/api/' . $storeA->slug . '/search', 'POST', [
                    'type' => 'product_filter',
                    'rating' => '4 OR 1=1',
                ]),
                $storeA->slug
            );

            $payload = json_decode(json_encode($response->getData() ?? []), true);
            array_walk_recursive($payload, static function ($value, $key) use (&$returned) {
                if ($key === 'id') {
                    $returned[] = (int) $value;
                }
            });
        });

        $this->assertNotContains(
            $foreign,
            $returned,
            'A search of store A returned store B\'s product. `OR 1=1` from the request is '
            . 'concatenated into the statement AFTER the `store_id` predicate, and AND binds '
            . 'tighter than OR, so the tenant predicate is bypassed and every product on the '
            . 'platform matches. Returned ids: ' . json_encode($returned)
        );
    }

    /**
     * CONTROL: the filter still filters. A fix that refused or ignored every rating would satisfy
     * the two cases above and remove a working feature.
     */
    #[Test]
    public function test_control_a_legitimate_rating_still_selects_only_matching_products(): void
    {
        [$merchant, $store] = $this->makeTenant();

        if (! \Illuminate\Support\Facades\Schema::hasColumn('products', 'average_rating')) {
            $this->markTestSkipped('products.average_rating is not in this schema');
        }

        $four = $this->productIn($store->id, $merchant->id, 4.0);
        $one = $this->productIn($store->id, $merchant->id, 1.0);

        $ids = Product::withoutGlobalScopes()
            ->where('store_id', $store->id)
            ->whereRaw('ROUND(average_rating) = ?', [4])
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertContains($four, $ids, 'the 4-rated product must match a rating of 4');
        $this->assertNotContains($one, $ids, 'the 1-rated product must not match a rating of 4');
    }
}
