{{--
    P2-03-2 — the product list's filter bar.

    A PLAIN GET FORM, AND NO JAVASCRIPT ANYWHERE. Submitting reloads the page with the filters in
    the query string, and yajra's `minifiedAjax()` sets the table's `ajax.url` to the CURRENT url
    — so the AJAX leg inherits them too. That was measured in a real browser before the feature
    was designed around it, and it is why this needs neither a handler nor a script: one form,
    both legs.

    `ss_`-PREFIXED NAMES. The AJAX url is the page url, so DataTables' own `draw`, `columns`,
    `search`, `order`, `start` and `length` travel in the same query string. A control named
    `status` would be one library rename away from a collision.

    NO FILTER IS SUBMITTED AS AN EMPTY STRING BY ACCIDENT — each select's "any" option has
    `value=""`, which `ProductDataTable::applyFilters()` ignores, so the url stays clean and a
    bookmark means what it says.

    THE RESULT COUNT IS NOT SHOWN HERE. DataTables already prints "Showing 1 to N of M entries"
    below the table and keeps it correct as the filters change; a second count would be a second
    thing to keep in agreement, and this phase has already been bitten by two numbers describing
    one fact.
--}}
<form method="GET" action="{{ route('product.index') }}" data-ss-product-filters
      class="ss-scope ss-filterbar" role="search" aria-label="{{ __('Filter products') }}">

    {{-- P2-06-5. The receipt renders whenever filters are in force, not only after a restore:
         the "we kept these" flash survives one request, so a RELOAD of a restored page would
         otherwise be the silently-narrowed screen this exists to prevent. --}}
    <x-ss.filter-summary
        :filters="\App\Services\TableFilterMemory::describe(request(), 'product', collect($filterCategories ?? [])->toArray())"
        :restored="(bool) session('ss_filters_restored')" />


    <div class="ss-filterbar__field">
        <label class="ss-label" for="ss-filter-status">{{ __('Status') }}</label>
        <select class="ss-select" id="ss-filter-status" name="ss_status">
            <option value="">{{ __('Any status') }}</option>
            <option value="1" @selected(request()->query('ss_status') === '1')>{{ __('Active') }}</option>
            <option value="0" @selected(request()->query('ss_status') === '0')>{{ __('Inactive') }}</option>
        </select>
    </div>

    <div class="ss-filterbar__field">
        <label class="ss-label" for="ss-filter-stock">{{ __('Stock') }}</label>
        <select class="ss-select" id="ss-filter-stock" name="ss_stock"
                aria-describedby="ss-filter-stock-hint">
            <option value="">{{ __('Any stock level') }}</option>
            <option value="out" @selected(request()->query('ss_stock') === 'out')>{{ __('Out of stock') }}</option>
            <option value="low" @selected(request()->query('ss_stock') === 'low')>{{ __('Low stock') }}</option>
            <option value="in" @selected(request()->query('ss_stock') === 'in')>{{ __('In stock') }}</option>
        </select>
    </div>

    <div class="ss-filterbar__field">
        <label class="ss-label" for="ss-filter-category">{{ __('Category') }}</label>
        <select class="ss-select" id="ss-filter-category" name="ss_category">
            <option value="">{{ __('Any category') }}</option>
            @foreach (($filterCategories ?? []) as $categoryId => $categoryName)
                <option value="{{ $categoryId }}"
                    @selected(request()->query('ss_category') === (string) $categoryId)>{{ $categoryName }}</option>
            @endforeach
        </select>
    </div>

    <div class="ss-filterbar__field">
        <label class="ss-label" for="ss-filter-variant">{{ __('Variants') }}</label>
        <select class="ss-select" id="ss-filter-variant" name="ss_variant">
            <option value="">{{ __('Any') }}</option>
            <option value="1" @selected(request()->query('ss_variant') === '1')>{{ __('Has variants') }}</option>
            <option value="0" @selected(request()->query('ss_variant') === '0')>{{ __('No variants') }}</option>
        </select>
    </div>

    {{--
        THE HINT IS A FULL-WIDTH LINE, NOT A FIELD'S THIRD ROW, and both earlier attempts are
        why. `<x-ss.help>` inside the stock field rendered a bordered tinted callout the height
        of two controls, which pushed that field onto its own line. Swapping it for a quiet
        `.ss-field__hint` fixed the weight but not the geometry: the bar aligns its fields by
        `flex-end`, so a field carrying an extra line puts its SELECT higher than its
        neighbours'. Both were visible in the screenshots and neither was visible in any test.

        On its own line every field is label-plus-control, identical in height, and the bar
        aligns cleanly in all four configurations. `aria-describedby` keeps the hint attached to
        the control it is about, which is the part that actually mattered.
    --}}
    <p class="ss-filterbar__hint ss-field__hint" id="ss-filter-stock-hint">
        {{ __('Applies to products with stock tracking on.') }}
    </p>

    <div class="ss-filterbar__actions">
        {{-- `name="ss_apply"`: its PRESENCE is what writes the filter memory (P2-06-5). Without it,
             any url carrying an `ss_` parameter -- a bookmark, a link in an email, a url pasted
             between colleagues -- would silently become this merchant's new default. --}}
        <button type="submit" name="ss_apply" value="1" class="ss-btn ss-btn--primary">{{ __('Apply filters') }}</button>

        {{-- NON-EMPTY, NOT MERELY PRESENT, and the old test was a real defect: a GET form
             submits every control, so Apply with every select on "Any" yields
             `?ss_status=&ss_stock=&...`, where `query('ss_status')` is `''` -- which is
             `!== null`. So an UNFILTERED list showed a "Clear filters" button, and the button
             that is meant to be the honest signal that something is on was on when nothing was.

             `?ss_clear=1` rather than the bare url, because the bare url is exactly what a
             restore re-decorates: without a distinct forget signal, Clear would hop to
             `/product`, the memory would fire, and the merchant would be returned to the
             filters they just asked to leave. --}}
        @if (\App\Services\TableFilterMemory::describe(request(), 'product', collect($filterCategories ?? [])->toArray()) !== [])
            {{-- A LINK, not `type="reset"`: reset restores the form's initial values, which on a
                 filtered page are the filters themselves. --}}
            <a class="ss-btn ss-btn--ghost" href="{{ route('product.index', ['ss_clear' => 1]) }}">{{ __('Clear filters') }}</a>
        @endif
    </div>
</form>
