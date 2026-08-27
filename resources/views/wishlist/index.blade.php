@extends('layouts.app')

@section('page-title', __('Wishlist'))

@section('action-button')

@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Wishlist') }}</li>
@endsection

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
                        var val = $('.product_id_div').attr('data_val', 0);
                        $('.product_id_div span').html(response.html);
                        comman_function();
                    }
                });

            });
        </script>
        <script>
            $(document).ready(function() {

            $(document).on('click', '.bandonwish', function(e) {
                var wish_id  = $(this).attr('data-id');

                $.ajax({
                    url: '{{ route('wish.emailsend') }}',
                    method: 'POST',
                    data: {wish_id :wish_id},
                    context: this,
                    success: function(response) {
                        $('#loader').fadeOut();
                        if(response.is_success){

                            show_toastr('Success', response.message, 'success');
                        }else{
                            show_toastr('Error', response.message, 'error');

                        }


                    }
                });

            });
        });
        $(document).on('click', '.bandonwishmess', function(e) {
        var wish_id  = $(this).attr('data-id');
        var wish_id  = $(this).attr('data-id');
        $.ajax({
            url: '{{ route("wishlist.message.send") }}',
            method: 'POST',
            data: { wish_id: wish_id },
            context: this,
            success: function(response) {
                $('#loader').fadeOut();
                if(response.is_success){

                    show_toastr('Success', response.message, 'success');
                }else{
                    show_toastr('Error', response.message, 'error');

                }


            }
        });

    });
        </script>
    @endpush
