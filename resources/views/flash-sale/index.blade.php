@extends('layouts.app')

@section('page-title', __('Flash Sale'))

@section('action-button')
    @permission('Create Flash Sale')
        <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
            {{-- Phase 2.5 / primary-action-named-only-by-title. The only name was `title`, blanked
                 by Bootstrap on tooltip init, so the computed name was the Tabler glyph U+EB0B.
                 `btn-badge` and `mr-1` both go: neither matches any rule under public/, and `mr-1`
                 is a Bootstrap 4 PHYSICAL edge that would mirror wrong in Arabic if it ever gained
                 one. This is the screen's only control, so nothing needed the spacing. --}}
            <a href="#" class="ss-btn ss-btn--primary ss-btn--sm" data-ajax-popup="true" data-size="lg" data-title="{{__('Add Flash Sale')}}"
                data-url="{{ route('flash-sale.create') }}"
                data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Add Flash Sale') }}">
                <i class="ti ti-plus" aria-hidden="true"></i>
                <span>{{ __('Add Flash Sale') }}</span>
            </a>
        </div>
    @endpermission
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Flash Sale') }}</li>
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
        $(document).ready(function () {
            $(document).on('change', '.flashsale-toggle', function () {
                const flashsaleId = $(this).data('flashsale-id');
                const isActivated = $(this).prop('checked');

                $.ajax({
                    type: 'POST',
                    url: '{{ route('update-flashsale-status') }}',
                    data: {
                        flashsaleId: flashsaleId,
                        isActivated: isActivated,
                        _token: '{{ csrf_token() }}',
                    },
                    success: function (data) {
                        $('#loader').fadeOut();
                        if (data.status == true) {
                            show_toastr("Success", data.message, "success");
                        } else {
                            show_toastr("Error", data.message, "error");
                        }
                    },
                    error: function (xhr, status, error) {
                        $('#loader').fadeOut();
                        console.error(xhr.responseText);
                    },
                });
            });
        });
    </script>
    @endpush
