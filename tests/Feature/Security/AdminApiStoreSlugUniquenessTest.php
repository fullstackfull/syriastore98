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

    /* =================================================================================
     * The two things the review asked me to settle by reading my own diff
     * ================================================================================= */

    /**
     * A LEGACY SLUG THAT NORMALISES TO EMPTY MUST NOT BE BLANKED by a prefilled-form resubmit.
     *
     * `Str::slug()` trims its separator, so `Str::slug('-') === ''` and
     * `Str::slug('-damascus') === 'damascus'` -- measured. The `-` rows that `User::slugs()`
     * currently produces for every Arabic-only store name are therefore NOT fixed points of the
     * writer, and the gate above it is `if (!empty($validated['store_slug']))`.
     *
     * Which way that falls depends on where the normalisation lands, and the review was right that
     * I should settle it by measurement rather than by reading my own fix:
     *
     *   - normalisation in `$validated` (what `$request->merge()` before `validate()` does):
     *     `$validated['store_slug']` is '', the gate SKIPS, the row is left alone.
     *   - normalisation only in the uniqueness rule: the gate sees '-', passes, and '' is written --
     *     blanking an Arabic merchant's storefront URL because they saved an unrelated field.
     *
     * This asserts the first. It is inherited behaviour rather than something the fix states, which
     * is exactly why it needs an assertion: nothing else in the suite names it, and a later
     * refactor that validated a copy instead of the request would flip it silently.
     */
    #[Test]
    public function test_a_legacy_hyphen_slug_is_left_alone_rather_than_blanked(): void
    {
        [, $newcomerStore, $token] = $this->twoStores();

        // The state `User::slugs()` produces for every Arabic-only store name today.
        $newcomerStore->slug = '-';
        $newcomerStore->save();

        $this->assertSame('', \Illuminate\Support\Str::slug('-'),
            "Str::slug('-') is no longer '', so this test is about something else.");

        // A prefilled form resubmits the stored slug alongside an unrelated change.
        $response = $this->callEndpoint($token, $newcomerStore, [
            'store_slug' => '-',
            'store_name' => 'Renamed By Owner',
        ]);

        $response->assertStatus(200);

        $after = Store::withoutGlobalScopes()->find($newcomerStore->id);

        $this->assertSame('-', (string) $after->slug,
            'A prefilled resubmit BLANKED the slug. An empty slug is unreachable by path (Laravel '
            . 'filters zero-length route parameter matches) and 403s on a custom domain, so this '
            . "would take an Arabic merchant's storefront offline for saving an unrelated field.");

        // ...and the unrelated field still saved, so this is not "the handler refused everything".
        $this->assertSame('Renamed By Owner', (string) $after->name,
            'The rest of the update was dropped, so the slug surviving proves nothing.');
    }

    /**
     * THE 101st-COLLISION NULL AND THE ARABIC `-` COLLAPSE ARE NOT TESTED HERE, DELIBERATELY.
     *
     * The review raised both, and both turned out to be ALREADY MEASURED AND ALREADY PINNED:
     * `tests/Feature/Platform/StoreSlugIdentityTest.php` holds them as audit sections 3.1
     * (an Arabic name collapses to `-`, so Arabic stores contend for one slug) and 3.2 (the
     * generator returns null after 100 collisions). That file asserts them AS DEFECTS on purpose,
     * and says so: "when the audit's stage 4 fixes land, these tests FAIL and must be rewritten to
     * the corrected expectation -- which is the intended signal, not an accident."
     *
     * I added a terminal `return` to `User::slugs()` before finding that, and it broke the 3.2 pin.
     * It is reverted. Stage 4 is gated by the owner directive of 2026-10-22, which requires the URL
     * format and migration strategy to be documented before this is touched, and a second
     * assertion of the same defect from a different file would only create a contradiction.
     *
     * What belongs here is the API validator, which those pins do not cover.
     */

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
