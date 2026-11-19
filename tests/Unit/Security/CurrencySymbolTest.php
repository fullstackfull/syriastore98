<?php

namespace Tests\Unit\Security;

use App\Support\Money\CurrencySymbol;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * P1-SEC-80, round 4 -- what a currency symbol may carry when it is printed next to an amount.
 *
 * The rule is a short list of characters a symbol never needs, removed where the value is read. The cases say what must survive it unchanged (every symbol and code the platform offers,
 * and a symbol stored as an entity) and what must not survive it (the characters markup, an attribute value and a script string cannot do without).
 */
final class CurrencySymbolTest extends TestCase
{
    /** @return array<string,array{0:string}> */
    public static function symbolsThatMustPrintUnchanged(): array
    {
        return [
            'dollar' => ['$'], 'euro' => ['€'], 'pound' => ['£'], 'syrian pound' => ['ل.س'], 'lebanese pound' => ['ل.ل'], 'iraqi dinar' => ['د.ع'], 'dirham' => ['د.إ'],
            'rupee, with a dot' => ['Rs.'], 'franc' => ['CHF'], 'real' => ['R$'], 'krone' => ['kr'], 'sol, with a slash' => ['S/.'], 'lira' => ['₺'], 'zloty' => ['zł'],
            'a code' => ['USD'], 'an entity' => ['&euro;'], 'a numeric entity' => ['&#8364;'], 'ten characters, the API\'s own bound' => ['ABCDEFGHIJ'],
        ];
    }

    #[DataProvider('symbolsThatMustPrintUnchanged')]
    public function test_a_symbol_a_store_may_have_is_printed_unchanged(string $symbol): void
    {
        $this->assertSame($symbol, CurrencySymbol::clean($symbol));
    }

    /** @return array<string,array{0:string}> */
    public static function valuesThatAreNotSymbols(): array
    {
        return [
            'a tag' => ['<img src=x onerror=1>'],
            'a closing tag and a script' => ['</td><script>1</script>'],
            'an attribute breakout' => ['" onmouseover="1'],
            'a single-quoted breakout' => ["' onmouseover='1"],
            'a template literal' => ['`${1}`'],
            'a script escape that decodes to a tag' => ['\\u003cimg src=x onerror=1\\u003e'],
            'a hex escape' => ['\\x3cimg\\x3e'],
            'a line break in a script string' => ["$\nalert(1)//"],
            'a null byte' => ["$\0<b>"],
        ];
    }

    #[DataProvider('valuesThatAreNotSymbols')]
    public function test_what_markup_an_attribute_or_a_script_string_needs_does_not_survive(string $stored): void
    {
        $clean = CurrencySymbol::clean($stored);

        foreach (['<', '>', '"', "'", '`', '\\', "\n", "\r", "\0"] as $character) {
            $this->assertStringNotContainsString($character, $clean, 'the cleaned symbol still carries ' . json_encode($character));
        }
        $this->assertLessThanOrEqual(CurrencySymbol::MAX_LENGTH, mb_strlen($clean));
    }

    public function test_the_rest_of_a_value_is_kept_rather_than_replaced_by_another_currencys_sign(): void
    {
        // printing `$` for a store whose currency is not the dollar would be a wrong statement about money; what is left of the value is printed instead
        $this->assertSame('Fr', CurrencySymbol::clean("Fr'"));
        $this->assertSame('', CurrencySymbol::clean('<>'), 'a value that was nothing but those characters prints as no symbol, not as a dollar sign');
    }

    public function test_a_long_value_is_cut_and_surrounding_space_is_dropped(): void
    {
        $this->assertSame(CurrencySymbol::MAX_LENGTH, mb_strlen(CurrencySymbol::clean(str_repeat('ل', 200))), 'the bound counts characters, not bytes');
        $this->assertSame('US$', CurrencySymbol::clean("  US$\t "));
    }

    /** Bytes that are not UTF-8 are nobody's symbol on a utf8mb4 database; they print as no symbol rather than as bytes a browser has to guess at. */
    public function test_bytes_that_are_not_utf8_are_no_symbol(): void
    {
        $this->assertSame('', CurrencySymbol::clean("\xA3"));
        $this->assertSame('', CurrencySymbol::clean("$\xC3\x28"));
    }

    public function test_a_value_that_is_not_text_is_no_symbol(): void
    {
        $this->assertSame('', CurrencySymbol::clean(null));
        $this->assertSame('', CurrencySymbol::clean(['$']));
        $this->assertSame('', CurrencySymbol::clean(new \stdClass()));
        $this->assertSame('', CurrencySymbol::clean(true));
        $this->assertSame('5', CurrencySymbol::clean(5));
    }

    /** The names are a list somebody could shorten without a test noticing, because the case below iterates the constant itself. */
    public function test_the_names_the_reader_cleans_are_these_six(): void
    {
        $this->assertSame(['defult_currancy_symbol', 'CURRENCY', 'defult_currancy', 'CURRENCY_NAME', 'currency_symbol', 'currency'], CurrencySymbol::SETTING_NAMES);
    }

    public function test_the_reader_cleans_the_symbol_names_only_and_keeps_an_absent_value_absent(): void
    {
        foreach (CurrencySymbol::SETTING_NAMES as $name) {
            $this->assertSame('img', CurrencySymbol::cleanSetting($name, '<img>'), $name . ' is a symbol or a code and must be cleaned');
            $this->assertNull(CurrencySymbol::cleanSetting($name, null), $name . ': an absent value stays absent, so that a caller\'s `??` fallback applies');
        }

        $this->assertSame('<b>x</b>', CurrencySymbol::cleanSetting('footer_note', '<b>x</b>'), 'a setting that is not a symbol is returned as it is');
        $this->assertSame(['a'], CurrencySymbol::cleanSetting('some_list', ['a']));
        $this->assertSame('<img>', CurrencySymbol::cleanSetting(null, '<img>'));
        $this->assertSame('<img>', CurrencySymbol::cleanSetting('currency_symbol ', '<img>'), 'a name is compared exactly');
    }
}
