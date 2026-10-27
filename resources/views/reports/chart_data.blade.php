{{--
    The customer-report body, re-rendered by AJAX each time the period changes.
    =============================================================================================

    PRD-001 — EVERY FIGURE CARRIES ITS PERIOD. This partial used to render
    `<h5><b>{{ $totaluser }} {{ __('orders') }}</b></h5>`: a bare number over three charts, with
    the selected window shown only as a pressed-looking button at the top of the page. Switch
    from "This year" to "Last 7 days" and the number changes with nothing beside it saying so,
    which is exactly the reading PRD-001 exists to stop. `$periodLabel` is derived in
    `ReportController::chartPeriodLabels()` from the same input the data was built from.

    AND THE CHARTS ARE NOW READABLE. The three ApexCharts here had no accessible representation
    of any kind — no table, no summary, not even a `role="img"` label — so a merchant using a
    screen reader got three empty divs. `<x-ss.bar-chart>` draws the same comparison and ships
    the numbers as a real `<table>` with a caption and `scope` on both axes, which is also how
    the dashboard's weekly chart works (P2-02-8). One chart mechanism, not two.
--}}

<div class="col-12">
    <x-ss.stats>
        <x-ss.stat :label="__('Orders')" :value="number_format($totaluser)" :period="$periodLabel" />
        <x-ss.stat :label="__('From registered customers')" :value="number_format($customer)" :period="$periodLabel" />
        <x-ss.stat :label="__('From guests')" :value="number_format($guest)" :period="$periodLabel" />
        <x-ss.stat :label="__('New customer sign-ups')" :value="number_format($customer_total)" :period="$periodLabel" />
    </x-ss.stats>

    {{-- "Order count", not "sales" or "revenue": these series count ORDERS, whatever their
         value or status, and naming them anything richer would be the claim PRD-001 forbids. --}}
    <x-ss.card :title="__('Orders placed')"
               :note="__('Counts every order placed in the period, whatever its status or value. Not revenue.')"
               class="mt-3">
        <x-ss.bar-chart
            :labels="$monthList"
            :values="$userTotal"
            :values2="$guestTotal"
            :title="__('Orders placed')"
            :value-label="__('Registered customers')"
            :value-label2="__('Guests')"
            :column-label="$columnLabel"
            :period="$periodLabel" />
    </x-ss.card>

    <x-ss.card :title="__('New sign-ups')"
               :note="__('Counts accounts registered, and guests seen for the first time, in the period.')"
               class="mt-3">
        <x-ss.bar-chart
            :labels="$monthList"
            :values="$registerTotal"
            :values2="$newguestTotal"
            :title="__('New sign-ups')"
            :value-label="__('Registered customers')"
            :value-label2="__('Guests')"
            :column-label="$columnLabel"
            :period="$periodLabel" />
    </x-ss.card>
</div>
