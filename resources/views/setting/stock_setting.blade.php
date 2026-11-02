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
                    <fieldset class="ss-field">
                        <legend class="ss-label">{{ __('Notifications') }}</legend>

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
