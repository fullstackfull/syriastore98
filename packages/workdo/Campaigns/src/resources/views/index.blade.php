@extends('layouts.app')

@section('page-title')
    {{ __('Campaigns') }}
@endsection
@section('title')
    {{ __('Campaigns') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item active" aria-current="page">{{ __('Campaigns') }}</li>
@endsection

@section('action-button')
    @if (Auth::user()->type == 'admin')
        <div class="col-xl-12 col-lg-12 col-md-12 d-flex align-items-center justify-content-between justify-content-md-end"
            data-bs-placement="top">
            <a href="#" data-size="lg" data-url="{{ route('campaigns.create') }}" data-ajax-popup="true"
                data-bs-toggle="tooltip" title="{{ __('Add New Campaigns') }}" data-title="{{ __('Create New Campaigns') }}"
                class="btn btn-sm btn-primary btn-badge">
                <i class="ti ti-plus"></i>
            </a>
        </div>
    @endif
@endsection

@section('content')
    <div class="col-3">
    </div>
    <div class="col-sm-12 col-lg-12 col-xl-12 col-md-12">
        <div class="mt-2" id="multiCollapseExample1" style="">
            <div class="card">
                <div class="card-body">
                    {{ Form::open(['route' => ['campaigns.index'], 'method' => 'get', 'id' => 'cam_filter']) }}
                    <div class="d-flex row row-gap align-items-center">
                        @if (Auth::user()->type == 'super admin')
                            <div class="col-xl-2 col-lg-3 col-sm-6  col-12 ">
                                <div class="btn-box">
                                    {{ Form::label('user', __('User'), ['class' => 'form-label']) }}
                                    {{ Form::select('user', $userList, isset($_GET['user']) ? $_GET['user'] : '', ['class' => 'form-control', 'id' => 'user']) }}
                                </div>
                            </div>
                        @endif

                        <div class="col-xl-2 col-lg-3 col-sm-6  col-12 ">
                            <div class="btn-box">
                                {{ Form::label('store', __('Store'), ['class' => 'form-label']) }}
                                {{ Form::select('store', $catList, isset($_GET['store']) ? $_GET['store'] : '', ['class' => 'form-control', 'id' => 'store']) }}
                            </div>
                        </div>
                        <div class="col-xl-2 col-lg-3 col-sm-6  col-12">
                            <div class="btn-box">
                                {{ Form::label('product', __('Product'), ['class' => 'form-label']) }}
                                {{ Form::select('product', $productList, isset($_GET['product']) ? $_GET['product'] : '', ['class' => 'form-control', 'id' => 'product']) }}
                            </div>
                        </div>

                        <div class="col-xl-2 col-lg-3 col-sm-6  col-12 ">
                            <div class="btn-box">
                                {{ Form::label('start_date', __('Start date'), ['class' => 'form-label']) }}
                                <input type="date" name="start_date" class="form-control" id="start_date"
                                    value="{{ isset($_GET['start_date']) ? $_GET['start_date'] : '' }}" placeholder ="">
                            </div>
                        </div>
                        <div class="col-xl-2 col-lg-3 col-sm-6  col-12 ">
                            <div class="btn-box">
                                {{ Form::label('end_date', __('End date'), ['class' => 'form-label']) }}
                                <input type="date" name="end_date" class="form-control" id="end_date"
                                    value="{{ isset($_GET['end_date']) ? $_GET['end_date'] : '' }}" placeholder ="">
                            </div>
                        </div>

                        <div class="col-auto float-end mt-sm-4">
                            <a href="#" class="btn btn-sm btn-badge btn-primary"
                                onclick="document.getElementById('cam_filter').submit(); return false;"
                                data-bs-toggle="tooltip" title="" data-bs-original-title="{{ __('Apply')}}">
                                <span class="btn-inner--icon"><i class="ti ti-search"></i></span>
                            </a>
                            <a href="{{ route('campaigns.index') }}" class="btn btn-badge btn-sm btn-danger" data-bs-toggle="tooltip"
                                title="" data-bs-original-title="{{ __('Reset')}}">
                                <span class="btn-inner--icon"><i class="ti ti-trash-off text-white-off "></i></span>
                            </a>
                        </div>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-12">
        <x-datatable :dataTable="$dataTable" />
    </div>
@endsection

@push('scripts')
    <script>
        $(document).on("click", ".campaigns_status_active", function() {
            var id = $(this).attr('data-id');
            var is_disable = ($(this).is(':checked')) ? $(this).val() : 0;

            $.ajax({
                url: '{{ route('campaigns.enable') }}',
                type: 'POST',
                data: {
                    "is_disable": is_disable,
                    "id": id,
                    "_token": "{{ csrf_token() }}",
                },
                success: function(data) {
                    if (data.is_success == true) {
                        show_toastr('Success', data.msg, 'success')

                    } else if (data.is_success == false) {
                        show_toastr('Error', data.msg, 'error')

                        $('.campaigns_status_active[data-id="' + id + '"]').prop('checked', !
                            is_disable);
                    }
                    setTimeout(() => {
                        location.reload();
                    }, 2000);

                }
            });
        });
    </script>
@endpush
