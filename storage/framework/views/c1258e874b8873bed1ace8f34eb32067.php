<?php $__env->startPush('css'); ?>
    <?php echo $__env->make('layouts.includes.datatable-css', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <style>
        #mail_Setting .table td {
            white-space: unset;
        }
    </style>
    <style>
        div.dataTables_wrapper div.dataTables_paginate ul.pagination {
            gap: 0;
            flex-wrap: wrap;

        }

        div.dataTables_wrapper div.dataTables_paginate ul.pagination li {
            width: auto;
            height: auto;
        }

        @media only screen and (max-width: 767px) {
            div.dataTables_wrapper div.dataTables_paginate ul.pagination {
                margin: 10px 0 0 0;
                justify-content: center;
            }

            .ecom-data-table div.dataTables_wrapper div.dataTables_length {
                text-align: left;
                margin-bottom: 15px;
            }

            .ecom-data-table div.dt-buttons {
                width: auto;
            }

            .ecom-data-table .data-tab-btn {
                justify-content: flex-start !important;
                padding-left: 0 !important;
                margin-bottom: 10px;

            }
        }

        @media only screen and (max-width: 575px) {
            .card .card-header .state-set {
                margin-bottom: 10px;
            }
        }
    </style>
<?php $__env->stopPush(); ?>
<div class="tab-pane fade <?php if(isset($app_setting_tab) && $app_setting_tab == 'pills-mail-tab'): ?> show active <?php endif; ?>" id="pills-mail" role="tabpanel"
    aria-labelledby="pills-mail-tab">
    <div id="mail_Setting">
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center gap-3">
                        <h5><?php echo e(__('Mail Settings')); ?></h5>
                    </div>

                    <form action="<?php echo e(route('PDFVouchers.mail_setting.store')); ?>" method="POST">
                    <div class="card-body p-4">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="app_setting_tab" value="pills-mail-tab">
                            <div class="row">
                                <div class="form-group col-6">
                                    <label for="subject" class="col-form-label text-dark"><?php echo e(__('Subject')); ?></label>
                                    <input type="text" name="subject" class="form-control font-style"
                                        value="<?php echo e(isset($setting->subject) ? $setting->subject : ''); ?>" required>
                                </div>
                                <div class="form-group col-6">
                                    <label for="from" class="col-form-label text-dark"><?php echo e(__('From')); ?></label>
                                    <input type="text" name="from" class="form-control font-style"
                                        value="<?php echo e(isset($setting->from) ? $setting->from : ''); ?>" required>
                                </div>
                                <div class="form-group col-12">
                                    <label for="content" class="form-label text-dark"><?php echo e(__('Email Message')); ?></label>
                                    <textarea name="content" class="summernote-simple" required><?php echo e(old('content', $setting->content ?? '')); ?></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer d-flex justify-content-end flex-wrap ">
                            <button type="submit" class="btn btn-primary btn-badge"><?php echo e(__('Save Changes')); ?></button>
                        </div>
                    </form>
                </div>
            </div>


            <div class="col-xl-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center gap-3">
                        <h5><?php echo e(__('Request Of Coupon Code')); ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive ecom-data-table p-4">
                            <table class="table" id="pdf-voucher-request-table" style="width:100%">
                                <thead>
                                    <tr>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(module_is_active('BulkDelete')): ?>
                                            <?php echo $__env->make('bulk-delete::pages.checkbox', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        <th><?php echo e(__('Name')); ?></th>
                                        <th><?php echo e(__('Email')); ?></th>
                                        <th><?php echo e(__('Details')); ?></th>
                                        <th class="text-end" width="70px"><?php echo e(__('Action')); ?></th>
                                    </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php $__env->startPush('custom-script'); ?>
    <?php echo $__env->make('layouts.includes.datatable-js', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <script type="text/javascript">
        $(function() {
            $('#pdf-voucher-request-table').DataTable({
                processing: true,
                serverSide: true,
                responsive: true,
                ajax: "<?php echo e(route('pdfvouchers.index')); ?>",
                columns:getPdfVoucherColumns(),
                initComplete: function(settings, json) {
                    removeSortingOrderFromHeader();
                    var tooltipTriggerList = [].slice.call(document.querySelectorAll(
                        '[data-bs-toggle="tooltip"]'));
                    var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                        return new bootstrap.Tooltip(tooltipTriggerEl);
                    });
                },
                buttons: getPdfVoucherButtons(),
                dom: "<'row'<'col-sm-12 col-md-3'l><'col-sm-12 col-md-9 data-tab-btn flex-wrap d-flex justify-content-end gap-1'Bf>>" +
                    "<'row'<'col-sm-12'tr>>" +
                    "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
                language: {
                    search: "", // Removes the "Search:" label
                    searchPlaceholder: "<?php echo app('translator')->get('Search...'); ?>", // Optional: Add a placeholder to the search input
                    lengthMenu: " _MENU_ <?php echo app('translator')->get('Entries Per Page'); ?>", // Localize the "Per page" dropdown
                    paginate: {
                        next: '<i class="ti ti-chevron-right"></i>',
                        previous: '<i class="ti ti-chevron-left"></i>'
                    },
                    info: "<?php echo app('translator')->get('Showing'); ?> _START_ <?php echo app('translator')->get('to'); ?> _END_ <?php echo app('translator')->get('of'); ?> _TOTAL_ <?php echo app('translator')->get('entries'); ?>", // Localize pagination info
                    emptyTable: "<?php echo app('translator')->get('No data available in table'); ?>",
                },
                drawCallback: function(settings) {
                    removeSortingOrderFromHeader();
                    $('.dt-buttons').addClass('d-flex gap-1');
                }
            });
        });

        // Function to get country columns
        function getPdfVoucherColumns() {
            let pdfVoucherColumns = [{
                    data: 'name',
                    name: 'name'
                },
                {
                    data: 'email',
                    name: 'email'
                },
                {
                    data: 'detail',
                    name: 'detail'
                },
                {
                    data: 'action',
                    name: 'action',
                    orderable: false,
                    searchable: false
                }
            ];

            if (typeof addCheckboxColumn === 'function') {
                // Add checkbox column if BulkDelete module is active
                addCheckboxColumn(pdfVoucherColumns);
            }

            return pdfVoucherColumns;
        }

        // Function to get country buttons
        function getPdfVoucherButtons() {
                let pdfVoucherButtons = [{
                        text: '<i class="ti ti-arrow-back-up" data-bs-toggle="tooltip" title="<?php echo app('translator')->get("Reset"); ?>" data-bs-original-title="<?php echo app('translator')->get("Reset"); ?>"></i>',
                        className: 'btn btn-default buttons-reset btn-light-info btn-badge',
                        action: function(e, dt, node, config) {
                            dt.search('').columns().search('')
                        .draw();
                        }
                    },
                    {
                        text: '<i class="ti ti-refresh" data-bs-toggle="tooltip" title="<?php echo app('translator')->get("Reload"); ?>" data-bs-original-title="<?php echo app('translator')->get("Reload"); ?>"></i>',
                        className: 'btn btn-default buttons-reload btn-light-warning btn-badge',
                        action: function(e, dt, node, config) {
                            dt.ajax.reload();
                        }
                    }
                ];

                if (typeof addBulkDeleteButton === 'function') {
                    // Add bulk delete button for country
                    addBulkDeleteButton(pdfVoucherButtons, 'pdfVoucher-addon', 'pdf-voucher-request-table');
                }

                return pdfVoucherButtons;
            }

    </script>
       <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(module_is_active('BulkDelete')): ?>
                <?php echo $__env->make('bulk-delete::pages.script', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php $__env->stopPush(); ?>
<?php /**PATH /home/storesyria/public_html/packages/workdo/PDFVouchers/src/app/Providers/../../resources/views/mail_setting/create.blade.php ENDPATH**/ ?>