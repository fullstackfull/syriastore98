<?php

namespace Tests\Feature\Theme;

use App\Models\Store;
use App\Models\ThemeCustomize;
use App\Models\User;
use App\Services\Theme\ThemeConfigurationService;
use App\Services\Theme\ThemePublishingService;
use App\Support\Theme\ThemePreviewContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M4-5 — THE DRAFT PREVIEW SEAM: that it works, and that it cannot reach anyone else.
 * =================================================================================================
 *
 * A preview must render an UNPUBLISHED configuration for the owning tenant and be invisible to
 * every shopper. The seam is inside `getThemeSetting()` because the three obvious alternatives are
 * each closed by something measured on this codebase -- the view composer overwrites controller
 * data, a `{theme}` route parameter switches on an unvalidated store-1 branch, and a `themes` path
 * segment is read as a theme name by `ActiveTheme`. `ThemePreviewContext`'s docblock carries the
 * file:line for each.
 *
 * WHAT THIS FILE ASSERTS, and the order matters: that the seam is INERT by default, that it works
 * when armed, and -- the one that would actually hurt -- that an armed preview does not change what
 * any OTHER store or theme reads in the same request.
 */
class ThemePreviewSeamTest extends TestCase
{
    use RefreshDatabase;

    private const LIVE_A  = 'M4-5-STORE-A-PUBLISHED';
    private const LIVE_B  = 'M4-5-STORE-B-PUBLISHED';
    private const DRAFTED = 'M4-5-STORE-A-DRAFTED-NOT-PUBLISHED';

    private User $merchantA;
    private User $merchantB;
    private Store $storeA;
    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchantA = User::forceCreate([
            'name' => 'Pv A', 'email' => 'pv-a@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);
        $this->storeA = Store::create([
            'name' => 'Pv Store A', 'email' => 'pv-sa@example.test', 'slug' => 'pv-store-a',
            'theme_id' => 'stylique', 'created_by' => $this->merchantA->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->merchantA->current_store = $this->storeA->id;
        $this->merchantA->save();

        $this->merchantB = User::forceCreate([
            'name' => 'Pv B', 'email' => 'pv-b@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);
        $this->storeB = Store::create([
            'name' => 'Pv Store B', 'email' => 'pv-sb@example.test', 'slug' => 'pv-store-b',
            'theme_id' => 'stylique', 'created_by' => $this->merchantB->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->merchantB->current_store = $this->storeB->id;
        $this->merchantB->save();

        ThemeCustomize::create([
            'store_id' => $this->storeA->id, 'theme_id' => 'stylique',
            'name' => 'footer_description', 'value' => self::LIVE_A,
        ]);
        ThemeCustomize::create([
            'store_id' => $this->storeB->id, 'theme_id' => 'stylique',
            'name' => 'footer_description', 'value' => self::LIVE_B,
        ]);
    }

    private function context(): ThemePreviewContext
    {
        return app(ThemePreviewContext::class);
    }

    /* ================================================================== inert until armed */

    /**
     * THE MOST IMPORTANT TEST IN THE FILE, because the seam sits in the function all 75 anonymous
     * storefront routes call. If it changed anything by existing, it would change it for shoppers.
     */
    #[Test]
    public function test_the_seam_changes_nothing_when_it_is_not_armed(): void
    {
        $this->assertFalse($this->context()->isArmed());

        $settings = getThemeSetting($this->storeA->id, 'stylique');

        $this->assertSame(self::LIVE_A, $settings['footer_description'],
            'An unarmed preview context altered the live read. This function serves every '
            . 'anonymous storefront route.');

        $this->assertArrayHasKey('footer_logo', $settings,
            'The defaults merge stopped happening, which is a 500 on ~520 unguarded keys.');
    }

    /* ======================================================================== armed, it works */

    #[Test]
    public function test_an_armed_preview_returns_the_draft_for_that_store_and_theme(): void
    {
        $draft = $this->draftFor($this->storeA, self::DRAFTED);

        $preview = (new ThemePublishingService())
            ->preview($this->storeA->id, 'stylique', $draft->id, $this->merchantA);

        $this->assertNotNull($preview, 'PREMISE: the draft preview was refused for its owner.');

        $this->context()->arm($this->storeA->id, 'stylique', $preview, $draft->id);

        $this->assertSame(self::DRAFTED, getThemeSetting($this->storeA->id, 'stylique')['footer_description'],
            'The armed preview did not reach getThemeSetting(), so a rendered page would show the '
            . 'published configuration and the merchant would believe their draft was empty.');
    }

    /** The composer fires twice per page, so a one-shot would serve two different configurations. */
    #[Test]
    public function test_an_armed_preview_answers_the_same_on_every_call(): void
    {
        $draft = $this->draftFor($this->storeA, self::DRAFTED);
        $preview = (new ThemePublishingService())
            ->preview($this->storeA->id, 'stylique', $draft->id, $this->merchantA);

        $this->context()->arm($this->storeA->id, 'stylique', $preview, $draft->id);

        $first  = getThemeSetting($this->storeA->id, 'stylique')['footer_description'];
        $second = getThemeSetting($this->storeA->id, 'stylique')['footer_description'];

        $this->assertSame($first, $second,
            'The preview answered differently on a second call. The view composer fires TWICE on a '
            . 'storefront page -- @extends makes the layout its own View -- so a one-shot serves '
            . 'the draft to one and the published configuration to the other.');
    }

    /* ============================================================== armed, it reaches no one else */

    /**
     * THE CONTAINMENT, and the failure it prevents is the serious one: an override that answered
     * for ANY arguments would leak store A's unpublished draft into a page rendered for store B.
     */
    #[Test]
    public function test_an_armed_preview_does_not_change_what_another_store_reads(): void
    {
        $draft = $this->draftFor($this->storeA, self::DRAFTED);
        $preview = (new ThemePublishingService())
            ->preview($this->storeA->id, 'stylique', $draft->id, $this->merchantA);

        $this->context()->arm($this->storeA->id, 'stylique', $preview, $draft->id);

        $this->assertSame(self::LIVE_B, getThemeSetting($this->storeB->id, 'stylique')['footer_description'],
            'Store A\'s armed draft changed what store B reads. The override must be keyed on the '
            . '(store, theme) pair, not global.');

        $this->assertSame(self::DRAFTED, getThemeSetting($this->storeA->id, 'stylique')['footer_description'],
            'Sanity: store A should still see its own draft.');
    }

    /** The same store under a DIFFERENT theme is a different pair and must read live. */
    #[Test]
    public function test_an_armed_preview_does_not_change_another_theme_for_the_same_store(): void
    {
        $draft = $this->draftFor($this->storeA, self::DRAFTED);
        $preview = (new ThemePublishingService())
            ->preview($this->storeA->id, 'stylique', $draft->id, $this->merchantA);

        $this->context()->arm($this->storeA->id, 'stylique', $preview, $draft->id);

        $other = getThemeSetting($this->storeA->id, 'greentic');

        $this->assertNotSame(self::DRAFTED, $other['footer_description'] ?? null,
            'A stylique draft leaked into the greentic read for the same store.');
    }

    /* ============================================================================ it writes nothing */

    #[Test]
    public function test_arming_a_preview_writes_nothing(): void
    {
        $before = ThemeCustomize::withoutGlobalScopes()->count();

        $draft = $this->draftFor($this->storeA, self::DRAFTED);
        $preview = (new ThemePublishingService())
            ->preview($this->storeA->id, 'stylique', $draft->id, $this->merchantA);
        $this->context()->arm($this->storeA->id, 'stylique', $preview, $draft->id);

        getThemeSetting($this->storeA->id, 'stylique');

        $this->assertSame($before, ThemeCustomize::withoutGlobalScopes()->count(),
            'Previewing changed the published projection.');

        $this->assertSame(self::LIVE_A,
            ThemeCustomize::withoutGlobalScopes()
                ->where('store_id', $this->storeA->id)
                ->where('name', 'footer_description')->value('value'),
            'The stored row moved while only a preview was armed.');
    }

    /** Disarming restores the live read, which is what a request boundary does implicitly. */
    #[Test]
    public function test_disarming_restores_the_live_read(): void
    {
        $draft = $this->draftFor($this->storeA, self::DRAFTED);
        $preview = (new ThemePublishingService())
            ->preview($this->storeA->id, 'stylique', $draft->id, $this->merchantA);

        $this->context()->arm($this->storeA->id, 'stylique', $preview, $draft->id);
        $this->assertSame(self::DRAFTED, getThemeSetting($this->storeA->id, 'stylique')['footer_description']);

        $this->context()->disarm();

        $this->assertSame(self::LIVE_A, getThemeSetting($this->storeA->id, 'stylique')['footer_description'],
            'The live read did not come back after disarming.');
    }

    private function draftFor(Store $store, string $value): \App\Models\ThemeVersion
    {
        $draft = (new ThemeConfigurationService())->saveDraft(
            $store->id, 'stylique', ['footer_description' => $value], 'preview draft',
            $store->id === $this->storeA->id ? $this->merchantA : $this->merchantB
        );

        $this->assertNotNull($draft, 'PREMISE: the draft was refused.');

        return $draft;
    }
}
