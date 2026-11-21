{{--
    THE MERCHANT ANALYTICS SCREEN. P5-4 / ANA-001…ANA-004.

    `@extends` + `@section`, NEVER the `<x-*-layout>` slot shape. `layouts/app.blade.php` is the
    vendored WorkDo layout and contains zero `$slot` references, so a view written in the component
    shape renders perfect chrome, HTTP 200 and an EMPTY content area — measured, in all four
    language/theme combinations. tests/Unit/Ui/SlotLayoutViewsRenderTest fails on that shape because
    the failure is otherwise invisible.

    EVERY TILE'S TEXT COMES FROM THE FIGURES CLASS, not from this file. The label is the only string
    chosen here; the period and the caveat are computed beside the number they qualify, so a figure
    and its own limits cannot drift apart in a later edit of one file. `value` of null renders as
    "Not available" through the component — never as a zero, which would be a different claim.

    NOTHING HERE COMPUTES, FORMATS OR ROUNDS. If a number needs working out, it belongs in
    App\Support\Metrics\MerchantAnalyticsFigures where a test can see it; a Blade that does
    arithmetic is a figure no test covers.

    BILINGUAL BY CONSTRUCTION: every string is `__()`, and direction comes from the layout's own
    `dir` (the admin side follows the locale, not a SITE_RTL setting). There is no hard-coded ltr/rtl
    and no directional margin in this file, so the grid mirrors with the page.
--}}
@extends('layouts.app')

@section('page-title')
    {{ __('Analytics') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">
        {{ __('Analytics') }}
    </li>
@endsection

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <p class="ss-muted">
            {{ __('How the store has been doing. Every figure states the period it covers and what it leaves out.') }}
        </p>
    </div>
</div>

{{-- ANA-002 — THE PERIOD FILTER AND THE SAVED PERIODS.

     A GET FORM, SO THE PERIOD IS IN THE URL. That is the whole design: a merchant can bookmark a
     period, send it to a colleague, or press back and get the period they were looking at. A POST
     would hide the state in a session and break all three. It also means there is no CSRF token to
     reason about, because nothing is written — and the one thing that IS written (a saved period)
     has its own POST form below with its own token.

     `ss_period` is a SELECT over `AnalyticsPeriod::options()`, which is built from the same
     constant `fromRequest()` validates against, so an option cannot be offered without being
     accepted or accepted without being offered. The custom dates are `type="date"`, which gives the
     browser's own picker and an ISO value; the server re-parses them strictly anyway, because a
     `type="date"` input is a hint to a browser and not a guarantee about bytes.

     THE CLAMP NOTICE IS NOT A TOAST. When a requested period was shortened or moved, the sentence
     sits above the figures it applies to and stays there, because it describes what these numbers
     cover. A dismissable toast would leave a merchant reading a window that is not the one they
     asked for with nothing on screen saying so. --}}
<div class="row mb-4">
    <div class="col-12">
        <div class="ss-card">
            <form method="GET" action="{{ route('analytics.index') }}" class="row g-2 align-items-end">
                <div class="col-sm-4">
                    <label class="ss-field__label" for="ss_period">{{ __('Period') }}</label>
                    <select class="form-control" id="ss_period" name="ss_period">
                        @foreach ($periodOptions as $value => $words)
                            <option value="{{ $value }}" @selected($periodKey === $value)>{{ $words }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-sm-3">
                    <label class="ss-field__label" for="ss_from">{{ __('From') }}</label>
                    {{-- `max` IS DEFENCE IN DEPTH AND NOT THE CONTROL. A future start date was a
                         live 500 (see AnalyticsPeriod::custom()); the server fix is what closes it,
                         and this only stops the browser offering a day that has not happened. A
                         `type="date"` input is a hint to a browser, never a guarantee about bytes. --}}
                    <input class="form-control" type="date" id="ss_from" name="ss_from" max="{{ $periodToday }}" value="{{ $periodFrom }}">
                </div>

                <div class="col-sm-3">
                    <label class="ss-field__label" for="ss_to">{{ __('To') }}</label>
                    <input class="form-control" type="date" id="ss_to" name="ss_to" max="{{ $periodToday }}" value="{{ $periodTo }}">
                </div>

                <div class="col-sm-2">
                    <button type="submit" class="ss-btn ss-btn--primary w-100">{{ __('Apply') }}</button>
                </div>

                {{-- The two date inputs only matter for the custom option, and saying so in text
                     rather than hiding them with JavaScript keeps the form usable with scripting
                     off — and keeps the hint available to a screen reader, which a `display:none`
                     would not. --}}
                <div class="col-12">
                    <p class="ss-field__hint mb-0">
                        {{ __('The two dates apply when the period is "a period I choose". A period longer than :count days is shortened, and the end is moved to today if it is later.', ['count' => \App\Support\Analytics\AnalyticsPeriod::MAX_SPAN_DAYS]) }}
                    </p>
                </div>
            </form>

            @if (! empty($savedPeriods) && count($savedPeriods) > 0)
                <div class="mt-3">
                    <h2 class="h6 mb-2">{{ __('Saved periods') }}</h2>
                    <ul class="list-unstyled d-flex flex-wrap gap-2 mb-0">
                        @foreach ($savedPeriods as $saved)
                            <li>
                                <a class="ss-btn ss-btn--secondary ss-btn--sm"
                                   href="{{ route('analytics.index', ['ss_view' => $saved->id]) }}">{{ $saved->name }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- SAVING IS A WRITE, so it is a POST with a token, and it posts the period that is
                 CURRENTLY ON SCREEN rather than whatever is in the select — the two differ after a
                 clamp, and saving the un-clamped request would store a period the merchant has
                 never actually seen. --}}
            <form method="POST" action="{{ route('saved-views.store') }}" class="row g-2 align-items-end mt-2">
                @csrf
                <input type="hidden" name="screen" value="analytics">

                {{-- `filters[...]`, NOT flat keys. `SavedViewController::store()` reads
                     `$request->input('filters')` and runs it through the screen's vocabulary; flat
                     `ss_period` would sanitise to `[]` and the controller would answer "Select at
                     least one filter before saving a view" on a screen where one is always
                     selected. Measured against the controller rather than guessed from the form. --}}
                <input type="hidden" name="filters[ss_period]" value="{{ $periodKey }}">
                <input type="hidden" name="filters[ss_from]" value="{{ $periodFrom }}">
                <input type="hidden" name="filters[ss_to]" value="{{ $periodTo }}">

                <div class="col-sm-6">
                    <label class="ss-field__label" for="saved_period_name">{{ __('Save this period as') }}</label>
                    <input class="form-control" type="text" id="saved_period_name" name="name" maxlength="60"
                           placeholder="{{ __('e.g. Ramadan campaign') }}">
                </div>

                <div class="col-sm-3">
                    <button type="submit" class="ss-btn ss-btn--secondary w-100">{{ __('Save') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ANA-003 — SCHEDULED EXPORTS OF THIS FILE.

     THE "LAST SENT" COLUMN IS THE POINT OF THIS TABLE, not decoration. Delivery depends on the
     server running `php artisan schedule:run` every minute, and the project's own Phase 7 readiness
     notes record that as an UNVERIFIED item for production — "a deployment without a scheduler
     passes §4–§10 and fails silently". A schedule that has never fired must therefore look
     different from one that is working, or its absence looks like success. So each row says when it
     last ran and how that run ended, and a row that has never run says so in words.

     THE RECIPIENT IS NOT A FIELD. It is the signed-in employee's own account address, shown so the
     merchant knows where the file goes. An e-mail input here would make this an authenticated mail
     relay: store figures to any address on the internet, on a timer.

     The form posts the period that is CURRENTLY ON SCREEN, clamps included, for the same reason the
     saved-period form does — saving the un-clamped request would store a window the merchant has
     never seen. --}}
<div class="row mb-4">
    <div class="col-12">
        <div class="ss-card">
            <h2 class="h5 mb-1">{{ __('Scheduled exports') }}</h2>

            <p class="ss-muted">
                {{ __('Receive this file by e-mail on a schedule, at :email.', ['email' => auth('web')->user()?->email]) }}
            </p>

            @if (! empty($schedules) && count($schedules) > 0)
                <div class="table-responsive">
                    <table class="table">
                        <caption class="ss-visually-hidden">{{ __('Scheduled exports') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Name') }}</th>
                                <th scope="col">{{ __('Period') }}</th>
                                <th scope="col">{{ __('How often') }}</th>
                                <th scope="col">{{ __('Last sent') }}</th>
                                <th scope="col">{{ __('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($schedules as $schedule)
                                <tr>
                                    <th scope="row">{{ $schedule->name }}</th>
                                    <td>{{ $schedule->period_key === 'custom'
                                        ? $schedule->period_from?->toDateString() . ' — ' . $schedule->period_to?->toDateString()
                                        : $schedule->period_key }}</td>
                                    <td>{{ $scheduleFrequencies[$schedule->frequency] ?? $schedule->frequency }}</td>
                                    <td>
                                        @if ($schedule->hasEverRun())
                                            {{ $schedule->last_run_at->toDayDateTimeString() }}
                                            @if ($schedule->last_outcome !== \App\Models\AnalyticsReportSchedule::OUTCOME_SENT)
                                                <span class="ss-stat__hint">{{ $schedule->last_outcome }}</span>
                                            @endif
                                        @else
                                            {{ __('Never sent yet') }}
                                        @endif
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('analytics.schedules.destroy', $schedule->id) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="ss-btn ss-btn--secondary ss-btn--sm">{{ __('Stop') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="ss-stat__hint">
                    {{ __('A scheduled export is sent by the server, not by your browser. If a report says it has never been sent, the server\'s scheduler may not be running — ask whoever administers it.') }}
                </p>
            @else
                <p>{{ __('You have no scheduled exports.') }}</p>
            @endif

            <form method="POST" action="{{ route('analytics.schedules.store') }}" class="row g-2 align-items-end mt-2">
                @csrf
                <input type="hidden" name="ss_period" value="{{ $periodKey }}">
                <input type="hidden" name="ss_from" value="{{ $periodFrom }}">
                <input type="hidden" name="ss_to" value="{{ $periodTo }}">

                <div class="col-sm-5">
                    <label class="ss-field__label" for="schedule_name">{{ __('Send this period as') }}</label>
                    <input class="form-control" type="text" id="schedule_name" name="name" maxlength="60"
                           placeholder="{{ __('e.g. Monday morning numbers') }}">
                </div>

                <div class="col-sm-4">
                    <label class="ss-field__label" for="schedule_frequency">{{ __('How often') }}</label>
                    <select class="form-control" id="schedule_frequency" name="frequency">
                        @foreach ($scheduleFrequencies as $value => $words)
                            <option value="{{ $value }}">{{ $words }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-sm-3">
                    <button type="submit" class="ss-btn ss-btn--secondary w-100">{{ __('Schedule') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

@if (! empty($figures['period']['clamped']))
    <div class="row mb-4">
        <div class="col-12">
            <p class="ss-stat__hint" role="status">{{ $figures['period']['clamped'] }}</p>
        </div>
    </div>
@endif

{{-- ACTIVITY. Orders and page views are counts of rows, so they are always available; conversion is
     a ratio and is "Not available" whenever there is nothing to divide by. --}}
<div class="row mb-4">
    <div class="col-12">
        <x-ss.stats :label="__('Store activity')">
            <x-ss.stat :label="__('Orders')"
                       :value="$figures['orders']['value']"
                       :period="$figures['orders']['period']"
                       :hint="$figures['orders']['hint']"
                       :comparison="$figures['orders']['comparison']" />

            <x-ss.stat :label="__('Page views')"
                       :value="$figures['visits']['value']"
                       :period="$figures['visits']['period']"
                       :hint="$figures['visits']['hint']"
                       :comparison="$figures['visits']['comparison']" />

            <x-ss.stat :label="__('Conversion')"
                       :value="$figures['conversion']['value']"
                       :period="$figures['conversion']['period']"
                       :hint="$figures['conversion']['hint']" />

            <x-ss.stat :label="__('Buyers')"
                       :value="$figures['customers']['value']"
                       :period="$figures['customers']['period']"
                       :hint="$figures['customers']['hint']" />

            {{-- P5-6. Its PERIOD differs from its neighbours' thirty days on purpose: a repeat
                 purchase needs two orders, so a thirty-day window would report every store as less
                 loyal than it is. The tile states the period it really covers, which is the span the
                 financial record can vouch for (owner decision D1). --}}
            <x-ss.stat :label="__('Repeat purchase')"
                       :value="$figures['retention']['value']"
                       :period="$figures['retention']['period']"
                       :hint="$figures['retention']['hint']" />
        </x-ss.stats>
    </div>
</div>

{{-- ANA-004. WHERE THE VISITS CAME FROM — two breakdowns, and neither invents a category.

     These are TABLES rather than tiles because a breakdown is a set of rows and a tile is one
     number; the platform screen made the same choice for its per-currency amounts. Nothing here
     computes, folds, orders or totals: MerchantAnalyticsFigures did all of it, including the
     decision to order by count rather than by label and to name an absence an absence.

     THE TWO DISCLOSURES ARE THE POINT OF THE SECTION. The device list says a visit is a REQUEST
     and not a person, and that an undetermined device is shown as not recorded rather than
     guessed. The referrer list says that no host is filed under a marketing channel — that would
     need a maintained list of search and social domains — and that a visit with no referring page
     is NOT a direct visit, which is the reading a merchant will otherwise make. --}}
<div class="row">
    @foreach ([
        'devices' => ['heading' => __('Devices'), 'column' => __('Device')],
        'channels' => ['heading' => __('Referring pages'), 'column' => __('Referring page')],
    ] as $key => $meta)
        <div class="col-xl-6">
            <div class="ss-card mt-3">
                <h2 class="h5 mb-1">{{ $meta['heading'] }}</h2>

                <p class="ss-muted mb-3">
                    {{ __('Period') }}: {{ $figures[$key]['period'] }}
                </p>

                @if ($figures[$key]['rows'] === [])
                    <p>{{ __('Not available') }}</p>
                @else
                    <div class="table-responsive">
                        <table class="table">
                            <caption class="ss-visually-hidden">
                                {{ $meta['heading'] }} — {{ $figures[$key]['period'] }}
                            </caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ $meta['column'] }}</th>
                                    <th scope="col">{{ __('Visits') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($figures[$key]['rows'] as $row)
                                    <tr>
                                        {{-- The label is this row's HEADER, not another cell. With two
                                             plain `<td>`s a screen reader reading the count column
                                             announces "5" with no indication of WHICH device or
                                             referrer it belongs to; `scope="row"` is what makes the
                                             pairing audible. The column headers already carry
                                             `scope="col"`. --}}
                                        <th scope="row">{{ $row['label'] }}</th>
                                        <td>{{ $row['count'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <p class="ss-stat__hint">{{ $figures[$key]['hint'] }}</p>
            </div>
        </div>
    @endforeach
</div>

{{-- MONEY. The same two tiles as `/finance`, from the same FinanceLedgerFigures, so the two screens
     cannot disagree about one number — which is the defect this reuse exists to prevent. Their
     period is the ledger's own coverage (store_order_financials has no backfill), not the 30-day
     window above, and each tile says so itself. --}}
<div class="row mb-4">
    <div class="col-12">
        <h2 class="h5 mb-3">{{ __('Money') }}</h2>

        <x-ss.stats :label="__('Financial ledger')">
            <x-ss.stat :label="__('Payments received')"
                       :value="$figures['payments']['value']"
                       :period="$figures['payments']['period']"
                       :hint="$figures['payments']['hint']" />

            <x-ss.stat :label="__('Estimated gross profit')"
                       :value="$figures['profit']['value']"
                       :period="$figures['profit']['period']"
                       :hint="$figures['profit']['hint']" />

            {{-- P5-5 / section 9. Shown only for a period whose redemption records are complete; for
                 any earlier period the figures class returns a null value and says why, because a
                 number with "this cannot be trusted" under it gets read as a number. --}}
            <x-ss.stat :label="__('Coupon discounts given')"
                       :value="$figures['redemptions']['value']"
                       :period="$figures['redemptions']['period']"
                       :hint="$figures['redemptions']['hint']" />
        </x-ss.stats>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <p class="ss-muted">
            {{ __('These figures are read from this store\'s own records and are never shared between stores.') }}
        </p>
    </div>
</div>
{{-- P5-8. The export is reachable from the screen it exports, and carries the same gate. The
     description below is not decoration: it is the one place a merchant learns that the file keeps
     the disclosures, which is why the file is worth downloading instead of copying the tiles. --}}
<div class="row mb-4">
    <div class="col-12">
        <a class="btn btn-sm btn-outline-primary" href="{{ route('analytics.export') }}">
            {{ __('Download as CSV') }}
        </a>
        <p class="ss-stat__hint mt-2">
            {{ __('Download these figures as a spreadsheet. Every row carries its own period and what it leaves out, so a number cannot be read apart from its limits.') }}
        </p>
    </div>
</div>
@endsection
