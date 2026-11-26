<?php

namespace Tests\Unit\Ui;

use App\Support\Docs\DocumentationLibrary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * GAP-108 (P03-D-05) — THE RELEASE LABEL: THE PLUMBING, AND WHY THE VALUE IS NOT INVENTED.
 * =================================================================================================
 *
 * The gap, verbatim: "A reader cannot tell how old an article is OR WHICH RELEASE IT DESCRIBES."
 * Its next action offers the choice: "add a VERSION or last-reviewed field". The last-reviewed half
 * shipped on 2026-11-25 and `DocumentationArticleFreshnessTest` pins it. This file is the other
 * clause, and the honest answer to it is in two parts.
 *
 * PART ONE, MEASURED: THERE IS NO RELEASE IDENTIFIER IN THIS APPLICATION TO PRINT. `composer.json`
 * carries no `version` key, `config/app.php` carries none, and nothing in the repository numbers a
 * release. `test_this_application_still_carries_no_release_identifier` re-measures that here rather
 * than quoting the register, because the whole argument rests on it and it is the kind of fact that
 * changes without anyone revisiting a help page. A release label would therefore have had to be
 * INVENTED, and an invented release number on a help article is worse than none: it looks
 * authoritative and means nothing. Whether the product has releases to name is an owner decision.
 *
 * PART TWO: THE PLUMBING IS BUILT ANYWAY, SO THE OWNER'S ANSWER IS A FRONT-MATTER EDIT. The key
 * parses, it is carried to the view, it renders when an article declares it and renders nothing
 * when none does.
 *
 * THE TESTS ARE DRIVEN BY FIXTURES AND NOT BY THE CORPUS, AND THAT IS THE SAME TRAP GAP-109 HIT.
 * No shipped article declares a version, so a test that iterated the corpus looking for one would
 * iterate an empty set, pass, and keep passing on the day the parse broke. The fixtures below write
 * real files into the real tree, read them through the real parser and remove them again, so the
 * mechanism is proved independently of whether anything uses it yet. The corpus is then measured
 * separately — as a COUNT with a message, not as a requirement.
 */
class DocumentationReleaseLabelTest extends TestCase
{
    use StripsSourceComments;

    private const KEY = 'version';

    private const VIEW = 'resources/views/platform/docs/article.blade.php';

    /** Fixtures are written here and removed in `finally`; the name is distinctive on purpose. */
    private const FIXTURE_DIR = 'resources/docs/en/01-getting-started';

    private const FIXTURE_FILE = '98-release-label-fixture.md';

    protected function setUp(): void
    {
        parent::setUp();
        DocumentationLibrary::forget();
    }

    protected function tearDown(): void
    {
        /* A leaked fixture breaks `DocumentationLibraryTest::the_two_languages_carry_the_same_articles`
           in a way that reads as somebody else's fault, so the cleanup is belt and braces. */
        @unlink(base_path(self::FIXTURE_DIR . '/' . self::FIXTURE_FILE));
        DocumentationLibrary::forget();

        parent::tearDown();
    }

    /** Writes a fixture article with the given front-matter lines and returns the parsed article. */
    private function fixtureArticle(string $frontMatter): array
    {
        $path = base_path(self::FIXTURE_DIR . '/' . self::FIXTURE_FILE);

        file_put_contents($path, "---\ntitle: Release Label Fixture\norder: 98\nsummary: A fixture.\n"
            . $frontMatter . "\n---\n\n## Fixture Heading\n\nFixture body.\n");

        DocumentationLibrary::forget();

        $article = DocumentationLibrary::article('en', 'getting-started', 'release-label-fixture');

        $this->assertNotNull($article,
            'PREMISE: the fixture article did not parse at all, so nothing below is measuring the '
            . 'release label.');

        return $article;
    }

    /**
     * THE MEASUREMENT THE WHOLE DISPOSITION RESTS ON.
     *
     * If this ever fails, the application HAS acquired a release identifier and the owner question
     * has an answer: the corpus can start declaring one.
     */
    #[Test]
    public function test_this_application_still_carries_no_release_identifier(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

        $this->assertIsArray($composer, 'PREMISE: composer.json did not parse.');
        $this->assertArrayNotHasKey('version', $composer,
            'composer.json now declares a version. The reason no article carries a release label '
            . 'was that this application had no release identifier to print; it now has one, so '
            . 'GAP-108\'s release clause can be closed with a real value instead of an owner '
            . 'question.');

        $this->assertNull(config('app.version'),
            'config/app.php now declares a version — see the message above.');
    }

    #[Test]
    public function test_the_library_parses_a_declared_release_label(): void
    {
        $article = $this->fixtureArticle('version: 2026.11');

        $this->assertSame('2026.11', $article[self::KEY],
            'the library does not carry a declared `' . self::KEY . '` through to the view, so the '
            . 'release clause of GAP-108 has no plumbing at all.');
    }

    #[Test]
    public function test_an_article_that_declares_no_release_label_carries_an_empty_one(): void
    {
        $article = $this->fixtureArticle('related:');

        $this->assertArrayHasKey(self::KEY, $article,
            'the key must always be present so the view can guard on it without `??`.');
        $this->assertSame('', $article[self::KEY]);
    }

    /** It is printed into the article chrome, where a long value is a wall of text. */
    #[Test]
    public function test_a_long_release_label_is_capped_rather_than_printed_whole(): void
    {
        $article = $this->fixtureArticle('version: ' . str_repeat('9', 300));

        $this->assertSame(40, mb_strlen($article[self::KEY]),
            'an unbounded release label would be printed verbatim into the page chrome.');
    }

    #[Test]
    public function test_surrounding_whitespace_is_trimmed(): void
    {
        $article = $this->fixtureArticle('version:    2026.11   ');

        $this->assertSame('2026.11', $article[self::KEY]);
    }

    /**
     * THE RENDERED SIDE, at the level of the template.
     *
     * Comments stripped: this change added a long Blade comment to that view which names the key,
     * the label and `dir="ltr"` several times. CLAUDE.md records six censuses satisfied by prose
     * about the thing they measure.
     */
    #[Test]
    public function test_the_article_view_renders_a_declared_release_label(): void
    {
        $source = $this->sourceWithoutComments(self::VIEW);

        $this->assertMatchesRegularExpression(
            "/@if\s*\(\s*\(\\\$article\['" . self::KEY . "'\]\s*\?\?\s*''\)\s*!==\s*''\s*\)/",
            $source,
            'the view does not guard the release label on an empty value, so every article would '
            . 'print an empty label.'
        );

        $this->assertStringContainsString("{{ \$article['" . self::KEY . "'] }}", $source,
            'the view never prints the article\'s own ' . self::KEY . ' value.');

        $this->assertStringContainsString("__('Applies to version')", $source,
            'the release label is printed with no label, so a reader sees a bare token.');
    }

    /**
     * A RELEASE TOKEN IS FORCED LTR AND THE LOCALISED DATE IS NOT — the opposite choices, for
     * opposite reasons, three lines apart.
     *
     * `2026.11 (beta)` is a machine token with no language: under an RTL base direction its
     * brackets mirror. `25 تشرين الثاني 2026` is Arabic text: under an LTR base direction its words
     * reorder. Getting these the same way round would break one of them, so both are pinned.
     */
    #[Test]
    public function test_the_release_token_is_isolated_left_to_right_and_the_date_is_not(): void
    {
        $source = $this->sourceWithoutComments(self::VIEW);

        $this->assertMatchesRegularExpression(
            '/<span dir="ltr">\{\{ \$article\[\'' . self::KEY . '\'\] \}\}<\/span>/',
            $source,
            'the release token is not wrapped in an LTR span, so its punctuation mirrors on the '
            . 'Arabic page.'
        );

        $this->assertSame(1, preg_match('/<time\b[^>]*>/', $source, $m),
            'the view no longer renders exactly one <time> element.');

        $this->assertStringNotContainsString('dir="ltr"', $m[0],
            'the <time> element forces LTR around a value that is now localised Arabic text.');

        $this->assertStringContainsString('<bdi>', $source,
            'the localised date is not isolated. Without <bdi> the date and the label beside it '
            . 'can reorder against each other in a bidirectional paragraph.');
    }

    /**
     * THE CORPUS, MEASURED RATHER THAN REQUIRED — and the parity rule that starts to bite the
     * moment anybody declares one.
     *
     * This deliberately does NOT assert that every article carries a version. That is the owner
     * question. What it does assert is that the two locales AGREE: a release label declared on the
     * English file and not on its Arabic twin would tell two readers two different things about the
     * same article.
     *
     * @param array{0: string} unused
     */
    #[Test]
    public function test_the_two_locales_declare_the_same_release_labels(): void
    {
        $declared = [];

        foreach (DocumentationLibrary::LOCALES as $locale) {
            foreach (glob(base_path(DocumentationLibrary::ROOT . '/' . $locale . '/*'), GLOB_ONLYDIR) ?: [] as $dir) {
                foreach (glob($dir . '/*.md') ?: [] as $file) {
                    if (basename($file) === '_category.md') {
                        continue;
                    }

                    $meta = DocumentationLibrary::readFrontMatter($file);
                    $id   = basename($dir) . '/' . basename($file);

                    $declared[$id][$locale] = trim((string) ($meta[self::KEY] ?? ''));
                }
            }
        }

        $this->assertGreaterThan(10, count($declared), 'PREMISE: the census found almost nothing.');

        $disagreeing = [];
        $withLabel   = 0;

        foreach ($declared as $id => $byLocale) {
            if (count(array_unique($byLocale)) > 1) {
                $disagreeing[] = $id . ' — ' . json_encode($byLocale, JSON_UNESCAPED_UNICODE);
            }
            if (($byLocale['en'] ?? '') !== '') {
                $withLabel++;
            }
        }

        $this->assertSame([], $disagreeing,
            "An article declares a release label in one language and a different one (or none) in "
            . "the other:\n  " . implode("\n  ", $disagreeing));

        /* The count is the record, not a requirement. It is 0 today because the owner has not been
           asked whether the product has releases to name; if it ever stops being 0, the parity
           assertion above becomes the live control and this message says why it is here. */
        $this->assertSame(0, $withLabel,
            "{$withLabel} article(s) now declare a release label. That is not a defect — it means "
            . 'the owner has answered GAP-108\'s release question. Update this expectation and the '
            . 'register row, and keep the parity assertion above.');
    }
}
