@props(['screen', 'route', 'label' => null])

{{--
    MKT-002 — the filter bar for the five Marketing lists, as ONE copy.

    WHY IT IS A COMPONENT AND NOT FIVE PARTIALS. `product/partials/filters.blade.php` and its order
    twin each carry ten hard-won properties, every one of them a defect somebody already shipped.
    Five more copies would be five more places for those ten to drift, and this project's own rule
    for the shared sales-summary card is "one copy, so the next fix lands on both". The per-screen
    difference is DATA -- which filters a screen has -- so it is read rather than written out.

    IT ASKS `TableFilterMemory` FOR ITS OWN LABELS AND OPTIONS. `vocabularyFor()` gives the filters,
    `headingFor()` the heading, `optionsFor()` the value wording. So the control a merchant uses and
    the receipt printed above it come from the SAME arrays: a select cannot offer a value the receipt
    has no words for, which is exactly the shape that produces a narrowed list with no Clear button.

    A PLAIN GET FORM, AND NO JAVASCRIPT ANYWHERE. Submitting reloads the page with the filters in the
    query string, and yajra's `minifiedAjax()` sets the table's `ajax.url` to the CURRENT url, so the
    AJAX leg inherits them too. One form, both legs -- which is also why a JS disclosure would be a
    second mechanism beside a design that works because it has none.

    `ss_`-PREFIXED NAMES, because the AJAX url IS the page url: DataTables' own `draw`, `columns`,
    `search`, `order`, `start` and `length` travel in the same query string, and a control named
    `status` would be one library rename away from a collision.

    EVERY "ANY" OPTION IS `value=""`, which each DataTable's `applyFilters()` ignores, so an
    unfiltered submit leaves the url clean and a bookmark means what it says.
--}}

@php
    $ssFilters = \App\Services\TableFilterMemory::vocabularyFor($screen);
    $ssReceipt = \App\Services\TableFilterMemory::describe(request(), $screen);
@endphp

<form method="GET" action="{{ route($route) }}"
      class="ss-scope ss-filterbar" role="search"
      aria-label="{{ $label ?? __('Filter this list') }}">

    {{-- The receipt renders whenever filters are in force, not only after a restore: the "we kept
         these" flash survives one request, so a RELOAD of a restored page would otherwise be the
         silently-narrowed screen this exists to prevent. --}}
    <x-ss.filter-summary :filters="$ssReceipt" :restored="(bool) session('ss_filters_restored')" />

    {{-- Phone width: the fields and actions collapse behind this and the summary above stays
         visible. CSS-only -- see components/ss/filter-disclosure.blade.php. --}}
    <x-ss.filter-disclosure :id="'ss-filters-' . $screen" />

    @foreach ($ssFilters as $ssName)
        @php
            $ssId = 'ss-filter-' . $screen . '-' . str_replace('_', '-', $ssName);
            $ssHeading = \App\Services\TableFilterMemory::headingFor($ssName);
            $ssOptions = \App\Services\TableFilterMemory::optionsFor($screen, $ssName);
            $ssCurrent = request()->query($ssName);
            $ssCurrent = is_string($ssCurrent) ? $ssCurrent : '';
        @endphp

        <div class="ss-filterbar__field">
            <label class="ss-label" for="{{ $ssId }}">{{ __($ssHeading) }}</label>

            @if ($ssOptions === [])
                {{-- A date, which `label()` echoes back rather than wording: `ss_from` and `ss_to`
                     have no vocabulary, so they are inputs and not selects. `type="date"` so the
                     browser enforces the `Y-m-d` shape `TableFilterMemory::parseDate()` requires --
                     and a value it still rejects simply narrows nothing. --}}
                <input type="date" class="ss-input" id="{{ $ssId }}" name="{{ $ssName }}"
                       value="{{ $ssCurrent }}">
            @else
                <select class="ss-select" id="{{ $ssId }}" name="{{ $ssName }}">
                    <option value="">{{ __('Any') }}</option>
                    @foreach ($ssOptions as $ssValue => $ssWording)
                        <option value="{{ $ssValue }}"
                            @selected($ssCurrent === (string) $ssValue)>{{ $ssWording }}</option>
                    @endforeach
                </select>
            @endif
        </div>
    @endforeach

    <div class="ss-filterbar__actions">
        {{-- `name="ss_apply"`: its PRESENCE is what writes the filter memory. Without it, any url
             carrying an `ss_` parameter -- a bookmark, a link in an email, a url pasted between
             colleagues -- would silently become this merchant's new default. --}}
        <button type="submit" name="ss_apply" value="1" class="ss-btn ss-btn--primary">{{ __('Apply filters') }}</button>

        {{-- RENDERED ON THE RECEIPT, NOT ON "A PARAMETER IS PRESENT", and the difference was a real
             defect on the product screen: a GET form submits every control, so Apply with every
             select on "Any" yields `?ss_status=&ss_type=&...`, where `query('ss_status')` is `''` --
             which is `!== null`. An UNFILTERED list then showed a Clear button, so the button meant
             to be the honest signal that something is on was on when nothing was.

             `?ss_clear=1` rather than the bare url, because the bare url is exactly what a restore
             re-decorates: without a distinct forget signal, Clear would hop to the list, the memory
             would fire, and the merchant would be returned to the filters they just left. --}}
        @if ($ssReceipt !== [])
            {{-- A LINK, not `type="reset"`: reset restores the form's initial values, which on a
                 filtered page are the filters themselves. --}}
            <a class="ss-btn ss-btn--ghost"
               href="{{ route($route, [\App\Services\TableFilterMemory::CLEAR => 1]) }}">{{ __('Clear filters') }}</a>
        @endif
    </div>
</form>
