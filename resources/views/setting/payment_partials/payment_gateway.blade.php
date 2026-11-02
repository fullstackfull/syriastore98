{{--
    One payment gateway's accordion row, on the design system.
    =============================================================================================

    F117's LAST AND LARGEST EXCEPTION. This partial is rendered once per gateway -- 52 of them,
    108 fields -- and every field was

        <input class="form-control" type="text" value="{{ $setting[$field['key']] ?? '' }}">

    so opening the Payment tab put every configured provider's live secret key into the DOM at
    once. The other sixteen F117 fields were each named in a view and could be converted one at a
    time; these are GENERATED, so the fix had to be made where the fields are defined.
    `SettingController::paymentSettingArray()` now marks each field `'secret' => true|false`, its
    docblock records how each flag was set, and `PaymentGatewayFieldContractTest` asserts the flag
    is present on every field so a new gateway cannot skip the decision.

    DISPLAY AND PERSISTENCE CHANGED TOGETHER, because either alone is worse than neither.
    `PaymentSettingController::processGateway()` wrote
    `!empty($request->$field) ? $request->$field : ''`, so a box that posts empty wrote the EMPTY
    STRING over the stored credential. Masking without that change would have erased 58 secrets
    the first time anyone pressed Save on this screen.

    AND THE ENABLE TOGGLE HAD NO ACCESSIBLE NAME -- `<label class="custom-control-label" for="…">`
    with nothing between the tags, the same empty-label pattern found in every integration panel.
    It is rendered 52 times on this screen, so a screen reader met 52 checkboxes that announced
    nothing. The `x-ss.switch` component takes the name as text, so it cannot ship without one.

    WHAT IS DELIBERATELY UNCHANGED: every field `name`, the enable key, the image key, the
    description key, the `{id}_mode` radio names, the accordion's `data-bs-toggle="collapse"` and
    `data-bs-parent="#payment-gateways"` wiring, and the `is_only_admin` gate applied by the
    caller. `SettingsPanelContractTest` pins the panel's name set.
--}}
<div class="ss-card mb-3 accordion-item" id="gateway_{{ $gateway['id'] }}">
    <h2 class="accordion-header" id="{{ $gateway['id'] }}">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
            data-bs-target="#collapseone_{{ $gateway['id'] }}" aria-expanded="false"
            aria-controls="collapseone_{{ $gateway['id'] }}">
            <span class="d-flex align-items-center w-100 justify-content-between">
                <span>
                    <i class="ti ti-credit-card me-2" aria-hidden="true"></i>{{ __($gateway['name']) }}
                </span>
            </span>
        </button>
    </h2>
    <div id="collapseone_{{ $gateway['id'] }}" class="accordion-collapse collapse"
        aria-labelledby="{{ $gateway['id'] }}" data-bs-parent="#payment-gateways">
        <div class="ss-card__body">
            {{-- The toggle moved OUT of the accordion button. It was nested inside it, so a
                 click on the switch also toggled the panel and a keyboard user reached a
                 checkbox inside a button -- interactive content inside interactive content,
                 which has no defined activation behaviour. --}}
            <x-ss.switch :name="$gateway['enable_key']"
                         :id="$gateway['enable_key']"
                         value="on"
                         :label="__('Enable :gateway', ['gateway' => __($gateway['name'])])"
                         :checked="isset($setting[$gateway['enable_key']]) && $setting[$gateway['enable_key']] === 'on'" />

            <p class="ss-field__hint">
                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>
                {{ __('This configuration will be used for product checkout processing.') }}
            </p>

            @if (isset($gateway['mode_options']))
                <fieldset class="ss-field mt-3">
                    <legend class="ss-label">{{ __($gateway['name'] . ' Mode') }}</legend>
                    @php $currentMode = $setting[$gateway['id'] . '_mode'] ?? 'sandbox'; @endphp
                    @foreach ($gateway['mode_options'] as $mode => $label)
                        <div class="ss-choice">
                            <input type="radio" class="ss-choice__input"
                                   name="{{ $gateway['id'] }}_mode" value="{{ $mode }}"
                                   id="{{ $gateway['id'] }}_{{ $mode }}"
                                   @checked($currentMode === $mode)>
                            <label class="ss-choice__label" for="{{ $gateway['id'] }}_{{ $mode }}">
                                {{ __($label) }}
                            </label>
                        </div>
                    @endforeach
                </fieldset>
            @endif

            <div class="row mt-3">
                @if (isset($gateway['fields']))
                    <div class="col-md-{{ count($gateway['fields']) <= 2 ? '8' : '7' }}">
                        <div class="row">
                            @foreach ($gateway['fields'] as $field)
                                <div class="col-md-{{ count($gateway['fields']) <= 2 ? '12' : '6' }}">
                                    @if ($field['secret'] ?? true)
                                        {{-- `?? true` is the SAFE default, not a convenience: a
                                             field added without the flag is masked rather than
                                             exposed. The contract test fails on it separately,
                                             so the omission is still caught -- it just is not
                                             caught by a disclosure. --}}
                                        <x-ss.secret-field
                                            :name="$field['key']"
                                            :label="__($field['label'])"
                                            :stored="$setting[$field['key']] ?? null" />

                                        @if (\App\Support\SecretSetting::isSet($setting[$field['key']] ?? null))
                                            {{-- F141. Offered only when there IS something to
                                                 remove, so the control is an honest signal that
                                                 a credential is stored rather than decoration.

                                                 It asks the SERVER (`payment.secret.confirm`),
                                                 which renders a page naming this field and this
                                                 gateway. No JavaScript is involved, so it cannot
                                                 fail open. --}}
                                            <button type="submit" form="payment-secret-forget"
                                                    name="field" value="{{ $field['key'] }}"
                                                    class="ss-btn ss-btn--danger-quiet ss-btn--sm">
                                                {{ __('Remove saved value') }}
                                            </button>
                                        @endif
                                    @else
                                        <div class="ss-field">
                                            <label class="ss-label" for="{{ $field['key'] }}">{{ __($field['label']) }}</label>
                                            <input class="ss-input" type="text"
                                                   name="{{ $field['key'] }}" id="{{ $field['key'] }}"
                                                   value="{{ $setting[$field['key']] ?? '' }}"
                                                   placeholder="{{ __('Enter') . ' ' . __($field['label']) }}">
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="col-md-{{ count($gateway['fields']) <= 2 ? '4' : '5' }}">
                        @include('setting.payment_partials.gateway_image', ['gateway' => $gateway, 'setting' => $setting])
                    </div>
                @else
                    <div class="col-md-8">
                        <div class="ss-field">
                            <label for="{{ $gateway['description_key'] }}" class="ss-label">{{ __('Description') }}</label>
                            {!! Form::textarea($gateway['description_key'],
                                $setting[$gateway['description_key']] ?? $gateway['description_default'] ?? '',
                                ['class' => 'ss-textarea', 'placeholder' => __('Enter Description'),
                                 'rows' => '4', 'id' => $gateway['description_key']]) !!}
                        </div>
                    </div>
                    <div class="col-md-4">
                        @include('setting.payment_partials.gateway_image', ['gateway' => $gateway, 'setting' => $setting])
                    </div>
                @endif
            </div>

            @if (\Auth::user()->type == 'admin' && isset($gateway['description_key']) && isset($gateway['fields']))
                <div class="ss-field mt-3">
                    {{-- The same `id` as the textarea in the `@else` branch above, but the two
                         branches are mutually exclusive, so only one is ever in the document. --}}
                    <label for="{{ $gateway['description_key'] }}" class="ss-label">{{ __('Description') }}</label>
                    {!! Form::textarea($gateway['description_key'], $setting[$gateway['description_key']] ?? '',
                        ['class' => 'ss-textarea', 'placeholder' => __('Enter Description'), 'rows' => '3',
                         'id' => $gateway['description_key'],
                         'aria-describedby' => $gateway['description_key'] . '-hint']) !!}
                    <p class="ss-field__hint" id="{{ $gateway['description_key'] }}-hint">
                        {{ __('This description will be shown to customers during checkout.') }}
                    </p>
                </div>
            @endif
        </div>
    </div>
</div>
