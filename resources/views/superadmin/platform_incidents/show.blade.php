{{--
    OPS-003 — ONE INCIDENT AND ITS WHOLE HISTORY.

    `@extends` + `@section`, NEVER the `<x-*-layout>` slot shape — see the index view's note.

    THE TRAIL IS THE POINT OF THIS PAGE. An incident's current state is one word and is on the list
    screen; what this page adds is the SEQUENCE, which is the only thing that tells a real
    resolution from a mis-click. So the trail is rendered in full, oldest first, with who did each
    thing and when, and there is no control anywhere on this page that edits or removes any of it.

    THE BUTTONS ARE BUILT FROM THE STATE MACHINE, not written out. `$moves` is
    `IncidentState::movesFrom()`, so a move the machine refuses has no button — and the handler
    refuses it again anyway, because a button is not a control.

    NO FIGURE ON THIS PAGE IS JUDGED. The snapshot is rendered exactly as the two measurement
    producers made it, with each row's own period, source and unavailability sentence, and nothing
    is coloured or compared with anything.
--}}
@extends('layouts.app')

@section('page-title')
    {{ $incident->reference }}
@endsection

@section('breadcrumb')
    {{-- `ss-breadcrumb__link` AND NOT `ss-link`, which is the class this one anchor needs and the
         one the first draft of this file got wrong -- twice, in the same place.
         `BreadcrumbContractTest` asserts the breadcrumb-specific class, because a breadcrumb link
         takes a token redefined per theme while an unclassed anchor keeps the host theme's own
         brand colour, which is the dark-mode contrast failure that test exists for. The generic
         `ss-link` is not that token. Caught by the ratchet both times rather than by reading. --}}
    <li class="ss-breadcrumb__item">
        <a class="ss-breadcrumb__link" href="{{ route('platform.incidents.index') }}">{{ __('Platform incidents') }}</a>
    </li>
    <li aria-current="page" class="ss-breadcrumb__item">{{ $incident->reference }}</li>
@endsection

@section('content')
    @include('layouts.includes.store-syria-ds')

@if (session('error'))
    <div class="row mb-4">
        <div class="col-12">
            <div class="ss-card" role="alert"><p class="mb-0">{{ session('error') }}</p></div>
        </div>
    </div>
@endif

@if (session('success'))
    <div class="row mb-4">
        <div class="col-12">
            <div class="ss-card" role="status"><p class="mb-0">{{ session('success') }}</p></div>
        </div>
    </div>
@endif

<!-- ss-incident-detail-start -->
<div class="row mb-4">
    <div class="col-12">
        <div class="ss-card">
            <h2 class="h5 mb-1">{{ $incident->title }}</h2>

            @if ($incident->summary)
                <p class="ss-muted">{{ $incident->summary }}</p>
            @endif

            <div class="table-responsive mt-3">
                <table class="table">
                    <caption class="ss-visually-hidden">{{ __('What is recorded about this incident.') }}</caption>
                    <tbody>
                        <tr>
                            <th scope="row">{{ __('Reference') }}</th>
                            <td><code class="ss-code">{{ $incident->reference }}</code></td>
                        </tr>
                        <tr>
                            <th scope="row">{{ __('Severity') }}</th>
                            <td>
                                {{ $incident->severityLabel() }}
                                <span class="ss-field__hint d-block">
                                    {{ \App\Support\Operations\IncidentSeverity::description($incident->severity) }}
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">{{ __('State') }}</th>
                            <td>
                                {{ $incident->statusLabel() }}
                                <span class="ss-field__hint d-block">
                                    {{ \App\Support\Operations\IncidentState::description($incident->status) }}
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">{{ __('Began') }}</th>
                            <td>
                                {{ $incident->detected_at ? $incident->detected_at->toDateTimeString() : __('Not recorded') }}
                                <span class="ss-field__hint d-block">
                                    {{ __('As the operator stated it. This is when the problem started, not when the record was written.') }}
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">{{ __('Recorded') }}</th>
                            <td>{{ $incident->created_at ? $incident->created_at->toDateTimeString() : __('Not recorded') }}</td>
                        </tr>
                        <tr>
                            <th scope="row">{{ __('Recorded by') }}</th>
                            <td>{{ $incident->openedByName() ?? __('Not recorded') }}</td>
                        </tr>
                        @foreach ([
                            'acknowledged_at' => __('Acknowledged'),
                            'mitigated_at' => __('Mitigated'),
                            'resolved_at' => __('Resolved'),
                        ] as $column => $label)
                            <tr>
                                <th scope="row">{{ $label }}</th>
                                <td>
                                    @if ($incident->{$column})
                                        {{ $incident->{$column}->toDateTimeString() }}
                                    @else
                                        {{ __('Not yet') }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($incident->resolved_at && $incident->isLive())
                <p class="ss-stat__hint mb-0">
                    {{ __('This incident was resolved once before and was reopened. The first resolution time is kept: it really happened, and the reopening is in the history below.') }}
                </p>
            @endif
        </div>
    </div>
</div>

{{-- THE MOVES. Built from the state machine, so there is never a button for a move that would be
     refused — and the handler checks again regardless, because a button is not a control. --}}
<div class="row mb-4">
    <div class="col-12">
        <div class="ss-card">
            <h2 class="h5 mb-1">{{ __('Move this incident') }}</h2>

            @if ($moves === [])
                <p class="ss-muted mb-0">{{ __('There is nowhere for this incident to go from here.') }}</p>
            @else
                <p class="ss-muted">
                    {{ __('Each move is recorded with who made it and when. A move cannot be undone; a correction is a note.') }}
                </p>

                @foreach ($moves as $move)
                    <form method="POST" action="{{ route('platform.incidents.transition', $incident->id) }}"
                          class="row g-2 align-items-end mb-3">
                        @csrf
                        <input type="hidden" name="to" value="{{ $move }}">

                        <div class="col-md-8">
                            <label class="ss-field__label" for="note_{{ $move }}">
                                {{ \App\Support\Operations\IncidentState::moveLabel($incident->status, $move) }}
                                — {{ __('why, if it helps') }}
                            </label>
                            <input class="form-control" type="text" id="note_{{ $move }}" name="note"
                                   maxlength="5000">
                            <span class="ss-field__hint d-block">
                                {{ \App\Support\Operations\IncidentState::description($move) }}
                            </span>
                        </div>

                        <div class="col-md-4">
                            <button type="submit" class="ss-btn ss-btn--secondary w-100">
                                {{ \App\Support\Operations\IncidentState::moveLabel($incident->status, $move) }}
                            </button>
                        </div>
                    </form>
                @endforeach
            @endif
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-12">
        <div class="ss-card">
            <h2 class="h5 mb-1">{{ __('Add a note') }}</h2>

            <p class="ss-muted">
                {{ __('Anything worth recording that does not change the state. Notes are never edited or removed.') }}
            </p>

            <form method="POST" action="{{ route('platform.incidents.note', $incident->id) }}" class="row g-2 mt-2">
                @csrf

                <div class="col-12">
                    {{-- THE MARKER, and `RequiredFieldMarkerTest` is why it is here rather than
                         left to the browser: a `required` control whose label does not say so is
                         a form that refuses on submit without having told anybody which field it
                         wants. `aria-hidden` on the asterisk because the `required` attribute is
                         what a screen reader announces -- the marker is for eyes only, and
                         announcing both would say it twice. --}}
                    <label class="ss-field__label ss-visually-hidden" for="incident_note">{{ __('Note') }}</label><span class="ss-label__required" aria-hidden="true">*</span>
                    <textarea class="form-control" id="incident_note" name="note" rows="3"
                              maxlength="5000" required></textarea>
                </div>

                <div class="col-12">
                    <button type="submit" class="ss-btn ss-btn--secondary">{{ __('Add the note') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- THE HISTORY, oldest first, in full. --}}
<div class="row mb-4">
    <div class="col-12">
        <h2 class="h5 mb-1">{{ __('History') }}</h2>

        <p class="ss-muted mb-3">
            {{ __('Everything that has happened to this incident, in the order it happened. Nothing in this list can be changed or removed.') }}
        </p>

        <div class="table-responsive">
            <table class="table">
                <caption class="ss-visually-hidden">
                    {{ __('The incident audit trail: every move and every note, oldest first.') }}
                </caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('When') }}</th>
                        <th scope="col">{{ __('What') }}</th>
                        <th scope="col">{{ __('Who') }}</th>
                        <th scope="col">{{ __('Note') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($incident->events as $event)
                        <tr>
                            <th scope="row">
                                {{ $event->recorded_at ? $event->recorded_at->toDateTimeString() : __('Not recorded') }}
                            </th>
                            <td>{{ $event->describe() }}</td>
                            <td>{{ $event->actorName() ?? __('Not recorded') }}</td>
                            <td>{{ $event->note ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

{{--
    THE SNAPSHOT. What was measured at the moment the incident was recorded, rendered exactly as
    the producers made it — each row with its own period, its own source and, where a figure was
    withheld, the sentence saying why. This is the half of OPS-003's diagnostics that the
    measurement screens cannot supply: they show NOW, and an incident is about then.
--}}
<div class="row mb-4">
    <div class="col-12">
        <h2 class="h5 mb-1">{{ __('What was measured at the time') }}</h2>

        @if (empty($incident->diagnostics))
            <p class="ss-muted mb-0">
                {{ __('No measurements were saved with this incident. Either it was recorded after the fact, or the host would not answer at the moment it was written — which is itself worth knowing.') }}
            </p>
        @else
            <p class="ss-muted mb-3">
                {{ __('Read at the moment this incident was recorded, and not since. These figures do not change when this page is reloaded.') }}
            </p>

            @php
                /*
                 * THE SNAPSHOT IS DECODED DEFENSIVELY AND NOT TRUSTED TO STILL HAVE TODAY'S SHAPE.
                 * It is JSON written by whatever version of the producers was deployed when the
                 * incident was recorded, and an incident from six months ago must still render
                 * after a producer gains or loses a group. So the groups are walked as they are
                 * found rather than named, and a row is printed only if it carries the five keys
                 * this table reads. An old incident that fails that test shows nothing for that
                 * group rather than raising on an operator trying to read an outage.
                 */
                $groups = [];

                foreach (($incident->diagnostics ?? []) as $producer => $producerGroups) {
                    if (! is_array($producerGroups)) {
                        continue;
                    }

                    foreach ($producerGroups as $name => $rows) {
                        if (! is_array($rows) || $rows === []) {
                            continue;
                        }

                        $usable = [];

                        foreach ($rows as $row) {
                            if (is_array($row) && array_key_exists('label', $row) && array_key_exists('value', $row)) {
                                $usable[] = $row;
                            }
                        }

                        if ($usable !== []) {
                            $groups[$producer . ' / ' . $name] = $usable;
                        }
                    }
                }
            @endphp

            @forelse ($groups as $name => $rows)
                <h3 class="h6 mb-2"><code class="ss-code">{{ $name }}</code></h3>

                <div class="table-responsive mb-4">
                    <table class="table">
                        <caption class="ss-visually-hidden">
                            {{ __('Measurements saved with this incident.') }}
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Measurement') }}</th>
                                <th scope="col">{{ __('Figure') }}</th>
                                <th scope="col">{{ __('Period') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <th scope="row">{{ $row['label'] }}</th>
                                    <td>
                                        @if ($row['value'] === null)
                                            {{ __('Not available') }}
                                        @else
                                            {{ $row['value'] }}
                                        @endif
                                    </td>
                                    <td>{{ $row['period'] ?? '' }}</td>
                                </tr>
                                @if (! empty($row['reason']))
                                    <tr>
                                        <td colspan="3" class="ss-stat__hint">{{ $row['reason'] }}</td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @empty
                <p class="ss-muted mb-0">
                    {{ __('The measurements saved with this incident are in a shape this screen no longer reads. Nothing has been lost: they are still stored exactly as they were taken.') }}
                </p>
            @endforelse
        @endif
    </div>
</div>
<!-- ss-incident-detail-end -->
@endsection
