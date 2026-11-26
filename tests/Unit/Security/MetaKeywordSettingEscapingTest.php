<?php

namespace Tests\Unit\Security;

use Tests\TestCase;

/**
 * GAP-118 / P03-MP-02 — `metaKeywordSetting()` interpolates its arguments into SINGLE-QUOTED HTML
 * attributes with no escaping, and all four call sites print the result with `{!! !!}`.
 * =================================================================================================
 *
 * THE SINK. `app/Helper/helper.php` builds twelve `<meta>` tags by string interpolation:
 *
 *     <meta name='title' content='$metakeyword'>
 *
 * and the four storefront layouts print it raw:
 *
 *     themes/stylique/views/front_end/layouts/app.blade.php:12
 *     themes/greentic/views/front_end/layouts/app.blade.php:12
 *     themes/techzonix/views/front_end/layouts/app.blade.php:12
 *     resources/views/front_end/layouts/app.blade.php:12      (the fallback)
 *
 *     {!! metaKeywordSetting($metakeyword ?? null,$metadesc ?? null,$metaimage ?? null,$slug) !!}
 *
 * REACHABILITY, MEASURED AND NOT OVERSTATED. The three injectable arguments are supplied by
 * NOTHING today. A census of `$metakeyword`, `$metadesc`, `$metaimage` and `$metatitle` across
 * `app/`, `resources/`, `themes/`, `packages/`, `routes/` and `database/` finds them only inside
 * this helper, inside the four `?? null` call sites (a coalesce against a variable no controller,
 * composer or `View::share` ever sets, so always null), and at
 * `AppSettingController.php:139-140`, which is a DIFFERENT `$metaimage` handed to the MERCHANT's
 * `AppSetting.index` settings form — not to a storefront layout. So every storefront page emits
 * `config('app.name')` twelve times and the stored values are never read back.
 *
 * The merchant HALF of the pipe is real and live: `POST /seo-setting`
 * (`AppSettingController::seoSettingStore`) validates and stores `metakeyword`, `metadesc` and
 * `metaimage` into `settings` under the merchant's `store_id`. The read-back half was never built.
 * GAP-118's own words: "a merchant-configurable feature is wired to nothing".
 *
 * So TODAY the escaping defect is NOT reachable by a real request. Wiring it up is Phase 03 SEO
 * scope and is deliberately NOT done here. What IS done is the half of GAP-118's next action that
 * belongs in this file — "escape the helper's output" — so that the plumbing, when it is built,
 * connects to a sink that is already safe instead of shipping a stored XSS on every storefront
 * page of every tenant.
 *
 * WHAT EACH PAYLOAD ACTUALLY DOES, pre-fix, because the three variants are not equivalent inside a
 * single-quoted attribute and claiming otherwise would be false:
 *
 *   - a SINGLE QUOTE terminates the attribute. This is the live break-out: it both injects an
 *     attribute onto the `<meta>` element itself (`' onerror='...`) and, with `'>`, closes the tag
 *     and injects whole elements. MEASURED below.
 *   - a DOUBLE QUOTE does NOT break out of a single-quoted attribute. It stays data. It is still
 *     asserted, because the fix must be ENT_QUOTES-grade rather than relying on the quoting style:
 *     the quoting style is one edit away from changing, and `e()` covers both.
 *   - `<script>` with no quote does NOT break out either: `<` and `>` inside a quoted attribute
 *     value are data to an HTML parser. Also asserted for the same reason.
 *
 * THE ORACLE IS THE DOM, not a substring search: the question is whether the payload stayed the
 * VALUE of an attribute, which is answered by parsing. The core assertion is a ROUND TRIP — the
 * `content` attribute a browser builds must equal the payload byte for byte. A break-out cannot
 * satisfy that, because the bytes that escaped the attribute are no longer in it.
 *
 * ARABIC. This product is bilingual, so an escaping fix that mangles Arabic is not a fix. The
 * Arabic case asserts the codepoints survive untouched, the output stays valid UTF-8 (the
 * condition `json_encode()` needs, which has already failed in this repository), and the
 * character COUNT is preserved under `mb_*` — never a byte count.
 *
 * CONTROL. An ordinary value must still render as itself, so the fix cannot be "escape everything
 * into uselessness".
 */
class MetaKeywordSettingEscapingTest extends TestCase
{
    /** Terminates the attribute and puts a handler on the `<meta>` element itself. GAP-118's shape. */
    private const BREAKOUT_ATTRIBUTE = "XSS-1' onerror='XSS-1";

    /** Terminates the attribute, closes the tag, injects a whole element with a handler. */
    private const BREAKOUT_ELEMENT = "XSS-2'><img src=x onerror=XSS-2>";

    /** The `"` variant. Inert inside a single-quoted attribute; must be escaped anyway. */
    private const DOUBLE_QUOTE = 'XSS-3"><img src=x onerror=XSS-3>';

    /** The `<script>` variant. Inert inside any quoted attribute; must be escaped anyway. */
    private const SCRIPT = 'XSS-4<script>alert("XSS-4")</script>';

    /** Arabic that must survive intact, carrying three characters that must not. */
    private const ARABIC = "متجر سوريا — أفضل الأسعار <هنا> 'دائماً' & أكثر";

    /** An ordinary merchant value. Must still read as itself. */
    private const CONTROL = 'Store Syria best prices';

    protected function setUp(): void
    {
        parent::setUp();

        // The helper calls route('landing_page', $slug), so the real router has to be booted.
        // Nothing here touches the database.
        $this->assertTrue(
            function_exists('metaKeywordSetting'),
            'metaKeywordSetting() is a global helper from app/Helper/helper.php; it must be autoloaded.'
        );
    }

    // -------------------------------------------------------------------------------------------
    // the oracle
    // -------------------------------------------------------------------------------------------

    /**
     * What a browser would build from the helper's output.
     *
     * @return array{content: array<string,string>, metas: int, injected: list<string>, handlers: list<string>}
     */
    private function parse(string $html): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->loadHTML(
            '<?xml encoding="UTF-8"?><html><head>' . $html . '</head><body></body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($document);

        // The `content` of every meta, keyed by its name= or property=.
        $content = [];
        $metas = 0;
        foreach ($xpath->query('//meta') as $meta) {
            $metas++;
            $key = $meta->getAttribute('name') !== ''
                ? $meta->getAttribute('name')
                : $meta->getAttribute('property');
            $content[$key] = $meta->getAttribute('content');
        }

        // Any element that is not one of the metas the helper emits: proof of a closed tag.
        $injected = [];
        foreach ($xpath->query('//head/*[not(self::meta)] | //body/*') as $element) {
            $injected[] = $element->nodeName;
        }

        // Any `on*` attribute anywhere carrying a payload marker, on the meta itself or elsewhere.
        $handlers = [];
        foreach ($xpath->query('//*[@*[starts-with(name(), "on") and contains(., "XSS-")]]') as $element) {
            foreach ($element->attributes as $attribute) {
                if (str_starts_with($attribute->name, 'on')) {
                    $handlers[] = $element->nodeName . '@' . $attribute->name . '=' . $attribute->value;
                }
            }
        }

        return ['content' => $content, 'metas' => $metas, 'injected' => $injected, 'handlers' => $handlers];
    }

    public function test_the_oracle_sees_a_break_out_when_one_is_handed_to_it(): void
    {
        // PREMISE. If the oracle cannot see the defect in markup that plainly has it, no later
        // assertion means anything.
        $raw = $this->parse("<meta name='title' content='" . self::BREAKOUT_ELEMENT . "'>");

        $this->assertSame(['img'], $raw['injected'], 'the oracle must see the element that escaped the attribute');
        $this->assertNotSame([], $raw['handlers'], 'the oracle must see the handler on it');
        $this->assertNotSame(self::BREAKOUT_ELEMENT, $raw['content']['title'] ?? null, 'a break-out cannot round-trip');

        $attribute = $this->parse("<meta name='title' content='" . self::BREAKOUT_ATTRIBUTE . "'>");
        $this->assertNotSame([], $attribute['handlers'], 'the oracle must see a handler injected onto the meta itself');
    }

    // -------------------------------------------------------------------------------------------
    // the premise: the helper is a sink at all
    // -------------------------------------------------------------------------------------------

    public function test_the_helper_emits_twelve_metas_and_puts_each_argument_in_an_attribute(): void
    {
        $parsed = $this->parse(metaKeywordSetting(self::CONTROL, 'a description', 'an image', 'a-store'));

        $this->assertSame(12, $parsed['metas'], 'the helper emits twelve meta tags');
        $this->assertSame(self::CONTROL, $parsed['content']['title']);
        $this->assertSame('a description', $parsed['content']['description']);
    }

    // -------------------------------------------------------------------------------------------
    // the defect
    // -------------------------------------------------------------------------------------------

    /** @return array<string, array{0:string}> */
    public static function payloads(): array
    {
        return [
            'single quote, attribute injection' => [self::BREAKOUT_ATTRIBUTE],
            'single quote, element injection' => [self::BREAKOUT_ELEMENT],
            'double quote' => [self::DOUBLE_QUOTE],
            'script tag' => [self::SCRIPT],
        ];
    }

    /**
     * @dataProvider payloads
     */
    public function test_a_payload_in_the_title_cannot_break_out_of_the_attribute(string $payload): void
    {
        $parsed = $this->parse(metaKeywordSetting($payload, 'd', 'i', 'a-store'));

        $this->assertSame(
            [],
            $parsed['injected'],
            'nothing may escape the attribute and become an element: got ' . implode(', ', $parsed['injected'])
        );
        $this->assertSame(
            [],
            $parsed['handlers'],
            'no element may carry an event handler built from the payload: got ' . implode(', ', $parsed['handlers'])
        );
        $this->assertSame(12, $parsed['metas'], 'the twelve metas must still be twelve metas');
        $this->assertSame(
            $payload,
            $parsed['content']['title'] ?? null,
            'the payload must survive as the VALUE of the attribute, byte for byte'
        );
    }

    /**
     * @dataProvider payloads
     */
    public function test_a_payload_in_the_description_cannot_break_out_either(string $payload): void
    {
        $parsed = $this->parse(metaKeywordSetting('k', $payload, 'i', 'a-store'));

        $this->assertSame([], $parsed['injected']);
        $this->assertSame([], $parsed['handlers']);
        $this->assertSame($payload, $parsed['content']['description'] ?? null);
    }

    /**
     * @dataProvider payloads
     */
    public function test_a_payload_in_the_image_cannot_break_out_either(string $payload): void
    {
        $parsed = $this->parse(metaKeywordSetting('k', 'd', $payload, 'a-store'));

        $this->assertSame([], $parsed['injected']);
        $this->assertSame([], $parsed['handlers']);
        // og:image and twitter:image carry a pre-existing leading space in the source; assert on
        // the trimmed value so this test pins the ESCAPING and not that cosmetic defect.
        $this->assertSame($payload, ltrim((string) ($parsed['content']['og:image'] ?? ''), ' '));
    }

    public function test_the_raw_output_encodes_both_quote_characters(): void
    {
        // ENT_QUOTES, not ENT_COMPAT: a single quote is the character that actually terminates
        // these attributes, and a value one edit away from a double-quoted attribute must be safe
        // too. Asserted on the bytes, because the DOM decodes entities away.
        $html = metaKeywordSetting("it's", 'say "hi"', 'i', 'a-store');

        // ANCHORED TO THE PAYLOAD, deliberately. A first draft asserted the markup contained no
        // `'s` at all and failed against the CORRECT output: the markup is full of legitimate
        // apostrophes -- they are the attribute delimiters -- so `content='say …` and
        // `content='summary_large_image'` both matched. The question is only ever whether the
        // PAYLOAD's own quotes survived raw.
        $this->assertStringNotContainsString("it's", $html, "the payload's raw apostrophe must not survive");
        $this->assertStringNotContainsString('"hi"', $html, "the payload's raw double quote must not survive");
        $this->assertStringContainsString("it&#039;s", $html, 'the apostrophe must be encoded, ENT_QUOTES');
        $this->assertStringContainsString('&quot;hi&quot;', $html, 'the double quote must be encoded too');
    }

    // -------------------------------------------------------------------------------------------
    // Arabic must survive
    // -------------------------------------------------------------------------------------------

    public function test_arabic_survives_the_escaping_intact(): void
    {
        $html = metaKeywordSetting(self::ARABIC, self::ARABIC, 'i', 'a-store');
        $parsed = $this->parse($html);

        $this->assertSame(
            self::ARABIC,
            $parsed['content']['title'] ?? null,
            'every Arabic codepoint must come back, and only < > & \' may have been encoded on the way'
        );

        // Nothing was cut by BYTES: the character count is preserved.
        $this->assertSame(
            mb_strlen(self::ARABIC, 'UTF-8'),
            mb_strlen((string) ($parsed['content']['title'] ?? ''), 'UTF-8'),
            'the character count must be preserved -- never truncate a UTF-8 string by bytes'
        );

        // The Arabic is still literal Arabic in the markup, not numeric entities.
        $this->assertStringContainsString('متجر سوريا', $html, 'Arabic must not be entity-encoded');

        // Valid UTF-8 throughout: this is the condition json_encode() needs, and a byte-level
        // escape (or htmlspecialchars without ENT_SUBSTITUTE) is how it has failed here before.
        $this->assertTrue(mb_check_encoding($html, 'UTF-8'), 'the whole output must stay valid UTF-8');
        $this->assertNotFalse(json_encode($html), 'the output must be json_encode-able: ' . json_last_error_msg());

        // And the dangerous characters that were in the Arabic payload DID change.
        $this->assertStringNotContainsString('<هنا>', $html, 'the angle brackets inside the Arabic must be encoded');
        $this->assertStringContainsString('&lt;هنا&gt;', $html);
    }

    public function test_invalid_utf8_does_not_blank_the_whole_tag(): void
    {
        // htmlspecialchars WITHOUT ENT_SUBSTITUTE returns '' for a string that is not valid UTF-8,
        // which would silently empty the attribute. `e()` passes ENT_SUBSTITUTE, so the bad byte
        // becomes U+FFFD and the rest of the value survives.
        $parsed = $this->parse(metaKeywordSetting("قبل \xB1 بعد", 'd', 'i', 'a-store'));

        $title = (string) ($parsed['content']['title'] ?? '');
        $this->assertNotSame('', $title, 'an invalid byte must not blank the attribute');
        $this->assertStringContainsString('قبل', $title);
        $this->assertStringContainsString('بعد', $title);
    }

    // -------------------------------------------------------------------------------------------
    // the control
    // -------------------------------------------------------------------------------------------

    public function test_an_ordinary_value_still_renders_as_itself(): void
    {
        $html = metaKeywordSetting(self::CONTROL, 'Shop the catalogue', 'logo.png', 'a-store');

        $this->assertStringContainsString(
            "content='" . self::CONTROL . "'",
            $html,
            'an ordinary value must appear verbatim -- the fix is escaping, not mangling'
        );
        $this->assertStringContainsString("content='Shop the catalogue'", $html);
        $this->assertStringNotContainsString('&amp;amp;', $html, 'nothing may be double-encoded');
    }

    public function test_the_defaults_and_the_url_are_unchanged(): void
    {
        // Signature and fall-backs must not move: other code may call this.
        $html = metaKeywordSetting(null, null, null, 'a-store');

        $this->assertStringContainsString("content='" . config('app.name') . "'", $html);
        $this->assertStringContainsString(route('landing_page', 'a-store'), $html);
        $this->assertIsString($html);
    }

    // -------------------------------------------------------------------------------------------
    // the second defect in the same statement: the format string
    // -------------------------------------------------------------------------------------------

    public function test_a_percent_in_a_value_does_not_throw(): void
    {
        // `return sprintf($script);` passes the fully-interpolated markup as the FORMAT argument
        // with no arguments after it, so any `%` directive in a value is read as a conversion
        // specification. PHP 8 raises ArgumentCountError / ValueError rather than ignoring it.
        //
        // This one is reachable TODAY, unlike the escaping: `$url = route('landing_page', $slug)`
        // percent-encodes the slug, which real storefront controllers do supply.
        $html = metaKeywordSetting('50% off', 'up to 20%s', 'i', 'a-store');

        $this->assertStringContainsString('50% off', $html, 'a percent sign is data, not a format directive');
        $this->assertStringContainsString('20%s', $html);
    }

    public function test_a_percent_encoded_slug_does_not_throw(): void
    {
        // route() renders a slug containing `<` as `%3C`; `%3E` and `%27` arrive the same way.
        // Each is a `%` followed by characters sprintf() reads as a width and a specifier.
        $html = metaKeywordSetting('k', 'd', 'i', 'a<b>c,d');

        $this->assertIsString($html);
        $this->assertSame(12, $this->parse($html)['metas']);
    }

    public function test_an_arabic_store_slug_does_not_throw(): void
    {
        // THE REACHABLE ONE, and it is an Arabic defect. `User::slugs()` strips everything outside
        // `[\p{L}\p{N}\s-]` with the `u` modifier, so Arabic LETTERS are kept: an Arabic store
        // name yields an Arabic slug. `route('landing_page', 'متجر-سوريا')` percent-encodes it to
        //
        //     http://localhost/%D9%85%D8%AA%D8%AC%D8%B1-%D8%B3%D9%88%D8%B1%D9%8A%D8%A7/home
        //
        // and `sprintf($script)` read those pairs as conversion specifications:
        // `ArgumentCountError: 37 arguments are required, 1 given`. The four layouts call this
        // helper at line 12, inside `<head>`, so that is HTTP 500 on every storefront page of an
        // Arabic-named store -- with no merchant SEO value needed, because `$slug` IS supplied.
        // The shorter name `مطعم` gave `17 arguments are required, 1 given`.
        $slug = 'متجر-سوريا';

        $url = route('landing_page', $slug);
        $this->assertStringContainsString('%D9%85', $url, 'the premise: route() percent-encodes an Arabic slug');

        $html = metaKeywordSetting(null, null, null, $slug);
        $parsed = $this->parse($html);

        $this->assertSame(12, $parsed['metas']);
        $this->assertSame($url, $parsed['content']['og:url'] ?? null, 'the URL must survive intact');
        $this->assertStringContainsString($url, $html);

        foreach (['مطعم', 'ملابس-وأحذية', 'store-syria'] as $other) {
            $this->assertIsString(
                metaKeywordSetting(null, null, null, $other),
                "a store slug of '{$other}' must render rather than throw"
            );
        }
    }
}
