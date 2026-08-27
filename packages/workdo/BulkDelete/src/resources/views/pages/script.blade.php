<script>
    // Handle select all checkboxes
    $('#select-all').on('click', function() {
        var isChecked = $(this).is(':checked');
        $('.select-row').prop('checked', isChecked);
    });

    // Handle select all checkboxes for visible rows in all tables
    $('.tab-content').on('click', 'th .select-all', function () {
        var isChecked = $(this).is(':checked');  // Check the current state of the "select-all" checkbox
        var tableId = $(this).closest('table').attr('id');  // Get the closest table's ID
       
        // Check/uncheck only visible row checkboxes in the current table
        var checkboxes = $('#' + tableId + ' .select-row:visible');  // Filter to get only visible checkboxes
      
        checkboxes.each(function(index, checkbox) {
            $(checkbox).prop('checked', isChecked);  // Check/uncheck checkbox based on the "select-all"
        });
    });

    // Handle individual row checkbox clicks for visible rows
    $('.tab-content td').on('change', '.select-row', function () {
        var tableId = $(this).closest('table').attr('id');  // Get the current table ID
        var allCheckboxes = $('#' + tableId + ' .select-row:visible');  // Get only visible row checkboxes in this table
        var selectAllCheckbox = $('#' + tableId + ' .select-all');  // Get the "select-all" checkbox
        
        var checkedCheckboxes = allCheckboxes.filter(':checked').length;  // Get the number of checked visible checkboxes
        
        // Toggle the "select-all" checkbox based on whether all visible checkboxes are checked
        selectAllCheckbox.prop('checked', allCheckboxes.length === checkedCheckboxes);
    });

    // Bulk delete function
    function bulkDelete(type, datableId) {
        var ids = [];
        $('.select-row:checked').each(function() {
            ids.push($(this).val());
        });

        if (ids.length === 0) {
            Swal.fire({
                icon: 'error',
                title: '{{ __("No rows selected") }}',
                text: '{{ __("Please select rows to delete.") }}',
            });
            return;
        }

        const swalWithBootstrapButtons = Swal.mixin({
            customClass: {
                confirmButton: 'btn btn-success',
                cancelButton: 'btn btn-danger'
            },
            buttonsStyling: false
        });

        swalWithBootstrapButtons.fire({
            title: '{{ __("Are you sure?") }}',
            text: '{{ __("This action cannot be undone. Do you want to continue?") }}',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '{{ __("Yes, delete it!") }}',
            cancelButtonText: '{{ __("No, cancel!") }}',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                // Perform AJAX request if confirmed
                $.ajax({
                    url: '{{ route("bulk-delete") }}', // Adjust this route as needed
                    type: 'POST',
                    data: {
                        ids: ids,
                        type: type,
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        if (response.status) {
                            // Show success notification
                            show_toastr('{{ __('Success') }}', response.message, 'success');                            
                        } else {
                            show_toastr('{{ __('Error') }}', response.message, 'error');
                        }
                        if ($.fn.dataTable.isDataTable('#' + datableId)) {
                            $('#' + datableId).DataTable().ajax.reload(null, false);
                        } else {
                            console.error('DataTable not initialized for ID: ' + datableId);
                        }
                    },
                    error: function(xhr) {
                        show_toastr('{{ __('Error') }}', response.message, 'error');
                    }
                });
            }
        });
    }

    // Function to add a checkbox column if the BulkDelete module is active
    function addCheckboxColumn(columns) {
        columns.unshift({
            data: 'checkbox',
            name: 'checkbox',
            orderable: false,
            searchable: false,
            render: function(data, type, full, meta) {
                return '<input type="checkbox" class="select-row" value="' + full.id + '">';
            },
            width: '20px',
            className: 'text-center select-row'
        });
    }

    // Function to add a bulk delete button if the BulkDelete module is active
    function addBulkDeleteButton(buttons, type, tableId) {
        buttons.unshift({
            text: '<i class="ti ti-trash" data-bs-toggle="tooltip" title="@lang("Bulk Delete")" data-bs-original-title="@lang("Bulk Delete")"></i>',
            className: 'btn btn-light-danger bulk-delete',
            action: function(e, dt, button, config) {
                bulkDelete(type, tableId); // Call bulk delete function
            }
        });
    }
</script>