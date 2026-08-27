<style>
    .colorinput {
    margin: 0;
    position: relative;
    cursor: pointer;
}
    .colorinput-color {
        background-color: #fdfdff;
        border-color: #e4e6fc;
        border-width: 1px;
        border-style: solid;
        display: inline-block;
        width: 1.75rem;
        height: 1.75rem;
        border-radius: 3px;
        color: #fff;
        box-shadow: 0 1px 2px 0 rgb(0 0 0 / 5%);
    }

    .colorinput-input {
        position: absolute;
        z-index: -1;
        opacity: 0;
    }

    .colorinput-input:checked~.colorinput-color:before {
        opacity: 1;
    }

    .colorinput-color:before {
        content: '';
        opacity: 0;
        position: absolute;
        top: .25rem;
        left: .25rem;
        height: 1.25rem;
        width: 1.25rem;
        transition: .3s opacity;
        background: url("data:image/svg+xml;charset=utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3E%3Cpath fill='%23fff' d='M6.564.75l-3.59 3.612-1.538-1.55L0 4.26 2.974 7.25 8 2.193z'/%3E%3C/svg%3E") no-repeat center center/50% 50%;
    }
</style>
<li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
    <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'auto_print_setting' ? 'active' : '' }}" data-url="{{ route('automatic-print.form') }}" data-tab="auto_print_setting" id="pills-auto_print-tab" data-bs-toggle="pill" data-bs-target="#eco-auto_print" type="button" role="tab" aria-controls="pills-auto_print" aria-selected="false">
    {{ __('Print Settings') }}
    </a>
</li>

@push('custom-script')
    <script>
        $(document).on("change", "select[name='invoice_template'], input[name='invoice_color']", function() {
            var template = $("select[name='invoice_template']").val();
            var color = $("input[name='invoice_color']:checked").val();
            $('#purchase_frame').attr('src', '{{ url('invoices/preview') }}/' + template + '/' + color);
        });
    </script>
@endpush
