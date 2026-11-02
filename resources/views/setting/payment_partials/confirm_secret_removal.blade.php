@extends('layouts.app')

@section('page-title', __('Remove a saved credential'))

@section('breadcrumb')
    <li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('setting.index') }}">{{ __('Settings') }}</a></li>
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Remove a saved credential') }}</li>
@endsection

@section('content')
    {{--
        F141 — the confirmation step, on the SERVER.

        A PAGE RATHER THAN A DIALOG, and that is the whole reason this step exists. The house
        confirm helper is JavaScript and FAILS OPEN: with scripting broken or blocked the button
        submits and the credential is gone with no question asked. It also resolves its form with
        `closest("form")`, which on the payment screen — one form wrapping all 52 gateways —
        would submit the entire settings form instead. A destructive credential action has to
        fail closed, so the question is asked by the server and answered by a second request.

        IT NAMES ONE FIELD AND ONE GATEWAY. A confirmation that says "are you sure?" and nothing
        else is a click to get past, not a decision; this one says which credential, for which
        provider, and what removal does NOT do.
    --}}
    <div class="ss-page">
        <div class="ss-card">
            <div class="ss-card__header">
                <div class="ss-page-header__text">
                    <h2 class="ss-card__title">{{ __('Remove the saved :field?', ['field' => __($label)]) }}</h2>
                    <p class="ss-page-header__subtitle">{{ __($gateway['name']) }}</p>
                </div>
            </div>

            <div class="ss-card__body">
                @if ($blocked)
                    <div class="ss-alert ss-alert--warning" role="alert">
                        <div class="ss-alert__content">
                            <p class="ss-alert__title">{{ __('This gateway is switched on.') }}</p>
                            <p>{{ __('Removing a credential :gateway needs while it is switched on would leave it unable to take payments, and a shopper would meet the failure at checkout. Turn :gateway off first, then remove the credential.', ['gateway' => __($gateway['name'])]) }}</p>
                        </div>
                    </div>
                @else
                    <p>{{ __('This removes the stored value from Store Syria. The field goes back to "not set", and the gateway will have no credential until a new one is entered.') }}</p>

                    {{-- The sentence the owner's decision asks for in so many words, and the one
                         a merchant is most likely to assume the opposite of. --}}
                    <div class="ss-alert ss-alert--info" role="note">
                        <div class="ss-alert__content">
                            <p class="ss-alert__title">{{ __('This does not revoke anything at the payment provider.') }}</p>
                            <p>{{ __('A key removed here can still be used by anyone who already has a copy of it. If you are removing it because it may have leaked, revoke or rotate it in :gateway\'s own dashboard as well — that is the step that makes it stop working.', ['gateway' => __($gateway['name'])]) }}</p>
                        </div>
                    </div>
                @endif
            </div>

            <div class="ss-card__footer ss-card__footer--between">
                <a class="ss-btn ss-btn--secondary" href="{{ route('setting.index') }}">{{ __('Cancel') }}</a>

                @unless ($blocked)
                    <form method="POST" action="{{ route('payment.secret.remove') }}">
                        @csrf
                        <input type="hidden" name="field" value="{{ $field }}">
                        {{-- The confirmation travels as a VALUE the handler checks, so the write
                             re-asks the question rather than trusting that this page was seen. --}}
                        <input type="hidden" name="confirm" value="remove">
                        <button type="submit" class="ss-btn ss-btn--danger">
                            {{ __('Remove the saved :field', ['field' => __($label)]) }}
                        </button>
                    </form>
                @endunless
            </div>
        </div>
    </div>
@endsection
