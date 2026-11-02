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

    THE GAP THAT USED TO BE RECORDED HERE IS CLOSED (F141, owner decision). A stored credential
    can be removed, but only by its own deliberate action — the "Remove saved value" button
    beside a field that has one, which asks the SERVER to confirm before anything is written.
    Switching a gateway off still keeps its keys, and an empty box on a normal save still means
    keep; removal is never a side effect of either. A credential a gateway needs cannot be
    removed while that gateway is switched on.

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

    {{--
        F141 — the form the per-field "Remove" buttons submit to.

        OUTSIDE the settings form and declared ONCE, because the payment screen is a single form
        wrapping all 52 gateways and a nested `<form>` is invalid HTML — the browser drops it and
        the button silently submits the outer form instead, which here would mean SAVING every
        gateway when the merchant asked to remove one credential.

        Each button carries `form="payment-secret-forget"` and its own `name="field"
        value="<the field>"`, so the field being removed is the button that was pressed. A
        submit button's name/value pair is part of the submission; no hidden input has to be
        kept in step with it, and there is nothing for a stale one to get wrong.
    --}}
    <form id="payment-secret-forget" method="POST" action="{{ route('payment.secret.confirm') }}">
        @csrf
    </form>
</div>
