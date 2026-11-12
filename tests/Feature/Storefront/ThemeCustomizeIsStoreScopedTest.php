<?php

namespace Tests\Feature\Storefront;

use App\Models\Store;
use App\Models\ThemeCustomize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M0 — a merchant's theme customisation must override the theme default, FOR THAT STORE
 * ONLY.
 *
 * `theme_customizes` is ABSENT from `config/tenancy.php`'s `scoped_models`, so `StoreScope`
 * short-circuits and the model adds NO store predicate for any principal — the same fact behind the
 * `Product` cross-tenant reads of S19. The only thing keeping one merchant's storefront copy off
 * another's page is the explicit `->where('store_id', $storeId)` inside `getThemeSetting()`.
 *
 * That makes this a tenancy pin, not a content test: it is the predicate, not the scope, that has
 * to hold, and M3/M4 will add editor, preview and publish paths that each need their own. This file
 * fails if that predicate is ever dropped from the read.
 *
 * It also records the state gap 5 described. `theme_customizes` had ZERO rows, so BOTH tenants'
 * home pages rendered the theme's own demo copy and were identical above the product grid — which
 * is why a capture of one could not be told from a capture of the other.
 */
class ThemeCustomizeIsStoreScopedTest extends TestCase
{
    use RefreshDatabase;

    private Store $a;
    private Store $b;

    protected function setUp(): void
    {
        parent::setUp();

        $merchant = User::forceCreate([
            'name' => 'Customise Merchant', 'email' => 'customise@example.test',
            'password' => bcrypt('x'), 'type' => 'admin',
            'created_by' => 1, 'email_verified_at' => now(),
        ]);

        foreach (['a' => 'customise-store-a', 'b' => 'customise-store-b'] as $k => $slug) {
            $this->$k = Store::create([
                'name' => 'Customise ' . strtoupper($k), 'email' => "{$slug}@example.test",
                'slug' => $slug, 'theme_id' => 'stylique',
                'created_by' => $merchant->id, 'is_active' => 1, 'default_language' => 'en',
            ]);
        }
    }

    private function customise(Store $store, string $name, string $value): void
    {
        $row = new ThemeCustomize();
        $row->store_id = $store->id;
        $row->theme_id = (string) $store->theme_id;
        $row->name = $name;
        $row->value = $value;
        $row->save();
    }

    /** The premise: `theme_customizes` really is unscoped, so the predicate is doing the work. */
    #[Test]
    public function test_theme_customize_is_not_a_tenancy_scoped_model(): void
    {
        $this->assertNotContains(ThemeCustomize::class, config('tenancy.scoped_models', []),
            'ThemeCustomize is now in scoped_models. That is not wrong, but this file asserts the '
            . 'EXPLICIT store predicate is what isolates the read — re-read it before relying on '
            . 'the scope instead.');
    }

    #[Test]
    public function test_a_stores_own_value_overrides_the_theme_default(): void
    {
        $default = getThemeSetting($this->a->id, 'stylique')['category_title'] ?? null;

        $this->assertSame('Shop by Category', $default,
            'The stylique default changed; this test is comparing against the wrong baseline.');

        $this->customise($this->a, 'category_title', 'Fabrics by the Metre');

        $this->assertSame(
            'Fabrics by the Metre',
            getThemeSetting($this->a->id, 'stylique')['category_title'],
            'A saved customisation did not override the theme default.'
        );
    }

    #[Test]
    public function test_one_stores_customisation_does_not_reach_another_store(): void
    {
        $this->customise($this->a, 'category_title', 'Store A Private Heading');

        $this->assertSame(
            'Shop by Category',
            getThemeSetting($this->b->id, 'stylique')['category_title'],
            'Store B reads store A\'s heading. `theme_customizes` carries no global scope, so the '
            . 'explicit store_id predicate in getThemeSetting() is the only isolation there is.'
        );
    }

    /** The same question through the real rendered page, which is where it would be seen. */
    #[Test]
    public function test_the_rendered_storefront_shows_only_its_own_copy(): void
    {
        $this->customise($this->a, 'category_title', 'Store A Private Heading');
        $this->customise($this->b, 'category_title', 'Store B Own Heading');

        $pageA = $this->get("/{$this->a->slug}/home");
        $pageB = $this->get("/{$this->b->slug}/home");

        $pageA->assertOk();
        $pageB->assertOk();

        $pageA->assertSee('Store A Private Heading', false);
        $pageA->assertDontSee('Store B Own Heading', false);

        $pageB->assertSee('Store B Own Heading', false);
        $pageB->assertDontSee('Store A Private Heading', false);
    }

    /**
     * GAP 5, recorded as a measurement rather than prose: with no rows the two storefronts are the
     * same document, and with rows they are not. A capture of one could not be distinguished from a
     * capture of the other, which is precisely what the capture-integrity gates exist to catch.
     */
    #[Test]
    public function test_without_customisations_two_tenants_render_the_same_copy(): void
    {
        $this->assertSame(
            getThemeSetting($this->a->id, 'stylique')['category_title'],
            getThemeSetting($this->b->id, 'stylique')['category_title'],
            'Two uncustomised stores already differ, so gap 5 is not what the baseline recorded.'
        );

        $this->customise($this->a, 'category_title', 'Now Different');

        $this->assertNotSame(
            getThemeSetting($this->a->id, 'stylique')['category_title'],
            getThemeSetting($this->b->id, 'stylique')['category_title'],
            'A customisation did not make the two tenants distinguishable.'
        );
    }

    /** A themeless store's rows are keyed on '' and must still be read. */
    #[Test]
    public function test_a_themeless_store_can_still_carry_its_own_customisations(): void
    {
        $themeless = Store::forceCreate([
            'name' => 'Customise Themeless', 'email' => 'customise-none@example.test',
            'slug' => 'customise-themeless', 'theme_id' => '',
            'created_by' => $this->a->created_by, 'is_active' => 1, 'default_language' => 'en',
        ]);

        $this->customise($themeless, 'category_title', 'Browse the Shelves');

        $resolved = getThemeSetting($themeless->id, '');

        $this->assertSame('Browse the Shelves', $resolved['category_title'],
            'A themeless store cannot read its own customisations. getThemeSetting() looks stored '
            . "rows up on the store's OWN theme_id, so an empty one must still match.");

        // ...and the rest still comes from the configured theme's defaults.
        $this->assertArrayHasKey('product_title', $resolved);
        $this->assertSame('Featured Products', $resolved['product_title']);
    }
}
