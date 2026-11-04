@extends('layouts.app')

@section('page-title', __('Testimonial'))

@section('action-button')
    @permission('Create Testimonial')
    <div class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
        {{-- Phase 2.5 / primary-action-named-only-by-title. The only name was `title`, which
             Bootstrap blanks when it initialises the tooltip, leaving the Tabler glyph U+EB0B as
             the computed accessible name. Visible <span> plus `aria-hidden` on the <i>, following
             the three shipped precedents; no `aria-label` beside visible text (WCAG 2.5.3). --}}
        <a href="#" class="ss-btn ss-btn--primary ss-btn--sm" data-ajax-popup="true" data-size="md"
            data-title="{{ __('Add Testimonial') }}" data-url="{{ route('testimonial.create') }}"
            data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Add Testimonial') }}">
            <i class="ti ti-plus" aria-hidden="true"></i>
            <span>{{ __('Add Testimonial') }}</span>
        </a>
    </div>
    @endpermission
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Testimonial') }}</li>
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
                    /* The $.each that stood here appended <option> elements to the product
                       select by its old id, reading `value.id` and `value.name` out of
                       `response` -- which is the {status, html} object, so both were undefined
                       and it appended two <option value="undefined">. The next line then
                       discarded them by replacing the span's innerHTML with `response.html`.
                       Removed together with the id it addressed (the select now carries the
                       `product_id` the controller's own html uses). The route, the controller
                       and the JSON shape -- shared with wishlist -- are untouched. */
                    var val = $('.product_id_div').attr('data_val', 0);
                    $('.product_id_div span').html(response.html);
                    comman_function();
                }
            });

        });
    </script>
@endpush
