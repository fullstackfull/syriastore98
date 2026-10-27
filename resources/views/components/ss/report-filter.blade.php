@props([
    'periods' => null,
    'selected' => 'year',
    'date' => null,
    'id' => 'report',
    'label' => null,
    'generateValue' => '',
    'generateDisabled' => false,
])

{{--
    THE PERIOD PICKER FOUR REPORT SCREENS HAD A COPY OF, EACH WRONG THE SAME WAY.
    =============================================================================================

    `reports/index`, `sale_by_product`, `sale_by_category` and `sale_by_brand` each carried the
    same hand-written bar, and each carried the same four defects with it:

      - `<ul role="tablist">` with buttons that have NO `role="tab"`, no `aria-selected` and no
        `aria-controls`. The selection was an `active` CSS class and nothing else, so a
        screen-reader user could not tell which of four periods they were looking at — while
        `role="tablist"` promised tab behaviour that was not there.
      - Two `<div>`s as DIRECT CHILDREN of that `<ul>`. Only `<li>` may be a child of a list, so
        the date field and the Generate button were announced as part of the list's item count.
      - The date input had no label at all.
      - The duplication itself: fixing one screen fixed one screen.

    These are not tabs — a tab reveals a panel that is already in the page, and these refetch —
    so they are buttons in a labelled group with `aria-pressed`. The CSS selects on
    `[aria-pressed="true"]` too (`.ss-chip`), so the announced state and the visible state cannot
    drift apart, which is exactly what the parallel `active` class allowed.

    @param array|null  $periods   value => label; the four standard windows by default
    @param string      $selected  which one is pressed on first load
    @param string|null $date      the date field's value
    @param string      $id        prefix for the date field's id, so two bars on one page do not
                                  collide (an `id` must be unique and matching is CASE-SENSITIVE)
    @param string|null $label     the group's accessible name
    @param string      $generateValue  what Generate posts as `chart_data`. The hub re-runs the
                                  SELECTED period and sends nothing; the sale-by screens have a
                                  distinct `date-wise` branch and send that.
    @param bool        $generateDisabled  start Generate disabled, for a screen whose date-wise
                                  branch needs a date before it means anything. The screen's own
                                  script enables it when the field is filled — and `disabled` is
                                  used rather than a class, so a keyboard user is told.
--}}

@php
    $ssPeriods = $periods ?? [
        'year' => __('This year'),
        'last-month' => __('Last month'),
        'this-month' => __('This month'),
        'seven-day' => __('Last 7 days'),
    ];
    $ssDateId = $id . '-date';
@endphp

<div {{ $attributes->merge(['class' => 'ss-toolbar']) }}
     role="group" aria-label="{{ $label ?? __('Report period') }}">
    @foreach ($ssPeriods as $value => $text)
        <button type="button"
                class="ss-chip chart-data"
                name="chart_data"
                value="{{ $value }}"
                aria-pressed="{{ $value === $selected ? 'true' : 'false' }}">{{ $text }}</button>
    @endforeach

    <div class="ss-field">
        <label class="ss-label" for="{{ $ssDateId }}">{{ __('Or pick a date') }}</label>
        <input type="date"
               class="ss-input date month-btn"
               id="{{ $ssDateId }}"
               name="date"
               value="{{ $date ?? request('date', date('Y-m-d')) }}">
    </div>

    <button type="button" class="ss-btn ss-btn--primary ss-btn--sm generate_button"
            id="{{ $id }}-generate"
            value="{{ $generateValue }}"
            @disabled($generateDisabled)>{{ __('Generate') }}</button>

    {{ $slot }}
</div>
