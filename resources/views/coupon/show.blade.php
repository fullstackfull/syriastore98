@extends('layouts.app')

@section('page-title', __('Coupon Detail'))

@section('action-button')
@endsection

@section('breadcrumb')
    <li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('coupon.index') }}">{{ __('Coupon') }}</a></li>
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Coupon Detail') }}</li>
@endsection

@section('content')
<div class="row">
    <div class="col-xl-12">
        <x-datatable :dataTable="$dataTable" />
    </div>
</div>
@endsection

@push('custom-script')
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
