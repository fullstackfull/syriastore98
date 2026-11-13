<?php

namespace Tests\Unit\Theme;

use App\Support\Theme\ThemeFieldCatalog;
use App\Support\Theme\ThemeSettingValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M3-C — TYPE-AWARE VALIDATION: WHAT IT REFUSES, AND WHAT IT MUST NOT TOUCH.
 *
 * TWO FAILURE MODES, AND THE SECOND IS THE EASY ONE TO SHIP. The obvious one is accepting a
 * `<script>` block. The other is CORRUPTING LEGITIMATE COPY, which is exactly what the existing
 * middleware does: `App\Http\Middleware\XSS` deletes `javascript` and `alert` as case-sensitive
 * substrings, so `Smart alerts for shoppers` is stored as `Smart s for shoppers` while
 * `Alert & Co <b>Bold</b>` passes untouched (measured; tracked as S-09 in
 * `docs/discovery/11-RELEASE-READINESS.md`). A validator that repeated that would be a regression
 * dressed as a security control, so the "does not corrupt" half is asserted as hard as the
 * "refuses" half.
 *
 * REQUIREMENT 8 IS LOAD-BEARING HERE. An unknown field has no declared type, so there is no rule
 * that could apply and no safe default — including "store it unchanged", which is the audited hole
 * (`docs/discovery/03-FEATURE-MATRIX.md:223`, HIGH/P0). The refusal must happen BEFORE any dispatch
 * on type.
 */
class ThemeSettingValidatorTest extends TestCase
{
    private ThemeSettingValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ThemeSettingValidator();
    }

    /* ================================================== requirement 8: unknown fields fail closed */

    #[Test]
    public function test_a_field_no_theme_declares_is_refused(): void
    {
        $out = $this->validator->validate('stylique', ['not_a_real_setting' => 'anything']);

        $this->assertSame([], $out, 'An undeclared field was accepted.');
        $this->assertArrayHasKey('not_a_real_setting', $this->validator->errors());
        $this->assertFalse($this->validator->passed());
    }

    #[Test]
    public function test_an_unknown_theme_accepts_nothing_at_all(): void
    {
        $out = $this->validator->validate('no-such-theme', ['site_title' => 'Shop']);

        $this->assertSame([], $out,
            'An unknown theme must accept NOTHING. ThemeFieldCatalog returns an empty allowlist for '
            . 'it, and a caller that read empty as "no opinion" would accept everything.');
        $this->assertStringContainsString('declares no editable fields',
            $this->validator->errors()['site_title'] ?? '');
    }

    #[Test]
    public function test_the_assert_helper_throws_rather_than_returning_false(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/BEFORE dispatching on its type/');

        ThemeSettingValidator::assertTypeIsKnown('stylique', 'not_a_real_setting');
    }

    #[Test]
    public function test_the_assert_helper_returns_the_type_for_a_real_field(): void
    {
        $this->assertSame('textarea',
            ThemeSettingValidator::assertTypeIsKnown('stylique', 'footer_description'));
    }

    /* ============================================ ordinary merchant copy is stored, not mangled */

    /**
     * Every one of these is legitimate shop copy. The existing middleware damages the first two.
     */
    #[Test]
    #[DataProvider('legitimateCopyProvider')]
    public function test_legitimate_merchant_copy_is_accepted_verbatim(string $value, string $why): void
    {
        $out = $this->validator->validate('stylique', ['footer_description' => $value]);

        $this->assertSame([], $this->validator->errors(),
            "Refused legitimate copy ({$why}): " . json_encode($this->validator->errors()));
        $this->assertSame($value, $out['footer_description'] ?? null,
            "The value was ALTERED ({$why}). This validator refuses or accepts; it never rewrites. "
            . 'Silent mutation is what S-09 is.');
    }

    public static function legitimateCopyProvider(): array
    {
        return [
            'the word alert'        => ['Smart alerts for shoppers', 'XSS.php deletes "alert" as a substring'],
            'the word javascript'   => ['We teach javascript to kids', 'XSS.php deletes "javascript" too'],
            'ampersand and quotes'  => ['Tom & Jerry\'s "Best" Shop', 'ordinary punctuation'],
            'arabic'                => ['متجر دمشق للمنتجات الطازجة', 'the storefront ships Arabic'],
            'arabic with latin'     => ['متجر Damascus — fresh produce', 'mixed script and an em dash'],
            'emoji'                 => ['Fresh produce 🥕 daily', 'merchants use emoji'],
            'a url'                 => ['Visit https://example.test/shop?a=1&b=2', 'a plain https link'],
            'a price'               => ['From $4.50 — 20% off', 'currency and percent'],
            'newlines'              => ["Line one\nLine two\n\nLine four", 'a textarea holds newlines'],
            'angle brackets as text'=> ['Sizes < 10 and > 2 available', 'comparison operators, not tags'],
            'the word script'       => ['Our script for great service', '"script" as a word, not a tag'],
            'an html entity'        => ['Caf&eacute; &amp; Bar', 'already-encoded entities'],
        ];
    }

    /* ======================================================== and genuine markup is REFUSED */

    #[Test]
    #[DataProvider('refusedMarkupProvider')]
    public function test_executable_markup_is_refused(string $value, string $why): void
    {
        $out = $this->validator->validate('stylique', ['footer_description' => $value]);

        $this->assertArrayHasKey('footer_description', $this->validator->errors(),
            "Accepted a value that {$why}: " . var_export($value, true)
            . '. These 43 settings reach the storefront through unescaped {!! !!} output.');
        $this->assertSame([], $out);
    }

    public static function refusedMarkupProvider(): array
    {
        return [
            'script tag'          => ['<script>fetch("/steal")</script>', 'opens a script tag'],
            'script uppercase'    => ['<SCRIPT>x()</SCRIPT>', 'opens a script tag in capitals'],
            'script spaced'       => ['< script >x()</ script >', 'opens a spaced script tag'],
            'closing script only' => ['</script><script>x()</script>', 'closes then opens a script tag'],
            'iframe'              => ['<iframe src="//evil.test"></iframe>', 'embeds a frame'],
            'object'              => ['<object data="x.swf"></object>', 'embeds an object'],
            'svg'                 => ['<svg onload="x()"></svg>', 'embeds svg with a handler'],
            'style'               => ['<style>body{display:none}</style>', 'injects a stylesheet'],
            'onerror attribute'   => ['<img src=x onerror="fetch(1)">', 'carries an inline handler'],
            'onclick attribute'   => ['<div onclick="x()">click</div>', 'carries an inline handler'],
            'javascript scheme'   => ['<a href="javascript:x()">go</a>', 'uses the javascript: scheme'],
            'scheme bare'         => ['javascript:alert(1)', 'is a javascript: URL'],
            'scheme spaced'       => ['javascript : x()', 'is a spaced javascript: URL'],
            'vbscript scheme'     => ['vbscript:msgbox(1)', 'uses the vbscript: scheme'],
            'data scheme'         => ['data:text/html;base64,PHNjcmlwdD4=', 'uses a data: URL'],
            'nul byte'            => ["safe\x00<script>", 'contains a NUL byte'],
            'form'                => ['<form action="//evil.test"><input name=p></form>', 'injects a form'],
            'meta refresh'        => ['<meta http-equiv="refresh" content="0;url=//evil.test">', 'injects a redirect'],
        ];
    }

    /* ======================================================================= per-type rules */

    #[Test]
    public function test_a_switch_accepts_only_its_declared_values(): void
    {
        $ok = $this->validator->validate('stylique', ['footer_status' => '1']);
        $this->assertSame(['footer_status' => '1'], $ok);

        $bad = $this->validator->validate('stylique', ['footer_status' => 'yes please']);
        $this->assertArrayHasKey('footer_status', $this->validator->errors());
        $this->assertSame([], $bad);
    }

    #[Test]
    #[DataProvider('imagePathProvider')]
    public function test_an_image_is_a_stored_path(string $value, bool $accepted): void
    {
        $out = $this->validator->validate('stylique', ['footer_logo' => $value]);

        $accepted
            ? $this->assertSame([], $this->validator->errors(), "Refused {$value}")
            : $this->assertArrayHasKey('footer_logo', $this->validator->errors(), "Accepted {$value}");
    }

    public static function imagePathProvider(): array
    {
        return [
            'a real stored path' => ['themes/stylique/assets/images/footer-logo.png', true],
            'an upload path'     => ['uploads/4/p3fixt/logo.png', true],
            'empty clears it'    => ['', true],
            'traversal'          => ['../../.env', false],
            'traversal deeper'   => ['uploads/4/../../../.env', false],
            'a javascript url'   => ['javascript:x()', false],
            'a remote url'       => ['https://evil.test/x.png', false],
            'a space'            => ['uploads/4/my logo.png', false],
        ];
    }

    #[Test]
    public function test_a_repeater_must_be_valid_json_and_is_stored_verbatim(): void
    {
        $valid = '[{"link":"https://x.com/shop","icon":"fab fa-x-twitter"}]';
        $out = $this->validator->validate('stylique', ['footer_repeater' => $valid]);

        $this->assertSame([], $this->validator->errors());
        $this->assertSame($valid, $out['footer_repeater'],
            'A repeater must be stored byte-exactly; the storefront json_decode()s it at render.');

        $this->validator->validate('stylique', ['footer_repeater' => '[{"link": broken']);
        $this->assertArrayHasKey('footer_repeater', $this->validator->errors());
    }

    #[Test]
    public function test_a_repeater_carrying_markup_in_a_leaf_is_refused(): void
    {
        $hostile = json_encode([['link' => 'javascript:fetch(1)', 'icon' => 'fa-x']]);

        $this->validator->validate('stylique', ['footer_repeater' => $hostile]);

        $this->assertArrayHasKey('footer_repeater', $this->validator->errors(),
            'A repeater is decoded and its leaf strings checked, because the storefront renders '
            . 'them. Checking only the outer string would let a hostile link through.');
    }

    /* ============================================================ partial acceptance is explicit */

    #[Test]
    public function test_a_mixed_write_accepts_the_good_and_names_the_bad(): void
    {
        /*
         * `blog_title` and `footer_status` are declared; `site_title` deliberately is NOT.
         * MEASURED: of the 12 setting names the development fixture stores, TEN are declared by
         * theme_json and two -- `site_title` and `logo` -- are not. They are read by views and have
         * defaults, but the editor does not offer them, so a write to one is refused. That is the
         * editable-versus-readable gap M3-A measured, and it is asserted here rather than papered
         * over: a field the form cannot render is a field the form cannot save.
         */
        $out = $this->validator->validate('stylique', [
            'blog_title'         => 'Damascus Fresh',
            'footer_description' => '<script>x()</script>',
            'not_a_field'        => 'whatever',
            'site_title'         => 'readable but not editable',
            'footer_status'      => '1',
        ]);

        $this->assertSame(['blog_title' => 'Damascus Fresh', 'footer_status' => '1'], $out,
            'The acceptable fields should be returned, canonically ordered.');
        $this->assertSame(['footer_description', 'not_a_field', 'site_title'],
            array_keys($this->validator->errors()));
    }

    /* ================================================== every declared type has a rule, measured */

    /**
     * If a theme ever declares a type this validator has no rule for, the `default` arm returns a
     * refusal naming it — but the real protection is noticing before that happens.
     */
    #[Test]
    public function test_every_type_the_shipped_themes_declare_has_a_rule(): void
    {
        $seen = [];

        foreach (ThemeFieldCatalog::themes() as $theme) {
            foreach (ThemeFieldCatalog::editableFields($theme) as $type) {
                $seen[$type] = true;
            }
        }

        $this->assertNotSame([], $seen, 'No types were discovered; this assertion is vacuous.');

        $this->assertSame([], array_diff(array_keys($seen), ThemeFieldCatalog::KNOWN_TYPES),
            'A shipped theme declares a type outside ThemeFieldCatalog::KNOWN_TYPES, so this '
            . 'validator has no rule for it and would refuse every value of that type. Add a rule '
            . 'and decide whether that type can reach an unescaped output before allowing it.');
    }
}
