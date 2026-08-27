@extends('layouts.app')

@section('page-title', __('Backup & Restore'))

@section('action-button')
    @permission('backup&restore create')
        <div class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
            <a href="#" class="btn btn-sm btn-primary btn-badge add_attribute" data-ajax-popup="true" data-size="md" data-title="{{ __('Create New Backup') }}" data-url="{{ route('backuprestore.create') }}" data-bs-toggle="tooltip" title="{{ __('Create New Backup') }}">
                <i class="ti ti-plus"></i>
            </a>
        </div>
    @endpermission
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Backup And Restore') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection
@push('scripts')
<script>
    $(document).on("click", "#submitForm", function() {
        var database_name = $("#database_name").val();
        var file_name = $("#file_name").val();
        var database = $("#platformswt1").prop('checked') ? 'on' : 'off';
        var files = $("#platformswt2").prop('checked') ? 'on' : 'off';
        $.ajax({
            url: '{{ route('backuprestore.store') }}',
            beforeSend: function() {
                $("#backing_up").show();
                $("#refresh_note").show();
                $("#submitForm").hide();
            },
            data: {
                _token: $('meta[name="csrf-token"]').attr('content'),
                database_name: database_name,
                file_name: file_name,
                database: database,
                files: files
            },
            type: 'POST',
            success: function(data) {
                $('#loader').fadeOut();
                $("#backing_up").hide();
                $("#submitForm").show();
                $(".btn-close").click();
                if (data.status) {
                    show_toastr('{{ __('Success') }}', data.msg, 'success');
                    // Instead of reloading the page, refresh Yajra DataTable
                    $('#backuprestorelog-table').DataTable().ajax.reload(null, false); // false ensures the page doesn't reset to page 1
                } else {
                    show_toastr('Error', data.msg, 'error');
                }
                
            }
        })
    });
</script>
@endpush