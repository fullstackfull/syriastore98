<?php

namespace Tests\Feature\Security;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * P3-SEC — `POST api/admin/updateStoreOwnerInfo` VALIDATED ONE VALUE AND STORED ANOTHER.
 *
 * `Api\DashboardController:8860` asserted uniqueness on the RAW input:
 *
 *     'store_slug' => 'nullable|string|max:255|unique:stores,slug,' . $store->id,
 *
 * and `:8902` stored a NORMALISED one:
 *
 *     $store->slug = \Illuminate\Support\Str::slug($validated['store_slug']);
 *
 * So the uniqueness check guarded a string that never reaches the column.
 *
 * MEASURED: `Str::slug('My Shop')`, `Str::slug('my-shop')` and `Str::slug('MY SHOP')` are all
 * `'my-shop'`. A merchant who owns no slug at all posts `store_slug = "My Shop"`; `unique` compares
 * `"My Shop"` against the column, finds nothing, passes; `my-shop` is written beside a victim's
 * identical `my-shop`.
 *
 * EVERY STOREFRONT RESOLVER IS `where('slug', …)->first()` — `helper.php:3578` (`getStore`), `:3627`
 * (`getRouteStoreId`), `ActiveTheme:21`, and four raw lookups in `ViewServiceProvider` that choose
 * the THEME VIEW ROOT. Lowest id wins. One of the two merchants' public URLs therefore serves the
 * other's catalogue, categories, theme and settings. If the newcomer holds the lower id it is the
 * victim's shoppers who are carried onto someone else's shop, which is a brand- and
 * payment-confusion problem rather than a self-inflicted one.
 *
 * IT IS NOT A TENANCY DEFECT AND THAT IS WORTH STATING so nobody re-litigates the handler. The
 * caller is an ordinary entitled merchant acting on their OWN store: `resolveOwnedStore()`,
 * `mayChangeOwnerIdentity()` and the `CURRENCY_NAME` limb all pass legitimately. Nothing in the
 * victim's row is written and no private data is read — a storefront is public. The whole defect is
 * the validate-one-value-write-another gap.
 *
 * `2023_12_14_090259_create_stores_table.php:19` is a bare `$table->string('slug')` with NO unique
 * index, so the database does not backstop it, and duplicates may already exist in production —
 * which is why a unique index cannot simply be added. That is recorded, not done here.
 *
 * Raised by the `tenant-authz-reviewer` subagent while confirming the Arabic-slug escalation.
 */
class AdminApiStoreSlugUniquenessTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array{0: Store, 1: Store, 2: string} victim store, attacker store, attacker token */
    private function twoStores(): array
    {
        $this->makePlatformAdmin();

        [$victim, $victimStore] = $this->makeTenant();
        [$newcomer, $newcomerStore] = $this->makeTenant();

        // The victim holds a normal, already-normalised slug.
        $victimStore->slug = 'my-shop';
        $victimStore->save();

        return [
            $victimStore->fresh(),
            $newcomerStore->fresh(),
            $newcomer->createToken('auth_token')->plainTextToken,
        ];
    }

    private function callEndpoint(string $token, Store $store, array $payload)
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/updateStoreOwnerInfo?store_id=' . $store->id, $payload);
    }

    /** The premise. Two different raw strings must normalise to the same slug, or there is no case. */
    #[Test]
    public function test_the_premise_two_raw_strings_normalise_to_one_slug(): void
    {
        foreach (['My Shop', 'MY SHOP', 'my shop', 'my-shop'] as $raw) {
            $this->assertSame('my-shop', \Illuminate\Support\Str::slug($raw),
                "Str::slug({$raw}) is no longer 'my-shop', so this test is about something else.");
        }
    }

    #[Test]
    public function test_a_differently_cased_slug_cannot_collide_with_another_stores(): void
    {
        [$victimStore, $newcomerStore, $token] = $this->twoStores();

        $this->callEndpoint($token, $newcomerStore, ['store_slug' => 'My Shop']);

        $holders = Store::withoutGlobalScopes()->where('slug', 'my-shop')->pluck('id')->all();

        $this->assertSame([$victimStore->id], $holders,
            'Two stores now hold the slug "my-shop". Every storefront resolver is '
            . "where('slug', …)->first(), so one merchant's public URL serves the other's shop.");
    }

    /** The same question through the storefront, which is where it would be seen. */
    #[Test]
    public function test_the_victims_url_still_serves_the_victims_store(): void
    {
        [$victimStore, $newcomerStore, $token] = $this->twoStores();

        $this->callEndpoint($token, $newcomerStore, ['store_slug' => 'My Shop']);

        $holders = Store::withoutGlobalScopes()->where('slug', 'my-shop')->get();

        // The COUNT is asserted first, deliberately. Asserting only that `->first()` returns the
        // victim passes before the fix as well, because the victim holds the lower id -- a test
        // that would have reported green on the defect.
        $this->assertCount(1, $holders,
            'More than one store holds "my-shop", so which one a URL resolves to is decided by '
            . 'insertion order.');
        $this->assertSame($victimStore->id, (int) $holders->first()->id,
            "getStore('my-shop') resolves to a store that is not the one that owns the slug.");
    }

    /**
     * THE POSITIVE CONTROL. The endpoint must still let a merchant set a slug that is genuinely
     * free, or "no collision" would be satisfied by a fix that refuses everything.
     */
    #[Test]
    public function test_a_merchant_can_still_set_a_free_slug_and_it_is_normalised(): void
    {
        [, $newcomerStore, $token] = $this->twoStores();

        $response = $this->callEndpoint($token, $newcomerStore, ['store_slug' => 'Totally Free Name']);

        $response->assertStatus(200);

        $this->assertSame('totally-free-name',
            (string) Store::withoutGlobalScopes()->find($newcomerStore->id)->slug,
            'A free slug was refused or stored un-normalised.');
    }

    /** And re-posting one's own slug must stay allowed — a prefilled form does exactly that. */
    #[Test]
    public function test_a_merchant_can_resubmit_their_own_slug(): void
    {
        [, $newcomerStore, $token] = $this->twoStores();

        $this->callEndpoint($token, $newcomerStore, ['store_slug' => 'Keeper Shop'])->assertStatus(200);
        $this->callEndpoint($token, $newcomerStore, ['store_slug' => 'Keeper Shop'])->assertStatus(200);

        $this->assertSame('keeper-shop',
            (string) Store::withoutGlobalScopes()->find($newcomerStore->id)->slug);
    }

    /**
     * A SOURCE PIN. The uniqueness rule must be applied to the value that is STORED. A later edit
     * that reintroduces raw validation would pass every case above only until someone re-posts a
     * colliding case variant, so the ordering is asserted directly.
     */
    #[Test]
    public function test_the_slug_is_normalised_before_the_uniqueness_rule_runs(): void
    {
        $source = file_get_contents(
            base_path('app/Http/Controllers/Api/DashboardController.php'));

        $body = self::methodBody($source, 'updateStoreOwnerInfo');
        $code = preg_replace('{//[^\n]*}', '', $body);

        $normaliseAt = strpos($code, "Str::slug");
        $validateAt = strpos($code, "unique:stores,slug");

        $this->assertNotFalse($normaliseAt, 'updateStoreOwnerInfo no longer normalises the slug.');
        $this->assertNotFalse($validateAt, 'updateStoreOwnerInfo no longer checks slug uniqueness.');

        $this->assertLessThan($validateAt, $normaliseAt,
            'The uniqueness rule runs BEFORE the slug is normalised, so it guards a string that '
            . 'never reaches the column. That is the defect this file exists for.');
    }

    /** Bounded by brace depth: an unbounded regex across a method boundary makes a pin vacuous. */
    private static function methodBody(string $source, string $method): string
    {
        $start = strpos($source, "function {$method}(");
        self::assertNotFalse($start, "{$method}() is not in that file.");

        $open = strpos($source, '{', $start);
        $depth = 0;

        for ($i = $open, $n = strlen($source); $i < $n; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }

        self::fail("{$method}() has unbalanced braces.");
    }
}
