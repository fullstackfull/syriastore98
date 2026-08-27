@extends('layouts.app')

@section('page-title', __('Order'))

@section('action-button')
<div class="text-end">
    <a class="btn btn-sm btn-primary btn-icon export-btn" href="{{ route('order.export') }}" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Export') }}" filename="{{ __('Order') }}">
        <i  class="ti ti-file-export"></i>
    </a>
    <a href="{{ route('order.grid') }}" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Grid View') }}" class="btn btn-sm btn-primary btn-icon ">
        <i class="ti ti-layout-grid"></i>
    </a>
</div>
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Order') }}</li>
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
        $(document).on('click', '.code', function() {
            var type = $(this).val();
            $('#code_text').addClass('col-md-12').removeClass('col-md-8');
            $('#autogerate_button').addClass('d-none');
            if (type == 'auto') {
                $('#code_text').addClass('col-md-8').removeClass('col-md-12');
                $('#autogerate_button').removeClass('d-none');
            }
        });

        $(document).on('click', '.return_request', function() {
            var id = $(this).attr('data-id');
            var status = $(this).attr('data-status');
            var data = {
                id: id,
                status: status
            }
            $.ajax({
                url: '{{ route('order.return.request') }}',
                method: 'POST',
                data: data,
                context:this,
                success: function (response)
                {
                    $('#loader').fadeOut();
                    if(response.status == 'error') {
                        show_toastr('{{ __('Error') }}', response.message, 'error')
                    } else {
                        show_toastr('{{ __('Success') }}', response.message, 'success')
                        $(this).parent().find('.return_request').remove();
                    }
                }
            });
        });

        $(document).on('click', '#code-generate', function() {
            var length = 10;
            var result = '';
            var characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            var charactersLength = characters.length;
            for (var i = 0; i < length; i++) {
                result += characters.charAt(Math.floor(Math.random() * charactersLength));
            }
            $('#auto-code').val(result);
        });
    </script>
@endpush
