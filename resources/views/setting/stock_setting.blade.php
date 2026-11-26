@php
    $notifications = isset($setting['notification']) ? json_decode($setting['notification']) : [];
    $notifications = is_array($notifications) ? $notifications : [];
@endphp
{{--
    Stock settings, on the design system.

    `json_decode` returns NULL for a malformed or absent value, and `in_array(..., null)` is a
    TypeError on PHP 8 — the panel would have died rather than rendered. Guarded above.

    The two notification checkboxes were a `<label class="form-label">Notifications</label>`
    followed by a `.form-group row` acting as a group: a label naming a pair, which is what a
    `<legend>` is for.
--}}
<div class="ss-card" id="Stock_Setting">
    {{ Form::model($setting, ['route' => 'stock.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}

    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('Stock Settings') }}</h2>
        </div>
    </div>

    <div class="ss-card__body">
        <div class="row">
            <div class="col-md-6">
                <div class="ss-field">
                    <label class="ss-label" for="low_stock_threshold">{{ __('Low Stock Threshold') }}</label>
                    <input type="number" class="ss-input" id="low_stock_threshold" name="low_stock_threshold"
                           value="{{ $setting['low_stock_threshold'] ?? '' }}">
                </div>
            </div>
            <div class="col-md-6">
                <div class="ss-field">
                    <label class="ss-label" for="out_of_stock_threshold">{{ __('Out of Stock Threshold') }}</label>
                    <input type="number" class="ss-input" id="out_of_stock_threshold" name="out_of_stock_threshold"
                           value="{{ $setting['out_of_stock_threshold'] ?? '' }}">
                </div>
            </div>
        </div>

        @if (auth()->user()->type == 'admin')
            <div class="row">
                <div class="col-md-6">
                    <x-ss.switch name="stock_management" id="stock_management" value="on"
                                 :label="__('Enable Stock Management')"
                                 :checked="isset($setting['stock_management']) && $setting['stock_management'] == 'on'" />
                </div>

                <div class="col-md-6">
                    {{-- GAP-060 WS5. CLEARING BOTH BOXES DOES NOT TURN THE ALERTS OFF -- IT BREAKS
                         THE SAVE, and it takes the two threshold figures down with it.

                         `SettingController::StockSettings()` line 1323 validates
                         `'notification' => 'required'`. Unchecked checkboxes post nothing, so with
                         both boxes clear the name is absent, the validator fails, and the handler
                         returns `redirect()->back()->with('error', ...)` BEFORE writing anything --
                         so `low_stock_threshold` and `out_of_stock_threshold` are not stored either.

                         MEASURED through the real endpoint: posting the two thresholds with no box
                         ticked answered the error "The notification field is required." and left
                         `low_stock_threshold` unset in `settings`.

                         AND TICKING A BOX IS NOT ENOUGH ON ITS OWN. Every send site gates on Twilio
                         first: `PaymentService.php:2817-2823` and `:2898-2904` reach
                         `Utility::low_stock_threshold()` / `::out_of_stock()` only when
                         `$settings['twilio_setting_enabled'] == 'on'`, and the check itself runs
                         inside the checkout's stock decrement. So the sentence names the Twilio
                         toggle by its real label, "Enable Twilio", and the panel it lives on,
                         "Twilio Settings".

                         The description is on the FIELDSET because the pair is what is being
                         described, and the fieldset stays inline so the settings census keeps
                         finding this group by its legend. --}}
                    <fieldset class="ss-field" aria-describedby="stock_notification-group-help">
                        <legend class="ss-label">{{ __('Notifications') }}</legend>

                        <p class="ss-field__hint" id="stock_notification-group-help">
                            {{ __('At least one box must be ticked or the whole panel refuses to save, and the two threshold figures on this panel are not stored either. A message is only sent while Enable Twilio is switched on in the Twilio Settings panel, and the check runs when an order reduces the stock of a product.') }}
                        </p>

                        <div class="ss-choice">
                            <input type="checkbox" class="ss-choice__input" id="low_stock"
                                   name="notification[]" value="enable_low_stock"
                                   @checked(in_array('enable_low_stock', $notifications))>
                            <label class="ss-choice__label" for="low_stock">{{ __('Low stock notifications') }}</label>
                        </div>

                        <div class="ss-choice">
                            <input type="checkbox" class="ss-choice__input" id="out_of_stock"
                                   name="notification[]" value="enable_out_of_stock"
                                   @checked(in_array('enable_out_of_stock', $notifications))>
                            <label class="ss-choice__label" for="out_of_stock">{{ __('Out of stock notifications') }}</label>
                        </div>
                    </fieldset>
                </div>
            </div>
        @endif
    </div>

    <div class="ss-card__footer ss-card__footer--end">
        <button type="submit" class="ss-btn ss-btn--primary btn-submit">{{ __('Save Changes') }}</button>
    </div>
    {{ Form::close() }}
</div>
