<?php

namespace Tests\Feature\Platform;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M5 — THE OFFICIAL SITE IN ARABIC, ASSERTED ON ABSENT ENGLISH.
 * =================================================================================================
 *
 * THE ASSERTION DIRECTION IS DELIBERATE AND IT WAS LEARNED THE HARD WAY IN M4. A test that only
 * asserts an Arabic string is PRESENT can pass while the translation is missing, because another
 * sentence on the page may contain it as a substring — that is exactly how the M4 editor's
 * bilingual test passed with its key deleted. So each case here asserts that the ENGLISH original
 * is ABSENT from the Arabic render. `__()` returns its key unchanged when a translation is
 * missing, and the key here IS the English sentence, so a missing translation puts the English
 * back on the page and fails.
 *
 * THE LOCALE IS SET THROUGH THE SESSION, NOT WITH `app()->setLocale()`. The `setlocate` middleware
 * re-derives the locale on every request from the session, then the cookie, then the signed-in
 * user's `language` column, and it would simply overwrite a locale set in the test body. Measured
 * in M4; restated here because it is invisible and silently makes a bilingual test meaningless.
 */
class PlatformSiteBilingualTest extends TestCase
{
    use RefreshDatabase;

    private const URLS = [
        '/platform',
        '/platform/features',
        '/platform/pricing',
        '/platform/getting-started',
        '/platform/faq',
        '/platform/contact',
        '/platform/legal',
    ];

    #[Test]
    public function arabic_flips_the_document_direction_and_english_does_not(): void
    {
        foreach (self::URLS as $url) {
            $ar = $this->withSession(['LANGUAGE' => 'ar'])->get($url)->getContent();
            $this->assertStringContainsString('dir="rtl"', $ar, "no RTL on {$url}");
            $this->assertStringContainsString('lang="ar"', $ar, "no Arabic lang on {$url}");

            $en = $this->withSession(['LANGUAGE' => 'en'])->get($url)->getContent();
            $this->assertStringContainsString('dir="ltr"', $en, "no LTR on {$url}");
            /* `dir=""` was a real defect on the guest layout: both [dir="rtl"] and [dir="ltr"]
               rules missed and the direction was only implied by the stylesheet choice. */
            $this->assertStringNotContainsString('dir=""', $en);
        }
    }

    /**
     * ONE NAMED SENTENCE PER PAGE, ASSERTED IN BOTH DIRECTIONS.
     *
     * Each pair is [url, the English sentence that must disappear, the Arabic that must replace
     * it]. The English string is chosen to be one that appears nowhere else on the page, so its
     * absence is evidence about that sentence rather than about the page in general.
     */
    public static function pages(): array
    {
        return [
            'home'      => ['/platform', 'What you get', 'ما الذي تحصل عليه'],
            'features'  => ['/platform/features', 'What the platform does', 'ما الذي تقوم به المنصّة'],
            'pricing'   => ['/platform/pricing', 'Questions about a plan', 'أسئلة عن الباقات'],
            'onboarding' => ['/platform/getting-started', 'Create your first store', 'أنشئ متجرك الأول'],
            'faq'       => ['/platform/faq', 'Questions people ask', 'أسئلة يطرحها الناس'],
            'contact'   => ['/platform/contact', 'Talk to us', 'تحدّث إلينا'],
            'legal'     => ['/platform/legal', 'Terms of service', 'شروط الخدمة'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function each_page_replaces_its_english_with_arabic(string $url, string $english, string $arabic): void
    {
        $en = $this->withSession(['LANGUAGE' => 'en'])->get($url);
        $en->assertOk();
        $this->assertStringContainsString($english, $en->getContent(), "the English control string is not on {$url}");

        $ar = $this->withSession(['LANGUAGE' => 'ar'])->get($url);
        $ar->assertOk();

        $html = $ar->getContent();
        $this->assertStringContainsString($arabic, $html, "the Arabic translation is missing on {$url}");
        /* THE ASSERTION THAT CAN ACTUALLY FAIL. */
        $this->assertStringNotContainsString($english, $html, "{$url} still renders the English original in Arabic");
    }

    #[Test]
    public function the_shared_chrome_is_translated_too(): void
    {
        /* The header and footer carry their own strings, and a page can be fully translated while
           its navigation is not -- the merchant then reads an Arabic page wrapped in English. */
        $html = $this->withSession(['LANGUAGE' => 'ar'])->get('/platform')->getContent();

        $this->assertStringContainsString('نظرة عامة', $html);     // Overview
        $this->assertStringContainsString('دخول التجّار', $html);   // Merchant sign in
        $this->assertStringContainsString('المعلومات القانونية', $html); // Legal information

        $this->assertStringNotContainsString('Merchant sign in', $html);
        $this->assertStringNotContainsString('Skip to main content', $html);
    }

    #[Test]
    public function the_language_switch_offers_both_languages_on_every_page(): void
    {
        foreach (self::URLS as $url) {
            $html = $this->get($url)->getContent();

            $this->assertStringContainsString(url('change-languages/ar'), $html, "no Arabic switch on {$url}");
            $this->assertStringContainsString(url('change-languages/en'), $html, "no English switch on {$url}");
            /* The switch labels are each marked with their own `lang`, so a screen reader does not
               read "العربية" with an English voice. */
            $this->assertStringContainsString('lang="ar"', $html);
        }
    }

    #[Test]
    public function plan_limit_labels_are_translated_rather_than_left_in_english(): void
    {
        Plan::create([
            'name' => 'باقة التجربة', 'price' => '10', 'duration' => 'Month',
            'max_stores' => '1', 'max_products' => '-1', 'max_users' => '3',
            'storage_limit' => '512', 'enable_domain' => 'on', 'enable_subdomain' => 'off',
            'shipping_method' => 'on', 'enable_tax' => 'on', 'pwa_store' => 'off',
            'themes' => 'stylique',
        ]);

        $html = $this->withSession(['LANGUAGE' => 'ar'])->get('/platform/pricing')->getContent();

        $this->assertStringContainsString('المتاجر المشمولة', $html);   // Stores included
        $this->assertStringContainsString('المساحة (ميغابايت)', $html); // Storage (MB)
        $this->assertStringNotContainsString('Stores included', $html);
        $this->assertStringNotContainsString('Storage (MB)', $html);
    }
}
