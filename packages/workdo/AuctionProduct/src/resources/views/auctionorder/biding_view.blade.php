
<div id="tab-13" class="biding-card">
    <div class="section-title">
        <h2>{{ __('Product Biding List') }}</h2>
    </div>
    <div class="order-history-frame">
        <table class="order-history-tbl">
            <thead>
                <tr>
                    <th>{{ __('Product Name') }}</th>
                    <th>{{ __('My Bid Amount') }}</th>
                    <th>{{ __('Height Bid Amount') }}</th>
                    <th>{{ __('Start Date') }}</th>
                    <th>{{ __('End Date') }}</th>
                </tr>
            </thead>
            <tbody>
                @if (count($bid_product) > 0)
                @php
                    $displayed_product_ids = [];
                @endphp
                    @foreach ($bid_product as $key=> $bid)
                    @php
                        if (in_array($bid->product_id, $displayed_product_ids)) {
                            continue;
                        }
                        $product = \App\Models\Product::findOrFail($bid->product_id);
                        $highest_bid = \Workdo\AuctionProduct\app\Models\AuctionProductBid::where('product_id', $bid->product_id)->where('store_id', $store->id)->max('bid_amount');
                        $latest_bid = \Workdo\AuctionProduct\app\Models\AuctionProductBid::where('product_id', $bid->product_id)->where('customer_id', auth('customers')->user()->id)->where('store_id', $store->id)->orderByDesc('created_at')->value('bid_amount');
                        $settings = \Workdo\AuctionProduct\app\Models\AuctionProductSetting::whereRaw("FIND_IN_SET($bid->product_id, auction_product)")
                                                    ->first();
                        $displayed_product_ids[] = $bid->product_id;
                    @endphp
                        <tr>
                            <td>{{!empty($product->name) ? $product->name : '-' }}</td>
                            <td>{{ $currency . number_format($latest_bid, 2) }}</td>
                            <td>{{ $currency . number_format($highest_bid, 2)}}</td>
                            <td>{{ $settings->start_date }}</td>
                            <td> {{ $settings->end_date }}
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
