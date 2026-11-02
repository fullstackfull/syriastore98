@props(['filters' => [], 'clearUrl' => null, 'clearLabel' => null, 'restored' => false])

{{--
    P2-06-5 — a filtered list says so, in words.

    THE FAILURE THIS EXISTS TO PREVENT is the one P2-06-4 named when it refused to restore a
    search term: *"a merchant who searched 'refund' last Thursday comes back to a table showing
    four rows out of nine hundred, with the box pre-filled somewhere they are not looking, and
    concludes their orders are gone."* Restoring a filter is that same screen with a dropdown
    instead of a search box, so the restore is only safe if the narrowing is impossible to miss.

    IT RENDERS WHENEVER FILTERS ARE ON, NOT ONLY AFTER A RESTORE, and that is the load-bearing
    choice. The "we restored this" flash survives exactly one request: a merchant who RELOADS a
    restored page would otherwise be looking at the silently-narrowed screen this component
    exists to prevent. Rendering on the typed case too also closes the same hole for a url that
    arrived from a bookmark or a colleague, which has been open since the filter bars shipped.

    IT STATES; IT DOES NOT ACT. An earlier version carried its own "Show all products" link, and
    driving the real page is what showed why that was wrong: the bar already has a "Clear
    filters" control a few lines below, in the same form and visible without scrolling, so the
    page offered TWO links to one url with two different labels about 100px apart. That reads as
    two different actions. `clearUrl` is still a prop for a caller whose surface has no clear
    control of its own; the two list bars do not pass it.

    IT NAMES THE FILTERS AND NEVER A ROW COUNT. The bar's own docblock already refuses a second
    count, because DataTables prints "Showing 1 to N of M" below the table and two numbers
    describing one fact is a thing to keep in agreement. What is NOT already on screen is which
    filters are responsible, in a form a merchant can read without inspecting four selects.
--}}

@if (!empty($filters))
    <div class="ss-filterbar__summary" role="status">
        <p class="ss-filterbar__summary-text">
            @if ($restored)
                {{ __('We kept the filters you were using.') }}
            @else
                {{ __('Filters are on.') }}
            @endif
            <span class="ss-filterbar__summary-list">{{ implode(' · ', $filters) }}</span>
        </p>

        @if ($clearUrl)
            <a class="ss-btn ss-btn--ghost ss-btn--sm" href="{{ $clearUrl }}">{{ $clearLabel }}</a>
        @endif
    </div>
@endif
