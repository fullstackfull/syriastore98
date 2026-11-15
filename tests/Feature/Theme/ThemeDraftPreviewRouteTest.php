<?php

namespace Tests\Feature\Theme;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\ThemeCustomize;
use App\Models\User;
use App\Services\Theme\ThemeConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M4-5 — THE DRAFT PREVIEW ROUTE: it renders the draft, and it reaches nobody else.
 * =================================================================================================
 *
 * The first authenticated route in this codebase that renders a STOREFRONT view, which is why the
 * isolation is asserted in the same test as the success rather than in a sibling file: the question
 * is not "does the preview show the draft" but "does the preview show the draft WHILE the public
 * storefront still shows the published settings".
 *
 * THE VALUES ARE SENTINELS NO THEME DEFAULT CARRIES. `getThemeSetting()` merges ~520 defaults under
 * the stored rows, so a value that happened to match a default would make both assertions pass
 * whatever the route did.
 */
class ThemeDraftPreviewRouteTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLISHED = 'M4-5-ROUTE-PUBLISHED-a71f';
    private const DRAFTED   = 'M4-5-ROUTE-DRAFTED-c93b';

    private User $owner;
    private User $staff;
    private User $otherMerchant;
    private Store $store;
    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::forceCreate([
            'name' => 'Pv Owner', 'email' => 'pvr-owner@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);
        $this->store = Store::create([
            'name' => 'Pv Shop', 'email' => 'pvr-s@example.test', 'slug' => 'pvr-shop',
            'theme_id' => 'stylique', 'created_by' => $this->owner->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->owner->current_store = $this->store->id;
        $this->owner->save();

        $this->staff = User::forceCreate([
            'name' => 'Pv Staff', 'email' => 'pvr-staff@example.test', 'password' => bcrypt('x'),
            'type' => 'staff', 'created_by' => $this->owner->id, 'email_verified_at' => now(),
        ]);
        $this->staff->current_store = $this->store->id;
        $this->staff->save();

        $this->otherMerchant = User::forceCreate([
            'name' => 'Pv Other', 'email' => 'pvr-other@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);
        $this->otherStore = Store::create([
            'name' => 'Pv Other Shop', 'email' => 'pvr-os@example.test', 'slug' => 'pvr-other-shop',
            'theme_id' => 'stylique', 'created_by' => $this->otherMerchant->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->otherMerchant->current_store = $this->otherStore->id;
        $this->otherMerchant->save();

        ThemeCustomize::create([
            'store_id' => $this->store->id, 'theme_id' => 'stylique',
            'name' => 'footer_description', 'value' => self::PUBLISHED,
        ]);
    }

    private function draft(?User $as = null): \App\Models\ThemeVersion
    {
        $d = (new ThemeConfigurationService())->saveDraft(
            $this->store->id, 'stylique',
            ['footer_description' => self::DRAFTED], 'preview draft', $as ?? $this->owner);

        $this->assertNotNull($d, 'PREMISE: the draft was refused.');

        return $d;
    }

    /* ============================================ the preview shows the draft, the shop does not */

    /**
     * THE TEST THE WHOLE SLICE EXISTS FOR, and both halves are in ONE method on purpose: a leaking
     * override would be invisible to two tests that each issue a single request.
     */
    #[Test]
    public function test_the_preview_shows_the_draft_while_the_public_shop_still_shows_published(): void
    {
        $draft = $this->draft();

        $preview = $this->actingAs($this->owner)->get("/theme-draft/{$this->store->slug}/{$draft->id}/preview");
        $preview->assertOk();
        $previewBody = (string) $preview->getContent();

        $this->assertStringContainsString(self::DRAFTED, $previewBody,
            'The preview did not render the draft value. The seam lives inside getThemeSetting() '
            . 'because the view composer recomputes $themeSettings and overwrites controller data.');

        $this->assertStringNotContainsString(self::PUBLISHED, $previewBody,
            'The preview rendered the PUBLISHED value as well as, or instead of, the draft.');

        /* The public shop, in the SAME test, immediately after. */
        $public = $this->get('/' . $this->store->slug . '/home');
        $publicBody = (string) $public->getContent();

        $this->assertStringContainsString(self::PUBLISHED, $publicBody,
            'The public storefront stopped showing its published configuration.');

        $this->assertStringNotContainsString(self::DRAFTED, $publicBody,
            'THE DRAFT LEAKED TO THE PUBLIC STOREFRONT. This is the one failure this slice exists '
            . 'to prevent, which is why the context is bound `scoped` and keyed on (store, theme).');
    }

    /** Previewing writes nothing: the published projection is byte-identical afterwards. */
    #[Test]
    public function test_previewing_does_not_change_published_data(): void
    {
        $draft = $this->draft();
        $before = ThemeCustomize::withoutGlobalScopes()->count();

        $this->actingAs($this->owner)->get("/theme-draft/{$this->store->slug}/{$draft->id}/preview")->assertOk();

        $this->assertSame($before, ThemeCustomize::withoutGlobalScopes()->count());
        $this->assertSame(self::PUBLISHED, ThemeCustomize::withoutGlobalScopes()
            ->where('store_id', $this->store->id)->where('name', 'footer_description')->value('value'),
            'Previewing modified the published projection.');
    }

    /** Unpublished content must never be cached by anything between the server and the browser. */
    #[Test]
    public function test_the_preview_response_is_not_cacheable(): void
    {
        $draft = $this->draft();

        $response = $this->actingAs($this->owner)->get("/theme-draft/{$this->store->slug}/{$draft->id}/preview");

        $this->assertStringContainsString('no-store',
            (string) $response->headers->get('Cache-Control'),
            'The preview is cacheable. A shared cache holding it would serve one tenant\'s '
            . 'unpublished draft to the next requester.');

        $this->assertStringContainsString('noindex',
            (string) $response->headers->get('X-Robots-Tag'),
            'An unpublished draft is indexable.');
    }

    /* ============================================================== who may not reach a draft */

    #[Test]
    public function test_an_anonymous_visitor_cannot_reach_a_draft(): void
    {
        $draft = $this->draft();

        $response = $this->get("/theme-draft/{$this->store->slug}/{$draft->id}/preview");

        $this->assertTrue($response->isRedirect(), 'An anonymous visitor was served a draft.');
        $this->assertStringNotContainsString(self::DRAFTED, (string) $response->getContent());
    }

    /**
     * A CUSTOMER SESSION IS NOT A MERCHANT SESSION, and this codebase records that confusing the
     * two is its most productive defect shape: `auth('customers')` and `auth()` are different
     * guards, and a handler that checks the wrong one is reachable by the wrong principal.
     */
    #[Test]
    public function test_a_customer_session_cannot_reach_a_merchant_draft(): void
    {
        $draft = $this->draft();

        $customer = Customer::create([
            'first_name' => 'Shop', 'last_name' => 'Per', 'email' => 'pvr-cust@example.test',
            'password' => bcrypt('x'), 'store_id' => $this->store->id,
        ]);

        $response = $this->actingAs($customer, 'customers')->get("/theme-draft/{$this->store->slug}/{$draft->id}/preview");

        $this->assertStringNotContainsString(self::DRAFTED, (string) $response->getContent(),
            'A signed-in CUSTOMER of this very store was served the merchant\'s unpublished draft.');
    }

    #[Test]
    public function test_another_tenants_merchant_cannot_preview_this_draft(): void
    {
        $draft = $this->draft();

        $response = $this->actingAs($this->otherMerchant)->get("/theme-draft/{$this->store->slug}/{$draft->id}/preview");

        $this->assertStringNotContainsString(self::DRAFTED, (string) $response->getContent(),
            'Another tenant previewed this store\'s unpublished draft by naming its version id.');
    }

    /**
     * STAFF MAY PREVIEW. The owner's decision requires tenant-authorised staff to prepare and
     * preview drafts without being able to publish; this is the half that proves delegation still
     * works, and without it every refusal above is satisfied by a total lockout.
     */
    #[Test]
    public function test_zero_permission_staff_of_the_tenant_may_preview(): void
    {
        $draft = $this->draft();

        $response = $this->actingAs($this->staff)->get("/theme-draft/{$this->store->slug}/{$draft->id}/preview");

        $response->assertOk();
        $this->assertStringContainsString(self::DRAFTED, (string) $response->getContent(),
            'Tenant staff could not preview a draft, so draft delegation does not work.');
    }

    /**
     * THE URL'S SLUG IS CALLER-SUPPLIED, AND MUST AGREE WITH THE GATE — a gap a removal check
     * found, not a test written from the design.
     *
     * The route carries `{storeSlug}` only so `ViewServiceProvider`'s composer can resolve `$store`
     * without `abort(404)`ing mid-render. That makes it an identifier the caller chooses, and this
     * codebase records four separate defects from treating caller-supplied input as a tenant
     * predicate. Removing the binding left every other test in this file passing, because none of
     * them pointed the preview at a store other than their own.
     *
     * What it would allow is not a disclosure of private data — another store's `/{slug}/home` is
     * public — but it is caller-controlled store selection on an authenticated route, and the
     * merchant would be shown a shop that is not theirs while believing it was their draft.
     */
    #[Test]
    public function test_the_preview_refuses_a_slug_that_is_not_the_authorised_store(): void
    {
        $draft = $this->draft();

        $response = $this->actingAs($this->owner)
            ->get("/theme-draft/{$this->otherStore->slug}/{$draft->id}/preview");

        $this->assertSame(404, $response->status(),
            'A merchant pointed the preview at ANOTHER store\'s slug and was served a page. The '
            . 'gate resolves which store may be previewed; the URL must agree with it, never '
            . 'determine it.');

        $this->assertStringNotContainsString(self::DRAFTED, (string) $response->getContent());
    }

    /* ================================================================== the draft does not leak */

    /**
     * A SECOND REQUEST IN THE SAME TEST, anonymous, immediately after an armed preview. The context
     * is bound `scoped` rather than `singleton` precisely so it cannot survive a request boundary
     * in a worker that keeps the application alive.
     */
    #[Test]
    public function test_a_draft_does_not_survive_into_a_later_anonymous_request(): void
    {
        $draft = $this->draft();

        $this->actingAs($this->owner)->get("/theme-draft/{$this->store->slug}/{$draft->id}/preview")->assertOk();

        $later = $this->get('/' . $this->store->slug . '/home');

        $this->assertStringNotContainsString(self::DRAFTED, (string) $later->getContent(),
            'The draft survived into a later anonymous request.');
        $this->assertStringContainsString(self::PUBLISHED, (string) $later->getContent());
    }

    #[Test]
    public function test_an_unknown_version_is_not_found(): void
    {
        $this->actingAs($this->owner)->get("/theme-draft/{$this->store->slug}/999999/preview")->assertNotFound();
    }
}
