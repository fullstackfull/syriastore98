<div id="auction-order">
    <div id="tab-12" class="my-acc-right-content">
        <div class="section-title">
            <h2>{{ __('Auction Product Order') }}</h2>
        </div>
        <div class="order-history-frame">
            <table class="order-history-tbl">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ __('Customer Name') }}</th>
                        <th>{{ __('Order Id') }}</th>
                        <th>{{ __('Order Price') }}</th>
                        <th>{{ __('Order Date') }}</th>
                        <th>{{ __('Delivered Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @if (count($orders) > 0)
                        @foreach ($orders as $key=> $order)
                            <tr>
                                <td>{{ ++$key }}</td>
                                <td>{{auth()->guard('customers')->user()->name ?? '-' }}</td>
                                <td>{{ !empty($order->product_order_id) ? $order->product_order_id : '-' }}
                                </td>
                                <td>{{ currency_format_with_sym( $order->price, $order->store_id) ?? SetNumberFormat($order->price) }}</td>
                                <td>{{ $order->order_date }}</td>
                                <td> {{ $order->order_status }}
                                </td>
                            </tr>
                        @endforeach
                    @else
                        <tr>
                            <td><h2>{{ __('No records found') }}</h2></td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
    <div class="right-result-tbl text-right">
        @if (isset($orders))
        <b>Showing {{ $orders->firstItem() }}</b> to {{ $orders->lastItem() }} of {{ $orders->currentPage() }} ({{ $orders->lastPage() }} Pages)
        @endif
    </div>
    <div class="form-container">
        <div class="d-flex acc-back-btn-wrp align-items-center justify-content-end">
            @php
                $previousPageUrl = '';
                $nextPageUrl = '';
                if (isset($orders) && $orders->currentPage() < 1) {
                    $previousPageUrl = $orders->previousPageUrl();
                }
                if (isset($orders) && $orders->lastPage() > 1 && $orders->currentPage() != $orders->lastPage()) {
                    $nextPageUrl = $orders->nextPageUrl();
                }
            @endphp
            <button class="btn-secondary back-btn-acc" onclick="get_auction_order('{{ $previousPageUrl }}')">
                <svg xmlns="http://www.w3.org/2000/svg" width="35" height="14" viewBox="0 0 35 14" fill="none">
                    <path d="M25.0749 14L35 7L25.0805 0L29.12 6.06667H0V7.93333H29.12L25.0749 14Z"></path>
                </svg>
                {{ __('Back') }}
            </button>
            <button class="btn continue-btn" onclick="get_auction_order('{{ $nextPageUrl }}')">
                {{ __('Next') }}
                <svg xmlns="http://www.w3.org/2000/svg" width="35" height="14" viewBox="0 0 35 14" fill="none">
                    <path d="M25.0749 14L35 7L25.0805 0L29.12 6.06667H0V7.93333H29.12L25.0749 14Z"></path>
                </svg>
            </button>
        </div>
    </div>
</div>

@push('page-script')
<script>
    $(document).ready(function(e) {
        get_auction_order();
    });

    // Function to fetch auction orders via AJAX
    function get_auction_order(url = '') {
        if (url == '') {
            url = '{{ route('auction.order.list', $store->slug) }}?page=1';
        }

        var data = {};

        $.ajax({
            url: url,
            method: 'GET',
            data: data,
            context: this,
            success: function(response) {
                $('#loader').fadeOut();
                $('#auction-order').html(response.html);
            }
        });
    }
</script>

@endpush