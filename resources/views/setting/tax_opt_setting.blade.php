{{--
    Tax options, on the design system.
    =============================================================================================

    FOUR OF THE FIVE CONTROLS HAD NO LABEL. Each was `Form::label('', …)` — an empty `for`, so
    the label was associated with nothing and the select beside it had no accessible name. Only
    `price_suffix` was wired correctly. The fifth control, the price-type radio pair, had a
    `<label class="col-form-label">` with no `for` acting as a GROUP heading, which is what a
    `<legend>` is for: a label can name one control, not a choice between two.

    `id="customRadio5"` / `customRadio6` are also the kind of id that collides the moment another
    panel borrows the same boilerplate. They are named for what they are.
--}}
<div class="ss-card" id="Tax_Option_Setting">
    {{ Form::open(['route' => 'tax-option.settings', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}

    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('Tax Option Settings') }}</h2>
            <p class="ss-page-header__subtitle">{{ __('Edit your Tax Option Settings') }}</p>
        </div>
    </div>

    <div class="ss-card__body">
        <fieldset class="ss-field">
            <legend class="ss-label">{{ __('Prices entered with tax') }}</legend>
            <div class="ss-choice">
                <input type="radio" class="ss-choice__input type" id="price_type_inclusive"
                       name="price_type" value="inclusive"
                       @checked(($tax_option['price_type'] ?? '') === 'inclusive')>
                <label class="ss-choice__label" for="price_type_inclusive">
                    {{ __('Yes, I will enter prices inclusive of tax') }}
                </label>
            </div>
            <div class="ss-choice">
                <input type="radio" class="ss-choice__input type" id="price_type_exclusive"
                       name="price_type" value="exclusive"
                       @checked(($tax_option['price_type'] ?? '') === 'exclusive')>
                <label class="ss-choice__label" for="price_type_exclusive">
                    {{ __('No, I will enter prices exclusive of tax') }}
                </label>
            </div>
        </fieldset>

        <div class="row">
            <div class="col-lg-4 col-sm-6">
                <div class="ss-field">
                    <label class="ss-label" for="tax_id">{{ __('Tax Class') }}</label>
                    {!! Form::select('tax_id', $taxes, $tax_option['tax_id'] ?? null, [
                        'class' => 'ss-input',
                        'id' => 'tax_id',
                    ]) !!}
                </div>
            </div>

            <div class="col-lg-4 col-sm-6">
                <div class="ss-field">
                    <label class="ss-label" for="shop_price">{{ __('Display prices in the shop') }}</label>
                    <select class="ss-input" id="shop_price" name="shop_price">
                        <option value="including" @selected(($tax_option['shop_price'] ?? '') === 'including')>{{ __('Including Tax') }}</option>
                        <option value="exclusive" @selected(($tax_option['shop_price'] ?? '') === 'exclusive')>{{ __('Exclusive Tax') }}</option>
                    </select>
                </div>
            </div>

            <div class="col-lg-4 col-sm-6">
                <div class="ss-field">
                    <label class="ss-label" for="checkout_price">{{ __('Display prices during cart and checkout') }}</label>
                    <select class="ss-input" id="checkout_price" name="checkout_price">
                        <option value="including" @selected(($tax_option['checkout_price'] ?? '') === 'including')>{{ __('Including Tax') }}</option>
                        <option value="exclusive" @selected(($tax_option['checkout_price'] ?? '') === 'exclusive')>{{ __('Exclusive Tax') }}</option>
                    </select>
                </div>
            </div>

            <div class="col-lg-4 col-sm-6">
                <div class="ss-field">
                    <label class="ss-label" for="display_tax_option">{{ __('Display tax totals') }}</label>
                    <select class="ss-input" id="display_tax_option" name="display_tax_option">
                        <option value="single_total" @selected(($tax_option['display_tax_option'] ?? '') === 'single_total')>{{ __('As a single total') }}</option>
                        <option value="itemized" @selected(($tax_option['display_tax_option'] ?? '') === 'itemized')>{{ __('Itemized') }}</option>
                    </select>
                </div>
            </div>

            <div class="col-lg-4 col-sm-6">
                <div class="ss-field">
                    <label class="ss-label" for="price_suffix">{{ __('Price Display Suffix') }}</label>
                    <input type="text" class="ss-input" id="price_suffix" name="price_suffix"
                           value="{{ $tax_option['price_suffix'] ?? '' }}"
                           placeholder="{{ __('Price Display Suffix') }}">
                </div>
            </div>
        </div>
    </div>

    <div class="ss-card__footer ss-card__footer--end">
        <button type="submit" class="ss-btn ss-btn--primary btn-submit">{{ __('Save Changes') }}</button>
    </div>
    {!! Form::close() !!}
</div>
