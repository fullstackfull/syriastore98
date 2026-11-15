<?php

namespace Tests\Feature\Platform;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M5/M6 — `platform` MAY NOT BE A STORE'S SLUG, BY ANY OF THE THREE WRITERS.
 * =================================================================================================
 *
 * WHY IT MATTERS. `routes/web.php` declares the platform's own pages under `/platform/...` before it
 * requires `routes/customer.php`, so they win every collision with `{storeSlug?}/...`. The mirror
 * case is the dangerous one: a store slugged `platform` would be shadowed on the seven paths the
 * platform owns and would STILL ANSWER on the ones it does not. `routes/customer.php` declares
 * `{storeSlug?}/login` and `{storeSlug?}/register`, so that merchant's customer sign-in and
 * registration forms would be served at `/platform/login` and `/platform/register` — URLs a
 * visitor reads as the platform's own. Brand impersonation, reachable by an ordinary merchant.
 *
 * WHY THIS FILE EXISTS RATHER THAN ONE ASSERTION IN THE ROUTES SUITE. The first version of the fix
 * guarded `User::slugs()` and a comment there called it "the ONE place store slugs are minted".
 * An independent review measured two more writers, both reachable by a merchant on their own
 * store, neither of which calls that method:
 *
 *   `AppSettingController::ThemeSettings()`           `$store->slug = $request->store_slug;`
 *   `Api\DashboardController` store-settings update   `$store->slug = Str::slug($validated[...]);`
 *
 * So this suite drives ALL THREE. A fix that covers one writer and leaves the other two is the
 * exact failure that review found, and a test that only checked the mint path would have called it
 * fixed.
 *
 * THE MINT PATH SUFFIXES; THE RENAME PATHS REFUSE. Deliberate, and asserted as such: nobody is
 * watching a registration form derive a slug, so failing it there would block a sign-up for no
 * reason, while a merchant typing a name into a settings screen should be told what happened.
 */
class ReservedStoreSlugTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::forceCreate([
            'name' => 'Slug Owner', 'email' => 'slug-owner@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->store = Store::create([
            'name' => 'Slug Shop', 'email' => 'slug-shop@example.test', 'slug' => 'slug-shop',
            'theme_id' => 'stylique', 'created_by' => $this->owner->id, 'is_active' => 1,
        ]);

        $this->owner->current_store = $this->store->id;
        $this->owner->save();
    }

    #[Test]
    public function the_reserved_list_is_not_empty(): void
    {
        /* PREMISE. Every assertion below is vacuous against an empty list. */
        $this->assertNotEmpty(Store::RESERVED_SLUGS);
        $this->assertContains('platform', Store::RESERVED_SLUGS);
    }

    #[Test]
    public function the_comparison_ignores_case_and_surrounding_space(): void
    {
        $this->assertTrue(Store::slugIsReserved('platform'));
        $this->assertTrue(Store::slugIsReserved('  Platform '));
        $this->assertTrue(Store::slugIsReserved('PLATFORM'));

        $this->assertFalse(Store::slugIsReserved('platform-1'));
        $this->assertFalse(Store::slugIsReserved('platforms'));
        $this->assertFalse(Store::slugIsReserved('my-platform'));
        $this->assertFalse(Store::slugIsReserved(null));
        $this->assertFalse(Store::slugIsReserved(''));
    }

    // --- writer 1: the mint path ----------------------------------------------------------------

    #[Test]
    public function the_mint_path_suffixes_rather_than_refusing(): void
    {
        $this->assertSame('platform-1', User::slugs('Platform'));
        /* Not broken for ordinary names. */
        $this->assertSame('slug-shop-1', User::slugs('Slug Shop'));
        $this->assertSame('abu-samra-sweets', User::slugs('Abu Samra Sweets'));
    }

    // --- writer 2: the merchant's own App Setting screen -----------------------------------------

    #[Test]
    public function the_theme_settings_rename_refuses_a_reserved_slug(): void
    {
        $response = $this->actingAs($this->owner)
            ->post(route('theme.settings'), [
                'store_slug'      => 'platform',
                'app_setting_tab' => 'store',
                'theme_name'      => 'Slug Shop',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        /* THE ASSERTION THAT MATTERS: the row is unchanged. A refusal that still wrote would show
           the same redirect and the same toast. Read with `withoutGlobalScopes()` because a plain
           find answers against whichever tenant resolved, which is how a test passes for the wrong
           reason in this codebase. */
        $this->assertSame(
            'slug-shop',
            Store::withoutGlobalScopes()->find($this->store->id)->slug
        );
    }

    // --- writer 3: the shipped Flutter admin app ------------------------------------------------

    #[Test]
    public function the_flutter_store_settings_endpoint_refuses_a_reserved_slug(): void
    {
        $token = $this->owner->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/updateStoreOwnerInfo', [
                'store_id'   => $this->store->id,
                'store_slug' => 'Platform',
            ]);

        /* 422 is the shape this endpoint already produces for a slug it will not take (the
           `unique` rule beside it), so the shipped app handles it. Asserted explicitly because a
           NEW status code on a path a client calls would be a contract change -- and because a
           401 here would force the app to log the merchant out. */
        $response->assertStatus(422);

        $this->assertSame(
            'slug-shop',
            Store::withoutGlobalScopes()->find($this->store->id)->slug
        );
    }

    #[Test]
    public function the_flutter_store_settings_endpoint_still_accepts_an_ordinary_slug(): void
    {
        /* The control: without it, an endpoint broken for every slug would look like the refusal
           above working. It also pins that `Str::slug()` still normalises, since the posted value
           carries a capital and a space. */
        $token = $this->owner->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/updateStoreOwnerInfo', [
                'store_id'   => $this->store->id,
                'store_slug' => 'Slug Shop Renamed',
            ]);

        $this->assertSame(
            'slug-shop-renamed',
            Store::withoutGlobalScopes()->find($this->store->id)->slug
        );
    }

    #[Test]
    public function the_theme_settings_rename_still_accepts_an_ordinary_slug(): void
    {
        /* The control. Without it, a refusal caused by the form being broken for every value would
           look exactly like the test above passing. */
        $this->actingAs($this->owner)
            ->post(route('theme.settings'), [
                'store_slug'      => 'slug-shop-renamed',
                'app_setting_tab' => 'store',
                'theme_name'      => 'Slug Shop',
            ]);

        $this->assertSame(
            'slug-shop-renamed',
            Store::withoutGlobalScopes()->find($this->store->id)->slug
        );
    }
}
