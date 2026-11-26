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
        {{--
            GAP-060 / GAP-061 (WS I4 + I5) — THE INSTRUCTION, BOUND TO THE GROUP.

            `aria-describedby` on the FIELDSET and not on a radio: the thing being described is
            the choice, and a description hung off one option is announced only when a reader
            happens to land on that option. The id convention is the one
            `resources/views/components/ss/choice-group.blade.php` already uses
            (`<name>-group-help`), so the two surfaces cannot drift into two conventions; this
            panel keeps its own `ss-field`/`ss-label` classes, which is why it does not swap in
            the component itself.

            WHY THIS SENTENCE AND NOT A RESTATEMENT OF THE LABELS. The inclusive behaviour is
            conditional on BOTH this setting and `shop_price`. THIS COMMENT SAID "four call sites"
            AND THAT WAS UNDERSTATED; re-measured 2026-11-26 there are TEN, and one of the four it
            listed had drifted (Cart.php:1010 -> :1036): app/Models/Cart.php:718 and :1036,
            app/Models/Product.php:420, app/Models/Tax.php:73,
            app/Http/Controllers/ProductController.php:5000, :5145 and :5293, and
            app/Http/Controllers/PosController.php:215, :529 and :789 -- each of which reads
            `price_type == 'inclusive' && shop_price == 'including'`. So a merchant who chooses
            inclusive here and leaves the select below on Exclusive Tax gets tax added on top of
            a price that already contains it, and nothing on the screen said so.
        --}}
        <fieldset class="ss-field" aria-describedby="price_type-group-help">
            <legend class="ss-label">{{ __('Prices entered with tax') }}</legend>

            <p class="ss-field__hint" id="price_type-group-help">
                {{ __('Whether the price you type on a product already contains tax. Inclusive pricing also needs Display prices in the shop below to be set to Including Tax: the cart, the product page and the tax calculation each test the two settings together, so choosing inclusive on its own still adds tax on top of a price that already contains it.') }}
            </p>

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
                    {{-- GAP-060 WS5. THE CHOICE IS STORED AND NOTHING CHARGES TAX FROM IT.

                         `tax_options.tax_id` is written by `TaxOptionController::taxSettings()`
                         (lines 92-97, falling back to the store's first Tax row) and read back by
                         exactly one thing: this select, to repopulate itself. Grepped for the array
                         read across `app/`, `packages/workdo/` and every view -- the only hit is the
                         `Form::select` default below.

                         WHAT REALLY DECIDES THE TAX is the tax on the PRODUCT:
                         `Tax::TaxCount()` takes `$data['product_tax_id']` and calls
                         `Tax::find($product_tax_id)` (app/Models/Tax.php:48), `Product::ProductPrice`
                         uses `Tax::find($product->tax_id)` (app/Models/Product.php:330) and
                         `ProductController` the same at :4997. So a merchant can change this class
                         all day and no cart total moves.

                         The sentence says that rather than naming the product screen's own control,
                         because that control is labelled "Taxs" (product/create.blade.php:102) and
                         an instruction that quotes a label the merchant has to squint at is worse
                         than one that describes the thing. --}}
                    <label class="ss-label" for="tax_id">{{ __('Tax Class') }}</label>
                    {!! Form::select('tax_id', $taxes, $tax_option['tax_id'] ?? null, [
                        'class' => 'ss-input',
                        'id' => 'tax_id',
                        'aria-describedby' => 'tax_id-help',
                    ]) !!}

                    <x-ss.field-help for="tax_id">{{ __('This is stored but nothing charges tax from it. The tax on a cart or an order is taken from the tax set on each individual product, so changing the class here does not change what any order is taxed.') }}</x-ss.field-help>
                </div>
            </div>

            <div class="col-lg-4 col-sm-6">
                <div class="ss-field">
                    {{-- GAP-060 / P02-I4. THE SECOND HALF OF THE PAIR, which the fieldset above
                         already tells the merchant matters while this control said nothing.

                         `x-ss.field-help` AND NOT `x-ss.choice-group`: a `<select>` is ONE control
                         in the accessibility tree -- its options are not siblings in the tab order
                         -- so a fieldset here would be the wrong association rather than a
                         stricter one. The component's own docblock carries that reasoning.

                         THE CLAIM WAS COUNTED, NOT ASSUMED. Ten sites in `app/` read
                         `price_type == 'inclusive' && shop_price == 'including'` or its negation:
                         Cart.php:718 and :1036, Product.php:420, Tax.php:73,
                         ProductController.php:5000/:5145/:5293 and
                         PosController.php:215/:529/:789. Every one tests the PAIR, so either
                         setting alone changes nothing -- which is exactly what a merchant cannot
                         see from two controls in two different boxes. (The fieldset's own comment above
                         said "four call sites" and listed a line that had drifted; it is corrected
                         in place, with the old figure withdrawn rather than overwritten, after the
                         ten were re-counted independently.) --}}
                    <label class="ss-label" for="shop_price">{{ __('Display prices in the shop') }}</label>
                    <select class="ss-input" id="shop_price" name="shop_price"
                            aria-describedby="shop_price-help">
                        <option value="including" @selected(($tax_option['shop_price'] ?? '') === 'including')>{{ __('Including Tax') }}</option>
                        <option value="exclusive" @selected(($tax_option['shop_price'] ?? '') === 'exclusive')>{{ __('Exclusive Tax') }}</option>
                    </select>

                    <x-ss.field-help for="shop_price">{{ __('Set this to Including Tax only if the Prices entered with tax choice above is Yes, I will enter prices inclusive of tax. Ten places test the two settings together, so either one on its own leaves tax added on top of a price that already contains it.') }}</x-ss.field-help>
                </div>
            </div>

            <div class="col-lg-4 col-sm-6">
                <div class="ss-field">
                    {{-- GAP-060 WS5. THIS CONTROL CANNOT BE SET ON ITS OWN, AND NOTHING SAID SO.

                         `TaxOptionController::taxSettings()` lines 101-111 do not store what this
                         select posts unless all three agree. When `price_type` is `inclusive` and
                         EITHER `shop_price` or `checkout_price` disagrees, BOTH are overwritten with
                         `including`; the `exclusive` limb is the mirror image. The success message is
                         replaced too -- "Consistent tax settings: Prices should be uniform with or
                         without taxes to prevent rounding errors."

                         MEASURED, not read off the branch: posting price_type=inclusive with
                         shop_price=exclusive and checkout_price=exclusive stored
                         shop_price=including and checkout_price=including, with that message. So
                         the merchant does not get what they chose and the confirmation does not
                         look like a refusal either.

                         `x-ss.field-help` AND NOT `x-ss.choice-group`: one select is one control in
                         the accessibility tree, so a fieldset round it would be the wrong
                         association rather than a stricter one. Same reasoning as `shop_price`
                         above, and the component's own docblock carries it. --}}
                    <label class="ss-label" for="checkout_price">{{ __('Display prices during cart and checkout') }}</label>
                    <select class="ss-input" id="checkout_price" name="checkout_price"
                            aria-describedby="checkout_price-help">
                        <option value="including" @selected(($tax_option['checkout_price'] ?? '') === 'including')>{{ __('Including Tax') }}</option>
                        <option value="exclusive" @selected(($tax_option['checkout_price'] ?? '') === 'exclusive')>{{ __('Exclusive Tax') }}</option>
                    </select>

                    <x-ss.field-help for="checkout_price">{{ __('You cannot set this on its own. When you save, it is forced to match the Prices entered with tax choice above: inclusive stores Including Tax both here and in Display prices in the shop, whatever you picked, and the confirmation message changes to say the three were made consistent.') }}</x-ss.field-help>
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
