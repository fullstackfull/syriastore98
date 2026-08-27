<style>
    .print-button{
        margin-left : 4px;
        /* padding: 10px 10px 10px 10px; */
    }
</style>
@if (isset($enable_order_print) && $enable_order_print === 'on')
    <a href="{{ route('invoice.shippinglabel.pdf', \Illuminate\Support\Facades\Crypt::encrypt($order['id'])) }}"
        target="_blank" class="btn btn-sm btn-badge mr-2 btn-primary btn-icon d-flex align-items-center m-1" data-bs-toggle="tooltip"
        data-bs-placement="top" title="{{ __('Download') }}" aria-label="Download">
        <i class="ti ti-download" style="font-size:20px"></i>
    </a>
    <a href="#" class="btn btn-sm btn-badge mr-2 btn-primary btn-icon d-flex align-items-center print-button m-1" data-bs-toggle="tooltip"
        data-bs-placement="top" title="{{ __('Print') }}" aria-label="Print" onclick="printPDF()">
        <i class="ti ti-printer" style="font-size:20px"></i>
    </a>
@else
    <a href="{{ route('shippinglabel.pdf', \Illuminate\Support\Facades\Crypt::encrypt($order['id'])) }}" target="_blank"
        class="btn btn-sm btn-badge mr-2 btn-primary btn-icon d-flex align-items-center m-1" data-bs-toggle="tooltip"
        data-bs-placement="top" title="{{ __('Print') }}" aria-label="Print">
        <i class="ti ti-printer" style="font-size:20px"></i>
    </a>
@endif
@push('page-script')
    <script>
        function printPDF() {
            const pdfUrl =
                `{{ route('invoice.shippinglabel.printing', \Illuminate\Support\Facades\Crypt::encrypt($order['id'])) }}`;

            const iframe = document.createElement('iframe');
            iframe.style.display = 'none';
            iframe.src = pdfUrl;

            document.body.appendChild(iframe);

            iframe.onload = function() {
                iframe.contentWindow.print();

                setTimeout(() => {
                    document.body.removeChild(iframe);
                }, 1000);
            };
        }
    </script>
@endpush
@push('custom-script')
    <script>
        function printPDF() {
            const pdfUrl =
                `{{ route('invoice.shippinglabel.printing', \Illuminate\Support\Facades\Crypt::encrypt($order['id'])) }}`;

            const iframe = document.createElement('iframe');
            iframe.style.display = 'none';
            iframe.src = pdfUrl;

            document.body.appendChild(iframe);

            iframe.onload = function() {
                iframe.contentWindow.print();

                setTimeout(() => {
                    document.body.removeChild(iframe);
                }, 1000);
            };
        }
    </script>
@endpush
