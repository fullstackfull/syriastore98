<?php

namespace Tests\Feature\Theme;

use App\Models\Store;
use App\Models\ThemeCustomize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M4-8 — THE EDITOR IN ARABIC, ASSERTED ON ACTUAL ARABIC OUTPUT.
 * =================================================================================================
 *
 * THE FAILURE THIS FILE EXISTS TO CATCH IS A PAGE THAT "RENDERS IN ARABIC" AND IS ENTIRELY ENGLISH.
 * `__()` returns its key unchanged when there is no translation, so wrapping every string in it
 * makes the code look finished while the merchant still reads English. A test that only asserted
 * `assertOk()` on `?lang=ar`, or that the `dir` attribute flipped, would pass against exactly that.
 *
 * So each assertion names a SPECIFIC Arabic string and, where it is unambiguous, asserts the
 * English one is ABSENT. Measured before this slice: of the editor's own strings 17 of 55 were
 * missing, and of the theme taxonomy 139 of 224 -- 81 field labels, 47 section titles and 11 page
 * titles. All 156 are now present.
 *
 * ONE DEFECT FOUND WHILE WIRING IT, and it would have defeated the whole effort for one string:
 * `customize.blade.php` called PHP's **gettext** `_()` rather than Laravel's `__()`. gettext is not
 * configured in this application, so that string could never be translated however complete the
 * Arabic file became. `ThemeEditorVersionPanelTest` now refuses the pattern outright.
 *
 * WHAT THIS DOES NOT CLAIM. That the translations are IDIOMATIC. They follow the conventions
 * already in `ar.json` and use storefront terminology deliberately -- a theme is `قالب`, a live
 * version is `مباشر` -- but the file carries older entries that are poor for this domain
 * (`Theme` => `الموضوع`, `Live` => `حياً`), which are left alone because rewriting shipped
 * translations is a separate, owner-visible change. Native review of the new strings is recorded as
 * outstanding rather than assumed.
 */
class ThemeEditorBilingualTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::forceCreate([
            'name' => 'AR Owner', 'email' => 'ar-o@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
            'language' => 'ar',
        ]);
        $this->store = Store::create([
            'name' => 'AR Shop', 'email' => 'ar-s@example.test', 'slug' => 'ar-shop',
            'theme_id' => 'stylique', 'created_by' => $this->owner->id,
            'is_active' => 1, 'default_language' => 'ar',
        ]);
        $this->owner->current_store = $this->store->id;
        $this->owner->save();

        ThemeCustomize::create([
            'store_id' => $this->store->id, 'theme_id' => 'stylique',
            'name' => 'footer_description', 'value' => 'AR-FIXTURE-VALUE',
        ]);
    }

    /**
     * THE LOCALE COMES FROM THE REQUEST, NOT FROM `app()->setLocale()`. `SetLocate` middleware runs
     * on every request and re-derives it from the session, then the cookie, then
     * `auth()->user()->language` -- so a locale set before the call is overwritten before the view
     * renders. MEASURED: the first version of this file called `setLocale('ar')` and asserted
     * Arabic, and got an entirely English page. The session is what a merchant's language switcher
     * actually sets.
     *
     * Note the column is `users.language`; `default_language` is the STORE's column and setting it
     * on a user does nothing at all.
     */
    private function editorInArabic(): string
    {
        return (string) $this->withSession(['LANGUAGE' => 'ar'])
            ->actingAs($this->owner)
            ->get('/theme-customize/stylique/page/footer')
            ->getContent();
    }

    /* ============================================================= every string has a translation */

    /**
     * THE COVERAGE ASSERTION, over the real taxonomy rather than a sample. If a theme ships a new
     * field tomorrow, this fails and names it -- which is the only way the Arabic file stays
     * complete rather than complete-as-of-one-afternoon.
     */
    #[Test]
    public function test_every_theme_taxonomy_string_has_an_arabic_translation(): void
    {
        $ar = json_decode(file_get_contents(base_path('resources/lang/ar.json')), true);
        $this->assertIsArray($ar, 'PREMISE: ar.json did not parse.');

        $missing = [];

        foreach (['stylique', 'techzonix', 'greentic'] as $theme) {
            foreach (glob(base_path("themes/{$theme}/theme_json/*.json")) as $path) {
                $doc = json_decode((string) file_get_contents($path), true);

                if (! is_array($doc)) {
                    continue;
                }

                if (basename($path) === 'pages.json') {
                    foreach ($doc as $entry) {
                        if (is_array($entry) && ! empty($entry['title']) && ! isset($ar[$entry['title']])) {
                            $missing[] = "page: {$entry['title']}";
                        }
                    }
                    continue;
                }

                array_walk_recursive($doc, static function () {});
                $this->collectMissing($doc, $ar, $missing);
            }
        }

        $this->assertSame([], array_values(array_unique($missing)),
            "Theme taxonomy strings have no Arabic translation:\n  "
            . implode("\n  ", array_unique($missing))
            . "\n\n`__()` returns the key unchanged when a translation is absent, so these render as "
            . 'English on an Arabic page while every other test still passes.');
    }

    /** @param array<string,mixed> $node */
    private function collectMissing(array $node, array $ar, array &$missing): void
    {
        if (isset($node['settings']) && is_array($node['settings'])
            && (array_key_exists('key', $node) || array_key_exists('slug', $node))) {
            if (! empty($node['title']) && ! isset($ar[$node['title']])) {
                $missing[] = "section: {$node['title']}";
            }

            foreach ($node['settings'] as $setting) {
                if (is_array($setting) && ! empty($setting['label']) && ! isset($ar[$setting['label']])) {
                    $missing[] = "label: {$setting['label']}";
                }
            }
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectMissing($value, $ar, $missing);
            }
        }
    }

    /* ======================================================= the rendered page is really Arabic */

    /**
     * ACTUAL ARABIC IN THE BODY, and the English absent. This is the assertion `assertOk()` on an
     * Arabic locale cannot make.
     */
    #[Test]
    public function test_the_editor_renders_arabic_strings_not_english_keys(): void
    {
        $body = $this->editorInArabic();

        /*
         * THE ASSERTION IS THAT THE ENGLISH IS ABSENT, and the first version of this test got it
         * wrong in a way worth recording.
         *
         * It asserted the ARABIC was PRESENT -- and passed with the key deleted from `ar.json`.
         * The reason: the panel's empty-state sentence is
         * "غيّر أي إعداد ثم اختر حفظ كمسودة..." which CONTAINS "حفظ كمسودة" as a substring. So the
         * assertion was satisfied by a different string that happened to include it, and would have
         * gone on passing while the button itself read "Save as draft".
         *
         * Absence of the English is unambiguous: `__()` returns the key verbatim when a translation
         * is missing, so an untranslated button puts its exact English into the body and nothing
         * else does.
         */
        foreach (['Save as draft', 'Publication history', 'Restore this version'] as $english) {
            $this->assertStringNotContainsString($english, $body,
                "The editor rendered the English string \"{$english}\" on an Arabic page. `__()` "
                . 'returns its key unchanged when the translation is absent, so this is exactly '
                . 'what a page that "renders in Arabic" and is entirely English looks like.');
        }

        /* And the Arabic really is there -- asserted on a string that is not a substring of any
         * other value in the file. */
        $this->assertStringContainsString('سجل النشر', $body,
            'The Arabic for "Publication history" did not render.');
    }

    /** The theme's own taxonomy, not just the chrome: a section title and a field label. */
    #[Test]
    public function test_theme_section_titles_and_field_labels_render_in_arabic(): void
    {
        $body = $this->editorInArabic();

        $this->assertStringContainsString('أسفل الصفحة', $body,
            'The footer section title rendered in English. The taxonomy strings come from the '
            . 'theme JSON and are only translated because the views wrap them in __().');

        $this->assertStringContainsString('وصف', $body,
            'No translated field label appeared on the footer page.');
    }

    /** The page is laid out right-to-left, which is a different question from being translated. */
    #[Test]
    public function test_the_editor_is_right_to_left_in_arabic(): void
    {
        $body = $this->editorInArabic();

        $this->assertMatchesRegularExpression('/<html[^>]+dir=["\']rtl["\']/i', $body,
            'The editor did not render right-to-left under the Arabic locale.');
    }

    /** And it is still English in English — a translation that leaks the other way is also a bug. */
    #[Test]
    public function test_the_editor_is_still_english_in_english(): void
    {
        $body = (string) $this->withSession(['LANGUAGE' => 'en'])
            ->actingAs($this->owner)
            ->get('/theme-customize/stylique/page/footer')->getContent();

        $this->assertStringContainsString('Save as draft', $body);
        $this->assertStringNotContainsString('حفظ كمسودة', $body,
            'Arabic leaked into the English interface.');
    }
}
