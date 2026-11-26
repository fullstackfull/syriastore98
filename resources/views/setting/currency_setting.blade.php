{{--
    Currency settings, on the design system.
    =============================================================================================

    THE "WITH SPACE / WITHOUT SPACE" DEFAULT WAS BACKWARDS, and silently so. Both radios carried
    `@if (! isset($setting['currency_space']) || $setting['currency_space'] == '…') checked`, so
    when the setting was unset BOTH were marked checked — and because they share a name the
    browser keeps the LAST one. A store that had never saved this got "without space" while the
    screen presented "with space" as the default. One `??` decides it now.

    THREE LABELS POINTED AT NOTHING. `for="example3cols3Input"` appeared TWICE — a control that
    does not exist on this screen, nor anywhere else in the repository — and `for="new_note_value"`
    sat over the Preview, which is a `<span>`, not a field. All three were GROUP headings over a
    radio pair or over read-only output; the first two are `<legend>`s now and the Preview is
    output with `aria-live`, since it changes as the other controls change.

    `id="flexCheckDefault"` / `flexCheckChecked` are Bootstrap sample ids on RADIO inputs — named
    "Check", borrowed from a checkbox example, and exactly the kind that collides. Named for what
    they are.

    IN THE RATES TABLE every row's rate box and status toggle had no label at all: a column
    header is not an accessible name for a control inside the cell. Each names its currency now,
    so a screen reader hears "Rate per USD, Euro" rather than "edit text, 0.92".
--}}
<div class="ss-card" id="currency-setting-sidenav">
    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('Currency Settings') }}</h2>
        </div>
    </div>

    {{ Form::open(['route' => ['currency.settings'], 'method' => 'post', 'id' => 'setting-currency-form']) }}
    <div class="ss-card__body">
        <div class="row">
            <div class="col-md-6">
                <div class="ss-field">
                    {{-- GAP-060 WS5 — AND A DEFECT OF THE SAME CLASS AS D-42.3, TWO BOXES AWAY.

                         `@selected(($setting['currency_format'] ?? '') === $ssValue)` CAN NEVER BE
                         TRUE. PHP casts integer-like string array keys to integers, so `$ssValue` is
                         `int 0`..`int 4` while the stored setting is the STRING '2' -- and `'2' ===
                         2` is false. Verified both ways: `var_dump` of the literal's keys reports
                         `integer`, and the rendered panel with `currency_format = '2'` stored came
                         back with `selected` on NO option while `decimal_separator = 'comma'`, four
                         lines below and compared with a string, came back correctly marked.

                         WHAT THAT COSTS A MERCHANT. The browser falls back to the first option, so
                         the screen reports zero decimal places whatever the store has saved -- and
                         because `SettingController::currencySettings()` writes every posted name,
                         pressing Save on this panel for any reason stores `currency_format = 0` and
                         every price loses its minor units. This is the same shape as the separator
                         defect this file's own comment records at the top: a form that reports the
                         opposite of what the four money formatters are doing.

                         CASTING BOTH SIDES TO STRING WAS NOT THE WHOLE FIX, and an independent
                         review of the first cut said so. It marks exactly one option for the five
                         VALID values -- but for an ABSENT row, a stored empty string, or anything
                         else, still no option is marked, the browser pre-selects `value="0"`, and
                         the next Save writes 0: the exact outcome the fix set out to remove.

                         AND THAT STATE IS REACHABLE, MEASURED, NOT THEORETICAL. Two sources:
                           - `Api/DashboardController.php:11551` validates this name as
                             `nullable|string|max:50` and `:11657-11662` writes it with
                             `updateOrCreate`, so the SHIPPED FLUTTER APP can store '' or any
                             50-character string in this row.
                           - the two defaults disagree: `defaultSetting()` seeds
                             `currency_format => "1"` (helper.php:1602) while the formatters' own
                             absent-value default is 2 (helper.php:1758-1762, :2452-2456). So a
                             store with no row PRINTS two decimals while the panel SHOWS "1".

                         SO THE COMPARISON IS NORMALISED, not merely cast, and the fallback is the
                         formatters' own absent-value default rather than a number chosen here --
                         the screen must not be able to report something the price does not do. A
                         value that is not one of the five is treated as absent, because there is no
                         honest option to mark for it: `number_format($price, '')` is a PHP 8
                         TypeError, so a non-numeric stored value does not print 2 decimals, it
                         500s every page that prints a price. That is a separate registered finding;
                         what this guarantees is only that the SELECT always marks exactly one
                         option, so saving cannot silently rewrite the format.

                         THE INSTRUCTION'S OWN CLAIM: the value is `number_format`'s SECOND argument
                         in `currency_format_with_sym()` (app/Helper/helper.php:1773) and in its
                         three siblings, so it is a display width and nothing rounds a stored
                         amount. Verified by the review across every reader: no writer, total,
                         export or order column derives from it. --}}
                    @php
                        /* The five the select offers, as STRINGS, so nothing here depends on PHP
                           casting integer-like array keys. */
                        $ssDecimalFormats = ['0' => '1', '1' => '1.0', '2' => '1.00', '3' => '1.000', '4' => '1.0000'];
                        $ssCurrencyFormat = (string) ($setting['currency_format'] ?? '');

                        if (! in_array($ssCurrencyFormat, ['0', '1', '2', '3', '4'], true)) {
                            $ssCurrencyFormat = '2';
                        }
                    @endphp
                    <label class="ss-label" for="currency_format">{{ __('Decimal Format') }}</label>
                    <select class="ss-input currency_note" name="currency_format" id="currency_format"
                            aria-describedby="currency_format-help">
                        @foreach ($ssDecimalFormats as $ssValue => $ssText)
                            <option value="{{ $ssValue }}" @selected((string) $ssValue === $ssCurrencyFormat)>{{ $ssText }}</option>
                        @endforeach
                    </select>

                    <x-ss.field-help for="currency_format">{{ __('How many decimal places every amount is printed with, here and on your storefront. It changes only how a price is written and never the amount that is stored: choosing the first option prints 10 for a price of 10.49.') }}</x-ss.field-help>
                </div>
            </div>

            <div class="col-md-6">
                <div class="ss-field">
                    {{-- GAP-060 WS5. THE MOST CONSEQUENTIAL CONTROL ON THE SETTINGS SCREEN, and it
                         presented itself as a symbol picker.

                         Saving this reaches `StoreCurrencyChange::apply()` and then
                         `StoreCurrencyConversionService::convertStoreCurrentValues()`, which inside
                         one transaction REWRITES: every `products.price`, `sale_price` and
                         `cost_price` for the store (lines 107-113), every `product_variants.price`
                         and `variation_price` (116-123), the five money columns on the store's
                         wallet plus its `currency_code` (126-135), and every `cod` order whose
                         `delivered_status` is 0 (138-...). `SettingController::currencySettings()`
                         carries the operator refusal for exactly this reason -- its own comment
                         calls one POST here "a whole-catalogue price change".

                         The sentence names the four things that are rewritten rather than saying
                         "converts your prices", because a merchant who reads the short version will
                         not think of the wallet or of an order already placed. --}}
                    <label class="ss-label" for="defult_currancy">{{ __('Default Currancy') }}</label>
                    <select class="ss-input currency_note" name="defult_currancy" id="defult_currancy"
                            aria-describedby="defult_currancy-help">
                        @foreach ($currencyRates->where('is_active', 1) as $c)
                            <option value="{{ $c->symbol }}-{{ $c->currency_code }}"
                                    data-symbol="{{ $c->symbol }}"
                                    @selected(($setting['defult_currancy'] ?? '') === $c->currency_code)>
                                {{ $c->symbol }} - {{ $c->currency_code }}
                            </option>
                        @endforeach
                    </select>

                    <x-ss.field-help for="defult_currancy">{{ __('Saving a different currency converts the money already in your store: every product price, sale price and cost price, every variant price, your wallet balances and every undelivered cash-on-delivery order are rewritten at the stored rate. It is not only a change of symbol.') }}</x-ss.field-help>
                </div>
            </div>

            @php
                /* D-42.3. THE SCREEN MUST REPORT THE FORMAT THE STORE IS ACTUALLY USING.
                   `@selected(($setting['decimal_separator'] ?? '') === 'dot')` marked NO option at
                   all when nothing was stored, so the browser fell back to showing the first one --
                   `Dot` -- while the four formatters were using a comma. A merchant reading this
                   screen was told the opposite of what their storefront did.
                   `CurrencySeparators::tokensFromSettings()` is the same resolver those formatters
                   use, including its collision rule, so the marked options cannot disagree with the
                   behaviour again. */
                $ssSeparators = \App\Support\Money\CurrencySeparators::tokensFromSettings($setting ?? []);
            @endphp

            {{-- GAP-060 WS5 — TWO CONTROLS, TWO DIFFERENT SENTENCES, AND THE ASYMMETRY IS THE POINT.

                 These two share a fact: comma+comma is a reachable SAVED state, and
                 `CurrencySeparators::tokens()` resolves it by moving the THOUSANDS mark
                 (`$t = $d === DOT ? COMMA : DOT`), never the decimal one. So the shared fact is not
                 symmetric -- on one control the merchant's choice survives and on the other it does
                 not -- and one shared sentence would have to say "one of these is overridden"
                 without saying which. Two sentences each state the outcome for the control they are
                 attached to, which is what makes them useful rather than merely shared.

                 THE LAYOUT WAS CHECKED BEFORE THE SHAPE WAS CHOSEN, because that is what cost time
                 on `order/view`. Each of these is a `col-md-6` holding a block-level
                 `label`/`select` stack inside `.ss-field`, not a `d-flex` row, so a `<p>` beneath
                 the select extends the column downward exactly as `shop_price`'s already does on
                 the tax panel. No row height is shared with anything.

                 Neither sentence quotes an option label, deliberately. The Arabic for `Comma` was
                 `- إرفع`, which is not a word for a punctuation mark at all; it is corrected to
                 `فاصلة` in the same change, and the sentences still speak of "the same character"
                 so that they stay true of whatever the two options are called. --}}
            <div class="col-md-6">
                <div class="ss-field">
                    <label class="ss-label" for="decimal_separator">{{ __('Decimal Separator') }}</label>
                    <select name="decimal_separator" class="ss-input currency_note" id="decimal_separator"
                            aria-describedby="decimal_separator-help">
                        <option value="dot" @selected($ssSeparators['decimal'] === 'dot')>{{ __('Dot') }}</option>
                        <option value="comma" @selected($ssSeparators['decimal'] === 'comma')>{{ __('Comma') }}</option>
                    </select>

                    <x-ss.field-help for="decimal_separator">{{ __('The character that separates the fractional part of every amount. If you choose the same character here as for the thousands separator, your choice is kept and the thousands separator is moved to the other character, so that an amount can still be read back.') }}</x-ss.field-help>
                </div>
            </div>

            <div class="col-md-6">
                <div class="ss-field">
                    <label class="ss-label" for="thousand_separator">{{ __('Thousands Separator') }}</label>
                    <select name="thousand_separator" class="ss-input currency_note" id="thousand_separator"
                            aria-describedby="thousand_separator-help">
                        <option value="dot" @selected($ssSeparators['thousand'] === 'dot')>{{ __('Dot') }}</option>
                        <option value="comma" @selected($ssSeparators['thousand'] === 'comma')>{{ __('Comma') }}</option>
                    </select>

                    <x-ss.field-help for="thousand_separator">{{ __('The character that groups the digits before the decimal point. If you choose the same character as the decimal separator, this one is moved to the other character instead, because the decimal choice wins, so that an amount can still be read back.') }}</x-ss.field-help>
                </div>
            </div>

            @php
                /* One source for each default, so the two radios of a pair cannot both be
                   checked — which is what the old `! isset(...) || ...` on BOTH produced. */
                $ssSpace = $setting['currency_space'] ?? 'withspace';
                $ssPosition = $setting['site_currency_symbol_position'] ?? 'pre';
                $ssSymbolName = $setting['site_currency_symbol_name'] ?? 'symbol';
            @endphp

            <div class="col-md-6">
                {{--
                    THE ONE GROUP ON THIS SCREEN THAT IS DELIBERATELY LEFT WITHOUT AN INSTRUCTION
                    (GAP-060 / GAP-061), because the control does not work and a sentence saying
                    what it does would be a false statement.

                    `app/Helper/helper.php:1772`, `:1773`, `:2467` and `:2468` all read
                    `((isset($currency_space) && $currency_space) == 'withspace' ? ' ' : '')`. The
                    parenthesisation makes the left operand a BOOLEAN, so the comparison is
                    `true == 'withspace'`, which PHP settles by casting the string to bool -- true.
                    Measured: a stored value of `withoutspace` yields TRUE and a space is printed;
                    only an UNSET value yields no space. So "Without space" does nothing once this
                    panel has ever been saved.

                    `app/Helper/helper.php` is not this workstream's file and this is a money
                    formatter, so the defect is REPORTED rather than patched here.
                    `SettingsChoiceGroupGuidanceTest::test_the_two_groups_whose_behaviour_is_
                    broken_carry_no_instruction` pins the absence, names the lines, and says what
                    to do when the formatter is fixed.
                --}}
                <fieldset class="ss-field">
                    <legend class="ss-label">{{ __('Currency Symbol Space') }}</legend>
                    <div class="ss-choice">
                        <input class="ss-choice__input currency_note" type="radio" name="currency_space"
                               value="withspace" id="currency_space_with" @checked($ssSpace === 'withspace')>
                        <label class="ss-choice__label" for="currency_space_with">{{ __('With space') }}</label>
                    </div>
                    <div class="ss-choice">
                        <input class="ss-choice__input currency_note" type="radio" name="currency_space"
                               value="withoutspace" id="currency_space_without" @checked($ssSpace === 'withoutspace')>
                        <label class="ss-choice__label" for="currency_space_without">{{ __('Without space') }}</label>
                    </div>
                    @error('currency_space')
                        <p class="ss-field__error" role="alert">{{ $message }}</p>
                    @enderror
                </fieldset>
            </div>

            <div class="col-md-6">
                {{--
                    GAP-060 / GAP-061 (WS I4 + I5). `aria-describedby` on the FIELDSET, with the
                    id convention `resources/views/components/ss/choice-group.blade.php` uses, so
                    the instruction is announced when focus enters the group rather than when a
                    reader lands on one radio.

                    The sentence says the thing a merchant cannot see from this screen: the choice
                    is read by `currency_format_with_sym()` and its three siblings
                    (app/Helper/helper.php:1752-1754), which print every price in the panel and the
                    storefront -- not just the preview beside these controls.
                --}}
                <fieldset class="ss-field" aria-describedby="site_currency_symbol_position-group-help">
                    <legend class="ss-label">{{ __('Currency Symbol Position') }}</legend>

                    <p class="ss-field__hint" id="site_currency_symbol_position-group-help">
                        {{ __('Whether the currency symbol is printed before the amount or after it. This is how prices are written everywhere for this store, not only in the preview on this screen.') }}
                    </p>

                    <div class="ss-choice">
                        <input class="ss-choice__input currency_note" type="radio" name="site_currency_symbol_position"
                               value="pre" id="currency_position_pre" @checked($ssPosition === 'pre')>
                        <label class="ss-choice__label" for="currency_position_pre">{{ __('Pre') }}</label>
                    </div>
                    <div class="ss-choice">
                        <input class="ss-choice__input currency_note" type="radio" name="site_currency_symbol_position"
                               value="post" id="currency_position_post" @checked($ssPosition === 'post')>
                        <label class="ss-choice__label" for="currency_position_post">{{ __('Post') }}</label>
                    </div>
                </fieldset>
            </div>

            <div class="col-md-6">
                {{--
                    GAP-060 / GAP-061 (WS I4 + I5). The instruction here corrects the LABEL rather
                    than repeating it: "With Currency Name" prints `defult_currancy`
                    (app/Helper/helper.php:1766-1770), which is the three-letter CODE, not a
                    spelled-out name. A merchant reading the label expects "US Dollars".
                --}}
                <fieldset class="ss-field" aria-describedby="site_currency_symbol_name-group-help">
                    <legend class="ss-label">{{ __('Currency Symbol & Name') }}</legend>

                    <p class="ss-field__hint" id="site_currency_symbol_name-group-help">
                        {{ __('With Currency Name prints the three-letter currency code, such as USD, in place of the symbol. It is the code that is printed and not a spelled-out name.') }}
                    </p>

                    <div class="ss-choice">
                        <input class="ss-choice__input currency_note" type="radio" name="site_currency_symbol_name"
                               value="symbol" id="currency_symbol_only" @checked($ssSymbolName === 'symbol')>
                        <label class="ss-choice__label" for="currency_symbol_only">{{ __('With Currency Symbol') }}</label>
                    </div>
                    <div class="ss-choice">
                        <input class="ss-choice__input currency_note" type="radio" name="site_currency_symbol_name"
                               value="symbolname" id="currency_symbol_name" @checked($ssSymbolName === 'symbolname')>
                        <label class="ss-choice__label" for="currency_symbol_name">{{ __('With Currency Name') }}</label>
                    </div>
                </fieldset>
            </div>

            <div class="col-md-6">
                <div class="ss-field">
                    {{-- Output, not a control: a `<label for>` would have to name a field, and
                         the old one named `new_note_value`, which does not exist. `aria-live`
                         because it changes as the controls above change. --}}
                    <p class="ss-label" id="currency_preview_label">{{ __('Preview :') }}</p>
                    <p class="ss-num" id="formatted_price_span" aria-live="polite"
                       aria-labelledby="currency_preview_label"></p>
                </div>
            </div>
        </div>
    </div>

    <div class="ss-card__footer ss-card__footer--end">
        <button type="submit" class="ss-btn ss-btn--primary btn-submit btn-print-invoice">{{ __('Save Changes') }}</button>
    </div>
    {{ Form::close() }}
</div>

<div class="ss-card mt-4">
    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('Store Currency Rates') }}</h2>
            <p class="ss-page-header__subtitle">
                {{ __('All exchange rates are based on 1 USD for this store.') }}
            </p>
        </div>
    </div>

    <div class="ss-card__body ss-card__body--flush">
        <div class="ss-table-wrap">
            <table class="ss-table">
                <thead>
                    <tr>
                        <th scope="col">{{ __('Currency') }}</th>
                        <th scope="col">{{ __('Code') }}</th>
                        <th scope="col">{{ __('Symbol') }}</th>
                        <th scope="col">{{ __('Rate Per USD') }}</th>
                        <th scope="col">{{ __('Status') }}</th>
                        <th scope="col">{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($currencyRates as $currency)
                        @php $ssFormId = 'currency-rate-form-' . $currency->id; @endphp
                        <tr>
                            <th scope="row">{{ __($currency->currency_name) }}</th>
                            <td><strong>{{ $currency->currency_code }}</strong></td>
                            <td>{{ $currency->symbol }}</td>

                            <td>
                                {{-- The column header does not name a control inside the cell,
                                     so each box names its own currency. --}}
                                <input type="number" name="rate_per_usd" form="{{ $ssFormId }}"
                                       class="ss-input" step="0.00000001" min="0.00000001"
                                       value="{{ $currency->rate_per_usd }}"
                                       aria-label="{{ __('Rate Per USD') }} — {{ $currency->currency_code }}"
                                       {{ $currency->currency_code === 'USD' ? 'readonly' : '' }} required>
                            </td>

                            <td>
                                @if ($currency->currency_code === 'USD')
                                    <input type="hidden" name="is_active" value="1" form="{{ $ssFormId }}">
                                    <x-ss.status-badge tone="success" :label="__('Active')" />
                                @else
                                    {{-- The hidden `0` before the checkbox is how "switched off"
                                         is told apart from "not submitted": an unchecked
                                         checkbox posts nothing. --}}
                                    <input type="hidden" name="is_active" value="0" form="{{ $ssFormId }}">
                                    <x-ss.switch name="is_active"
                                                 :id="'currency-active-' . $currency->id"
                                                 value="1"
                                                 :label="__('Active') . ' — ' . $currency->currency_code"
                                                 :checked="(bool) $currency->is_active"
                                                 :form="$ssFormId" />
                                @endif
                            </td>

                            <td>
                                <form id="{{ $ssFormId }}" method="POST"
                                      action="{{ route('currency-rates.update', $currency->id) }}">
                                    @csrf
                                </form>

                                <button type="submit" form="{{ $ssFormId }}"
                                        class="ss-btn ss-btn--secondary ss-btn--sm">
                                    <i class="ti ti-device-floppy" aria-hidden="true"></i>
                                    {{ __('Save') }}
                                    <span class="ss-visually-hidden">— {{ $currency->currency_code }}</span>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-ss.empty :title="__('No currencies found.')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
