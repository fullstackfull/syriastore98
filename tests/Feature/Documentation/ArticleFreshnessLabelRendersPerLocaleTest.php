<?php

namespace Tests\Feature\Documentation;

use App\Support\Docs\DocumentationLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GAP-108 (P03-D-05) — THE FRESHNESS LABEL, IN THE READER'S OWN LANGUAGE.
 * =================================================================================================
 *
 * The row asks that "each article carries a version or last-reviewed label so a reader knows
 * whether it still describes the shipped product". The last-reviewed half was introduced on
 * 2026-11-25 and `DocumentationArticleFreshnessTest` pins it: every file declares `updated`, the
 * library carries it, and the view renders it inside `<time datetime="...">`.
 *
 * WHAT THAT HALF DID NOT DO, AND WHAT THIS FILE MEASURES. The VISIBLE text was the ISO string
 * itself — `2026-11-25` — in both languages, and the view's own comment justified it: "a localised
 * month name would need Arabic calendar data this application does not ship". THAT IS MEASURABLY
 * FALSE IN THIS REPOSITORY. Carbon ships Arabic month names, `app/Support/TimelineMoment.php`
 * already formats dates with them, and it deliberately asks for `ar_SY` rather than `ar` because
 * the generic Arabic locale gives the transliterated Gregorian names (`نوفمبر`) while the Syrian
 * variant gives the Levantine ones a Syrian merchant actually writes (`تشرين الثاني`). Measured
 * side by side before this change:
 *
 *     en      25 Nov 2026
 *     ar      25 نوفمبر 2026
 *     ar_SY   25 تشرين الثاني 2026
 *
 * So the date on the help pages of a product called Store Syria was rendered in no language at all,
 * while the customer timeline three directories away rendered it in Levantine Arabic.
 *
 * THE `datetime` ATTRIBUTE STAYS ISO AND THE VISIBLE TEXT BECOMES LOCALISED. They are the two
 * audiences of a `<time>` element and they want different things; `DocumentationArticleFreshnessTest`
 * owns the machine-readable half and this file owns the human one.
 *
 * `dir="ltr"` IS REMOVED FROM THE ELEMENT AND THAT IS THE SUBTLE PART. It was correct for an ISO
 * date — a run of neutral characters the bidi algorithm is free to reorder inside an Arabic
 * paragraph. It is WRONG for `25 تشرين الثاني 2026`: in an LTR base direction the two Arabic words
 * form one RTL run and display in the wrong order relative to the digits. The localised value must
 * inherit the paragraph's own direction. Asserted here, and proved by rendering.
 */
class ArticleFreshnessLabelRendersPerLocaleTest extends TestCase
{
    use RefreshDatabase;

    /** An article that exists in both locales, so neither request is a fallback. */
    private const URI = '/platform/docs/getting-started/create-your-account';

    protected function setUp(): void
    {
        parent::setUp();
        DocumentationLibrary::forget();
    }

    protected function tearDown(): void
    {
        DocumentationLibrary::forget();
        parent::tearDown();
    }

    /** The `<time>` element of the page, with its attributes and its text. */
    private function timeElement(string $html): string
    {
        $this->assertSame(1, preg_match('/<time\b[^>]*>.*?<\/time>/su', $html, $m),
            'the article page renders no <time> element at all, so there is no freshness label '
            . 'to measure (GAP-108)');

        return $m[0];
    }

    /**
     * THE PREMISE. Every shipped article carries the same date today, so a test that looked for a
     * hard-coded string would keep passing after the corpus moved on. The expected text is computed
     * from the file's own front matter instead.
     */
    #[Test]
    public function the_article_under_test_declares_a_date_at_all(): void
    {
        $article = DocumentationLibrary::article('en', 'getting-started', 'create-your-account');

        $this->assertNotNull($article, 'PREMISE: the article this file drives does not exist.');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $article['updated'],
            'PREMISE: the article carries no ISO date, so there is nothing to localise.');
    }

    #[Test]
    public function the_english_page_shows_the_date_in_english(): void
    {
        $article = DocumentationLibrary::article('en', 'getting-started', 'create-your-account');
        $expected = \Carbon\Carbon::parse($article['updated'])->locale('en')->translatedFormat('d M Y');

        $html = $this->withSession(['LANGUAGE' => 'en'])->get(self::URI)->getContent();
        $time = $this->timeElement($html);

        $this->assertStringContainsString('datetime="' . $article['updated'] . '"', $time,
            'the machine-readable ISO value must survive the localisation');

        $this->assertStringContainsString($expected, $time,
            "the English page does not show the date as `{$expected}`; the <time> element is: {$time}");
    }

    #[Test]
    public function the_arabic_page_shows_the_date_in_levantine_arabic(): void
    {
        $article = DocumentationLibrary::article('ar', 'getting-started', 'create-your-account');
        $expected = \Carbon\Carbon::parse($article['updated'])->locale('ar_SY')->translatedFormat('d M Y');

        /* PREMISE: the expected string must actually be Arabic, or this test would pass against an
           English rendering. `ar_SY` falls back to `ar` for anything Carbon does not carry, and a
           future Carbon that dropped the locale entirely would fall back to English. */
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $expected,
            'PREMISE: the expected Arabic date carries no Arabic character.');

        $html = $this->withSession(['LANGUAGE' => 'ar'])->get(self::URI)->getContent();
        $time = $this->timeElement($html);

        $this->assertStringContainsString('datetime="' . $article['updated'] . '"', $time,
            'the machine-readable ISO value must survive the localisation');

        $this->assertStringContainsString($expected, $time,
            "the Arabic page does not show the date as `{$expected}`; the <time> element is: {$time}");

        /* THE DIRECTION THAT FAILS. An Arabic page that printed the ISO string would satisfy every
           assertion above except this one, because `2026-11-25` is a substring of neither. This is
           the one that fails if the localisation is reverted. */
        $this->assertStringNotContainsString(
            '>' . $article['updated'] . '<',
            $time,
            'the Arabic page shows the bare ISO date as its VISIBLE text. A reader of an Arabic '
            . 'help page is shown a machine value instead of a date in their language (GAP-108).'
        );
    }

    /**
     * The localised Arabic value must inherit the paragraph direction, not be forced to LTR.
     *
     * `25 تشرين الثاني 2026` under `dir="ltr"` puts the two Arabic words in the wrong order
     * relative to the digits; under the page's own RTL it reads correctly.
     */
    #[Test]
    public function the_localised_value_is_not_forced_left_to_right(): void
    {
        $time = $this->timeElement(
            $this->withSession(['LANGUAGE' => 'ar'])->get(self::URI)->getContent()
        );

        $this->assertStringNotContainsString('dir="ltr"', $time,
            'the <time> element forces `dir="ltr"` around a localised Arabic date. That was '
            . 'correct for an ISO date (a run of neutral characters) and is wrong for '
            . '`25 تشرين الثاني 2026`, whose Arabic words reorder against the digits under an LTR '
            . 'base direction.');
    }

    /** The label beside the date must be the reader's, in both directions. */
    #[Test]
    public function the_label_is_translated_in_both_directions(): void
    {
        $ar = $this->withSession(['LANGUAGE' => 'ar'])->get(self::URI)->getContent();
        $en = $this->withSession(['LANGUAGE' => 'en'])->get(self::URI)->getContent();

        $this->assertStringContainsString('آخر تحديث', $ar,
            'the Arabic page does not carry the Arabic "Last Updated" label.');

        /* The ABSENT direction, which is the one that can fail: `__()` returns its key when a
           translation is missing, and the key here is the English sentence. */
        $this->assertStringNotContainsString('Last Updated', $ar,
            'the English label survived onto the Arabic page, so the translation is not being '
            . 'applied.');

        $this->assertStringContainsString('Last Updated', $en);
    }
}
