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
use App\Support\Theme\ThemePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M4-4 — THE FIRST VERSION A STORE EVER OWNS.
 * =================================================================================================
 *
 * M3 built publish, rollback, preview and history, and **no store can use any of them**, because
 * `2026_11_13_000100_create_theme_versions_table` backfills nothing: every store owns zero versions.
 * A backfill migration would be a persistent write to production, which the owner reserves, so the
 * baseline is captured lazily instead.
 *
 * THE ASSERTION THAT MATTERS IS NOT "a row appeared". It is that the baseline is the configuration
 * that was ALREADY LIVE — so a rollback to it is a no-op on the storefront rather than a change
 * nobody asked for. Pinned by `test_rolling_back_to_the_baseline_restores_what_was_live`.
 */
class ThemeBaselineVersionTest extends TestCase
{
    use RefreshDatabase;

    private const LIVE = 'M4-4-WAS-ALREADY-LIVE';

    private User $merchant;
    private User $staff;
    private User $other;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::forceCreate([
            'name' => 'Base M', 'email' => 'base-m@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);
        $this->store = Store::create([
            'name' => 'Base Store', 'email' => 'base-s@example.test', 'slug' => 'base-store',
            'theme_id' => 'stylique', 'created_by' => $this->merchant->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        $this->staff = User::forceCreate([
            'name' => 'Base S', 'email' => 'base-staff@example.test', 'password' => bcrypt('x'),
            'type' => 'staff', 'created_by' => $this->merchant->id, 'email_verified_at' => now(),
        ]);
        $this->staff->current_store = $this->store->id;
        $this->staff->save();

        $this->other = User::forceCreate([
            'name' => 'Base O', 'email' => 'base-o@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);

        ThemeCustomize::create([
            'store_id' => $this->store->id, 'theme_id' => 'stylique',
            'name' => 'footer_description', 'value' => self::LIVE,
        ]);
    }

    private function service(): ThemeConfigurationService
    {
        return new ThemeConfigurationService();
    }

    /* =========================================================== the premise this slice rests on */

    /**
     * THE PREMISE, ASSERTED SO IT CANNOT ROT. If a migration ever starts backfilling, this slice's
     * entire justification changes and this test says so by failing.
     */
    #[Test]
    public function test_a_store_owns_no_version_until_one_is_captured(): void
    {
        $this->assertSame(0, ThemeVersion::query()->count(),
            'A store already owns a version. The baseline capture exists because the migration '
            . 'backfills nothing; if that changed, this slice needs rethinking, not adjusting.');

        $this->assertNull(ThemePublishedVersion::liveFor($this->store->id, 'stylique'));
    }

    /* ======================================================================== what it captures */

    #[Test]
    public function test_the_baseline_captures_the_configuration_that_was_already_live(): void
    {
        $version = $this->service()->ensureBaseline($this->store->id, 'stylique', $this->merchant);

        $this->assertNotNull($version);
        $this->assertSame(ThemeVersion::STATUS_PUBLISHED, $version->status);

        $payload = ThemePayload::decode($version->payload);

        $this->assertSame(self::LIVE, $payload['footer_description'] ?? null,
            'The baseline does not hold the value that was live when it was taken.');

        $this->assertSame(
            $this->store->id,
            ThemePublishedVersion::liveFor($this->store->id, 'stylique')?->store_id,
            'No live pointer was created, so publish and rollback are still inert.');
    }

    /**
     * ONLY THE OVERRIDES, NOT THE ~500-KEY MERGE. If the baseline were key-complete, a rollback to
     * it would materialise every theme DEFAULT as an explicit stored row — a large silent write of
     * values the merchant never chose, permanently detached from the theme's own defaults.
     */
    #[Test]
    public function test_the_baseline_holds_only_the_stored_overrides(): void
    {
        $version = $this->service()->ensureBaseline($this->store->id, 'stylique', $this->merchant);
        $payload = ThemePayload::decode($version->payload);

        $this->assertSame(['footer_description'], array_keys($payload),
            'The baseline holds more than the stored rows. Key-completeness belongs to the READ '
            . '(liveSettings) and to preview, never to a snapshot that publish will upsert.');

        $this->assertArrayNotHasKey('footer_logo', $payload,
            'A theme default was captured into the baseline.');
    }

    /** It records what IS, so legacy markup is preserved rather than refused or rewritten. */
    #[Test]
    public function test_a_legacy_value_the_validator_would_reject_is_still_captured(): void
    {
        ThemeCustomize::create([
            'store_id' => $this->store->id, 'theme_id' => 'stylique',
            'name' => 'footer_copyright', 'value' => '<p>Legacy <b>HTML</b> already stored</p>',
        ]);

        $version = $this->service()->ensureBaseline($this->store->id, 'stylique', $this->merchant);
        $payload = ThemePayload::decode($version->payload);

        $this->assertSame('<p>Legacy <b>HTML</b> already stored</p>', $payload['footer_copyright'],
            'The baseline sanitised stored merchant content. It records what is live; rewriting it '
            . 'here would be a destructive update of published settings nobody asked for.');
    }

    /* ============================================================================= idempotence */

    #[Test]
    public function test_capturing_twice_creates_exactly_one_baseline(): void
    {
        $first  = $this->service()->ensureBaseline($this->store->id, 'stylique', $this->merchant);
        $second = $this->service()->ensureBaseline($this->store->id, 'stylique', $this->merchant);

        $this->assertSame($first->id, $second->id,
            'A second capture created a second baseline.');

        $this->assertSame(1, ThemeVersion::query()->where('store_id', $this->store->id)->count());
        $this->assertSame(1, ThemePublicationEvent::query()
            ->where('store_id', $this->store->id)
            ->where('action', ThemePublicationEvent::ACTION_BASELINE)->count());
    }

    /* ============================================================ what it unblocks, end to end */

    /**
     * THE POINT OF THE WHOLE SLICE. Before it, rollback had no target. After it, rolling back to
     * the baseline must restore exactly what was live — a no-op on the storefront, not a change.
     */
    #[Test]
    public function test_rolling_back_to_the_baseline_restores_what_was_live(): void
    {
        $baseline = $this->service()->ensureBaseline($this->store->id, 'stylique', $this->merchant);

        $draft = $this->service()->saveDraft($this->store->id, 'stylique',
            ['footer_description' => 'CHANGED BY THE MERCHANT'], 'a change', $this->merchant);

        $publishing = new ThemePublishingService();
        $this->assertNotNull(
            $publishing->publish($this->store->id, 'stylique', $draft->id, $this->merchant),
            'PREMISE: the publish must succeed, or the rollback below restores nothing.');

        $this->assertSame('CHANGED BY THE MERCHANT', $this->liveValue(),
            'PREMISE: the storefront must actually show the change before it is rolled back.');

        $this->assertNotNull(
            (new ThemePublishingService())
                ->rollback($this->store->id, 'stylique', $baseline->id, $this->merchant),
            'The rollback to the baseline was refused.');

        $this->assertSame(self::LIVE, $this->liveValue(),
            'Rolling back to the baseline did not restore the configuration that was live before '
            . 'versioning existed, which is the only thing the baseline is for.');
    }

    /** A rollback appends an event; it never rewrites history. */
    #[Test]
    public function test_the_baseline_event_is_distinguishable_from_a_publish(): void
    {
        $this->service()->ensureBaseline($this->store->id, 'stylique', $this->merchant);

        $event = ThemePublicationEvent::query()->where('store_id', $this->store->id)->first();

        $this->assertSame(ThemePublicationEvent::ACTION_BASELINE, $event->action,
            'The baseline was recorded as a publish. A merchant who has never published would see '
            . 'a publication they did not make.');

        $this->assertNull($event->from_version_id,
            'The baseline claims a predecessor; there was nothing before it.');
    }

    /* ================================================================================== tenancy */

    #[Test]
    public function test_another_tenant_cannot_capture_a_baseline_for_this_store(): void
    {
        $this->assertNull(
            $this->service()->ensureBaseline($this->store->id, 'stylique', $this->other),
            'Another tenant\'s merchant captured a baseline for this store.');

        $this->assertSame(0, ThemeVersion::query()->count());
    }

    /**
     * STAFF MAY TRIGGER IT, because opening the editor is what triggers it and the owner requires
     * staff to be able to prepare drafts. Capturing a baseline creates a version; it does not
     * change what shoppers see.
     */
    #[Test]
    public function test_zero_permission_staff_may_trigger_the_baseline(): void
    {
        $this->assertNotNull(
            $this->service()->ensureBaseline($this->store->id, 'stylique', $this->staff),
            'Zero-permission staff could not trigger the baseline, so opening the editor would '
            . 'leave publish and rollback inert for them.');
    }

    private function liveValue(): ?string
    {
        return ThemeCustomize::withoutGlobalScopes()
            ->where('store_id', $this->store->id)
            ->where('theme_id', 'stylique')
            ->where('name', 'footer_description')
            ->value('value');
    }
}
