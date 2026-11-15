<?php

namespace Tests\Feature\Theme;

use App\Models\Store;
use App\Models\ThemeCustomizeOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M3-D — SECTION ORDERING RESOLVES PER THEME, WITH A LEGACY FALLBACK.
 * =================================================================================================
 *
 * WHAT WAS WRONG, measured across M3-C and M3-D. Both handlers filtered `theme_customize_orders` on
 * a `theme_id` column that did not exist: `ERROR 1054` and a 500 on MySQL, SILENCE on SQLite, which
 * reinterprets the unresolvable identifier as a string literal. M3-C removed the predicates, which
 * fixed the 500 and left ordering keyed on `(store_id, page_slug)` -- **shared across every theme**.
 * M3-D adds the column and `ThemeCustomizeOrder::resolveFor()`.
 *
 * NULL MEANS "APPLIES TO WHICHEVER THEME HAS NO ROW OF ITS OWN". Legacy rows keep NULL because this
 * table never recorded which theme was active when a merchant chose that order, and
 * `stores.theme_id` only knows which theme is active TODAY -- backfilling from it would invent a
 * fact rather than recover one. The owner's condition was explicit: do not blindly assign legacy
 * rows to arbitrary themes.
 *
 * The directive names the cases: Stylique, Techzonix, Greentic, and the empty-theme fallback.
 */
class ThemeOrderResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        $merchantA = User::forceCreate([
            'name' => 'Order A', 'email' => 'order-a@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $merchantB = User::forceCreate([
            'name' => 'Order B', 'email' => 'order-b@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->storeA = Store::create([
            'name' => 'Order Store A', 'email' => 'os-a@example.test', 'slug' => 'os-a',
            'theme_id' => 'stylique', 'created_by' => $merchantA->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);

        $this->storeB = Store::create([
            'name' => 'Order Store B', 'email' => 'os-b@example.test', 'slug' => 'os-b',
            'theme_id' => 'greentic', 'created_by' => $merchantB->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);
    }

    /* ======================================================= the legacy row is the fallback */

    #[Test]
    public function test_a_legacy_row_answers_for_every_theme_including_the_empty_theme(): void
    {
        ThemeCustomizeOrder::create([
            'store_id' => $this->storeA->id, 'theme_id' => null,
            'page_slug' => 'home', 'orders' => 'LEGACY',
        ]);

        foreach (['stylique', 'techzonix', 'greentic', ''] as $theme) {
            $resolved = ThemeCustomizeOrder::resolveFor($this->storeA->id, $theme, 'home');

            $this->assertNotNull($resolved,
                "No ordering resolved for theme '{$theme}'. A legacy row must answer for a theme "
                . 'that has none of its own, or the column\'s arrival silently discards the order '
                . 'every merchant already chose.');
            $this->assertSame('LEGACY', $resolved->orders);
        }
    }

    /* ============================================= a theme-specific row beats the legacy one */

    #[Test]
    public function test_a_theme_specific_row_wins_and_the_others_still_fall_back(): void
    {
        ThemeCustomizeOrder::create([
            'store_id' => $this->storeA->id, 'theme_id' => null,
            'page_slug' => 'home', 'orders' => 'LEGACY',
        ]);

        ThemeCustomizeOrder::create([
            'store_id' => $this->storeA->id, 'theme_id' => 'techzonix',
            'page_slug' => 'home', 'orders' => 'TECHZONIX-ONLY',
        ]);

        $this->assertSame('TECHZONIX-ONLY',
            ThemeCustomizeOrder::resolveFor($this->storeA->id, 'techzonix', 'home')?->orders,
            'The theme-specific row must win once it exists.');

        foreach (['stylique', 'greentic', ''] as $other) {
            $this->assertSame('LEGACY',
                ThemeCustomizeOrder::resolveFor($this->storeA->id, $other, 'home')?->orders,
                "Theme '{$other}' saw techzonix's ordering. That is the cross-theme contamination "
                . 'M3-D exists to stop.');
        }
    }

    /* ============================================================== no cross-theme contamination */

    #[Test]
    public function test_each_theme_keeps_its_own_ordering(): void
    {
        foreach (['stylique' => 'S-ORDER', 'techzonix' => 'T-ORDER', 'greentic' => 'G-ORDER'] as $theme => $orders) {
            ThemeCustomizeOrder::create([
                'store_id' => $this->storeA->id, 'theme_id' => $theme,
                'page_slug' => 'home', 'orders' => $orders,
            ]);
        }

        $this->assertSame('S-ORDER', ThemeCustomizeOrder::resolveFor($this->storeA->id, 'stylique', 'home')?->orders);
        $this->assertSame('T-ORDER', ThemeCustomizeOrder::resolveFor($this->storeA->id, 'techzonix', 'home')?->orders);
        $this->assertSame('G-ORDER', ThemeCustomizeOrder::resolveFor($this->storeA->id, 'greentic', 'home')?->orders);

        $this->assertNull(ThemeCustomizeOrder::resolveFor($this->storeA->id, 'stylique', 'about'),
            'A different PAGE must not inherit another page\'s ordering.');
    }

    /* ================================================================== the tenant boundary */

    #[Test]
    public function test_one_stores_ordering_is_never_resolved_for_another(): void
    {
        ThemeCustomizeOrder::create([
            'store_id' => $this->storeA->id, 'theme_id' => 'stylique',
            'page_slug' => 'home', 'orders' => 'A-ONLY',
        ]);

        $this->assertNull(ThemeCustomizeOrder::resolveFor($this->storeB->id, 'stylique', 'home'),
            "Store B resolved store A's ordering. (This message used to say the table is NOT in "
            . "config/tenancy.php's scoped_models. It IS -- config/tenancy.php:176 -- and the model "
            . 'carries StoreScope. The assertion is still sound: no principal is authenticated in '
            . "this test, so the scope is unresolved and `null_tenant => 'open'` adds no predicate, "
            . 'which means the explicit store_id filter really is what refuses store B here.)');
    }

    #[Test]
    public function test_a_non_positive_store_id_resolves_nothing_rather_than_the_null_bucket(): void
    {
        ThemeCustomizeOrder::create([
            'store_id' => null, 'theme_id' => null,
            'page_slug' => 'home', 'orders' => 'OWNERLESS',
        ]);

        foreach ([0, -1] as $bad) {
            $this->assertNull(ThemeCustomizeOrder::resolveFor($bad, 'stylique', 'home'),
                "Store id {$bad} resolved an ownerless row. `where store_id = 0` matches nothing, "
                . 'but a null-coerced predicate would match EVERY ownerless row -- the shared '
                . 'bucket this phase refuses everywhere else.');
        }
    }

    /* =========================================================== the legacy row is not rewritten */

    #[Test]
    public function test_resolving_never_writes_anything(): void
    {
        ThemeCustomizeOrder::create([
            'store_id' => $this->storeA->id, 'theme_id' => null,
            'page_slug' => 'home', 'orders' => 'LEGACY',
        ]);

        $before = ThemeCustomizeOrder::withoutGlobalScopes()->get()
            ->map(fn ($r) => [$r->store_id, $r->theme_id, $r->page_slug, $r->orders])->toArray();

        foreach (['stylique', 'techzonix', 'greentic'] as $theme) {
            ThemeCustomizeOrder::resolveFor($this->storeA->id, $theme, 'home');
        }

        $after = ThemeCustomizeOrder::withoutGlobalScopes()->get()
            ->map(fn ($r) => [$r->store_id, $r->theme_id, $r->page_slug, $r->orders])->toArray();

        $this->assertSame($before, $after,
            'Resolving created or promoted a row. A read that writes would relabel the legacy row '
            . 'as belonging to whichever theme happened to be rendered first -- the "blindly assign '
            . 'legacy rows to arbitrary themes" the owner forbade, arriving by the back door.');
    }
}
