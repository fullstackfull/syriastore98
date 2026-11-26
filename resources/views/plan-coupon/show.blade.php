@extends('layouts.app')

@section('page-title', __('Plan Coupon Detail'))

@section('action-button')
@endsection

@section('breadcrumb')
    <li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('plan-coupon.index') }}">{{ __('Plan Coupon') }}</a></li>
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Plan Coupon Detail') }}</li>
@endsection

@push('css')
    @include('layouts.includes.datatable-css')
@endpush

{{--
    GAP-179 / P06-06 slice 3 -- THE ONE GRID IN THE PRODUCT WITH NO `ss-dt-card-body` ANCESTOR.

    Censused over every Blade file: 66 views render `<x-datatable>` and this one builds its own
    wrapper. That single difference was the whole defect. The component carries `ss-dt-card-body`,
    and EVERY repair in `store-syria-ds.css`'s grid-tool block is scoped under that class on
    purpose -- an unscoped table rule reaches all 70 vendored modules, which
    `DesignSystemStylesheetScopeTest` refused in P2.5-DESIGN-K. So this was the only grid still
    painted by the host's raw `btn-light-*` tints. Measured as the operator, APP_DEBUG off, all
    four combinations, against WCAG 2.1 1.4.11's 3:1 non-text floor:

        light   bulk-delete 2.66:1   reset 1.73:1   reload 1.74:1
        dark    colvis 1.39:1   density 1.39:1   bulk-delete 1.30:1   reset 1.15:1   reload 1.16:1

    THE OBVIOUS REPAIR WAS TO RENDER `<x-datatable>` LIKE THE OTHER 66, AND IT IS THE WRONG ONE.
    It was written, measured clean, and then withdrawn, because a capability probe found what a
    contrast harness cannot see: the component also pushes `bulk-delete::pages.script`, which is
    where `bulkDelete()` is DEFINED. Measured on this screen, with a `plan_user_coupons` row
    present so the grid is not empty:

        committed   .select-row 1   a.bulk-delete 1   typeof window.bulkDelete "undefined"
        converted   .select-row 1   a.bulk-delete 1   typeof window.bulkDelete "function"

    `buildDataTable()` already renders the Bulk Delete button and `buildDataTableColumn()` already
    renders the row checkbox, so the control is on screen either way -- but on THIS screen its
    handler has never existed, so clicking it throws a ReferenceError and deletes nothing.
    Converting the view would have SWITCHED ON bulk deletion of coupon-redemption history as a
    side effect of a colour change. That is a business-rule change on an operator screen and a
    standing escalation, not a contrast repair.

    So the class is added where it was missing and nothing else moves. One attribute, 16 findings,
    zero behaviour change -- and the live-page capability reading above is the evidence that the
    alternative was not a tidier version of the same change.
--}}
@section('content')
<div class="row">
    <div class="col-xl-12">
        <div class="card">
            <div class="card-body table-border-style ss-dt-card-body">
                <h5></h5>
                <div class="table-responsive">
                {{ $dataTable->table(['width' => '100%']) }}
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('custom-script')
    @include('layouts.includes.datatable-js')
    {{ $dataTable->scripts() }}

<script>
    var doc = new jsPDF();
    var elementHandler = {
    '#ignorePDF': function (element, renderer) {
        return true;
    }
    };
    var source = window.document.getElementsByTagName("body")[0];
    doc.fromHTML(
        source,
        15,
        15,
        {
        'width': 180,'elementHandlers': elementHandler
        });

    doc.output("dataurlnewwindow");
</script>
@endpush

