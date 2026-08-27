
@extends('layouts.app')
@section('page-title')
{{ __('Abandon Cart')}}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Abandon Cart') }}</li>
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

    $(document).on('click', '.bandoncart', function(e) {
        var cart_id  = $(this).attr('data-id');
        $.ajax({
            url: '{{ route('carts.emailsend') }}',
            method: 'POST',
            data: { cart_id: cart_id },
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
$(document).ready(function() {

$(document).on('click', '.bandoncartmess', function(e) {
    var cart_id  = $(this).attr('data-id');
    var cart_id  = $(this).attr('data-id');
    $.ajax({
        url: '{{ route('carts.messagesend') }}',
        method: 'POST',
        data: { cart_id: cart_id },
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



</script>
@endpush


