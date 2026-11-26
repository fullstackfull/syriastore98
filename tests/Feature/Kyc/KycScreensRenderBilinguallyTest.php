<?php

namespace Tests\Feature\Kyc;

use App\Models\KycVerification;
use App\Services\Kyc\KycAuthority;
use App\Services\Kyc\KycDocumentStore;
use App\Services\Kyc\KycReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\Kyc\MakesKycFixtures;

/**
 * THE THREE VERIFICATION SCREENS, RENDERED IN ARABIC (RTL) AND ENGLISH (LTR).
 * =================================================================================================
 *
 * THIS IS NOT A DESIGN REVIEW. It is the set of failures that are INVISIBLE in the source and that
 * this project has paid for more than once:
 *
 *   AN EMPTY CONTENT AREA        A view written in the `<x-*-layout>` slot shape renders perfect
 *                                chrome and HTTP 200 with NOTHING in it, because the WorkDo layouts
 *                                `@yield` and contain zero `$slot` references — measured 2026-11-10
 *                                in all four language/theme combinations. A status-code assertion
 *                                photographs that as success, so every assertion here names a
 *                                string that must be ON THE PAGE.
 *   A BLADE COMPILER 500         Three measured 500s in this repository, none of whose messages
 *                                mentioned a comment: `storeUncompiledBlocks()` runs before
 *                                `compileComments()`, so a raw-PHP directive's name inside a
 *                                `{{-- --}}` comment opens a raw block to the next closing
 *                                directive. These views carry long comments, so they are rendered
 *                                rather than read.
 *   A RAW TRANSLATION KEY        `__('kyc.kind.commercial_register')` with no translation returns
 *                                THE KEY, so a merchant would read `kyc.kind.commercial_register`
 *                                on their own compliance screen. The document kinds are
 *                                owner-decided and cannot be shipped translated, so
 *                                `KycAuthority::kindLabel()` humanises what it cannot translate —
 *                                and both directions are asserted below.
 *   A HARD-CODED DIRECTION       The layout forces `dir` from the principal's `lang`; a directional
 *                                margin or a literal `dir="ltr"` in a view would survive that
 *                                silently. Asserted on the rendered document.
 *
 * WHAT IT DOES NOT CLAIM: that the Arabic reads like Arabic, or that the contrast passes. Those are
 * human review and a contrast harness respectively, and this project records that a quick colour
 * probe is NOT the instrument.
 */
class KycScreensRenderBilinguallyTest extends SecurityTestCase
{
    use MakesKycFixtures;
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function locales(): array
    {
        return ['english' => ['en'], 'arabic' => ['ar']];
    }

    /**
     * @dataProvider locales
     */
    public function test_the_merchants_screen_renders_in_both_directions(string $locale): void
    {
        Storage::fake('kyc');
        $this->configureKycKinds(KycVerification::COMPANY, KycAuthority::STORE_SYRIA, ['commercial_register']);

        [$merchant, $store] = $this->kycMerchantWithStore();
        $merchant->language = $locale;
        $merchant->save();

        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);
        KycDocumentStore::store($record, $this->kycPngUpload('register.png'), 'commercial_register', $merchant);

        $response = $this->actingAs($merchant->fresh())->get('/verification');

        $response->assertStatus(200);

        $html = $response->getContent();

        $this->assertDirection($locale, $html);

        // The content area is really populated — the slot-layout trap.
        $this->assertStringContainsString('Commercial register', $html, 'the document list is empty');
        $this->assertStringContainsString('name="document"', $html, 'the upload form is absent');

        $this->assertNoRawTranslationKeys($html);
    }

    /**
     * @dataProvider locales
     */
    public function test_the_operator_queue_renders_in_both_directions(string $locale): void
    {
        Storage::fake('kyc');
        $this->configureKycKinds(KycVerification::COMPANY, KycAuthority::STORE_SYRIA, ['commercial_register']);

        $operator = $this->makePlatformAdmin(['language' => $locale]);
        [$merchant, $store] = $this->kycMerchantWithStore();
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);
        KycDocumentStore::store($record, $this->kycPngUpload(), 'commercial_register', $merchant);
        KycReview::submit($record->fresh(), $merchant);

        $response = $this->actingAs($operator)->get('/platform/verification');

        $response->assertStatus(200);

        $html = $response->getContent();

        $this->assertDirection($locale, $html);
        $this->assertStringContainsString($store->name, $html, 'the queue row is missing');
        $this->assertStringContainsString('name="decision"', $html, 'the decision form is absent');
        $this->assertNoRawTranslationKeys($html);
    }

    /**
     * @dataProvider locales
     */
    public function test_the_access_log_screen_renders_in_both_directions(string $locale): void
    {
        Storage::fake('kyc');
        $this->configureKycKinds(KycVerification::COMPANY, KycAuthority::STORE_SYRIA, ['commercial_register']);

        $operator = $this->makePlatformAdmin();
        [$merchant, $store] = $this->kycMerchantWithStore();
        $merchant->language = $locale;
        $merchant->save();

        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);
        $document = KycDocumentStore::store($record, $this->kycPngUpload(), 'commercial_register', $merchant);

        // One operator read, so the screen has something to show and the "who" column is exercised.
        KycDocumentStore::readForActor($document, $operator);

        $response = $this->actingAs($merchant->fresh())
            ->get('/verification/documents/' . $document->id . '/access-log');

        $response->assertStatus(200);

        $html = $response->getContent();

        $this->assertDirection($locale, $html);
        $this->assertStringContainsString('<table', $html, 'the trail table is absent');

        /*
         * THE LABEL IS ASSERTED IN THE LOCALE'S OWN WORDS, and the first version of this assertion
         * looked for the literal `Store Syria` in both. It passed in Arabic too — the LAYOUT prints
         * the site name — so it was not measuring this screen's cell at all. The label is what the
         * merchant reads about who opened their passport scan, so it is asserted as rendered.
         */
        $expectedLabel = $locale === 'ar' ? 'ستور سوريا' : 'Store Syria';

        $this->assertStringContainsString(
            '<td>' . $expectedLabel . '</td>',
            $html,
            'an operator read must be attributed to the platform, in the reader\'s own language, '
            . 'and never to a named account'
        );

        $this->assertStringNotContainsString(
            (string) $operator->name,
            $html,
            'a compliance screen is not a staff directory'
        );
        $this->assertNoRawTranslationKeys($html);
    }

    /** The humanised fallback, and the translated key when one exists — both directions. */
    public function test_a_document_kind_never_renders_as_a_dotted_key(): void
    {
        $this->assertSame(
            'Commercial register',
            KycAuthority::kindLabel('commercial_register'),
            'with no translation the machine name must be humanised, never printed as a key'
        );

        app('translator')->addLines(['kyc.kind.commercial_register' => 'السجل التجاري'], 'ar');
        app()->setLocale('ar');

        $this->assertSame(
            'السجل التجاري',
            KycAuthority::kindLabel('commercial_register'),
            'a translation, once supplied, must win over the fallback'
        );
    }

    private function assertDirection(string $locale, string $html): void
    {
        if ($locale === 'ar') {
            $this->assertMatchesRegularExpression('/<html[^>]*dir="rtl"/', $html, 'Arabic must render RTL');

            return;
        }

        $this->assertMatchesRegularExpression('/<html[^>]*dir="ltr"/', $html, 'English must render LTR');
    }

    /**
     * No `kyc.`-prefixed translation key reached the page as text.
     *
     * Scoped to this feature's own prefix on purpose: the vendored theme has its own untranslated
     * strings and an assertion over every dotted token on the page would be measuring somebody
     * else's work.
     */
    private function assertNoRawTranslationKeys(string $html): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/kyc\.(kind|authority|status)\./',
            $html,
            'a translation key reached the page as text'
        );
    }
}
