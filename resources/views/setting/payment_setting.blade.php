{{--
    Payment gateways, on the design system — the shell; the cards are in `payment_partials/`.
    =============================================================================================

    THE EXCEPTION THIS FILE USED TO DOCUMENT IS CLOSED. It said the gateway cards
    (`setting/payment_partials/payment_gateway.blade.php`) still rendered every stored credential
    into their inputs, and that converting them was a slice of its own because each gateway's
    field set differs and the POST contract would need pinning first. Both halves have since been
    done: `SettingController::paymentSettingArray()` marks each of the 108 fields
    `'secret' => true|false`, the partial branches on that flag into `<x-ss.secret-field>`, and
    `PaymentSettingController::processGateway()` keeps the stored value when a masked box posts
    empty. The contract is pinned by `tests/Unit/Ui/PaymentGatewayFieldContractTest.php` and the
    persistence by `tests/Feature/Security/PaymentGatewaySecretPersistenceTest.php`.

    ONE GAP REMAINS AND IS NOT A COMMENT'S TO HIDE: there is now NO WAY TO CLEAR a stored gateway
    secret from this screen. Blank means keep, and unlike the other converted panels the enable
    toggle does not blank the fields — `processGateway()` deliberately leaves stored rows alone
    when a gateway is switched off. So a merchant can rotate a credential but not remove one.
    Making the off-toggle erase them would change behaviour for 52 providers (today a gateway can
    be switched off and on again with its keys intact), so it is recorded for the owner rather
    than decided here.

    The header carried an EMPTY `<div class="badge bg-success p-2 px-3 rounded"></div>` — a
    coloured chip with no text, which a screen reader announces as nothing and a sighted reader
    reads as a green smudge. Nothing ever filled it. Removed.

    The gateway search had an `<input>` with NO label, only a placeholder. A placeholder is not a
    label: it disappears the moment the user types, and several screen readers do not announce it
    at all.
--}}
<div class="ss-card" id="Payment_Setting">
    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('Payment Settings') }}</h2>
            <p class="ss-page-header__subtitle">
                {{ __('Configure your payment gateways for accepting online payments') }}
            </p>
        </div>
    </div>

    {{ Form::open(['route' => 'payment.settings', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
    <div class="ss-card__body">
        <div class="ss-field">
            <label class="ss-label ss-visually-hidden" for="payment-search">{{ __('Search payment gateway') }}</label>
            <input type="search" class="ss-input" id="payment-search"
                   placeholder="{{ __('Search payment gateway...') }}">
        </div>

        <div class="accordion accordion-flush" id="payment-gateways">
            <div class="gateway-section">
                @foreach ($paymentGateways as $gateway)
                    @php
                        $ssIsAdmin = auth()->user() && auth()->user()->type == 'admin';
                        $ssOnlyAdmin = $gateway['is_only_admin'] ?? null;
                    @endphp
                    {{-- Unchanged dispatch: an admin sees every gateway whose `is_only_admin` is
                         SET (true or false); everyone else sees only those where it is false. --}}
                    @if ($ssIsAdmin && $ssOnlyAdmin !== null)
                        @include('setting.payment_partials.payment_gateway', ['gateway' => $gateway])
                    @elseif (! $ssIsAdmin && $ssOnlyAdmin === false)
                        @include('setting.payment_partials.payment_gateway', ['gateway' => $gateway])
                    @endif
                @endforeach
            </div>
        </div>
    </div>

    <div class="ss-card__footer ss-card__footer--end">
        <button type="submit" class="ss-btn ss-btn--primary btn-submit">{{ __('Save Changes') }}</button>
    </div>
    {!! Form::close() !!}
</div>
