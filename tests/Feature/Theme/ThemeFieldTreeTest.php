<?php

namespace Tests\Feature\Theme;

use App\Models\Store;
use App\Models\ThemeCustomize;
use App\Models\User;
use App\Services\Theme\ThemeConfigurationService;
use App\Support\Theme\ThemeFieldCatalog;
use App\Support\Theme\ThemeFieldTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M4-3 — THE EDITOR'S TWO READS: the field tree, and live settings behind the gate.
 * =================================================================================================
 *
 * WHAT WOULD MAKE THIS FILE PASS WITHOUT PROVING ANYTHING, closed deliberately:
 *
 *   - "EVERY FIELD HAS A LABEL" IS VACUOUS. Every setting node in all three themes carries one, so
 *     that assertion is true of any implementation including one that returns the catalogue
 *     unchanged. What is asserted instead is the thing that silently breaks a grouped editor: the
 *     DUPLICATES are surfaced rather than collapsed.
 *
 *   - KEY-COMPLETENESS MAKES PRESENCE ASSERTIONS VACUOUS. `getThemeSetting()` merges ~500 defaults
 *     under the stored rows, so `array_key_exists()` is true for almost any key whether or not a
 *     store was read. `liveSettings()` is therefore asserted on a SENTINEL VALUE no theme default
 *     carries.
 *
 *   - SINGLE-THEME COVERAGE HIDES REAL DIVERGENCE. greentic declares no `blog_status` at all and
 *     techzonix has a different section set, so every structural assertion runs over all three.
 */
class ThemeFieldTreeTest extends TestCase
{
    use RefreshDatabase;

    private const SENTINEL = 'M4-3-LIVE-SENTINEL-7c1d';

    /** Measured 2026-11-15; a drift here is a theme change, which should be deliberate. */
    private const EXPECTED_DUPLICATES = [
        'stylique'  => ['blog_status', 'track_cancel_title'],
        'techzonix' => ['article_status', 'track_cancel_title'],
        'greentic'  => ['article_status', 'track_cancel_title'],
    ];

    private User $merchant;
    private User $other;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        ThemeFieldTree::forget();

        $this->merchant = User::forceCreate([
            'name' => 'Tree M', 'email' => 'tree-m@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);
        $this->store = Store::create([
            'name' => 'Tree Store', 'email' => 'tree-s@example.test', 'slug' => 'tree-store',
            'theme_id' => 'stylique', 'created_by' => $this->merchant->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        $this->other = User::forceCreate([
            'name' => 'Tree O', 'email' => 'tree-o@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);

        ThemeCustomize::create([
            'store_id' => $this->store->id, 'theme_id' => 'stylique',
            'name' => 'footer_description', 'value' => self::SENTINEL,
        ]);
    }

    public static function themes(): array
    {
        return [['stylique'], ['techzonix'], ['greentic']];
    }

    /* ============================================================ the tree and the catalogue */

    /**
     * THE ASSERTION THAT KEEPS THE TWO READERS HONEST. `ThemeFieldCatalog` answers the validator's
     * question and `ThemeFieldTree` answers the editor's, over the same files by the same rule. If
     * their name sets ever diverge, a field is editable and invisible, or visible and unsaveable --
     * and neither failure shows up anywhere else.
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('themes')]
    public function test_the_tree_declares_exactly_the_catalogues_field_names(string $theme): void
    {
        $catalogue = array_keys(ThemeFieldCatalog::editableFields($theme));
        sort($catalogue);

        $this->assertNotEmpty($catalogue, "PREMISE: the catalogue is empty for {$theme}.");

        $this->assertSame($catalogue, ThemeFieldTree::names($theme),
            "The tree and the catalogue disagree about which fields {$theme} declares. One of them "
            . 'is reading the theme differently, which makes a field either editable-and-invisible '
            . 'or visible-and-unsaveable.');
    }

    /**
     * THE DUPLICATES ARE NAMED, NOT COLLAPSED. `editableFields()` assigns into `$fields[$name]`, so
     * a repeated name vanishes with no trace; a grouped editor renders it in two places and
     * whichever the merchant edits last wins. Two kinds exist and both are asserted: cross-file
     * (`blog_status` in blog.json + home.json) and within-file (`track_cancel_title`, twice in
     * track-order.json).
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('themes')]
    public function test_duplicate_field_names_are_surfaced_with_their_origins(string $theme): void
    {
        $dups = ThemeFieldTree::duplicates($theme);

        $this->assertSame(self::EXPECTED_DUPLICATES[$theme], array_keys($dups),
            "The duplicate set for {$theme} changed. Measured 2026-11-15; a drift is a theme "
            . 'change and should be deliberate.');

        foreach ($dups as $name => $origins) {
            $this->assertGreaterThan(1, count($origins),
                "`{$name}` is reported as duplicated but names only one origin.");
        }
    }

    /**
     * `pages.json` IS AN INCOMPLETE INDEX, so the tree is built from the directory. If it were
     * built from the index, these pages would be silently missing from the editor.
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('themes')]
    public function test_pages_absent_from_the_index_are_still_in_the_tree(string $theme): void
    {
        $pages = collect(ThemeFieldTree::forTheme($theme))->keyBy('page');

        foreach (['homepage', 'terms-condition'] as $hidden) {
            $this->assertTrue($pages->has($hidden),
                "`{$hidden}.json` ships with {$theme} but is absent from the tree. pages.json does "
                . 'not list it, which is exactly why the tree reads the directory instead.');
            $this->assertFalse($pages[$hidden]['listed'],
                "PREMISE: `{$hidden}` is expected to be absent from pages.json; if it is now "
                . 'listed, this test no longer probes the incomplete-index case.');
        }
    }

    /**
     * `homepage.json` IS A SECOND SCHEMA AND YIELDS NOTHING, and the tree says so rather than
     * presenting an empty page as a page with no settings. Measured: it contributes 0 fields to
     * all three themes while stylique has 307, techzonix 321 and greentic 328.
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('themes')]
    public function test_the_second_schema_page_is_reported_unreadable_not_empty(string $theme): void
    {
        $pages = collect(ThemeFieldTree::forTheme($theme))->keyBy('page');

        $this->assertFalse($pages['homepage']['readable'],
            "`homepage.json` yielded fields for {$theme}. It uses section_name/field_name and types "
            . 'spelled "photo upload" and "text area", none of which the walker recognises -- if it '
            . 'now parses, the walker changed and the editor must be re-checked against it.');

        $this->assertSame([], $pages['homepage']['sections']);
    }

    /** A repeater carries its sub-field list, or an editor renders a JSON blob in a text box. */
    #[Test]
    public function test_a_repeater_carries_its_sub_field_list(): void
    {
        $found = null;

        foreach (ThemeFieldTree::forTheme('stylique') as $page) {
            foreach ($page['sections'] as $section) {
                foreach ($section['fields'] as $field) {
                    if ($field['name'] === 'footer_repeater') {
                        $found = $field;
                    }
                }
            }
        }

        $this->assertNotNull($found, 'PREMISE: stylique declares footer_repeater.');
        $this->assertSame('slider', $found['type'],
            'A `slider` is this taxonomy\'s repeater, not a numeric slider.');
        $this->assertSame(['icon', 'link'], $found['repeater_fields'] ?? null,
            'The repeater did not carry its sub-field list, so an editor has nothing to render '
            . 'inputs from and falls back to a raw JSON text box.');
    }

    /* ======================================================== liveSettings(), behind the gate */

    #[Test]
    public function test_live_settings_returns_the_callers_own_stored_value(): void
    {
        $settings = (new ThemeConfigurationService())
            ->liveSettings($this->store->id, 'stylique', $this->merchant);

        $this->assertIsArray($settings);

        $this->assertSame(self::SENTINEL, $settings['footer_description'] ?? null,
            'liveSettings() did not return this store\'s own value. The sentinel is a string no '
            . 'theme default carries, so its absence means no store was read.');

        $this->assertArrayHasKey('footer_logo', $settings,
            'The merge is not key-complete, which is what the editor form depends on.');
    }

    #[Test]
    public function test_live_settings_refuses_another_tenant(): void
    {
        $service = new ThemeConfigurationService();

        $this->assertNull($service->liveSettings($this->store->id, 'stylique', $this->other),
            'A merchant from another tenant read this store\'s live configuration. The global '
            . 'helper getThemeSetting() performs no authorization at all, which is why this '
            . 'method exists.');

        $this->assertNotNull($service->refusal(),
            'The refusal gave no reason, so a caller cannot distinguish it from an empty store.');
    }

    #[Test]
    public function test_live_settings_refuses_an_unknown_theme_and_a_null_store(): void
    {
        $service = new ThemeConfigurationService();

        $this->assertNull(
            $service->liveSettings($this->store->id, 'no-such-theme', $this->merchant));

        $this->assertNull(
            (new ThemeConfigurationService())->liveSettings(null, 'stylique', $this->merchant));
    }
}
