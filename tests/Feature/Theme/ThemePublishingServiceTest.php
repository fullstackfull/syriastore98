<?php

namespace Tests\Feature\Theme;

use App\Models\Store;
use App\Models\ThemeCustomize;
use App\Models\ThemePublicationEvent;
use App\Models\ThemePublishedVersion;
use App\Models\ThemeVersion;
use App\Models\User;
use App\Services\Theme\ThemeConfigurationService;
use App\Services\Theme\ThemePublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M3-D — PUBLISH, ROLLBACK, PREVIEW, CONFLICT DETECTION, AND THE TENANT BOUNDARY.
 * =================================================================================================
 *
 * The concurrency half of M3-D is NOT here and cannot be: two transactions racing needs two
 * connections, and `RefreshDatabase` holds one open per test. It is measured by
 * `tests/Support/Security/bin/verify-theme-publish-concurrency-on-mysql.php`. What this file covers
 * is everything a single process CAN decide: the semantics, the refusals, and the tenant boundary.
 *
 * WHY THE NEGATIVE CASES OUTNUMBER THE POSITIVE ONES. None of the four tables is in
 * `config/tenancy.php`'s `scoped_models`, so no global scope filters any of them and every
 * predicate in the service is the only thing standing between two merchants' storefronts. The
 * directive names the cases to drive, and each is below.
 */
class ThemePublishingServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;
    private User $merchantA;
    private User $merchantB;
    private User $staffA;
    private Store $storeA;
    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::forceCreate([
            'name' => 'Pub Operator', 'email' => 'pub-op@example.test', 'password' => bcrypt('x'),
            'type' => 'super admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->merchantA = User::forceCreate([
            'name' => 'Pub A', 'email' => 'pub-a@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->merchantB = User::forceCreate([
            'name' => 'Pub B', 'email' => 'pub-b@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->storeA = Store::create([
            'name' => 'Pub Store A', 'email' => 'pub-sa@example.test', 'slug' => 'pub-sa',
            'theme_id' => 'stylique', 'created_by' => $this->merchantA->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);

        $this->storeB = Store::create([
            'name' => 'Pub Store B', 'email' => 'pub-sb@example.test', 'slug' => 'pub-sb',
            'theme_id' => 'greentic', 'created_by' => $this->merchantB->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);

        $this->merchantA->current_store = $this->storeA->id;
        $this->merchantA->save();

        $this->staffA = User::forceCreate([
            'name' => 'Pub Staff A', 'email' => 'pub-staff-a@example.test', 'password' => bcrypt('x'),
            'type' => 'staff', 'created_by' => $this->merchantA->id, 'email_verified_at' => now(),
        ]);
    }

    private function service(): ThemePublishingService
    {
        return new ThemePublishingService();
    }

    private function drafts(): ThemeConfigurationService
    {
        return new ThemeConfigurationService();
    }

    /** A draft for store A / stylique, through the real controlled write path. */
    private function draftA(array $settings, ?string $label = null): ThemeVersion
    {
        $version = $this->drafts()->saveDraft(
            $this->storeA->id, 'stylique', $settings, $label, $this->merchantA);

        $this->assertNotNull($version,
            'PREMISE: the draft could not be saved, so nothing below is about publishing. '
            . 'Reason: ' . var_export($this->drafts()->refusal(), true));

        return $version;
    }

    private function projection(Store $store, string $theme = 'stylique'): array
    {
        return ThemeCustomize::withoutGlobalScopes()
            ->where('store_id', $store->id)
            ->where('theme_id', $theme)
            ->pluck('value', 'name')
            ->all();
    }

    /* ============================================================== 1. the happy path */

    #[Test]
    public function test_publishing_a_draft_makes_it_live_and_writes_the_projection(): void
    {
        $version = $this->draftA(['footer_description' => 'Published copy']);

        $result = $this->service()->publish(
            $this->storeA->id, 'stylique', $version->id, $this->merchantA);

        $this->assertNotNull($result, 'Publish refused: ' . var_export($this->service()->refusal(), true));

        $this->assertSame('Published copy', $this->projection($this->storeA)['footer_description'] ?? null,
            'The published projection does not carry the version\'s value, so the storefront would '
            . 'not render it.');

        $live = ThemePublishedVersion::liveFor($this->storeA->id, 'stylique');

        $this->assertNotNull($live, 'No live pointer was written.');
        $this->assertSame($version->id, (int) $live->version_id);

        $this->assertSame(ThemeVersion::STATUS_PUBLISHED, $version->fresh()->status);
        $this->assertNotNull($version->fresh()->published_at);

        $this->assertSame(ThemePublicationEvent::ACTION_PUBLISH, $result['event']->action);
        $this->assertNull($result['event']->from_version_id,
            'The first publish has nothing to come FROM, so from_version_id must be NULL rather '
            . 'than 0 -- 0 is not a version id and would dangle.');
    }

    #[Test]
    public function test_the_storefront_read_sees_a_published_version(): void
    {
        $version = $this->draftA(['footer_description' => 'Visible on the storefront']);

        $this->service()->publish($this->storeA->id, 'stylique', $version->id, $this->merchantA);

        $this->assertSame('Visible on the storefront',
            getThemeSetting($this->storeA->id, 'stylique')['footer_description'] ?? null,
            'The storefront read did not see the publish. `theme_customizes` is Cachable, so the '
            . 'post-commit flush is what makes this true.');
    }

    /* ================================= 2. RULE 1: a publish deletes nothing it does not name */

    #[Test]
    public function test_publishing_preserves_a_key_the_snapshot_does_not_mention(): void
    {
        /* `footer_repeater` is written by the Flutter app, independently of the editor. */
        $social = '[{"link":"https://x.com/shop","icon":"fab fa-x-twitter","platform":"twitter"}]';

        ThemeCustomize::create([
            'store_id' => $this->storeA->id, 'theme_id' => 'stylique',
            'name' => 'footer_repeater', 'value' => $social,
        ]);

        $version = $this->draftA(['footer_description' => 'Editor copy only']);

        $result = $this->service()->publish(
            $this->storeA->id, 'stylique', $version->id, $this->merchantA);

        $this->assertNotNull($result);

        $this->assertSame($social, $this->projection($this->storeA)['footer_repeater'] ?? null,
            "Publishing an editor snapshot destroyed the Flutter app's social links. The owner's "
            . 'requirement is explicit: retain independently managed settings. A publish must UPSERT '
            . 'the names it carries and delete nothing.');

        $this->assertContains('footer_repeater', $result['keysNotInSnapshot'],
            'The key that survived must be REPORTED as not-in-snapshot, so a caller wanting a true '
            . 'revert knows it exists rather than discovering it later.');
    }

    /* ======================================================= 3. RULE 2: all or nothing */

    #[Test]
    public function test_one_unacceptable_value_refuses_the_whole_snapshot(): void
    {
        $version = $this->draftA(['footer_description' => 'fine']);

        /* Corrupt one value in place, then re-hash so the payload passes the integrity check and
         * the VALIDATOR is what refuses -- otherwise this would test the hash, not the rule. */
        $payload = json_encode([
            'footer_description' => 'fine',
            'blog_title' => '<script>steal()</script>',
        ]);

        DB::table('theme_versions')->where('id', $version->id)->update([
            'payload' => $payload,
            'payload_hash' => hash('sha256', $payload),
        ]);
        /* NO flushCache() here, and the reason is deliberate design: `ThemeVersion` is NOT
         * `Cachable` (its docblock says so, and the tenancy census records it), so a raw write to
         * it is immediately visible to Eloquent. A first version of this test called flushCache()
         * and died with BadMethodCallException -- the test assuming a hazard the model does not
         * have. */

        $service = $this->service();
        $result = $service->publish($this->storeA->id, 'stylique', $version->id, $this->merchantA);

        $this->assertNull($result, 'A snapshot with an unacceptable value was published.');
        $this->assertArrayHasKey('blog_title', $service->rejected());

        $this->assertSame([], $this->projection($this->storeA),
            'The VALID half of a refused snapshot was written. That is exactly the "partially '
            . 'published configuration" the directive forbids.');

        $this->assertNull(ThemePublishedVersion::liveFor($this->storeA->id, 'stylique'),
            'A refused publish moved the live pointer.');
        $this->assertSame([], ThemePublicationEvent::historyFor($this->storeA->id, 'stylique')->get()->all(),
            'A refused publish wrote a history event.');
    }

    #[Test]
    public function test_a_payload_whose_hash_no_longer_matches_is_refused(): void
    {
        $version = $this->draftA(['footer_description' => 'original']);

        /* A raw UPDATE of the payload WITHOUT re-hashing: what an import, a restore or a manual
         * edit looks like. */
        DB::table('theme_versions')->where('id', $version->id)
            ->update(['payload' => json_encode(['footer_description' => 'tampered'])]);

        $service = $this->service();

        $this->assertNull(
            $service->publish($this->storeA->id, 'stylique', $version->id, $this->merchantA),
            'A tampered payload was published. The version number would then vouch for content '
            . 'nothing verified.');
        $this->assertStringContainsString('hash', (string) $service->refusal());

        $this->assertSame([], $this->projection($this->storeA));
    }

    /* ============================================= 4. RULE 3: conflict detection */

    #[Test]
    public function test_a_stale_expectation_is_refused_rather_than_overwriting(): void
    {
        $first = $this->draftA(['footer_description' => 'first']);
        $second = $this->draftA(['footer_description' => 'second']);

        $this->service()->publish($this->storeA->id, 'stylique', $first->id, $this->merchantA);

        $service = $this->service();

        /* A caller that still believes NOTHING is published -- a form rendered before the first
         * publish, submitted after it. */
        $this->assertNull(
            $service->publish($this->storeA->id, 'stylique', $second->id, $this->merchantA, 0),
            'A publisher who believed nothing was live overwrote a version it never saw.');
        $this->assertStringContainsString('changed underneath', (string) $service->refusal());

        $this->assertSame('first', $this->projection($this->storeA)['footer_description'] ?? null,
            'The refused publish still changed the projection.');

        /* And with the CORRECT expectation it goes through. */
        $this->assertNotNull(
            $this->service()->publish($this->storeA->id, 'stylique', $second->id, $this->merchantA, $first->id),
            'A publisher with an accurate expectation was refused, which would make the control '
            . 'unusable rather than safe.');
        $this->assertSame('second', $this->projection($this->storeA)['footer_description'] ?? null);
    }

    #[Test]
    public function test_no_expectation_means_no_check(): void
    {
        $first = $this->draftA(['footer_description' => 'first']);
        $second = $this->draftA(['footer_description' => 'second']);

        $this->service()->publish($this->storeA->id, 'stylique', $first->id, $this->merchantA);

        $this->assertNotNull(
            $this->service()->publish($this->storeA->id, 'stylique', $second->id, $this->merchantA),
            'null must mean "do not check" -- it is the right default for a first publish and the '
            . 'wrong one for a UI showing a version id, which is why both forms exist.');
    }

    /* ================================================ 5. RULE 4: rollback appends to history */

    #[Test]
    public function test_a_rollback_appends_an_event_and_rewrites_nothing(): void
    {
        $v1 = $this->draftA(['footer_description' => 'version one']);
        $v2 = $this->draftA(['footer_description' => 'version two']);

        $this->service()->publish($this->storeA->id, 'stylique', $v1->id, $this->merchantA);
        $this->service()->publish($this->storeA->id, 'stylique', $v2->id, $this->merchantA, $v1->id);

        $historyBefore = ThemePublicationEvent::historyFor($this->storeA->id, 'stylique')->get();

        $this->assertCount(2, $historyBefore);

        $result = $this->service()->rollback(
            $this->storeA->id, 'stylique', $v1->id, $this->merchantA, $v2->id);

        $this->assertNotNull($result, 'Rollback refused: ' . var_export($this->service()->refusal(), true));

        $this->assertSame('version one', $this->projection($this->storeA)['footer_description'] ?? null,
            'The rollback did not restore the earlier value.');

        $history = ThemePublicationEvent::historyFor($this->storeA->id, 'stylique')->get();

        $this->assertCount(3, $history,
            'A rollback must APPEND an event. Rewriting or removing one would make the history a '
            . 'record of the current state rather than of what happened.');

        $newest = $history->first();

        $this->assertSame(ThemePublicationEvent::ACTION_ROLLBACK, $newest->action);
        $this->assertSame($v1->id, (int) $newest->version_id, 'The event must reference the RESTORED version.');
        $this->assertSame($v2->id, (int) $newest->from_version_id, 'and the one it came from.');

        /* The two earlier events are byte-identical to before. */
        $this->assertSame(
            $historyBefore->map(fn ($e) => [$e->id, $e->action, $e->version_id, $e->from_version_id])->all(),
            $history->slice(1)->values()->map(fn ($e) => [$e->id, $e->action, $e->version_id, $e->from_version_id])->all(),
            'An earlier event changed. History is append-only.');
    }

    #[Test]
    public function test_the_superseded_version_is_archived_and_never_deleted(): void
    {
        $v1 = $this->draftA(['footer_description' => 'one']);
        $v2 = $this->draftA(['footer_description' => 'two']);

        $this->service()->publish($this->storeA->id, 'stylique', $v1->id, $this->merchantA);
        $this->service()->publish($this->storeA->id, 'stylique', $v2->id, $this->merchantA, $v1->id);

        $this->assertNotNull(ThemeVersion::ownedBy($this->storeA->id, 'stylique', $v1->id),
            'The superseded version was DELETED. Preserving historical versions is a requirement, '
            . 'and the publish pointer\'s RESTRICT only protects the one that is live.');

        $this->assertSame(ThemeVersion::STATUS_ARCHIVED,
            ThemeVersion::ownedBy($this->storeA->id, 'stylique', $v1->id)?->status);
        $this->assertSame(ThemeVersion::STATUS_PUBLISHED,
            ThemeVersion::ownedBy($this->storeA->id, 'stylique', $v2->id)?->status);
    }

    /* ============================================================ 6. preview cannot leak */

    #[Test]
    public function test_a_preview_is_key_complete_and_writes_nothing(): void
    {
        $version = $this->draftA(['footer_description' => 'draft only, never published']);

        $before = $this->projection($this->storeA);

        $preview = $this->service()->preview(
            $this->storeA->id, 'stylique', $version->id, $this->merchantA);

        $this->assertIsArray($preview, 'Preview refused: ' . var_export($this->service()->refusal(), true));

        $this->assertSame('draft only, never published', $preview['footer_description'] ?? null);

        $this->assertGreaterThan(100, count($preview),
            'The preview is not key-complete. ~520 keys per theme are indexed directly by storefront '
            . 'views with no guard, and an absent key is a 500 -- the M0 defect. A preview must merge '
            . "the theme's defaults under the overrides, exactly as getThemeSetting() does.");

        $this->assertSame($before, $this->projection($this->storeA),
            'Previewing wrote to the published projection.');
    }

    #[Test]
    public function test_an_unpublished_draft_is_invisible_to_the_storefront_read(): void
    {
        $version = $this->draftA(['footer_description' => 'SECRET DRAFT']);

        $this->service()->preview($this->storeA->id, 'stylique', $version->id, $this->merchantA);

        $this->assertNotSame('SECRET DRAFT',
            getThemeSetting($this->storeA->id, 'stylique')['footer_description'] ?? null,
            'An unpublished draft reached the storefront read. Drafts live only in `theme_versions`, '
            . 'which no storefront path reads -- previewing one must not change that.');

        $this->assertSame([], $this->projection($this->storeA),
            'Previewing a draft wrote it into the published projection.');
    }

    /* ======================================================= 7. THE TENANT BOUNDARY */

    #[Test]
    public function test_a_merchant_cannot_publish_another_tenants_version(): void
    {
        $versionA = $this->draftA(['footer_description' => 'A content']);

        $service = $this->service();

        $this->assertNull(
            $service->publish($this->storeA->id, 'stylique', $versionA->id, $this->merchantB),
            "Merchant B published merchant A's version.");

        $this->assertNull(ThemePublishedVersion::liveFor($this->storeA->id, 'stylique'));
        $this->assertSame([], $this->projection($this->storeA));
    }

    #[Test]
    public function test_a_version_of_another_store_cannot_be_published_into_this_one(): void
    {
        $versionA = $this->draftA(['footer_description' => 'A content']);

        /* Merchant B, acting on their OWN store, naming store A's version id. */
        $service = $this->service();

        $this->assertNull(
            $service->publish($this->storeB->id, 'greentic', $versionA->id, $this->merchantB),
            "Store A's version was published as store B's live configuration. `ownedBy()` resolves "
            . 'on the (store, theme, id) TRIPLE precisely so a bare id cannot cross a tenant.');
        $this->assertStringContainsString('no such version', (string) $service->refusal());
    }

    #[Test]
    public function test_a_merchant_cannot_roll_back_another_tenants_theme(): void
    {
        $v1 = $this->draftA(['footer_description' => 'one']);
        $v2 = $this->draftA(['footer_description' => 'two']);

        $this->service()->publish($this->storeA->id, 'stylique', $v1->id, $this->merchantA);
        $this->service()->publish($this->storeA->id, 'stylique', $v2->id, $this->merchantA, $v1->id);

        $this->assertNull(
            $this->service()->rollback($this->storeA->id, 'stylique', $v1->id, $this->merchantB),
            "Merchant B rolled back merchant A's storefront.");

        $this->assertSame('two', $this->projection($this->storeA)['footer_description'] ?? null);
    }

    #[Test]
    public function test_a_merchant_cannot_preview_another_tenants_draft(): void
    {
        $versionA = $this->draftA(['footer_description' => 'A unpublished content']);

        $this->assertNull(
            $this->service()->preview($this->storeA->id, 'stylique', $versionA->id, $this->merchantB),
            "Merchant B previewed merchant A's unpublished draft. It changes nothing, but it is a "
            . 'disclosure of content the other merchant has not published.');
    }

    #[Test]
    public function test_an_operator_without_an_explicit_store_is_refused(): void
    {
        $versionA = $this->draftA(['footer_description' => 'A content']);

        $service = $this->service();

        $this->assertNull(
            $service->publish(null, 'stylique', $versionA->id, $this->operator),
            'An operator publishing with no store named was allowed. getCurrentStore() hands them '
            . 'the literal store 1, which belongs to a MERCHANT -- a support action would re-point '
            . "a tenant's live storefront while appearing to act on the operator's own.");
        $this->assertStringContainsString('never infer', (string) $service->refusal());

        $this->assertNull(
            $this->service()->rollback(null, 'stylique', $versionA->id, $this->operator));
        $this->assertNull(
            $this->service()->preview(null, 'stylique', $versionA->id, $this->operator));
    }

    #[Test]
    public function test_an_operator_who_names_a_store_may_publish_for_it(): void
    {
        $versionA = $this->draftA(['footer_description' => 'operator published this']);

        $this->assertNotNull(
            $this->service()->publish($this->storeA->id, 'stylique', $versionA->id, $this->operator),
            'The operator must be able to act on a NAMED store -- isAbleTo() alone refuses them, '
            . 'which is why the gate tests the operator first.');
    }

    #[Test]
    public function test_a_demoted_merchant_cannot_publish(): void
    {
        $versionA = $this->draftA(['footer_description' => 'A content']);

        /* The supported product action: create a role outside RESERVED_NAMES and assign it to
         * yourself, which drops creatorId() to created_by = 1. */
        $this->merchantA->type = 'staff';
        $this->merchantA->save();

        $this->assertNull(
            $this->service()->publish(
                $this->storeA->id, 'stylique', $versionA->id, $this->merchantA->fresh()),
            'A merchant who demoted their own type could still publish. `creatorId()` drops to 1 '
            . 'for a non-admin row, and the installer creates the OPERATOR\'S store with '
            . 'created_by = 1, so a root of 1 must not be treated as a tenant.');
    }

    #[Test]
    public function test_an_unauthenticated_caller_is_refused(): void
    {
        $versionA = $this->draftA(['footer_description' => 'A content']);

        $this->assertNull($this->service()->publish($this->storeA->id, 'stylique', $versionA->id, null));
        $this->assertNull($this->service()->preview($this->storeA->id, 'stylique', $versionA->id, null));
    }

    #[Test]
    public function test_an_unknown_theme_is_refused(): void
    {
        $versionA = $this->draftA(['footer_description' => 'A content']);

        $service = $this->service();

        $this->assertNull(
            $service->publish($this->storeA->id, 'a-theme-that-does-not-exist', $versionA->id, $this->merchantA));
        $this->assertStringContainsString('not a theme this installation ships',
            (string) $service->refusal());
    }

    #[Test]
    public function test_an_unknown_version_id_is_refused(): void
    {
        $service = $this->service();

        $this->assertNull(
            $service->publish($this->storeA->id, 'stylique', 987654321, $this->merchantA));
        $this->assertStringContainsString('no such version', (string) $service->refusal());
    }

    /* ================================================= 8. staff of the merchant may publish */

    #[Test]
    public function test_staff_of_the_merchant_may_publish(): void
    {
        $versionA = $this->draftA(['footer_description' => 'published by staff']);

        $this->assertNotNull(
            $this->service()->publish($this->storeA->id, 'stylique', $versionA->id, $this->staffA),
            'Staff resolve to their employer as tenant root and are entitled by ownership. '
            . 'Narrowing this to a permission no role holds would lock out every merchant team.');
    }

    /* ======================================================= 9. audit event dereferencing */

    #[Test]
    public function test_a_publication_event_resolves_its_versions_only_within_its_own_tenant(): void
    {
        $versionA = $this->draftA(['footer_description' => 'A content']);

        $result = $this->service()->publish(
            $this->storeA->id, 'stylique', $versionA->id, $this->merchantA);

        $event = $result['event'];

        $this->assertSame($versionA->id, $event->resolveVersion()?->id);

        /* Point the event at another tenant's version id with the engine's help withdrawn -- the
         * event table has no FK (deliberately, so an audited tenant cannot cascade its own log
         * away), which is exactly why the MODEL must scope the dereference. */
        $versionB = $this->drafts()->saveDraft(
            $this->storeB->id, 'greentic', ['footer_description' => 'B content'], null, $this->merchantB);

        $this->assertNotNull($versionB, 'PREMISE: store B needs a version for this to mean anything.');

        $stray = new ThemePublicationEvent([
            'store_id' => $this->storeA->id, 'theme_id' => 'stylique',
            'version_id' => $versionB->id, 'action' => ThemePublicationEvent::ACTION_PUBLISH,
            'payload_hash' => str_repeat('c', 64),
        ]);

        $this->assertNull($stray->resolveVersion(),
            "A publication event dereferenced ANOTHER TENANT'S version. The event resolves on the "
            . '(store, theme, version) triple precisely so a bare id cannot cross a tenant.');
    }

    /* ============================================ 10. a failed transaction changes nothing */

    #[Test]
    public function test_a_failure_mid_publish_leaves_projection_pointer_and_history_untouched(): void
    {
        $v1 = $this->draftA(['footer_description' => 'the live one']);
        $v2 = $this->draftA(['footer_description' => 'the one that will fail']);

        $this->service()->publish($this->storeA->id, 'stylique', $v1->id, $this->merchantA);

        $projectionBefore = $this->projection($this->storeA);
        $pointerBefore = (int) ThemePublishedVersion::liveFor($this->storeA->id, 'stylique')->version_id;
        $historyBefore = ThemePublicationEvent::historyFor($this->storeA->id, 'stylique')->count();

        /*
         * THE FAILURE IS INJECTED AT THE LAST WRITE, which is what makes this test meaningful.
         *
         * The publish writes in this order: projection upserts, archive the superseded version,
         * mark the new one published, move the pointer, append the event. A failure at the EVENT
         * means every earlier write has already happened -- so if the transaction is not doing its
         * job, the projection and the pointer are left changed and the assertions below catch it.
         * A failure at the first write would prove almost nothing.
         *
         * A model event is the seam, rather than engine surgery. An earlier draft forced a
         * duplicate primary key by rewriting `sqlite_sequence`, which is SQLite-specific plumbing
         * that says nothing about production and breaks if the suite ever moves engine.
         */
        ThemePublicationEvent::creating(static function (): void {
            throw new \RuntimeException('injected failure at the last write of the publish');
        });

        $threw = false;

        try {
            $this->service()->publish($this->storeA->id, 'stylique', $v2->id, $this->merchantA, $v1->id);
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw,
            'PREMISE: the injected failure did not fire, so this test is not about a failed '
            . 'transaction at all.');

        $this->assertSame($projectionBefore, $this->projection($this->storeA),
            'A FAILED publish changed the projection. The prior published configuration must '
            . 'survive a failure intact.');
        $this->assertSame($pointerBefore,
            (int) ThemePublishedVersion::liveFor($this->storeA->id, 'stylique')->version_id,
            'A failed publish moved the live pointer.');
        $this->assertSame($historyBefore,
            ThemePublicationEvent::historyFor($this->storeA->id, 'stylique')->count(),
            'The history gained a row, so the failed publish left an event behind. (An earlier '
            . 'version of this assertion expected one extra row, because the earlier injection '
            . 'inserted one itself -- a stale expectation, not a real finding.)');
        $this->assertSame(ThemeVersion::STATUS_DRAFT,
            ThemeVersion::ownedBy($this->storeA->id, 'stylique', $v2->id)?->status,
            'The version that failed to publish was left marked published.');
    }
}
