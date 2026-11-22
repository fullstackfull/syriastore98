@props([
    'action',
    'periodKey' => '',
    'periodOptions' => [],
    'periodFrom' => '',
    'periodTo' => '',
    'periodToday' => '',
    'periodClamped' => null,
])

{{--
    ANA-002 / GAP-221. THE PERIOD CONTROL FOR A SERVER-RENDERED REPORT SCREEN.
    =============================================================================================

    EXTRACTED AT THE SECOND COPY, NOT THE FIFTH. GAP-221 names five report screens that are
    genuinely unfiltered, and the first two had already produced an identical fifty lines. The
    four-screen period bar this directory also carries (`<x-ss.report-filter>`) is the cautionary
    tale: it was hand-copied four times and carried the same four accessibility defects into
    every copy, so fixing one screen fixed one screen.

    THIS IS NOT THAT COMPONENT, AND THE DIFFERENCE IS NOT COSMETIC.

      `<x-ss.report-filter>` posts the LEGACY vocabulary -- `chart_data=year|last-month|…` plus a
      `Date` range parsed on `' to '` -- and drives an AJAX fragment through per-view jQuery.

      This posts `ss_period` / `ss_from` / `ss_to`, which is `AnalyticsPeriod`: it CLAMPS the span
      (`MAX_SPAN_DAYS`), labels the window, and yields a comparable baseline through
      `MetricWindow::previous()`. GAP-221 names it as the building block, and it is the one with
      tests and a clamp.

      So the reports centre briefly carries two period vocabularies. That is CONVERGENCE on the
      newer one rather than a third invention, and the legacy screens are what should migrate.
      Do not "unify" these two by reverting a screen to `chart_data`.

    A PLAIN GET FORM, NO JAVASCRIPT. These screens render server-side and have no AJAX fragment,
    so a GET form is the whole mechanism. It also makes a filtered view bookmarkable and
    shareable, and keeps it working with scripting off.

    THE CALLER PLACES IT OUTSIDE `#printableArea`. A printed report should carry its period as a
    caption -- which the chart already does -- not as a form nobody can use on paper.

    @param string      $action         the screen's own route, so the form returns to it
    @param string      $periodKey      the selected period, '' for the unfiltered view
    @param array       $periodOptions  value => label, from `AnalyticsPeriod::options()`
    @param string      $periodFrom     the custom range's start, '' when none is set
    @param string      $periodTo       the custom range's end
    @param string      $periodToday    today, as the browser-side `max` hint on both date fields
    @param string|null $periodClamped  the note a shortened or moved window must state
--}}

<div class="row">
    <div class="col-12">
        <div class="ss-card">
            <form method="GET" action="{{ $action }}" class="row g-2 align-items-end">
                <div class="col-sm-4">
                    <label class="ss-field__label" for="ss_period">{{ __('Period') }}</label>
                    <select class="form-control" id="ss_period" name="ss_period">
                        {{-- The empty option is the SHIPPED DEFAULT and must stay first: with no
                             `ss_period` the report covers all time, exactly as it did before this
                             filter existed. Selecting a period is opt-in. --}}
                        <option value="" @selected($periodKey === '')>{{ __('All time') }}</option>
                        @foreach ($periodOptions as $value => $words)
                            <option value="{{ $value }}" @selected($periodKey === $value)>{{ $words }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-sm-3">
                    <label class="ss-field__label" for="ss_from">{{ __('From') }}</label>
                    {{-- `max` is a hint to the browser and never a guarantee about bytes; the
                         server clamp in `AnalyticsPeriod::custom()` is the control. --}}
                    <input class="form-control" type="date" id="ss_from" name="ss_from"
                           max="{{ $periodToday }}" value="{{ $periodFrom }}">
                </div>

                <div class="col-sm-3">
                    <label class="ss-field__label" for="ss_to">{{ __('To') }}</label>
                    <input class="form-control" type="date" id="ss_to" name="ss_to"
                           max="{{ $periodToday }}" value="{{ $periodTo }}">
                </div>

                <div class="col-sm-2">
                    <button type="submit" class="ss-btn ss-btn--primary w-100">{{ __('Apply') }}</button>
                </div>

                <div class="col-12">
                    {{-- NAMED AS THE OPTION IS LABELLED, which it was not. This sentence used to
                         read "when the period is set to Custom" -- and the select has never
                         offered an option called Custom: `AnalyticsPeriod::options()` labels that
                         key `a period I choose`. The mismatch was just as visible in Arabic
                         («مضبوطة على مخصص» against «فترة أختارها»). Found by review AFTER the
                         extraction had carried it to a second screen, which is exactly the failure
                         this component's docblock cites about its four-times-copied neighbour.

                         This is `/analytics`'s own sentence, reused rather than reworded, so the
                         four surfaces now say one thing in one place -- and it states the cap up
                         front instead of leaving `clampNote()` to disclose it after the fact. --}}
                    <p class="ss-muted mb-0">
                        {{ __('The two dates apply when the period is "a period I choose". A period longer than :count days is shortened, and the end is moved to today if it is later.', ['count' => \App\Support\Analytics\AnalyticsPeriod::MAX_SPAN_DAYS]) }}
                    </p>
                    @if ($periodClamped)
                        {{-- A period that was shortened or moved must say so beside the figure,
                             not silently answer a different question. --}}
                        <p class="ss-muted mb-0" role="status">{{ $periodClamped }}</p>
                    @endif
                </div>
            </form>
        </div>
    </div>
</div>
