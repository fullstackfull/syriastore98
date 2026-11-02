<?php

namespace Tests\Unit\Merchant;

use PHPUnit\Framework\TestCase;

/**
 * P2-03-4. Arabic is the platform's primary language, and it was the one locale with no
 * `validation.php`.
 * =======================================================================================
 *
 * MEASURED, not inferred. Driving the real product edit page with `lang=ar` and an empty name
 * rendered `"The name field is required."` at the field, while the five hand-written custom
 * messages in `ProductController::update()` rendered in Arabic. The difference is where each
 * comes from: the custom ones go through `__()` and resolve from `ar.json`, Laravel's built-in
 * rule messages resolve from `lang/<locale>/validation.php` -- and `resources/lang/ar/` held
 * only `installer_messages.php` and `myfatoorah.php`. So EVERY built-in rule (`required`,
 * `numeric`, `min`, `max`, `lte`, `mimes`, ...) was English for an Arabic merchant, on every
 * form in the product.
 *
 * WHY A TEST AND NOT JUST THE TRANSLATION. Two ways a translation file silently fails:
 *
 *   1. A MISSING KEY falls back to English with no error, which is exactly the state this test
 *      was written to end -- so adding a rule to `en` without adding it to `ar` must fail here,
 *      not be discovered by a merchant.
 *   2. A DROPPED PLACEHOLDER renders a message with the value missing: `':attribute' must be at
 *      least ':min'` translated without `:min` reads "must be at least" and tells the merchant
 *      nothing. The live probe asserts no LITERAL `:placeholder` leaks into the page, which is
 *      the opposite failure and would not catch this one.
 *
 * So this compares the two files key-for-key and placeholder-set-for-placeholder-set. It is a
 * pure file comparison with no framework boot, hence `PHPUnit\Framework\TestCase`.
 *
 * `attributes` IS DELIBERATELY EXEMPT. `en`'s is an empty array and so is `ar`'s: translating
 * field names there changes every form in the platform at once, which is not this slice's to
 * do. The exemption is asserted rather than assumed, so a future `en` entry fails the test and
 * somebody reads this paragraph.
 */
class ArabicValidationMessagesTest extends TestCase
{
    /** @return array<string,string> flattened dot-notation leaf messages */
    private static function flatten(array $arr, string $prefix = ''): array
    {
        $flat = [];
        foreach ($arr as $key => $value) {
            $dotted = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $flat += self::flatten($value, $dotted);
            } else {
                $flat[$dotted] = (string) $value;
            }
        }

        return $flat;
    }

    /** @return list<string> the distinct `:placeholder` tokens in a message, sorted */
    private static function placeholders(string $message): array
    {
        preg_match_all('/:[a-z_]+/', $message, $m);
        $found = array_values(array_unique($m[0]));
        sort($found);

        return $found;
    }

    private static function langPath(string $locale, string $file): string
    {
        return dirname(__DIR__, 3) . "/resources/lang/{$locale}/{$file}.php";
    }

    /** @return array<string,string> */
    private static function load(string $locale, string $file): array
    {
        $path = self::langPath($locale, $file);
        self::assertFileExists($path, "{$locale}/{$file}.php is missing");

        /** @var array<string,mixed> $data */
        $data = require $path;
        self::assertIsArray($data, "{$locale}/{$file}.php must return an array");

        return self::flatten($data);
    }

    /**
     * The four files `en` ships that `ar` did not. Each is listed so that a fifth added to `en`
     * is a deliberate decision rather than a silent English fallback.
     *
     * `installer_messages.php` and `myfatoorah.php` are NOT here: `ar` already had both, and
     * they are vendor/installer surfaces rather than the merchant experience.
     */
    public static function localeFiles(): array
    {
        return [
            'validation' => ['validation'],
            'auth' => ['auth'],
            'passwords' => ['passwords'],
            'pagination' => ['pagination'],
        ];
    }

    /**
     * @dataProvider localeFiles
     */
    public function test_arabic_translates_every_message_english_defines(string $file): void
    {
        $en = self::load('en', $file);
        $ar = self::load('ar', $file);

        $this->assertNotEmpty($en, "premise: en/{$file}.php has messages to compare against");

        $missing = array_keys(array_diff_key($en, $ar));
        $this->assertSame([], $missing,
            "en/{$file}.php defines messages with no Arabic translation, so these fall back to "
            . "English for an Arabic merchant:\n  " . implode("\n  ", $missing));
    }

    /**
     * @dataProvider localeFiles
     */
    public function test_arabic_defines_no_message_english_does_not(string $file): void
    {
        $en = self::load('en', $file);
        $ar = self::load('ar', $file);

        $extra = array_keys(array_diff_key($ar, $en));
        $this->assertSame([], $extra,
            "ar/{$file}.php defines keys `en` does not -- either a typo in a key name (which "
            . "means the real key is still untranslated) or a rule `en` should carry too:\n  "
            . implode("\n  ", $extra));
    }

    /**
     * @dataProvider localeFiles
     */
    public function test_every_arabic_message_keeps_the_english_placeholders(string $file): void
    {
        $en = self::load('en', $file);
        $ar = self::load('ar', $file);

        $problems = [];
        foreach ($en as $key => $english) {
            if (! array_key_exists($key, $ar)) {
                continue;   // the previous test owns that failure; don't double-report it
            }

            $want = self::placeholders($english);
            $have = self::placeholders($ar[$key]);

            if ($want !== $have) {
                $problems[] = sprintf(
                    '%s: en has [%s], ar has [%s]  --  ar: "%s"',
                    $key,
                    implode(' ', $want) ?: '-',
                    implode(' ', $have) ?: '-',
                    $ar[$key]
                );
            }
        }

        $this->assertSame([], $problems,
            "A placeholder present in `en` and absent in `ar` renders the message without its "
            . "value, and one present only in `ar` renders a literal `:token` to the merchant:\n  "
            . implode("\n  ", $problems));
    }

    /**
     * The `attributes` exemption, asserted rather than trusted -- see the class docblock.
     */
    public function test_the_attributes_map_is_empty_in_both_locales(): void
    {
        /** @var array<string,mixed> $en */
        $en = require self::langPath('en', 'validation');
        /** @var array<string,mixed> $ar */
        $ar = require self::langPath('ar', 'validation');

        $this->assertSame([], $en['attributes'] ?? null,
            'en/validation.php `attributes` is no longer empty. Translating field names changes '
            . 'every form at once; read the class docblock before mirroring it into `ar`.');
        $this->assertSame([], $ar['attributes'] ?? null,
            'ar/validation.php `attributes` must stay empty while `en`\'s is');
    }

    /**
     * The point of the whole file, driven through the translator rather than the arrays: ask for
     * the `required` message in Arabic and get Arabic. Without this, all of the above could pass
     * on a file the framework never loads (a wrong directory, a wrong locale name).
     */
    public function test_the_translator_actually_serves_the_arabic_required_message(): void
    {
        $loader = new \Illuminate\Translation\FileLoader(
            new \Illuminate\Filesystem\Filesystem(),
            dirname(__DIR__, 3) . '/resources/lang'
        );
        $translator = new \Illuminate\Translation\Translator($loader, 'ar');

        $message = $translator->get('validation.required', ['attribute' => 'الاسم']);

        $this->assertNotSame('validation.required', $message,
            'the translator did not resolve the key at all -- wrong path or wrong locale name');
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $message,
            "validation.required resolved to non-Arabic text: {$message}");
        $this->assertStringContainsString('الاسم', $message,
            'the `:attribute` placeholder must be substituted, not dropped');
        $this->assertStringNotContainsString(':attribute', $message,
            'a literal `:attribute` leaked into the rendered message');
    }
}
