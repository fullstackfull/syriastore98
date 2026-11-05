@props(['id' => 'ss-filters-disclosure'])

{{--
    P2.5-WAVE-22 — the filter bar's progressive disclosure, for phone width only.

    WHY IT EXISTS. MEASURED at 320x568 before this component: `/product` put its first data row
    at 814px and `/order` at 1293px, with ZERO rows above the fold in all four combinations. The
    filter bar alone was 427px of that — four fields taking three quarters of the screen before a
    merchant saw a single product. A list screen whose first screen carries no list is not a
    premium screen, whatever its contrast ratios.

    IT IS CSS-ONLY, AND THAT IS A CONSTRAINT THE BAR ITSELF IMPOSES. The two filter partials
    declare "A PLAIN GET FORM, AND NO JAVASCRIPT ANYWHERE", and explain why: yajra's
    `minifiedAjax()` sets the table's ajax url to the CURRENT url, so one form drives both the
    page load and the AJAX leg. A JS disclosure would put a second mechanism beside a design that
    works because it has none.

    THE CHECKBOX CARRIES NO `name`, DELIBERATELY. This is a GET form: every NAMED control is
    submitted. An unnamed control is not, so the disclosure cannot appear in the query string —
    which matters because that string is shared with DataTables' own `draw`, `columns`, `search`,
    `order`, `start` and `length`, and because a bookmarked filter url has to mean what it says.

    THE SUMMARY IS NOT COLLAPSED WITH THE FIELDS. `<x-ss.filter-summary>` renders ABOVE this
    control and stays visible: it is the receipt that says which filters are in force, and its own
    docblock explains that it exists to prevent exactly the failure a silent collapse would cause
    — "a merchant comes back to a table showing four rows out of nine hundred and concludes their
    orders are gone". Collapsing the CONTROLS while the STATE stays on screen is the whole design.

    TWO LABELS, ONE SHOWN AT A TIME, so the control's accessible name states the action it will
    perform. `display: none` content is excluded from the accessible name computation, so a
    screen reader reads "Show filters" when collapsed and "Hide filters" when open, with no ARIA
    and no script.

    THE FOCUS RING IS MOVED TO THE LABEL ON PURPOSE. The checkbox is `.ss-visually-hidden`, which
    keeps it focusable (clip, not display:none) — and store-syria-a11y.css restores an outline for
    `*:focus-visible`, so without the rule in the stylesheet the ring would be drawn on a 1px
    clipped box and no keyboard user would ever see it.
--}}

<input type="checkbox" id="{{ $id }}" class="ss-filterbar__toggle ss-visually-hidden">

<label class="ss-filterbar__disclosure" for="{{ $id }}">
    <i class="ti ti-adjustments-horizontal" aria-hidden="true"></i>
    <span class="ss-filterbar__disclosure-show">{{ __('Show filters') }}</span>
    <span class="ss-filterbar__disclosure-hide">{{ __('Hide filters') }}</span>
</label>
