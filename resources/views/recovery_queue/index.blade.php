@extends('layouts.app')

@section('page-title')
    {{ __('Recovery Queue') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Recovery Queue') }}</li>
@endsection

@section('content')
    @include('layouts.includes.store-syria-ds')

    {{--
        MKT-007 -- WHAT IS WORTH RECOVERING, AND WHY NOTHING WAS SENT.

        THE FIRST CARD IS THE HONEST ONE AND IT IS FIRST ON PURPOSE. Automatic promotional sending is
        OFF by owner decision until the required consent enforcement is approved and active. A screen
        that listed recoverable baskets without saying so would read as "these have been chased",
        which is the opposite of the truth -- and a merchant who believed it would stop chasing them
        by hand.

        IT READS THE SAME TWO OBJECTS THE SWEEP READS -- the consent send gate and the nudge
        throttle -- rather than restating their state, so the screen and the sweep cannot drift
        apart. Their class names are deliberately NOT spelled here: `ConsentLedgerCensusTest` holds
        that naming the gate outside a declared seam is a finding, and a Blade comment is not
        stripped by its scanner (`token_get_all` on a file with no `<?php` yields one
        `T_INLINE_HTML` token), so prose about the gate would have read as wiring it.
    --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="ss-card">
                <h2 class="h5 mb-1">{{ __('Nothing is sent automatically') }}</h2>

                <p class="ss-muted">
                    {{ __('This screen finds baskets and unpaid orders worth following up. It does not message anybody, and it is not waiting to: automatic sending stays switched off until consent rules are approved and active.') }}
                </p>

                <p class="ss-stat__hint">
                    @if (! $consentEnforced)
                        {{ __('Consent enforcement is off, so no promotional message may be sent automatically.') }}
                    @elseif (! $cadenceConfigured)
                        {{ __('No approved limit on how often a shopper may be messaged has been set, so no promotional message may be sent automatically.') }}
                    @else
                        {{ __('Both checks would allow a send, but this screen still sends nothing: releasing the queue is a separate, deliberate step.') }}
                    @endif
                </p>

                <p class="ss-stat__hint">
                    {{ __('You can still follow up on a basket yourself from the abandoned baskets screen, one at a time, exactly as before.') }}
                </p>
            </div>
        </div>
    </div>

    {{--
        THE AGES. No default and no suggested number: how long before a basket counts as abandoned is
        a marketing judgement, and this project does not invent those. A store that sets nothing
        detects nothing, and that is the shipped state.
    --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="ss-card">
                <h2 class="h5 mb-1">{{ __('When to start following up') }}</h2>

                <p class="ss-muted">
                    {{ __('Set how old something has to be before it appears below. Leave a field blank and that kind is not looked for at all.') }}
                </p>

                <form method="POST" action="{{ route('recovery-queue.ages') }}" class="row g-2 align-items-end mt-2">
                    @csrf

                    @foreach ($kinds as $kindKey => $kindSpec)
                        <div class="col-sm-4">
                            <label class="ss-field__label" for="age_{{ $kindKey }}">
                                {{ __($kindSpec['label']) }} ({{ __('hours') }})
                            </label>
                            <input class="form-control ss-num" type="number" min="1" max="8760" step="1"
                                   id="age_{{ $kindKey }}" name="{{ $kindKey }}"
                                   value="{{ $ages[$kindKey] ?? '' }}"
                                   placeholder="{{ __('Not looked for') }}">
                        </div>
                    @endforeach

                    <div class="col-sm-4">
                        <button type="submit" class="ss-btn ss-btn--secondary w-100">{{ __('Save') }}</button>
                    </div>
                </form>

                <p class="ss-stat__hint mt-2">
                    {{ __('A basket is only listed if the shopper has not ordered since leaving it, so somebody who came back and bought is never followed up.') }}
                </p>

                <p class="ss-stat__hint">
                    {{ __('An order counts as not paid only while your financial record says nothing has arrived. A part-paid order is a different conversation and is not listed here.') }}
                </p>

                <p class="ss-stat__hint">
                    {{ __('The check runs on the server. If this list stays empty while you expect entries, the server\'s scheduler may not be running — ask whoever administers it.') }}
                </p>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="ss-card">
                <h2 class="h5 mb-1">
                    {{ __('Waiting for follow-up') }}
                    (<span class="ss-num">{{ number_format($candidates->total()) }}</span>)
                </h2>

                @if ($candidates->total() > 0)
                    <div class="table-responsive">
                        <table class="table ss-table">
                            <caption class="ss-visually-hidden">{{ __('Waiting for follow-up') }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('Kind') }}</th>
                                    <th scope="col">{{ __('Reference') }}</th>
                                    <th scope="col">{{ __('First seen') }}</th>
                                    <th scope="col">{{ __('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($candidates as $candidate)
                                    <tr>
                                        <th scope="row">
                                            {{ __($kinds[$candidate->kind]['label'] ?? $candidate->kind) }}
                                        </th>
                                        <td>
                                            {{-- The subject's type and id, which is what a merchant
                                                 needs to find it. A link would need a per-type route
                                                 and a per-type permission check, and getting that
                                                 wrong is how a list becomes a door. --}}
                                            {{ $candidate->subject_type }}
                                            <span class="ss-num">#{{ $candidate->subject_id }}</span>
                                        </td>
                                        <td>
                                            {{ $candidate->detected_at?->toDayDateTimeString() ?? __('Unknown') }}
                                        </td>
                                        <td>
                                            @if ($candidate->isHeld())
                                                {{ __('Not sent') }}
                                                <span class="ss-stat__hint">
                                                    @if ($candidate->held_reason === \App\Models\MarketingRecoveryCandidate::HELD_CONSENT_OFF)
                                                        {{ __('Consent enforcement is off') }}
                                                    @elseif ($candidate->held_reason === \App\Models\MarketingRecoveryCandidate::HELD_NO_CADENCE)
                                                        {{ __('No approved sending limit') }}
                                                    @else
                                                        {{ __('Sending is not switched on') }}
                                                    @endif
                                                </span>
                                            @else
                                                {{ __('Released') }}
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{ $candidates->links() }}
                @else
                    <p>{{ __('Nothing is waiting for follow-up.') }}</p>
                @endif
            </div>
        </div>
    </div>
@endsection
