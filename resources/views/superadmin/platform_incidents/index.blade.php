{{--
    OPS-003 — THE PLATFORM'S OWN INCIDENT LOG.

    `@extends` + `@section`, NEVER the `<x-*-layout>` slot shape. `layouts/app.blade.php` is the
    vendored WorkDo layout and contains zero `$slot` references, so a view written in the component
    shape renders perfect chrome, HTTP 200 and an EMPTY content area — measured, in all four
    language/theme combinations. tests/Unit/Ui/SlotLayoutViewsRenderTest fails on that shape
    because the failure is otherwise invisible.

    A SEVERITY BADGE HERE IS NOT THE FORBIDDEN KIND OF VERDICT, and the distinction is worth
    stating because the two measurement screens beside this one are pinned against carrying any
    colour at all. There, a coloured figure would be the PRODUCT judging a measurement against a
    threshold nobody set. Here, the severity is what an OPERATOR said about an incident when they
    recorded it, and showing their own statement in their own words is not a judgement the product
    invented. `ss-badge` also carries a dot as well as the colour, because colour alone must not
    carry meaning (WCAG 1.4.1).

    NOT TAILWIND. The merchant admin is Blade plus the vendored Bootstrap 5 WorkDo theme plus
    `public/css/store-syria-ds.css`; `@vite` appears in zero views and there is no build, so a
    utility class written here would simply not paint.

    BILINGUAL BY CONSTRUCTION: every string is `__()` and direction comes from the layout's own
    `dir`. Every state and severity word comes from `IncidentState::label()` and
    `IncidentSeverity::label()`, which resolve in the reader's own language — the stored value is
    always the machine key, so an incident recorded in Arabic reads correctly in English.
--}}
@extends('layouts.app')

@section('page-title')
    {{ __('Platform incidents') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">
        {{ __('Platform incidents') }}
    </li>
@endsection

@section('content')
    @include('layouts.includes.store-syria-ds')

@php
    /*
     * The badge tone per severity. A map and not a conditional chain, so adding a level to
     * `IncidentSeverity` fails loudly here (no tone) rather than silently rendering every new
     * level as neutral.
     */
    $toneOf = [
        \App\Support\Operations\IncidentSeverity::CRITICAL => 'danger',
        \App\Support\Operations\IncidentSeverity::MAJOR => 'warning',
        \App\Support\Operations\IncidentSeverity::MINOR => 'info',
        \App\Support\Operations\IncidentSeverity::INFORMATIONAL => 'neutral',
    ];
@endphp

<div class="row mb-4">
    <div class="col-12">
        <div class="ss-card">
            <h2 class="h5 mb-1">{{ __('What this log is for') }}</h2>

            <p class="ss-muted">
                {{ __('One row per thing that went wrong with the platform itself — the shared database, the queues, an integration, a host. It is not a support ticket and it is not an error report: it is the human record of an operational event, with the states it passed through and who moved it.') }}
            </p>

            <p class="ss-stat__hint">
                {{ __('Nothing opens an incident by itself. There is no threshold anywhere in this product and nothing sends an alert, so every row here was written by a person who noticed something.') }}
            </p>

            <p class="ss-stat__hint mb-0">
                {{ __('Nothing here is ever edited or deleted. A move is recorded as a move and a correction is recorded as a note, because a history that can be rewritten is not a history.') }}
            </p>
        </div>
    </div>
</div>

@if (session('error'))
    <div class="row mb-4">
        <div class="col-12">
            <div class="ss-card" role="alert">
                <p class="mb-0">{{ session('error') }}</p>
            </div>
        </div>
    </div>
@endif

@if (session('success'))
    <div class="row mb-4">
        <div class="col-12">
            <div class="ss-card" role="status">
                <p class="mb-0">{{ session('success') }}</p>
            </div>
        </div>
    </div>
@endif

{{-- RECORD ONE. The form is first because the commonest reason to open this screen is to write
     something down while it is happening. --}}
<div class="row mb-4">
    <div class="col-12">
        <div class="ss-card">
            <h2 class="h5 mb-1">{{ __('Record an incident') }}</h2>

            <p class="ss-muted">
                {{ __('What was measured at the moment you record this is saved with it, so the incident keeps the figures as they were rather than as they are when somebody reads it later.') }}
            </p>

            <form method="POST" action="{{ route('platform.incidents.store') }}" class="row g-3 mt-2">
                @csrf

                <div class="col-md-6">
                    <label class="ss-field__label" for="incident_title">{{ __('What happened') }}</label>
                    <input class="form-control" type="text" id="incident_title" name="title"
                           maxlength="160" required value="{{ old('title') }}"
                           placeholder="{{ __('e.g. Card payments failing at the gateway') }}">
                </div>

                <div class="col-md-3">
                    <label class="ss-field__label" for="incident_severity">{{ __('Severity') }}</label>
                    <select class="form-control" id="incident_severity" name="severity" required>
                        @foreach ($severities as $severity)
                            <option value="{{ $severity }}" @selected(old('severity') === $severity)>
                                {{ \App\Support\Operations\IncidentSeverity::label($severity) }}
                            </option>
                        @endforeach
                    </select>
                    <span class="ss-field__hint d-block">
                        {{ __('Severity describes impact. It carries no response time and notifies nobody: what each level obliges has not been decided.') }}
                    </span>
                </div>

                <div class="col-md-3">
                    <label class="ss-field__label" for="incident_detected_at">{{ __('When it began') }}</label>
                    <input class="form-control" type="datetime-local" id="incident_detected_at"
                           name="detected_at" value="{{ old('detected_at') }}">
                    <span class="ss-field__hint d-block">
                        {{ __('Leave blank for now. This is when the problem started, not when you are writing it down — they are usually different and the difference is how long it was broken.') }}
                    </span>
                </div>

                <div class="col-12">
                    <label class="ss-field__label" for="incident_summary">{{ __('What is known so far') }}</label>
                    <textarea class="form-control" id="incident_summary" name="summary" rows="3"
                              maxlength="5000">{{ old('summary') }}</textarea>
                </div>

                <div class="col-12">
                    <button type="submit" class="ss-btn ss-btn--primary">{{ __('Record it') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ss-incident-log-start -->
{{-- LIVE INCIDENTS, worst first. Ordered by `IncidentSeverity::rank()` in the handler and not by
     the column, because a string sort puts `critical` after `major` alphabetically. --}}
<div class="row mb-4">
    <div class="col-12">
        <h2 class="h5 mb-3">{{ __('Open now') }}</h2>

        @if ($live->isEmpty())
            <x-ss.empty :title="__('No incident is open.')"
                        :body="__('Nothing is being worked on. This is not a statement that the platform is healthy: nothing watches the figures, so an incident exists only once a person records one.')"
                        icon="check" />
        @else
            @include('superadmin.platform_incidents._table', ['incidents' => $live, 'toneOf' => $toneOf])
        @endif
    </div>
</div>

{{-- THE HISTORY. "Has this happened before" is the question an operations log exists to answer, and
     it cannot be answered by a list of only what is currently broken. --}}
<div class="row mb-4">
    <div class="col-12">
        <h2 class="h5 mb-1">{{ __('Closed') }}</h2>

        <p class="ss-muted mb-3">
            {{ __('Kept for as long as the platform runs. How long an incident is kept has not been decided, so nothing removes one.') }}
        </p>

        @if ($closed->isEmpty())
            <x-ss.empty :title="__('No incident has been closed yet.')"
                        :body="__('Resolved incidents stay here, so the next time something like this happens you can see what was done about it last time.')"
                        icon="history" />
        @else
            @include('superadmin.platform_incidents._table', ['incidents' => $closed, 'toneOf' => $toneOf])
        @endif
    </div>
</div>
<!-- ss-incident-log-end -->

<div class="row">
    <div class="col-12">
        <p class="ss-stat__hint">
            {{ \App\Support\Operations\PlatformIncidentPolicy::noAlertingNotice() }}
        </p>

        <p class="ss-stat__hint mb-0">
            {{ __('This log is for the platform operator only. Whether a merchant ever sees a platform incident has not been decided, so nothing here is shown to anybody else.') }}
        </p>
    </div>
</div>
@endsection
