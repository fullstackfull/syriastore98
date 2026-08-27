@extends('layouts.app')

@section('page-title', __('Roles'))

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Roles') }}</li>
@endsection

@section('action-button')
    @permission('Create Role')
        <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
            <a href="#" class="btn btn-sm btn-primary" data-ajax-popup="true" data-size="xl" data-title="{{__('Create Role')}}"
                data-url="{{ route('roles.create') }}" data-bs-toggle="tooltip" title="{{ __('Add Role') }}">
                <i class="ti ti-plus"></i>
            </a>
        </div>
    @endpermission
@endsection

@section('content')
<div class="row">
    <div class="col-xl-12">
        <x-datatable :dataTable="$dataTable" />
    </div>
</div>

@endsection

@push('scripts')
<script type="text/javascript">
    function Checkall(module = null) {
        var ischecked = $("#checkall-" + module).prop('checked');
        if (ischecked == true) {
            $('.checkbox-' + module).prop('checked', true);
        } else {
            $('.checkbox-' + module).prop('checked', false);
        }

       // Get all checkboxes with IDs that start with 'module_checkbox_' and include the module
        var checkboxes = document.querySelectorAll('input[id^="module_checkbox_"]');

        // Check or uncheck all checkboxes based on the 'checkall' checkbox state
        checkboxes.forEach(function(checkbox) {
            var check = $("#checkall-" + module).prop('checked');
            if (checkbox.id.includes(module)) {
                checkbox.checked = check
            }
        });

        // Call CheckModule to update the module checkbox state
        CheckModule('module_checkbox_' + module);
    }

    function CheckModule(cl = null) {
        var ischecked = $("#" + cl).prop('checked');
        if (ischecked == true) {
            $('.' + cl).find("input[type=checkbox]").prop('checked', true);
        } else {
            $('.' + cl).find("input[type=checkbox]").prop('checked', false);
        }
    }

    function CheckPermission(cl = null, module = null) {
        var ischecked = $("#" + cl).prop('checked');
        var allChecked = true;

        // Check if all permissions for the given module are checked
        $('.' + module).find("input[type=checkbox]").each(function() {
            if (!$(this).prop('checked')) {
                allChecked = false;
                return false; // Exit the loop
            }
        });

        // Update the module checkbox based on the state of permissions
        if (allChecked) {
            $('#' + module).prop('checked', true);
        } else {
            $('#' + module).prop('checked', false);
        }
    }

    $(document).ready(function() {
        // Attach the CheckPermission function to all permission checkboxes
        $(document).on('change', 'input[type=checkbox]', function() {
            var id = $(this).attr('id');
            var module = $(this).data('module');
            CheckPermission(id, module);
        });
    });

    // Click event for "Show more" link
    $(document).on('click', '.show-more', function(e) {
        e.preventDefault();
        $(this).addClass('d-none'); // Hide "Show more"
       
        // Show hidden permission items
        $(this).closest('.role-permission-table').find('.nav-item.d-none').removeClass('d-none');

        $(this).closest('.role-permission-table').find('.show-more').addClass('d-none'); // Show "Show less"
    });

    // Click event for "Show less" link
    $(document).on('click', '.show-less', function(e) {
        e.preventDefault();
        $(this).addClass('d-none'); // Hide "Show less"
        // Hide permissions after the 10th item
        $(this).closest('.role-permission-table').find('.nav-item').slice(11).addClass('d-none');
        // Show the "Show more" link
        $(this).closest('.role-permission-table').find('.show-more').removeClass('d-none');
    });
</script>

@endpush
