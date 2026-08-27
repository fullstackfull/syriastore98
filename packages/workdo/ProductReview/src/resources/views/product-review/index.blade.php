@extends('layouts.app')

@section('page-title', __('Product Review'))

@section('action-button')
@permission('Create Product Review')
    <div class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
        <a href="#" class="btn btn-sm btn-primary" data-ajax-popup="true" data-size="md" data-title="{{ __('Add Product Review')}}"
            data-url="{{ route('product-review.create') }}" data-bs-toggle="tooltip" title="{{ __('Add Product Review') }}">
            <i class="ti ti-plus"></i>
        </a>
    </div>
@endpermission
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Product Review') }}</li>
@endsection

@push('css')
@endpush
@section('content')
    <!-- [ Main Content ] start -->
    <div class="row">
        <!-- [ basic-table ] start -->
        <div class="col-xl-12">
        <x-datatable :dataTable="$dataTable" />
            <!-- [ basic-table ] end -->
        </div>
    </div>
        <!-- [ Main Content ] end -->
    @endsection

    @push('custom-script')
        <script>
            $(document).on('change', '#category_id', function(e) {
                var id = $(this).val();
                var val = $('.product_id_div').attr('data_val');

                var data = {
                    id: id,
                    val: val

                }
                $.ajax({
                    url: '{{ route('get.product') }}',
                    method: 'POST',
                    data: data,
                    context: this,
                    success: function(response) {
                        $('#loader').fadeOut();
                        $.each(response, function (key, value) {
                            $("#product-dropdown").append('<option value="' + value
                                .id + '">' + value.name + '</option>');
                        });
                        var val = $('.product_id_div').attr('data_val', 0);
                        $('.product_id_div span').html(response.html);
                        comman_function();
                    }
                });

            });
        </script>
    @endpush
