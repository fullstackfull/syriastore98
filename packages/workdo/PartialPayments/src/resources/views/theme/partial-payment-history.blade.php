<style>
    .partial-payment-view .card .card-body .table-responsive {
        width: 100%;
        overflow-x: auto;
        margin: 0;
    }
</style>
@if (!$partial_payment->isEmpty())
    <div class="partial-payment-view">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <h4>{{ __('Partial Payment History') }}</h4>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('Payment') }}</th>
                                <th>{{ __('Order Id') }}</th>
                                <th>{{ __('Payment Type') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($partial_payment as $item)
                                <tr>
                                    <td class="total">
                                        {{ $item->payment }}
                                    </td>
                                    <td>#{{ $item->order_id }} - {{ $item->id }}</td>
                                    <td>{{ $item->payment_type }}</td>
                                    <td>{{ $item->payment_status }}</td>
                                    <td>
                                        {{ currency_format_with_sym($item['payment_amount'] ?? 0, $store->id) ?? SetNumberFormat($item['payment_amount']) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endif
