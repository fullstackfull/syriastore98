<?php

namespace Tests\Feature\Commerce;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-290 — A STORED `currency_format` THIS SERVER CANNOT COUNT WITH.
 * =================================================================================================
 *
 * WHAT THE ROW CLAIMS. `currency_format` is handed straight to `number_format()`'s second
 * argument by three price formatters. `Api/DashboardController` validates it
 * `nullable|string|max:50`, so the shipped Flutter admin app can store `''` or any 50-character
 * string. A non-numeric value is a PHP 8 `TypeError` — HTTP 500 on every surface that prints a
 * price — and the empty case round-trips through the panel into `0`, which drops every price's
 * minor units.
 *
 * MEASURED ON PHP 8.3, and it refines the row in two places (see
 * `test_premise_what_number_format_really_does_with_each_stored_value`):
 *
 *     number_format(1234.56, '')     TypeError          <- the empty string is ITSELF the 500,
 *     number_format(1234.56, ' ')    TypeError             not merely a route to 0
 *     number_format(1234.56, 'abc')  TypeError
 *     number_format(1234.56, '0')    "1,235"            <- the minor units, gone
 *     number_format(1234.56, '2')    "1,234.56"
 *     number_format(1234.56, '99')   a 100-DIGIT STRING <- `max:50` admits it; no 500, pure garbage
 *
 * So the row's "the empty-string case silently halves every price" compresses two different steps:
 * `''` is a hard 500 at the formatter, and the SILENT loss of minor units is what the panel
 * round-trip produces (`''` matches no option, the browser pre-selects the first — which is `'0'` —
 * and the next Save stores it). Both are real; they are not the same failure. And `'99'` is a third
 * behaviour the row does not mention.
 *
 * FOUR READERS, FOUR DEFAULTS, AND THEY DISAGREE — the same shape `CurrencySeparators` was built to
 * end for the two separator controls on this very panel:
 *
 *     currency_format_with_sym()          absent -> 2      (helper.php)
 *     default_currency_format_with_sym()  absent -> 2
 *     order_currency_format_with_sym()    absent -> 2
 *     SettingController::updateNoteValue() (the panel's live preview)  -> '1', from the REQUEST
 *     defaultSetting()                    seeds "1"
 *
 * TWO WRITERS, AND THE WEB ONE IS THE WIDER HOLE — which is the question I was asked to settle.
 * The admin API at least bounds the length. `SettingController::currencySettings()` does
 * `$post = $request->all()` and writes **every posted key by name** with no validation of any kind,
 * so the web door stores `''`, `'abc'`, or a 10,000-character string. Asserted below.
 *
 * NOTHING HERE TOUCHES AN AMOUNT. The row states this was verified across every reader: no writer,
 * total, export or order column derives from `currency_format`. It decides how many digits are
 * printed. That is what keeps this inside display-only engineering — and "display only" still
 * means every priced surface in the product.
 *
 * @see \App\Support\Money\CurrencyDecimals
 * @see \App\Support\Money\CurrencySeparators  the precedent this follows
 */
class CurrencyDecimalFormatIntegrityTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** Exactly what the panel offers. */
    private const PANEL_VALUES = ['0', '1', '2', '3', '4'];

    private function arrange(): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $merchant->current_store = $store->id;
        $merchant->save();
        $merchant = User::find($merchant->id);
        $this->grantPermission($merchant, 'Manage Setting');

        foreach ([
            'defult_currancy' => 'USD',
            'CURRENCY_NAME' => 'USD',
            'defult_currancy_symbol' => '$',
            'CURRENCY' => '$',
        ] as $name => $value) {
            $this->setting($store->id, $name, $value, (int) $merchant->id);
        }

        /*
         * AN ACTIVE USD RATE ROW IS LOAD-BEARING, and leaving it out was a false reading in the
         * first draft: every Save came back refused and the CONTROL case read "the door no longer
         * saves 0" when in fact the door had not run at all. `currencySettings()` defaults an
         * ABSENT `defult_currancy` to `'USD'` and then asks `StoreCurrencyChange::prepare()` for a
         * rate — with no row, the panel refuses before it writes anything. That refusal is itself
         * the GAP-291 mechanism seen from the other side, and it is measured there.
         */
        \Illuminate\Support\Facades\DB::table('currency_rates')->insert([
            'store_id' => $store->id, 'currency_code' => 'USD', 'currency_name' => 'USD',
            'symbol' => '$', 'rate_per_usd' => 1.0, 'is_active' => 1,
            'created_by' => (int) $merchant->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [User::find($merchant->id), $store];
    }

    private function setting(int $storeId, string $name, string $value, ?int $author = null): void
    {
        Setting::withoutGlobalScopes()->updateOrCreate(
            ['name' => $name, 'store_id' => $storeId],
            ['value' => $value, 'name' => $name, 'store_id' => $storeId, 'created_by' => $author ?? 1]
        );
    }

    private function read(int $storeId, string $name): ?string
    {
        $row = Setting::withoutGlobalScopes()->where('store_id', $storeId)->where('name', $name)->first();

        return $row === null ? null : (string) $row->value;
    }

    /*
    |--------------------------------------------------------------------------
    | PREMISES — the PHP facts, measured, because the row compresses two of them
    |--------------------------------------------------------------------------
    */

    public function test_premise_what_number_format_really_does_with_each_stored_value(): void
    {
        foreach (['', ' ', 'abc'] as $stored) {
            $threw = false;
            try {
                number_format(1234.56, $stored, '.', ',');
            } catch (\TypeError $e) {
                $threw = true;
            }

            $this->assertTrue($threw,
                'number_format() no longer refuses ' . var_export($stored, true) . '. The 500 half '
                . 'of GAP-290 rests on this, so the row must be re-measured.');
        }

        $this->assertSame('1,235', number_format(1234.56, '0', '.', ','),
            'the minor-units loss half of GAP-290 rests on this');
        $this->assertSame('1,234.56', number_format(1234.56, '2', '.', ','));

        /* `max:50` admits this, and it is neither a 500 nor a price. */
        $this->assertGreaterThan(
            80,
            strlen(number_format(1234.56, '99', '.', ',')),
            'a 99-decimal format no longer produces an absurd string; the max:50 clause then '
            . 'admits only harmless values and that part of the finding is withdrawn'
        );
    }

    /**
     * AND `?? '2'` IS NOT THE FIX — the point a previous workstream proved with a mutant.
     *
     * `''` is SET, so a null-coalesce never fires on the one value the API door actually admits.
     * Asserted here as an executable statement rather than as prose, because the obvious one-liner
     * is what a future reader will reach for.
     */
    public function test_premise_a_null_coalesce_does_not_catch_the_reachable_value(): void
    {
        $settings = ['currency_format' => ''];

        $this->assertSame('', $settings['currency_format'] ?? '2',
            '`?? \'2\'` caught the empty string, which would mean PHP changed. It catches null and '
            . 'absence only — and absence is NOT the reachable case; `""` is.');
        $this->assertTrue(isset($settings['currency_format']),
            'the formatters guard with isset(), which an empty string satisfies');
    }

    /*
    |--------------------------------------------------------------------------
    | THE DEFECT — every priced surface
    |--------------------------------------------------------------------------
    */

    /**
     * THE 500, through the three real formatters.
     *
     * Driven as a direct call rather than through a page, because these three helpers are what
     * every priced surface funnels into — asserting one rendered screen would prove less and take
     * a fixture per screen.
     */
    public function test_a_stored_empty_format_does_not_break_every_priced_surface(): void
    {
        [, $store] = $this->arrange();
        $this->setting($store->id, 'currency_format', '');

        foreach (['currency_format_with_sym', 'default_currency_format_with_sym'] as $fn) {
            $out = null;
            $error = null;
            try {
                $out = $fn(1234.56, $store->id);
            } catch (\Throwable $e) {
                $error = get_class($e) . ': ' . $e->getMessage();
            }

            $this->assertNull($error,
                'GAP-290: ' . $fn . '() threw on a stored empty currency_format, which is an '
                . 'HTTP 500 on every admin list, invoice and storefront page that prints a price. '
                . $error);
            $this->assertSame('$1,234.56', $out,
                $fn . '() no longer falls back to the formatters\' own two-decimal basis for an '
                . 'unusable stored value');
        }
    }

    /** The same for a stored non-numeric string, which `max:50` also admits. */
    public function test_a_stored_non_numeric_format_does_not_break_every_priced_surface(): void
    {
        [, $store] = $this->arrange();
        $this->setting($store->id, 'currency_format', 'abc');

        $error = null;
        try {
            $out = currency_format_with_sym(1234.56, $store->id);
        } catch (\Throwable $e) {
            $error = get_class($e) . ': ' . $e->getMessage();
            $out = null;
        }

        $this->assertNull($error, 'GAP-290: a non-numeric stored format is still a 500. ' . $error);
        $this->assertSame('$1,234.56', $out);
    }

    /** And the 50-character case, which is garbage rather than a crash. */
    public function test_a_stored_absurd_format_does_not_print_a_hundred_digit_price(): void
    {
        [, $store] = $this->arrange();
        $this->setting($store->id, 'currency_format', '99');

        $out = currency_format_with_sym(1234.56, $store->id);

        $this->assertLessThan(20, strlen((string) $out),
            'GAP-290: a stored 99 still prints a 100-digit price. max:50 admits it and no reader '
            . 'bounds it. Got: ' . $out);
    }

    /*
    |--------------------------------------------------------------------------
    | THE WRITERS — both of them
    |--------------------------------------------------------------------------
    */

    /**
     * THE WEB DOOR IS THE WIDER HOLE, and this is the question I was asked to settle.
     *
     * `currencySettings()` writes `$request->all()` by name with no validation, so it stores a
     * value the admin API's own `max:50` would have refused.
     */
    public function test_the_web_door_refuses_a_format_the_formatters_cannot_use(): void
    {
        [$merchant, $store] = $this->arrange();

        $this->actingAs($merchant)->post(route('currency.settings'), [
            'currency_format' => str_repeat('9', 400),
            'decimal_separator' => 'dot',
            'thousand_separator' => 'comma',
        ]);

        $stored = $this->read($store->id, 'currency_format');

        $this->assertNotSame(str_repeat('9', 400), $stored,
            'GAP-290: the WEB door stored a 400-character currency_format. It writes '
            . '$request->all() by name with no validation of any kind — a wider hole than the '
            . 'admin API door the row names, which at least bounds the length.');

        /*
         * NULL IS A PASS, and reading it as a failure cost a cycle: dropping the unusable key
         * leaves the setting UNWRITTEN, and this fixture seeds no `currency_format` row (measured:
         * zero rows after `makeStore`), so "no row" is exactly what a correct refusal looks like.
         * `(string) null` is `''`, which is why the first draft of this assertion failed against
         * the fix it was testing.
         */
        $this->assertTrue($stored === null || in_array((string) $stored, self::PANEL_VALUES, true),
            'the web door stored a value outside the five the panel offers: '
            . var_export($stored, true));
    }

    /** And the empty string specifically, which is the value the API door admits. */
    public function test_the_web_door_refuses_an_empty_format(): void
    {
        [$merchant, $store] = $this->arrange();
        $this->setting($store->id, 'currency_format', '2');

        $this->actingAs($merchant)->post(route('currency.settings'), [
            'currency_format' => '',
            'decimal_separator' => 'dot',
            'thousand_separator' => 'comma',
        ]);

        $stored = $this->read($store->id, 'currency_format');

        $this->assertSame('2', $stored,
            'GAP-290: an empty currency_format was stored over the merchant\'s saved 2, which is a '
            . '500 on every priced surface the moment anybody loads one. Dropping the unusable key '
            . 'must leave the stored value standing. Got: ' . var_export($stored, true));
    }

    /** A legitimate merchant choice must still be saved — including zero decimals. */
    public function test_the_web_door_still_saves_every_format_the_panel_offers(): void
    {
        [$merchant, $store] = $this->arrange();

        foreach (self::PANEL_VALUES as $value) {
            $this->actingAs($merchant)->post(route('currency.settings'), [
                'currency_format' => $value,
                'decimal_separator' => 'dot',
                'thousand_separator' => 'comma',
            ]);

            $this->assertSame($value, $this->read($store->id, 'currency_format'),
                'THE CONTROL BROKE: the panel offers ' . $value . ' and the door no longer saves '
                . 'it. A merchant who wants zero or four decimals is entitled to them, and a '
                . 'validation that refuses a legitimate choice is the worse defect.');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | THE PANEL — which option is marked, and therefore what the next Save stores
    |--------------------------------------------------------------------------
    */

    /**
     * THE SILENT HALVING, AT ITS SOURCE.
     *
     * The select is `@selected(($setting['currency_format'] ?? '') === $ssValue)` over
     * `['0','1','2','3','4']`. A stored `''` — or no row at all — matches nothing, the browser
     * pre-selects the FIRST option, and the first option is `'0'`. So the next Save of this panel
     * stores zero decimals, and the merchant never chose it.
     *
     * Asserted on the rendered page: the marked option must be the one the FORMATTERS would use,
     * for every stored value including the unusable ones.
     */
    public function test_the_panel_marks_the_format_the_formatters_actually_use(): void
    {
        [$merchant, $store] = $this->arrange();

        /*
         * A LIST OF PAIRS, NOT A MAP, because PHP casts a NULL array key to the empty string: the
         * first draft wrote `[null => '2', '' => '2', ...]` and the two collapsed into one entry,
         * so the missing-row case — the one `defaultSetting()` disagreement is about — was never
         * driven at all.
         */
        foreach ([[null, '2'], ['', '2'], ['abc', '2'], ['99', '2'], ['0', '0'], ['3', '3']] as [$stored, $expected]) {
            if ($stored === null) {
                Setting::withoutGlobalScopes()
                    ->where('store_id', $store->id)->where('name', 'currency_format')->delete();
            } else {
                $this->setting($store->id, 'currency_format', (string) $stored);
            }

            /*
             * THE TAB IS RENDERED BY ITS OWN AJAX ENDPOINT, not by the settings index: the index
             * only emits the tab buttons, each carrying `data-url="{{ route('setting.form') }}"`.
             * Asserting against the index read an empty select and would have passed for any fix
             * at all.
             */
            $response = $this->actingAs($merchant)
                ->post(route('setting.form'), ['tab_type' => 'currency_setting']);

            /*
             * AND IT ANSWERS JSON, with the markup under `data.content` — not a rendered page.
             * Searching the raw body would have matched the ESCAPED HTML and made the regex below
             * silently unmatchable, which is the "assertion that cannot fail" shape.
             */
            $html = (string) ($response->json('data.content') ?? '');

            $block = $this->currencyFormatSelect((string) $html);

            $this->assertNotSame('', $block,
                'the Decimal Format select is not on the rendered settings page, so this case '
                . 'measures nothing');

            $this->assertMatchesRegularExpression(
                '/<option value="' . preg_quote($expected, '/') . '"[^>]*\bselected\b/',
                $block,
                'GAP-290: with ' . var_export($stored, true) . ' stored, the panel does not mark '
                . $expected . ' — the value the formatters actually use. An unmarked select makes '
                . 'the browser pre-select the first option (0), and the next Save stores it, '
                . 'dropping the minor units from every price. Select was: ' . $block
            );
        }
    }

    /** Just the Decimal Format select, so the assertion is not satisfied by another control. */
    private function currencyFormatSelect(string $html): string
    {
        if (! preg_match('/<select[^>]*name="currency_format".*?<\/select>/s', $html, $m)) {
            return '';
        }

        return $m[0];
    }

    /*
    |--------------------------------------------------------------------------
    | THE FOUR DEFAULTS — one basis, as CurrencySeparators did for the separators
    |--------------------------------------------------------------------------
    */

    /**
     * THE PANEL'S LIVE PREVIEW AND THE FORMATTERS MUST AGREE.
     *
     * `updateNoteValue()` is the AJAX preview on this screen and defaulted to `'1'`, while the
     * three formatters default to `2`. So a store with no row was shown `1.0` by the preview and
     * printed `1.00` everywhere else — the same class of disagreement as the `decimal_separator`
     * defect recorded in this panel's own comment.
     */
    public function test_the_panels_preview_and_the_formatters_share_one_default(): void
    {
        [$merchant, $store] = $this->arrange();

        Setting::withoutGlobalScopes()
            ->where('store_id', $store->id)->where('name', 'currency_format')->delete();

        $printed = currency_format_with_sym(10000, $store->id);

        $preview = $this->actingAs($merchant)
            ->post(route('update.note.value'), ['defult_currancy' => '$-USD'])
            ->getContent();

        $printedDecimals = $this->decimalsIn((string) $printed);
        $previewDecimals = $this->decimalsIn((string) $preview);

        $this->assertNotNull($printedDecimals, 'the formatter printed nothing countable: ' . $printed);
        $this->assertNotNull($previewDecimals, 'the preview returned nothing countable: ' . $preview);

        $this->assertSame($printedDecimals, $previewDecimals,
            'GAP-290: the Currency panel\'s live preview shows ' . $previewDecimals . ' decimals '
            . 'while every price in the product prints ' . $printedDecimals . '. One of the two '
            . 'is lying to the merchant about their own store.');
    }

    /** How many digits follow the last dot in a formatted figure, or null. */
    private function decimalsIn(string $text): ?int
    {
        if (! preg_match_all('/\d+\.(\d+)/', $text, $m)) {
            return preg_match('/\d/', $text) ? 0 : null;
        }

        return strlen(end($m[1]));
    }
}
