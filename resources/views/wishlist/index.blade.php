@extends('layouts.app')

@section('page-title', __('Abandoned wishlists'))

@section('action-button')
    {{-- MKT-001. This screen had no action area at all; the analytics link is its only control. --}}
    <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
        @include('marketing._analytics_link')
    </div>
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Abandoned wishlists') }}</li>
@endsection

@section('content')
    {{-- MKT-002. The advanced-filter bar, from ONE shared component: it reads this screen's filters
         from `TableFilterMemory::vocabularyFor()` and their wording from `headingFor()` /
         `optionsFor()`, so the controls here and the receipt inside the bar cannot disagree. --}}
    <x-ss.marketing-filters screen="wishlist" route="wishlist.index" :label="__('Filter wishlists')" />

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
                    // The pending state: see the first handler in cart/index.blade.php. `complete` runs after
                    // both success and error, so no path leaves the button spinning.
                    beforeSend: function() { $(this).attr('aria-busy', 'true').prop('disabled', true); },
                    complete: function() { $(this).removeAttr('aria-busy').prop('disabled', false); },
                    success: function(response) {
                        $('#loader').fadeOut();
                        if(response.is_success){

                            show_toastr('Success', response.message, 'success');
                        }else{
                            show_toastr('Error', response.message, 'error');

                        }


                    },
                    error: function(xhr) {
                        // See the first handler in this file: a 403 or 404 was silent without this.
                        $('#loader').fadeOut();
                        var payload = xhr.responseJSON || {};
                        // Only a 4xx body is relayed. The first cut of this handler relayed any
                        // `message`, and driving a missing wishlist row put `Attempt to read property
                        // "customer_id" on null` on the merchant's screen -- Laravel's debug JSON uses
                        // the same key. That 500 is fixed; the next one must not be a disclosure, and a
                        // 4xx is the class the controller authored for a person to read.
                        var authored = (xhr.status >= 400 && xhr.status < 500) ? payload.message : null;
                        show_toastr('{{ __('Error') }}', authored || '{{ __('Something went wrong') }}', 'error');
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
            // The pending state: see the first handler in cart/index.blade.php. `complete` runs after
            // both success and error, so no path leaves the button spinning.
            beforeSend: function() { $(this).attr('aria-busy', 'true').prop('disabled', true); },
            complete: function() { $(this).removeAttr('aria-busy').prop('disabled', false); },
            success: function(response) {
                $('#loader').fadeOut();
                if(response.is_success){

                    show_toastr('Success', response.message, 'success');
                }else{
                    show_toastr('Error', response.message, 'error');

                }


            },
            error: function(xhr) {
                // See the first handler in this file: a 403 or 404 was silent without this.
                $('#loader').fadeOut();
                var payload = xhr.responseJSON || {};
                // Only a 4xx body is relayed. The first cut of this handler relayed any
                // `message`, and driving a missing wishlist row put `Attempt to read property
                // "customer_id" on null` on the merchant's screen -- Laravel's debug JSON uses
                // the same key. That 500 is fixed; the next one must not be a disclosure, and a
                // 4xx is the class the controller authored for a person to read.
                var authored = (xhr.status >= 400 && xhr.status < 500) ? payload.message : null;
                show_toastr('{{ __('Error') }}', authored || '{{ __('Something went wrong') }}', 'error');
            }
        });

    });
        </script>
    @endpush
