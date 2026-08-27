@extends('layouts.app')

@section('page-title', __('Users'))

@section('action-button')
    <div class="text-end d-flex flex-wrap all-button-box align-items-center btn-badge justify-content-md-end justify-content-center gap-1">
        <a href="{{ route('store.subdomain') }}" class="btn btn-sm btn-primary btn-icon" data-bs-toggle="tooltip"
            data-bs-placement="top" title="{{ __('Sub Domain') }}">{{ __('Sub Domain') }}</a>

        <a href="{{ route('store.customdomain') }}" class="btn btn-sm btn-primary btn-badge btn-icon" data-bs-toggle="tooltip"
            data-bs-placement="top" title="{{ __('Custom Domain') }}">{{ __('Custom Domain') }}</a>

            <a href="{{ route('stores.index') }}" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Grid View') }}"
                class="btn btn-sm btn-primary btn-icon">
                <i class="ti ti-layout-grid"></i>
            </a>
        <a href="javascript::void(0)" class="btn btn-sm btn-primary btn-badge btn-icon" data-ajax-popup="true" data-size="md"
            data-title="{{ __('Create New User') }}" data-url="{{ route('store.user.create') }}" data-bs-toggle="tooltip"
            title="{{ __('Add New User') }}">
            <i class="ti ti-plus"></i>
        </a>
    </div>
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Users') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection

@push('custom-script')
<script type="text/javascript">
    function myFunction(id) {
        var copyText = document.getElementById(id);
        copyText.select();
        copyText.setSelectionRange(0, 99999)
        document.execCommand("copy");
        show_toastr('Success', "{{ __('Link copied') }}", 'success');
    }

    $(document).on('change', '.active-store-index',function() {
        var status = $(this).prop('checked') == true ? 1 : 0;
        var id = $(this).data('id');
        $.ajax({
            type: "GET",
            dataType: "json",
            url: "{{ route('store.active.status') }}",
            data: {'status': status, 'id': id},
            success: function(data){
                $('#loader').fadeOut();
                if (data.status != 'success') {
                    show_toastr('Error', data.message, 'error');
                } else {
                    show_toastr('Success', data.message, 'success');
                }
            }
        });
    });

    $(document).on('keyup', '#user-search', function() {
        var searchValue = $(this).val().toLowerCase();
        
        $('#user-list .user-card').filter(function() {
            $(this).toggle($(this).find('.card-body h4').text().toLowerCase().indexOf(searchValue) > -1 || 
                           $(this).find('.card-body small').text().toLowerCase().indexOf(searchValue) > -1);
        });
    });
</script>
@endpush