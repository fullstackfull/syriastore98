@extends('layouts.app')

@section('page-title', __('Plan Coupon Detail'))

@section('action-button')
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('plan-coupon.index') }}">{{ __('Plan Coupon') }}</a></li>
    <li class="breadcrumb-item">{{ __('Plan Coupon Detail') }}</li>
@endsection

@push('css')
    @include('layouts.includes.datatable-css')
@endpush

@section('content')
<div class="row">
    <div class="col-xl-12">
        <div class="card">
            <div class="card-body table-border-style">
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

