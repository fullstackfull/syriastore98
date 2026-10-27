{{--
    Payment gateways, on the design system — the SHELL only.
    =============================================================================================

    THE GATEWAY CARDS THEMSELVES ARE `setting/payment_partials/payment_gateway.blade.php`, and
    they still render every stored gateway credential into their inputs. That is the documented
    exception to F117's claim: "no credential reaches a settings page" holds for the eleven
    converted panels and NOT for this tab, which is `StoreSettingsAccess::permits()`-gated and is
    not among F117's sixteen. Converting that partial is a slice of its own, because every
    gateway's field set is different and the POST contract for each would need pinning first.

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
