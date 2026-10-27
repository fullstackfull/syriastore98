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
                    <label class="ss-label" for="currency_format">{{ __('Decimal Format') }}</label>
                    <select class="ss-input currency_note" name="currency_format" id="currency_format">
                        @foreach (['0' => '1', '1' => '1.0', '2' => '1.00', '3' => '1.000', '4' => '1.0000'] as $ssValue => $ssText)
                            <option value="{{ $ssValue }}" @selected(($setting['currency_format'] ?? '') === $ssValue)>{{ $ssText }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="col-md-6">
                <div class="ss-field">
                    <label class="ss-label" for="defult_currancy">{{ __('Default Currancy') }}</label>
                    <select class="ss-input currency_note" name="defult_currancy" id="defult_currancy">
                        @foreach ($currencyRates->where('is_active', 1) as $c)
                            <option value="{{ $c->symbol }}-{{ $c->currency_code }}"
                                    data-symbol="{{ $c->symbol }}"
                                    @selected(($setting['defult_currancy'] ?? '') === $c->currency_code)>
                                {{ $c->symbol }} - {{ $c->currency_code }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="col-md-6">
                <div class="ss-field">
                    <label class="ss-label" for="decimal_separator">{{ __('Decimal Separator') }}</label>
                    <select name="decimal_separator" class="ss-input currency_note" id="decimal_separator">
                        <option value="dot" @selected(($setting['decimal_separator'] ?? '') === 'dot')>{{ __('Dot') }}</option>
                        <option value="comma" @selected(($setting['decimal_separator'] ?? '') === 'comma')>{{ __('Comma') }}</option>
                    </select>
                </div>
            </div>

            <div class="col-md-6">
                <div class="ss-field">
                    <label class="ss-label" for="thousand_separator">{{ __('Thousands Separator') }}</label>
                    <select name="thousand_separator" class="ss-input currency_note" id="thousand_separator">
                        <option value="dot" @selected(($setting['thousand_separator'] ?? '') === 'dot')>{{ __('Dot') }}</option>
                        <option value="comma" @selected(($setting['thousand_separator'] ?? '') === 'comma')>{{ __('Comma') }}</option>
                    </select>
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
                <fieldset class="ss-field">
                    <legend class="ss-label">{{ __('Currency Symbol Position') }}</legend>
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
                <fieldset class="ss-field">
                    <legend class="ss-label">{{ __('Currency Symbol & Name') }}</legend>
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
