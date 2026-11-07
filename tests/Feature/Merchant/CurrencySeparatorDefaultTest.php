<?php

namespace Tests\Feature\Merchant;

use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * A STORE THAT HAS NEVER OPENED CURRENCY SETTINGS SHOWED `$1,162,50`.
 * =================================================================================================
 *
 * FOUND BY LOOKING AT THE SCREEN, not by reading the code: the dashboard's one money figure read
 * `$1,162,50` and every card on `/order-grid` read `$10,00`. Both come from
 * `currency_format_with_sym()` (app/Helper/helper.php:1507).
 *
 * THE DEFAULT USED THE SAME CHARACTER FOR BOTH ROLES. The helper read two merchant settings and
 * defaulted each to a comma when absent:
 *
 *     $decimal_separator  = ($company_settings['decimal_separator']  ?? '') === 'dot' ? '.' : ',';
 *     $thousand_separator = ($company_settings['thousand_separator'] ?? '') === 'dot' ? '.' : ',';
 *
 * so an unset store got `number_format($price, 2, ',', ',')` -> `1,162,50`. That is not a European
 * format and not an Arabic one; it is a figure that cannot be read back, because nothing in it says
 * which comma is the decimal point. NEITHER SETTING IS EVER SEEDED -- `decimal_separator` appears
 * in exactly one writer (`SettingController::currencySettingStore`) and in the Currency Settings
 * form, and nowhere in any seeder or migration -- so EVERY store carries this until a merchant
 * happens to open that screen and press Save.
 *
 * AND THE SETTINGS SCREEN REPORTED A FORMAT THE APPLICATION DID NOT USE. The two selects mark an
 * option with `@selected(($setting['decimal_separator'] ?? '') === 'dot')`, so with no stored row
 * NEITHER option is marked and the browser falls back to showing the first one -- `Dot` -- while
 * the helper was formatting with a comma. A merchant reading that screen was told the opposite of
 * what their storefront did.
 *
 * THE FIX IS ONE DEFAULT, DEFINED ONCE, READ BY ALL THREE. `Currency::DEFAULTS` holds
 * `decimal => 'dot'`, `thousand => 'comma'` -- `1,162.50`, which is what `number_format($x, 2)`
 * does with no arguments and therefore what the finance screen, the chart tables and every
 * `number_format` elsewhere in this codebase already produce. The helper, the live-preview endpoint
 * and the form all resolve through it, so the screen cannot disagree with the behaviour again.
 *
 * A MERCHANT WHO STORED A PREFERENCE IS UNTOUCHED: a stored `dot` or `comma` is read exactly as
 * before. The only rows that change are the ones that were never written.
 *
 * SCOPE. This is display only, under owner decision D-42.3: no stored amount, financial
 * calculation, wallet balance, settlement, payment or conversion rate is involved. It is the same
 * money, written so it can be read.
 */
class CurrencySeparatorDefaultTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->merchant, $this->store] = $this->makeTenant();
        $this->actingAs($this->merchant);
    }

    private function seedSeparator(string $name, string $value): void
    {
        Setting::withoutGlobalScopes()->create([
            'name' => $name,
            'value' => $value,
            'store_id' => $this->store->id,
            'created_by' => $this->merchant->id,
        ]);
    }

    /**
     * PREMISE: neither separator is seeded anywhere, so "unset" is the state a real new store is
     * in. Asserted against the database rather than trusted, because the whole finding is that
     * this is the DEFAULT path and not an exotic one.
     */
    public function test_premise_a_new_store_has_no_separator_setting(): void
    {
        $this->assertSame(
            0,
            Setting::withoutGlobalScopes()
                ->whereIn('name', ['decimal_separator', 'thousand_separator'])
                ->count(),
            'premise: a freshly created tenant stores no separator preference'
        );
    }

    /** THE DEFECT. A thousands-grouped figure with no stored preference. */
    public function test_an_unset_store_formats_a_thousands_figure_readably(): void
    {
        $formatted = currency_format_with_sym(1162.50, $this->store->id);

        $this->assertStringNotContainsString(
            '1,162,50',
            $formatted,
            'the same character cannot be both the thousands separator and the decimal point: '
            . "the figure cannot be read back. got: {$formatted}"
        );
        $this->assertStringContainsString('1,162.50', $formatted, "got: {$formatted}");
    }

    /** The same default, on a figure with no thousands at all -- `/order-grid`'s `$10,00`. */
    public function test_an_unset_store_formats_a_small_figure_readably(): void
    {
        $formatted = currency_format_with_sym(10.00, $this->store->id);

        $this->assertStringNotContainsString('10,00', $formatted, "got: {$formatted}");
        $this->assertStringContainsString('10.00', $formatted, "got: {$formatted}");
    }

    /**
     * A STORED PREFERENCE IS UNTOUCHED, both ways round. This is the half that says the change is
     * a default and not a redefinition: a merchant who chose comma decimals keeps them.
     *
     * @dataProvider storedPreferences
     */
    public function test_a_stored_preference_is_honoured(string $decimal, string $thousand, string $expected): void
    {
        $this->seedSeparator('decimal_separator', $decimal);
        $this->seedSeparator('thousand_separator', $thousand);

        $this->assertStringContainsString(
            $expected,
            currency_format_with_sym(1162.50, $this->store->id)
        );
    }

    public static function storedPreferences(): array
    {
        return [
            'dot decimal, comma thousands' => ['dot', 'comma', '1,162.50'],
            'comma decimal, dot thousands' => ['comma', 'dot', '1.162,50'],
        ];
    }

    /**
     * ONE SETTING STORED AND THE OTHER NOT is the case a per-setting default gets wrong, and it is
     * reachable: `currencySettingStore` writes both, but a merchant upgrading from a build that
     * wrote only one, or a partial seed, lands here. Whichever is missing must take the default
     * that does not collide with the one that is present.
     */
    public function test_a_half_stored_preference_never_collides(): void
    {
        $this->seedSeparator('decimal_separator', 'comma');

        $formatted = currency_format_with_sym(1162.50, $this->store->id);

        $this->assertStringNotContainsString(
            '1,162,50',
            $formatted,
            "a stored comma decimal must not leave a comma thousands separator beside it. got: {$formatted}"
        );
        $this->assertStringContainsString('1.162,50', $formatted, "got: {$formatted}");
    }

    /**
     * AND A MERCHANT CANNOT SAVE THE COLLISION EITHER. The form offers `dot` and `comma` for both,
     * so comma+comma is a reachable stored state -- and it renders the same unreadable figure as
     * the old default did. The same resolution applies to a stored pair, not only to an absent one.
     */
    public function test_a_stored_collision_is_resolved_rather_than_rendered(): void
    {
        $this->seedSeparator('decimal_separator', 'comma');
        $this->seedSeparator('thousand_separator', 'comma');

        $formatted = currency_format_with_sym(1162.50, $this->store->id);

        $this->assertStringNotContainsString('1,162,50', $formatted, "got: {$formatted}");
        $this->assertStringContainsString('1.162,50', $formatted, "got: {$formatted}");
    }

    /**
     * THE SETTINGS SCREEN MUST REPORT THE FORMAT THE APPLICATION USES. With nothing stored the
     * select used to mark no option at all, so the browser showed `Dot` while the helper formatted
     * with a comma. The screen is where a merchant goes to find out what their store does.
     */
    public function test_the_currency_form_marks_the_default_that_is_actually_in_force(): void
    {
        /* The Currency tab is LAZY: `setting/index.blade.php:61` carries
           `data-url="{{ route('setting.form') }}" data-tab="currency_setting"`, so /setting never
           contains these selects and a GET of it would make this case vacuous. The tab's own
           endpoint is driven instead, and the premise below says the markup really arrived. */
        $payload = $this->post(route('setting.form'), ['tab_type' => 'currency_setting'])
            ->assertOk()
            ->json();

        $html = (string) ($payload['data']['content'] ?? '');

        $this->assertStringContainsString(
            'name="decimal_separator"',
            $html,
            'premise: the currency tab rendered its separator selects'
        );

        $this->assertMatchesRegularExpression(
            '/<select[^>]*name="decimal_separator".*?<option value="dot"[^>]*\bselected\b/s',
            $html,
            'the decimal select must mark `dot`, which is the default now in force'
        );
        $this->assertMatchesRegularExpression(
            '/<select[^>]*name="thousand_separator".*?<option value="comma"[^>]*\bselected\b/s',
            $html,
            'and the thousands select must mark `comma`'
        );
    }

    /**
     * THE LIVE PREVIEW ON THAT SCREEN MUST AGREE TOO. `currencySettingPreview` re-implements the
     * same defaulting for its sample figure, so a preview that still defaulted to comma+comma
     * would show the merchant an unreadable sample of a format the app no longer uses.
     */
    public function test_the_live_preview_agrees_with_the_helper(): void
    {
        $response = $this->post(route('update.note.value'), [
            'currency_format' => 2,
            'defult_currancy' => '$-USD',
        ]);

        $formatted = (string) ($response->json('formatted_price') ?? '');

        $this->assertNotSame('', $formatted, 'premise: the preview endpoint answered a figure');
        $this->assertStringNotContainsString('10,000,00', $formatted, "got: {$formatted}");
        $this->assertStringContainsString('10,000.00', $formatted, "got: {$formatted}");
    }
}
