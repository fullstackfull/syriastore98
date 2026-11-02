{{--
    Loyalty programme, on the design system.
    =============================================================================================

    TWO DEFECTS BEYOND THE MARKUP.

    The enable toggle's label was EMPTY, like every other panel in this directory, so the control
    had no accessible name.

    And the field's label was `Form::label('', …)` — an empty `for`, so it was associated with
    NOTHING; the one input on the panel had no label at all. Its text was also built by
    concatenation INSIDE the translation call:

        __('Reward points on orders of ' . $setting['CURRENCY'] . '1000')

    which makes the translation KEY vary with the store's currency symbol, so it can never match
    an entry in `ar.json` and always renders in English. The currency is a parameter now, and the
    sentence is one key.
--}}
<div class="ss-card" id="Loyality_program">
    {{ Form::open(['route' => 'loyality.program.settings', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}

    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('Loyality Program') }}</h2>
        </div>
        {!! Form::hidden('loyality_program_enabled', 'off') !!}
        <x-ss.switch name="loyality_program_enabled"
                     id="loyality_program_enabled"
                     value="on"
                     :label="__('Enable the loyalty program')"
                     :checked="isset($setting['loyality_program_enabled']) && $setting['loyality_program_enabled'] == 'on'" />
    </div>

    <div class="ss-card__body">
        <div class="row">
            <div class="col-lg-4 col-md-6">
                <div class="ss-field">
                    <label class="ss-label" for="reward_point">
                        {{ __('Reward points per :amount spent', ['amount' => ($setting['CURRENCY'] ?? '$') . '1000']) }}
                    </label>
                    <input type="number" step="0.01" class="ss-input" id="reward_point" name="reward_point"
                           value="{{ $setting['reward_point'] ?? '' }}"
                           placeholder="{{ __('Enter Point') }}">
                </div>
            </div>
        </div>
    </div>

    <div class="ss-card__footer ss-card__footer--end">
        <button type="submit" class="ss-btn ss-btn--primary btn-submit">{{ __('Save Changes') }}</button>
    </div>
    {!! Form::close() !!}
</div>
