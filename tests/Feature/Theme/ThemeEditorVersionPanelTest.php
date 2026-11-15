<?php

namespace Tests\Feature\Theme;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\ThemeCustomize;
use App\Models\ThemeVersion;
use App\Models\User;
use App\Services\Theme\ThemeConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M4-7 — THE EDITOR SHELL: the M3 engine, made visible and usable.
 * =================================================================================================
 *
 * Everything the version panel exposes existed since M3-D with no caller. These tests drive the
 * editor PAGE and assert on its rendered content, because the failure this surface is prone to is
 * a page that returns 200 with nothing on it: `layouts/app.blade.php` `@yield`s and holds zero
 * `$slot` references, so a wrongly-shaped view renders perfect chrome and an empty content area.
 *
 * THE PANEL SHOWS NO CONTROL THE MERCHANT CANNOT USE. With no draft there is nothing to preview or
 * publish, so it renders an empty state rather than a disabled button whose meaning has to be
 * guessed -- and `test_the_panel_offers_no_publish_control_when_there_is_no_draft` is what keeps
 * that true.
 */
class ThemeEditorVersionPanelTest extends TestCase
{
    use RefreshDatabase;

    private const LIVE = 'M4-7-PANEL-LIVE-VALUE';

    private User $owner;
    private User $staff;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::forceCreate([
            'name' => 'Panel Owner', 'email' => 'panel-o@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);
        $this->store = Store::create([
            'name' => 'Panel Shop', 'email' => 'panel-s@example.test', 'slug' => 'panel-shop',
            'theme_id' => 'stylique', 'created_by' => $this->owner->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->owner->current_store = $this->store->id;
        $this->owner->save();

        $this->staff = User::forceCreate([
            'name' => 'Panel Staff', 'email' => 'panel-st@example.test', 'password' => bcrypt('x'),
            'type' => 'staff', 'created_by' => $this->owner->id, 'email_verified_at' => now(),
        ]);
        $this->staff->current_store = $this->store->id;
        $this->staff->save();

        ThemeCustomize::create([
            'store_id' => $this->store->id, 'theme_id' => 'stylique',
            'name' => 'footer_description', 'value' => self::LIVE,
        ]);
    }

    private function editor(?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->get('/theme-customize/stylique/page/footer');
    }

    private function draft(?User $as = null): ThemeVersion
    {
        $d = (new ThemeConfigurationService())->saveDraft(
            $this->store->id, 'stylique',
            ['footer_description' => 'PANEL DRAFT VALUE'], 'panel draft', $as ?? $this->owner);

        $this->assertNotNull($d, 'PREMISE: the draft was refused.');

        return $d;
    }

    /* ============================================================ the panel is actually there */

    /**
     * A STORE WHOSE SHOP HAS BEEN LIVE FOR MONTHS MUST NOT READ "nothing published yet", which is
     * why the editor captures the baseline on open. Without it the panel and the history would both
     * be empty in a way that reads as a bug rather than as a new feature.
     */
    #[Test]
    public function test_opening_the_editor_shows_a_live_version_even_before_anything_is_published(): void
    {
        $this->assertSame(0, ThemeVersion::query()->count(), 'PREMISE: no version exists yet.');

        $page = $this->editor();
        $page->assertOk();
        $body = (string) $page->getContent();

        $this->assertStringContainsString('data-ss-version-panel', $body,
            'The version panel did not render on the editor page.');

        $this->assertStringContainsString('data-ss-live-badge', $body,
            'The editor reports nothing published for a store whose storefront is live. The '
            . 'baseline capture exists so the first open is not misleading.');

        $this->assertGreaterThan(0, ThemeVersion::query()->count(),
            'Opening the editor did not capture a baseline, so rollback has no target.');
    }

    #[Test]
    public function test_the_panel_offers_no_publish_control_when_there_is_no_draft(): void
    {
        $body = (string) $this->editor()->getContent();

        $this->assertStringNotContainsString('data-ss-publish-form', $body,
            'A publish control was offered with no draft to publish. The panel renders an empty '
            . 'state instead of a control the merchant cannot use.');

        $this->assertStringContainsString('data-ss-save-draft', $body,
            'Save-as-draft is always available; without it the editor cannot create its first draft.');
    }

    #[Test]
    public function test_a_draft_adds_publish_and_preview_controls(): void
    {
        $draft = $this->draft();

        $body = (string) $this->editor()->getContent();

        $this->assertStringContainsString('data-ss-publish-form', $body,
            'With a draft present, no publish control was offered.');
        $this->assertStringContainsString('data-ss-preview-draft', $body,
            'With a draft present, no preview link was offered.');
        $this->assertStringContainsString("/theme-draft/{$this->store->slug}/{$draft->id}/preview", $body,
            'The preview link does not address this store and this draft.');
    }

    /**
     * THE OPTIMISTIC CHECK IS ONLY REAL IF THE FORM CARRIES WHAT THE MERCHANT SAW. A publish form
     * without it gets the unchecked behaviour and two merchants with the editor open overwrite
     * each other silently.
     */
    #[Test]
    public function test_the_publish_form_carries_the_expected_live_version(): void
    {
        $this->draft();

        $body = (string) $this->editor()->getContent();

        $this->assertStringContainsString('name="expected_live_version_id"', $body,
            'The publish form omits the expected live version, so the optimistic concurrency check '
            . 'the service implements is inert from this UI.');
    }

    /* ================================================================== history and rollback */

    #[Test]
    public function test_history_lists_the_baseline_and_offers_restore_for_a_non_live_version(): void
    {
        $this->editor()->assertOk();               // captures the baseline
        $draft = $this->draft();

        $this->actingAs($this->owner)->post('/theme-version/stylique/publish', [
            'version_id' => $draft->id,
        ]);

        $body = (string) $this->editor()->getContent();

        $this->assertStringContainsString('data-ss-history', $body,
            'The publication history did not render.');
        $this->assertStringContainsString('data-ss-rollback-form', $body,
            'No restore control was offered for a version that is not live, so rollback is '
            . 'unreachable from the UI.');
    }

    /* ======================================================================= draft over HTTP */

    #[Test]
    public function test_saving_a_draft_over_http_does_not_touch_the_live_projection(): void
    {
        $this->actingAs($this->owner)->post('/theme-version/stylique/draft', [
            'footer_description' => 'DRAFTED VIA HTTP', 'label' => 'my draft',
        ]);

        $this->assertSame(self::LIVE, ThemeCustomize::withoutGlobalScopes()
            ->where('store_id', $this->store->id)->where('name', 'footer_description')->value('value'),
            'Saving a draft changed the LIVE storefront. That is the one thing a draft must not do.');

        $this->assertTrue(ThemeVersion::query()
            ->where('store_id', $this->store->id)
            ->where('status', ThemeVersion::STATUS_DRAFT)->exists(),
            'No draft version was created.');
    }

    /**
     * Staff may DRAFT but not publish — the delegation the owner required, asserted at the HTTP
     * boundary rather than at the service.
     */
    #[Test]
    public function test_zero_permission_staff_may_draft_but_not_publish(): void
    {
        $this->actingAs($this->staff)->post('/theme-version/stylique/draft', [
            'footer_description' => 'STAFF DRAFT',
        ]);

        $draft = ThemeVersion::query()->where('store_id', $this->store->id)
            ->where('status', ThemeVersion::STATUS_DRAFT)->first();

        $this->assertNotNull($draft, 'Tenant staff could not save a draft.');

        $this->actingAs($this->staff)->post('/theme-version/stylique/publish', [
            'version_id' => $draft->id,
        ]);

        $this->assertSame(self::LIVE, ThemeCustomize::withoutGlobalScopes()
            ->where('store_id', $this->store->id)->where('name', 'footer_description')->value('value'),
            'Zero-permission staff published their own draft to the live storefront.');
    }

    /* ======================================================================== the gettext bug */

    /**
     * `customize.blade.php` called PHP's gettext `_()` rather than Laravel's `__()`. gettext is not
     * configured here, so the string was never translatable at all -- it would stay English in an
     * Arabic interface however complete the translation file became.
     */
    #[Test]
    public function test_the_editor_uses_laravel_translation_not_php_gettext(): void
    {
        $view = file_get_contents(base_path('resources/views/theme_customize/customize.blade.php'));

        $this->assertDoesNotMatchRegularExpression('/\{\{\s*_\(/', $view,
            'The editor still calls PHP gettext `_()`. Laravel\'s helper is `__()`; gettext is not '
            . 'configured, so such a string can never be translated.');
    }
}
