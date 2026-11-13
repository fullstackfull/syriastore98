<?php

namespace Tests\Feature\Theme;

use App\Models\Store;
use App\Models\ThemeCustomize;
use App\Models\ThemePublicationEvent;
use App\Models\ThemePublishedVersion;
use App\Models\ThemeVersion;
use App\Models\User;
use App\Services\Theme\ThemeConfigurationService;
use App\Support\Theme\ThemePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M3-C — THE ONE CONTROLLED WRITE PATH, AND WHAT IT MUST NEVER DO.
 *
 * FOUR THINGS ARE BEING PINNED HERE, and each one is a measured defect rather than a hypothesis.
 *
 *   1. A DRAFT MUST NOT REACH THE STOREFRONT. `getThemeSetting()` plucks every row for a
 *      `(store_id, theme_id)` pair with NO published filter, and 75 anonymous storefront routes
 *      reach it. The design keeps drafts in `theme_versions`, which no storefront path reads, so
 *      this is structural -- but "structural" is a claim, and this is the test of it.
 *   2. A WRITE MUST REACH THE STOREFRONT. The Flutter social-links save did not: a raw
 *      `DB::table()->updateOrInsert()` does not invalidate the `Cachable` model, so the app showed
 *      success while the footer kept the old links. That is the regression this service exists to
 *      prevent, so a cache assertion is not optional decoration.
 *   3. THE PROJECTION STAYS KEY-COMPLETE. `getThemeSetting()` merges ~520 defaults UNDER the stored
 *      rows and storefront views index them with no guard, so an absent key is a 500 -- the M0
 *      defect. A write is an UPSERT of named keys, never a wholesale replacement.
 *   4. NOTHING CROSSES A TENANT. The schema's composite key stops cross-tenant PUBLICATION; it does
 *      nothing about a read, a write or a delete named by bare id. Those are the service's job.
 */
class ThemeConfigurationServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;
    private User $merchantA;
    private User $merchantB;
    private Store $storeA;
    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::forceCreate([
            'name' => 'Svc Operator', 'email' => 'svc-op@example.test', 'password' => bcrypt('x'),
            'type' => 'super admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);
        $this->merchantA = User::forceCreate([
            'name' => 'Svc A', 'email' => 'svc-a@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);
        $this->merchantB = User::forceCreate([
            'name' => 'Svc B', 'email' => 'svc-b@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->storeA = Store::create([
            'name' => 'Svc A', 'email' => 'svc-store-a@example.test', 'slug' => 'svc-store-a',
            'theme_id' => 'stylique', 'created_by' => $this->merchantA->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->storeB = Store::create([
            'name' => 'Svc B', 'email' => 'svc-store-b@example.test', 'slug' => 'svc-store-b',
            'theme_id' => 'greentic', 'created_by' => $this->merchantB->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);
    }

    /**
     * MEASURED, and it is why the cross-theme cases below do not all use the same field: the three
     * themes do NOT share one taxonomy. 267 fields are common to all three, but `blog_title` is
     * declared by **stylique only** -- greentic and techzonix do not have it. So a store that
     * switches theme has settings that are no longer editable under the new one, and a test that
     * reuses one theme's field name against another silently gets a refusal rather than a write.
     */
    private function service(): ThemeConfigurationService
    {
        return new ThemeConfigurationService();
    }

    /** The projection exactly as the storefront reads it. */
    private function projection(Store $store): array
    {
        return ThemeCustomize::withoutGlobalScopes()
            ->where('store_id', $store->id)
            ->where('theme_id', $store->theme_id)
            ->pluck('value', 'name')
            ->toArray();
    }

    /* =========================================================== 1. a draft never goes public */

    #[Test]
    public function test_saving_a_draft_does_not_touch_the_published_projection(): void
    {
        $before = $this->projection($this->storeA);

        $draft = $this->service()->saveDraft(
            $this->storeA->id, 'stylique',
            ['blog_title' => 'A DRAFT TITLE', 'footer_description' => 'draft footer'],
            'my draft', $this->merchantA);

        $this->assertNotNull($draft, 'The draft was refused for an owning merchant.');
        $this->assertSame(ThemeVersion::STATUS_DRAFT, $draft->status);

        $this->assertSame($before, $this->projection($this->storeA),
            'Saving a draft changed the PUBLISHED projection. getThemeSetting() reads that table '
            . 'with no published filter from 75 anonymous routes, so a draft row there is public '
            . 'the instant it lands.');

        $merged = getThemeSetting($this->storeA->id, 'stylique');
        $this->assertNotSame('A DRAFT TITLE', $merged['blog_title'] ?? null,
            'A draft value reached the storefront read.');
    }

    #[Test]
    public function test_a_draft_payload_round_trips_and_its_hash_is_intact(): void
    {
        $settings = [
            'footer_description' => 'Fresh produce — سوق دمشق',
            'footer_repeater'    => '[{"link":"https:\/\/x.com\/shop","icon":"fab fa-x-twitter"}]',
            'footer_status'      => '1',
        ];

        $draft = $this->service()->saveDraft($this->storeA->id, 'stylique', $settings, null, $this->merchantA);

        $this->assertNotNull($draft);
        $this->assertTrue($draft->hashIsIntact(), 'payload_hash does not match the stored payload.');
        $this->assertSame(ThemePayload::canonicalise($settings), $draft->settings(),
            'The payload did not round trip. A repeater must survive byte-exactly.');
        $this->assertSame(ThemePayload::hash($settings), $draft->payload_hash);
    }

    /* ================================================ 2. a write DOES reach the storefront */

    /**
     * THE MEASURED FLUTTER DEFECT, as a contrast: the OLD raw write is invisible to the storefront,
     * the NEW service write is not.
     *
     * WHAT THIS DOES *NOT* PROVE, stated because an earlier version of this test implied it did.
     * Deleting the service's post-commit `flushCache()` left this assertion GREEN, because
     * `ThemeCustomize::updateOrCreate()` is Eloquent and the trait flushes as part of its own save
     * lifecycle. So this measures Eloquent's flush, not the service's. The post-commit flush exists
     * for the CONCURRENT-READER race -- the lifecycle flush happens INSIDE the transaction, and
     * another process can re-cache pre-commit state between that flush and the commit. A
     * single-process test cannot observe two connections racing, so that specific guarantee is NOT
     * claimed here -- it is proved separately, in both directions, by
     * `tests/Support/Security/bin/verify-theme-cache-flush-after-commit-on-mysql.php`, which runs
     * two real processes against MySQL with a shared model cache.
     *
     * What this test does prove is the regression that actually shipped: a raw write does not
     * invalidate the cache and the storefront keeps the old value, while the service's write does.
     */
    #[Test]
    public function test_a_raw_write_is_invisible_to_the_storefront_and_the_service_write_is_not(): void
    {
        /* Warm the Cachable read so a stale entry exists to be served. */
        getThemeSetting($this->storeA->id, 'stylique');

        /* THE OLD PATH, exactly as Api/DashboardController:9032 did it. */
        \Illuminate\Support\Facades\DB::table('theme_customizes')->updateOrInsert(
            ['store_id' => $this->storeA->id, 'theme_id' => 'stylique', 'name' => 'blog_title'],
            ['value' => 'RAW WRITE']);

        $this->assertNotSame('RAW WRITE',
            getThemeSetting($this->storeA->id, 'stylique')['blog_title'] ?? null,
            'PREMISE: a raw write is supposed to be INVISIBLE to the Cachable storefront read. If '
            . 'this now passes through, the cache is not active in this run and the contrast below '
            . 'proves nothing.');

        /* THE NEW PATH. */
        $written = $this->service()->writeSettings(
            $this->storeA->id, 'stylique', ['blog_title' => 'SERVICE WRITE'], $this->merchantA);

        $this->assertSame(['blog_title' => 'SERVICE WRITE'], $written);
        $this->assertSame('SERVICE WRITE',
            getThemeSetting($this->storeA->id, 'stylique')['blog_title'] ?? null,
            'The storefront read did not see the service write. This is the whole point of routing '
            . 'the Flutter endpoint through it.');
    }

    /* ================================================= 3. the projection stays key-complete */

    #[Test]
    public function test_a_write_upserts_named_keys_and_destroys_nothing_else(): void
    {
        $this->service()->writeSettings($this->storeA->id, 'stylique',
            ['blog_title' => 'one', 'footer_description' => 'two', 'footer_status' => '1'],
            $this->merchantA);

        $this->service()->writeSettings($this->storeA->id, 'stylique',
            ['blog_title' => 'one changed'], $this->merchantA);

        $projection = $this->projection($this->storeA);

        $this->assertSame('one changed', $projection['blog_title'] ?? null);
        $this->assertSame('two', $projection['footer_description'] ?? null,
            'A second write destroyed a key it did not name. The projection holds the merchant\'s '
            . 'OVERRIDES and getThemeSetting() merges ~520 defaults under it; a wholesale '
            . 'replacement is how a sparse projection becomes a 500.');
        $this->assertSame('1', $projection['footer_status'] ?? null);
    }

    /**
     * RESIDUAL RISK 4. `footer_repeater` is written by the Flutter app, independently of anything
     * the merchant form touches. A later write from the web form must not remove it.
     */
    #[Test]
    public function test_a_web_write_preserves_the_flutter_written_footer_repeater(): void
    {
        $social = '[{"link":"https://x.com/shop","icon":"fab fa-x-twitter","platform":"twitter"}]';

        $this->service()->writeSettings($this->storeA->id, 'stylique',
            ['footer_repeater' => $social], $this->merchantA);

        $this->service()->writeSettings($this->storeA->id, 'stylique',
            ['blog_title' => 'unrelated edit'], $this->merchantA);

        $this->assertSame($social, $this->projection($this->storeA)['footer_repeater'] ?? null,
            "An unrelated web save removed the Flutter app's social links.");
    }

    /* ============================================================ 4. nothing crosses a tenant */

    #[Test]
    public function test_a_merchant_cannot_write_to_another_tenants_store(): void
    {
        $result = $this->service()->writeSettings(
            $this->storeB->id, 'greentic', ['footer_description' => 'INTRUSION'], $this->merchantA);

        $this->assertNull($result, "Merchant A wrote into merchant B's projection.");
        $this->assertSame([], $this->projection($this->storeB));
    }

    #[Test]
    public function test_a_merchant_cannot_save_a_draft_for_another_tenants_store(): void
    {
        $this->assertNull($this->service()->saveDraft(
            $this->storeB->id, 'greentic', ['footer_description' => 'x'], null, $this->merchantA));

        $this->assertSame(0, ThemeVersion::query()->where('store_id', $this->storeB->id)->count());
    }

    #[Test]
    public function test_a_merchant_cannot_delete_another_tenants_draft(): void
    {
        $draft = $this->service()->saveDraft(
            $this->storeB->id, 'greentic', ['footer_description' => 'B draft'], null, $this->merchantB);
        $this->assertNotNull($draft);

        $deleted = $this->service()->deleteVersion(
            $this->storeB->id, 'greentic', $draft->id, $this->merchantA);

        $this->assertFalse($deleted, "Merchant A deleted merchant B's draft.");
        $this->assertNotNull(ThemeVersion::find($draft->id), 'The draft was removed anyway.');
    }

    /**
     * RESIDUAL RISK 2, at the model level. A bare `find()` is what crosses tenants; `ownedBy()` is
     * the triple that does not.
     */
    #[Test]
    public function test_owned_by_refuses_a_version_belonging_to_another_store_or_theme(): void
    {
        $draft = $this->service()->saveDraft(
            $this->storeB->id, 'greentic', ['footer_description' => 'B'], null, $this->merchantB);

        $this->assertNotNull(ThemeVersion::find($draft->id),
            'Premise: a bare find() DOES return it, which is exactly why ownedBy() exists.');

        $this->assertNull(ThemeVersion::ownedBy($this->storeA->id, 'greentic', $draft->id),
            "ownedBy() returned another store's version.");
        $this->assertNull(ThemeVersion::ownedBy($this->storeB->id, 'stylique', $draft->id),
            "ownedBy() ignored the theme and returned a version of a different theme.");
        $this->assertNotNull(ThemeVersion::ownedBy($this->storeB->id, 'greentic', $draft->id));

        foreach ([0, -1] as $bad) {
            $this->assertNull(ThemeVersion::ownedBy($bad, 'greentic', $draft->id),
                'A non-positive store id must fail closed: where(store_id, null) compiles to '
                . 'store_id IS NULL, which is one shared bucket.');
        }
    }

    /* ========================================================== theme identity is validated */

    #[Test]
    public function test_a_theme_the_installation_does_not_ship_is_refused(): void
    {
        $service = $this->service();

        $this->assertNull($service->writeSettings(
            $this->storeA->id, 'not-a-real-theme', ['blog_title' => 'x'], $this->merchantA));
        $this->assertStringContainsString('not a theme this installation ships',
            (string) $service->refusal());
    }

    #[Test]
    public function test_a_store_with_no_theme_and_no_theme_named_is_refused(): void
    {
        $themeless = Store::create([
            'name' => 'Svc themeless', 'email' => 'svc-none@example.test', 'slug' => 'svc-none',
            'theme_id' => '', 'created_by' => $this->merchantA->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);

        $service = $this->service();

        $this->assertNull($service->writeSettings(
            $themeless->id, '', ['blog_title' => 'x'], $this->merchantA));
        $this->assertStringContainsString('no theme selected', (string) $service->refusal());
    }

    /* =========================================================== version numbering is per pair */

    #[Test]
    public function test_version_numbers_are_sequential_per_store_and_theme(): void
    {
        $a1 = $this->service()->saveDraft($this->storeA->id, 'stylique', ['blog_title' => '1'], null, $this->merchantA);
        $a2 = $this->service()->saveDraft($this->storeA->id, 'stylique', ['blog_title' => '2'], null, $this->merchantA);
        $b1 = $this->service()->saveDraft($this->storeB->id, 'greentic', ['footer_description' => '1'], null, $this->merchantB);

        $this->assertSame(1, $a1->version_no);
        $this->assertSame(2, $a2->version_no);
        $this->assertSame(1, $b1->version_no,
            "A second store's first version should be 1, not 3: numbering is per (store, theme).");

        /* The same store, a different theme, restarts too. */
        $aOther = $this->service()->saveDraft(
            $this->storeA->id, 'techzonix', ['footer_description' => '1'], null, $this->merchantA);
        $this->assertSame(1, $aOther->version_no);
    }

    /* ===================================================== a live version cannot be deleted */

    #[Test]
    public function test_a_published_version_is_refused_for_deletion(): void
    {
        $draft = $this->service()->saveDraft($this->storeA->id, 'stylique', ['blog_title' => 'live'], null, $this->merchantA);

        ThemePublishedVersion::create([
            'store_id' => $this->storeA->id, 'theme_id' => 'stylique',
            'version_id' => $draft->id, 'published_at' => now(),
        ]);

        $service = $this->service();
        $this->assertFalse($service->deleteVersion(
            $this->storeA->id, 'stylique', $draft->id, $this->merchantA));
        $this->assertStringContainsString('currently published', (string) $service->refusal());

        $this->assertNotNull(ThemeVersion::find($draft->id));
    }

    #[Test]
    public function test_a_draft_that_is_not_live_can_be_deleted_by_its_owner(): void
    {
        $draft = $this->service()->saveDraft($this->storeA->id, 'stylique', ['blog_title' => 'x'], null, $this->merchantA);

        $this->assertTrue($this->service()->deleteVersion(
            $this->storeA->id, 'stylique', $draft->id, $this->merchantA));
        $this->assertNull(ThemeVersion::find($draft->id));
    }

    /* ======================================== RESIDUAL RISK 6: events dereference by the triple */

    #[Test]
    public function test_a_publication_event_resolves_its_version_with_its_own_store_and_theme(): void
    {
        $bDraft = $this->service()->saveDraft(
            $this->storeB->id, 'greentic', ['footer_description' => 'B'], null, $this->merchantB);

        /* An event belonging to store A that names store B's version id -- the shape that would
         * cross tenants if it were dereferenced by bare id. The table has NO foreign keys by
         * design, so nothing in the engine refuses this row. */
        $event = ThemePublicationEvent::create([
            'store_id' => $this->storeA->id, 'theme_id' => 'stylique',
            'version_id' => $bDraft->id, 'action' => ThemePublicationEvent::ACTION_PUBLISH,
            'payload_hash' => str_repeat('a', 64),
        ]);

        $this->assertNull($event->resolveVersion(),
            "An event resolved a version belonging to another store. The event carries its own "
            . 'store_id and theme_id precisely so the dereference can be scoped; a belongsTo '
            . 'relation would compile to `where id = ?` and cross tenants silently.');
    }

    /* ====================================================== legacy storefront read compatibility */

    #[Test]
    public function test_get_theme_setting_still_returns_the_full_merged_array(): void
    {
        $this->service()->writeSettings($this->storeA->id, 'stylique',
            ['blog_title' => 'Overridden'], $this->merchantA);

        $merged = getThemeSetting($this->storeA->id, 'stylique');

        $this->assertGreaterThan(400, count($merged),
            'getThemeSetting() returned only ' . count($merged) . ' keys. Storefront views index '
            . '~520 of them with no guard, and an absent key is an ErrorException that Laravel '
            . 'turns into a 500 -- the M0 defect. The contract is the FULL merged array.');
        $this->assertSame('Overridden', $merged['blog_title'],
            'The override did not win over the theme default.');
        $this->assertArrayHasKey('category_title', $merged,
            'A key the merchant never touched disappeared from the merge.');
    }

    /* ============================================================= the operator is not store 1 */

    #[Test]
    public function test_an_operator_cannot_write_without_naming_a_store(): void
    {
        $service = $this->service();
        $this->operator->current_store = null;

        $this->assertNull($service->writeSettings(null, 'stylique', ['blog_title' => 'x'], $this->operator),
            'An operator wrote with no store named. getCurrentStore() would hand them store 1, '
            . "which belongs to a merchant.");
    }

    #[Test]
    public function test_an_operator_who_names_a_store_may_write_to_it(): void
    {
        $written = $this->service()->writeSettings(
            $this->storeA->id, 'stylique', ['blog_title' => 'operator edit'], $this->operator);

        $this->assertSame(['blog_title' => 'operator edit'], $written,
            'The operator must be able to act on a NAMED store -- isAbleTo() alone refuses them.');
    }

    /* ======================================================== rejected fields are reported, not silent */

    #[Test]
    public function test_rejected_fields_are_named_and_the_rest_is_still_written(): void
    {
        $service = $this->service();

        $written = $service->writeSettings($this->storeA->id, 'stylique', [
            'blog_title'         => 'kept',
            'footer_description' => '<script>x()</script>',
            'not_a_field'        => 'nope',
        ], $this->merchantA);

        $this->assertSame(['blog_title' => 'kept'], $written);
        $this->assertSame(['footer_description', 'not_a_field'], array_keys($service->rejected()));
        $this->assertArrayNotHasKey('footer_description', $this->projection($this->storeA),
            'A refused value was written anyway.');
    }
}
