{{-- =========================================================
    Abandoned Carts
========================================================= --}}

{{-- Bootstrap Icons --}}
<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
>

{{-- SweetAlert2 --}}
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    .abandoned-cart-page {
        --ac-border: #e9ecef;
        --ac-muted: #6c757d;
        --ac-bg: #f8f9fa;
    }

    .abandoned-cart-page .page-title {
        font-size: 1.4rem;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .abandoned-cart-page .page-subtitle {
        color: var(--ac-muted);
        font-size: .9rem;
        margin-bottom: 0;
    }

    .abandoned-cart-page .main-card {
        border: 1px solid var(--ac-border);
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 3px 14px rgba(0, 0, 0, .04);
    }

    .abandoned-cart-page .main-card .card-header {
        background: #fff;
        padding: 20px 22px;
        border-bottom: 1px solid var(--ac-border);
    }

    .abandoned-cart-page .main-card .card-body {
        padding: 0;
    }

    .abandoned-cart-page .toolbar {
        padding: 16px 20px;
        border-bottom: 1px solid var(--ac-border);
        background: #fff;
    }

    .abandoned-cart-page .search-wrapper {
        position: relative;
        max-width: 370px;
        width: 100%;
    }

    .abandoned-cart-page .search-wrapper i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #98a0a8;
    }

    .abandoned-cart-page .search-input {
        padding-left: 40px;
        border-radius: 9px;
        min-height: 42px;
    }

    .abandoned-cart-page .selection-badge {
        background: var(--ac-bg);
        border: 1px solid var(--ac-border);
        padding: 8px 12px;
        border-radius: 9px;
        font-size: .875rem;
        white-space: nowrap;
    }

    .abandoned-cart-page .table {
        margin-bottom: 0;
    }

    .abandoned-cart-page .table thead th {
        background: #f8f9fb;
        border-bottom: 1px solid var(--ac-border);
        color: #5c6370;
        font-size: .78rem;
        text-transform: uppercase;
        letter-spacing: .03em;
        padding: 13px 16px;
        white-space: nowrap;
        vertical-align: middle;
    }

    .abandoned-cart-page .table tbody td {
        padding: 15px 16px;
        vertical-align: middle;
        border-color: #f0f1f3;
    }

    .abandoned-cart-page .table tbody tr {
        transition: background-color .15s ease;
    }

    .abandoned-cart-page .table tbody tr:hover {
        background: #fafbfc;
    }

    .abandoned-cart-page .customer-avatar {
        width: 40px;
        height: 40px;
        min-width: 40px;
        border-radius: 50%;
        display: inline-flex;
        justify-content: center;
        align-items: center;
        font-weight: 700;
        background: #eef2ff;
        color: #4f46e5;
        text-transform: uppercase;
    }

    .abandoned-cart-page .customer-name {
        font-weight: 600;
        color: #24282d;
        line-height: 1.3;
    }

    .abandoned-cart-page .customer-email {
        color: var(--ac-muted);
        font-size: .8rem;
        margin-top: 2px;
    }

    .abandoned-cart-page .phone-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #f4fbf6;
        border: 1px solid #dcefe1;
        color: #24733d;
        border-radius: 8px;
        padding: 6px 9px;
        font-size: .875rem;
    }

    .abandoned-cart-page .item-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 36px;
        padding: 5px 9px;
        border-radius: 8px;
        background: #f4f5f7;
        font-weight: 600;
    }

    .abandoned-cart-page .cart-total {
        font-weight: 700;
        color: #24282d;
    }

    .abandoned-cart-page .activity-date {
        font-size: .875rem;
        color: #495057;
    }

    .abandoned-cart-page .activity-date small {
        display: block;
        color: var(--ac-muted);
        margin-top: 2px;
    }

    .abandoned-cart-page .footer-actions {
        padding: 18px 20px;
        background: #fff;
        border-top: 1px solid var(--ac-border);
    }

    .abandoned-cart-page .send-btn {
        min-height: 42px;
        padding-left: 18px;
        padding-right: 18px;
        border-radius: 9px;
        font-weight: 600;
    }

    .abandoned-cart-page .empty-state {
        padding: 60px 20px !important;
        text-align: center;
    }

    .abandoned-cart-page .empty-icon {
        width: 60px;
        height: 60px;
        margin: 0 auto 12px;
        border-radius: 50%;
        background: var(--ac-bg);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 25px;
        color: var(--ac-muted);
    }

    .abandoned-cart-page .form-check-input {
        width: 18px;
        height: 18px;
        cursor: pointer;
    }

    .abandoned-cart-page tr.selected-row {
        background: #f7f9ff !important;
    }

    @media (max-width: 768px) {
        .abandoned-cart-page .toolbar {
            gap: 12px !important;
        }

        .abandoned-cart-page .search-wrapper {
            max-width: 100%;
        }

        .abandoned-cart-page .footer-actions {
            flex-direction: column;
            gap: 12px;
            align-items: stretch !important;
        }

        .abandoned-cart-page .send-btn {
            width: 100%;
        }
    }
</style>


<div class="abandoned-cart-page">

    {{-- Page Heading --}}
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

        <div>
    <h4 class="page-title">
    <i class="bi bi-cart-x me-2"></i>
    {{ __('chatwoot.abandoned_carts') }}
</h4>

<p class="page-subtitle">
    {{ __('chatwoot.select_registered_customers') }}
</p>
        </div>

        @if($setting)
            <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2">
                <i class="bi bi-check-circle me-1"></i>
                {{ __('Reminder Template Active') }}
            </span>
        @else
            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-2">
                <i class="bi bi-exclamation-triangle me-1"></i>
                {{ __('Template Not Configured') }}
            </span>
        @endif

    </div>


    {{-- Backend message --}}
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">

            <i class="bi bi-check-circle-fill me-2"></i>

            {{ session('success') }}

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
                aria-label="Close"
            ></button>

        </div>
    @endif


    @if(session('send_result'))

    @php
        $sendResult = session('send_result');
    @endphp

    <div class="alert alert-danger">

        <div class="fw-bold mb-2">
            WhatsApp sending result
        </div>

        <div>
            Sent: {{ $sendResult['sent'] ?? 0 }}
        </div>

        <div>
            Failed: {{ $sendResult['failed'] ?? 0 }}
        </div>

        @if(!empty($sendResult['errors']))

            <hr>

            @foreach($sendResult['errors'] as $error)

                <div class="mb-2">

                    <strong>
                        Customer #{{ $error['customer_id'] }}
                    </strong>

                    <br>

                    {{ $error['message'] }}

                </div>

            @endforeach

        @endif

    </div>

@endif

    {{-- Validation --}}
    @if($errors->any())
        <div class="alert alert-danger">

            <div class="fw-semibold mb-1">
                <i class="bi bi-exclamation-circle me-1"></i>
                {{ __('Unable to send reminder') }}
            </div>

            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach

        </div>
    @endif


    {{-- No template --}}
    @if(!$setting)

        <div class="alert alert-warning d-flex align-items-start gap-2">

            <i class="bi bi-exclamation-triangle-fill mt-1"></i>

            <div>
        <div class="fw-semibold">
    {{ __('chatwoot.abandoned_cart_not_configured') }}
</div>

<div class="small mt-1">
    {{ __('chatwoot.configure_abandoned_cart_template') }}
</div>
            </div>

        </div>

    @endif


    <div class="card main-card">

        {{-- Header --}}
        <div class="card-header">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">

                <div>
                 <h5 class="mb-1">
    {{ __('chatwoot.customer_carts') }}
</h5>

<div class="text-muted small">
    {{ __('chatwoot.registered_customers_with_phone_only') }}
</div>
                </div>

                <div class="selection-badge">
                    <i class="bi bi-person-check me-1"></i>

                    <span id="selected-count">0</span>

                    {{ __('selected') }}
                </div>

            </div>

        </div>


        <form
            method="POST"
            action="{{ route('chatwoot.abandoned-carts.send') }}"
            id="abandoned-cart-form"
        >

            @csrf


            {{-- Toolbar --}}
            <div class="toolbar d-flex justify-content-between align-items-center flex-wrap gap-3">

                <div class="search-wrapper">

                    <i class="bi bi-search"></i>

                    <input
                        type="text"
                        id="cart-search"
                        class="form-control search-input"
                        placeholder="{{ __('Search customer, email or phone...') }}"
                    >

                </div>


                <button
                    type="button"
                    class="btn btn-light border"
                    id="clear-selection"
                >
                    <i class="bi bi-x-circle me-1"></i>
                    {{ __('Clear Selection') }}
                </button>

            </div>


            <div class="table-responsive">

                <table
                    class="table align-middle"
                    id="abandoned-cart-table"
                >

                    <thead>

                        <tr>

                            <th style="width: 45px;" class="text-center">

                                <input
                                    type="checkbox"
                                    class="form-check-input"
                                    id="select-all"
                                    title="{{ __('Select All') }}"
                                >

                            </th>

                            <th>
                                {{ __('Customer') }}
                            </th>

                            <th>
                                {{ __('Phone') }}
                            </th>

                            <th class="text-center">
                                {{ __('Items') }}
                            </th>

                            <th>
                                {{ __('Cart Total') }}
                            </th>

                            <th>
                                {{ __('Last Activity') }}
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                    @php
                        $visibleCarts = 0;
                    @endphp

                    @foreach($carts as $customerId => $items)

                        @php
                            $firstCart = $items->first();

                            $customer = $firstCart
                                ? $firstCart->UserData
                                : null;

                            if (
                                !$customer ||
                                empty($customer->mobile)
                            ) {
                                continue;
                            }

                            $visibleCarts++;

                            $itemsCount = $items->sum('qty');

                            $cartTotal = $items->sum(function ($item) {
                                return
                                    ((float) $item->price) *
                                    ((int) $item->qty);
                            });

                            $lastCart = $items
                                ->sortByDesc('updated_at')
                                ->first();

                            $customerName = trim(
                                ($customer->first_name ?? '') . ' ' .
                                ($customer->last_name ?? '')
                            );

                            if (!$customerName) {
                                $customerName = $customer->name ?? __('Customer');
                            }

                            $initial = mb_strtoupper(
                                mb_substr(
                                    trim($customerName),
                                    0,
                                    1
                                )
                            );
                        @endphp


                        <tr
                            class="cart-row"
                            data-search="{{ strtolower(
                                $customerName . ' ' .
                                ($customer->email ?? '') . ' ' .
                                $customer->mobile
                            ) }}"
                        >

                            {{-- Checkbox --}}
                            <td class="text-center">

                                <input
                                    type="checkbox"
                                    class="form-check-input customer-checkbox"
                                    name="customer_ids[]"
                                    value="{{ $customerId }}"
                                >

                            </td>


                            {{-- Customer --}}
                            <td>

                                <div class="d-flex align-items-center gap-3">

                                    <div class="customer-avatar">
                                        {{ $initial }}
                                    </div>

                                    <div class="min-w-0">

                                        <div class="customer-name">
                                            {{ $customerName }}
                                        </div>

                                        @if(!empty($customer->email))
                                            <div class="customer-email">
                                                {{ $customer->email }}
                                            </div>
                                        @endif

                                    </div>

                                </div>

                            </td>


                            {{-- Phone --}}
                            <td>

                                <span class="phone-badge">

                                    <i class="bi bi-whatsapp"></i>

                                    {{ $customer->mobile }}

                                </span>

                            </td>


                            {{-- Items --}}
                            <td class="text-center">

                                <span class="item-badge">
                                    {{ $itemsCount }}
                                </span>

                            </td>


                            {{-- Total --}}
                            <td>

                                <span class="cart-total">
                                    {{ number_format($cartTotal, 2) }}
                                </span>

                            </td>


                            {{-- Activity --}}
                            <td>

                                @if(
                                    $lastCart &&
                                    $lastCart->updated_at
                                )

                                    <div class="activity-date">

                                        <i class="bi bi-clock me-1"></i>

                                        {{ $lastCart->updated_at->format('Y-m-d') }}

                                        <small>
                                            {{ $lastCart->updated_at->format('H:i') }}
                                        </small>

                                    </div>

                                @else

                                    <span class="text-muted">
                                        —
                                    </span>

                                @endif

                            </td>

                        </tr>

                    @endforeach


                    @if($visibleCarts === 0)

                        <tr id="empty-cart-row">

                            <td
                                colspan="6"
                                class="empty-state"
                            >

                                <div class="empty-icon">
                                    <i class="bi bi-cart"></i>
                                </div>

                                <div class="fw-semibold mb-1">
                                    {{ __('No abandoned carts found') }}
                                </div>

                                <div class="text-muted small">
                                    {{ __('There are currently no registered customers with carts and valid phone numbers.') }}
                                </div>

                            </td>

                        </tr>

                    @endif


                    {{-- JS search no result --}}
                    <tr
                        id="search-empty-row"
                        style="display: none;"
                    >

                        <td
                            colspan="6"
                            class="empty-state"
                        >

                            <div class="empty-icon">
                                <i class="bi bi-search"></i>
                            </div>

                            <div class="fw-semibold mb-1">
                                {{ __('No matching customers') }}
                            </div>

                            <div class="text-muted small">
                                {{ __('Try another name, phone number or email address.') }}
                            </div>

                        </td>

                    </tr>

                    </tbody>

                </table>

            </div>


            {{-- Footer --}}
            <div class="footer-actions d-flex justify-content-between align-items-center">

                <div class="text-muted small">

                    <i class="bi bi-info-circle me-1"></i>

                    {{ __('The configured Abandoned Cart WhatsApp template will be sent to the selected customers.') }}

                </div>


                <button
                    type="submit"
                    class="btn btn-primary send-btn"
                    id="send-reminder-btn"
                    {{ !$setting ? 'disabled' : '' }}
                >

                    <i class="bi bi-whatsapp me-1"></i>

                    {{ __('Send WhatsApp Reminder') }}

                    <span
                        id="send-count"
                        class="ms-1"
                    ></span>

                </button>

            </div>

        </form>

    </div>

</div>


<script>
document.addEventListener('DOMContentLoaded', function () {

    const form =
        document.getElementById('abandoned-cart-form');

    const selectAll =
        document.getElementById('select-all');

    const selectedCount =
        document.getElementById('selected-count');

    const sendCount =
        document.getElementById('send-count');

    const clearSelection =
        document.getElementById('clear-selection');

    const searchInput =
        document.getElementById('cart-search');

    const searchEmptyRow =
        document.getElementById('search-empty-row');


    function getCheckboxes() {
        return Array.from(
            document.querySelectorAll('.customer-checkbox')
        );
    }


    function getVisibleRows() {
        return Array.from(
            document.querySelectorAll('.cart-row')
        ).filter(function (row) {
            return row.style.display !== 'none';
        });
    }


    function updateSelection()
    {
        const checkboxes =
            getCheckboxes();

        const checked =
            checkboxes.filter(function (checkbox) {
                return checkbox.checked;
            });

        selectedCount.textContent =
            checked.length;

        sendCount.textContent =
            checked.length
                ? '(' + checked.length + ')'
                : '';


        document
            .querySelectorAll('.cart-row')
            .forEach(function (row) {

                const checkbox =
                    row.querySelector(
                        '.customer-checkbox'
                    );

                row.classList.toggle(
                    'selected-row',
                    checkbox && checkbox.checked
                );

            });


        const visibleRows =
            getVisibleRows();

        const visibleCheckboxes =
            visibleRows
                .map(function (row) {
                    return row.querySelector(
                        '.customer-checkbox'
                    );
                })
                .filter(Boolean);

        const visibleChecked =
            visibleCheckboxes
                .filter(function (checkbox) {
                    return checkbox.checked;
                });


        if (visibleCheckboxes.length === 0) {

            selectAll.checked = false;
            selectAll.indeterminate = false;

            return;
        }


        selectAll.checked =
            visibleChecked.length ===
            visibleCheckboxes.length;

        selectAll.indeterminate =
            visibleChecked.length > 0 &&
            visibleChecked.length <
            visibleCheckboxes.length;
    }


    selectAll.addEventListener(
        'change',
        function () {

            getVisibleRows()
                .forEach(function (row) {

                    const checkbox =
                        row.querySelector(
                            '.customer-checkbox'
                        );

                    if (checkbox) {
                        checkbox.checked =
                            selectAll.checked;
                    }

                });

            updateSelection();
        }
    );


    document.addEventListener(
        'change',
        function (event) {

            if (
                event.target.classList.contains(
                    'customer-checkbox'
                )
            ) {
                updateSelection();
            }

        }
    );


    clearSelection.addEventListener(
        'click',
        function () {

            getCheckboxes()
                .forEach(function (checkbox) {
                    checkbox.checked = false;
                });

            updateSelection();
        }
    );


    searchInput.addEventListener(
        'input',
        function () {

            const search =
                this.value
                    .trim()
                    .toLowerCase();

            let visibleCount = 0;

            document
                .querySelectorAll('.cart-row')
                .forEach(function (row) {

                    const haystack =
                        (
                            row.getAttribute(
                                'data-search'
                            ) || ''
                        ).toLowerCase();

                    const show =
                        !search ||
                        haystack.includes(search);

                    row.style.display =
                        show ? '' : 'none';

                    if (show) {
                        visibleCount++;
                    }

                });


            if (searchEmptyRow) {

                searchEmptyRow.style.display =
                    visibleCount === 0 && search
                        ? ''
                        : 'none';

            }

            updateSelection();
        }
    );


    if (form) {

        form.addEventListener(
            'submit',
            function (event) {

                const checked =
                    getCheckboxes()
                        .filter(function (checkbox) {
                            return checkbox.checked;
                        });


                if (checked.length === 0) {

                    event.preventDefault();

                    Swal.fire({
                        icon: 'warning',
                        title: '{{ __("No customers selected") }}',
                        text: '{{ __("Please select at least one customer before sending the reminder.") }}',
                        confirmButtonText: '{{ __("OK") }}'
                    });

                    return;
                }


                event.preventDefault();


                Swal.fire({
                    icon: 'question',
                    title: '{{ __("Send WhatsApp reminders?") }}',
                    html:
                        '{{ __("The reminder will be sent to") }}' +
                        ' <strong>' +
                        checked.length +
                        '</strong> ' +
                        '{{ __("selected customer(s).") }}',

                    showCancelButton: true,

                    confirmButtonText:
                        '<i class="bi bi-whatsapp"></i> {{ __("Send Reminder") }}',

                    cancelButtonText:
                        '{{ __("Cancel") }}',

                    reverseButtons: true

                }).then(function (result) {

                    if (result.isConfirmed) {

                        const button =
                            document.getElementById(
                                'send-reminder-btn'
                            );

                        button.disabled = true;

                        button.innerHTML =
                            '<span class="spinner-border spinner-border-sm me-2"></span>' +
                            '{{ __("Sending...") }}';

                        form.submit();
                    }

                });

            }
        );

    }


    updateSelection();

});
</script>