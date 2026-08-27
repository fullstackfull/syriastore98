@extends('layouts.app')

@section('page-title', __('Auction Setting'))

@section('action-button')
    @permission('Create Auction Setting')
        <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
            <a href="#" class="btn btn-sm btn-primary" data-ajax-popup="true" data-size="lg"
                data-title="{{ __('Add Auction Setting') }}" data-url="{{ route('auctionsetting.create') }}" data-bs-toggle="tooltip"
                title="{{ __('Create Auction Setting') }}">
                <i class="ti ti-plus"></i>
            </a>
        </div>
    @endpermission
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Auction Setting') }}</li>
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
        $(document).ready(function() {
            $(document).on('change', '.auction-toggle', function() {
                const auctionId = $(this).data('auction-id');
                const isActivated = $(this).is(':checked');

                $.ajax({
                    type: 'POST',
                    url: '{{ route('update-auction-setting') }}',
                    data: {
                        auctionId: auctionId,
                        isActivated: isActivated,
                        _token: '{{ csrf_token() }}',
                    },
                    success: function(data) {
                        $('#loader').fadeOut();
                        show_toastr('{{ __('Success') }}',
                            '{{ __('Status Updated Successfully!') }}', 'success');
                    },
                    error: function(xhr, status, error) {
                        $('#loader').fadeOut();
                        console.error('Error:', error);
                    },
                });
            });
        });
    </script>
@endpush
