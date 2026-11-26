<!DOCTYPE html>
{{--
    ORD-008 (GAP-185) — THE PICKING AND PACKING DOCUMENT, standalone.

    Plan text: docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-WITH-AMENDMENTS.md:287,
    section 04-C — "Picking وPacking والباركود والملصقات والفواتير". The third sibling of
    order/shippinglabel.blade.php and order/receipt.blade.php.

    P1-SEC-11 criteria 1 and 4, the same two this document inherits from its siblings: the logo and
    the direction come from the ORDER'S store, resolved by the handler through DocumentDirection and
    never fetched here. A read here would resolve getCurrentStore() -- the VIEWER's store -- so the
    platform operator printing a merchant's document got the operator's logo, and the `<html>` was a
    hardcoded lang="en" with no dir, which no Arabic store's stylesheet could ever match.

    NO MONEY. App\Support\Orders\PackingSlip carries no price, tax, discount or total, and
    PackingSlipTest pins the line's key list. The slip travels in the parcel; the priced document is
    the receipt. That is the document's definition, and it is why this needed no pricing decision.
--}}
<html lang="{{ $documentLocale ?? app()->getLocale() }}" dir="{{ ($documentRtl ?? false) ? 'rtl' : 'ltr' }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>{{ __('Picking and packing list') }} — {{ config('platform.brand_name') }}</title>
    <style>
        /* Ink on paper: black on white whatever the panel theme is, and no dependence on the
           admin stylesheet, which this document does not load. */
        body {
            margin: 0;
            padding: 24px;
            background: #fff;
            color: #000;
            font-family: 'Trebuchet MS', Arial, sans-serif;
            font-size: 14px;
        }

        .ss-doc__head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            border-bottom: 1px solid #000;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }

        .ss-doc__title { font-size: 18px; font-weight: 700; margin: 0 0 4px; }
        .ss-doc__meta { margin: 0; }
        .ss-doc__logo { max-width: 120px; height: auto; }

        table { width: 100%; border-collapse: collapse; }
        caption { text-align: start; }
        th, td { border-bottom: 1px solid #000; padding: 8px 6px; text-align: start; }
        tfoot th, tfoot td { border-bottom: 0; border-top: 2px solid #000; font-weight: 700; }

        /* LOGICAL, not `right`: this document renders right-to-left for an Arabic store. */
        .ss-doc__qty { text-align: end; white-space: nowrap; }
        .ss-doc__code { white-space: nowrap; }
        .ss-doc__tick { width: 40px; }

        .ss-doc__box {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 1px solid #000;
        }

        .ss-doc__flag { font-weight: 700; }
        .ss-doc__empty { margin: 0; }
        .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0 0 0 0);
            white-space: nowrap;
        }

        @media print {
            body { padding: 0; }
        }
    </style>
</head>

<body>
@php
    $ssSlip = \App\Support\Orders\PackingSlip::forLines(
        $order['product'] ?? [],
        (int) ($order['store_id'] ?? 0)
    );
@endphp

<div class="ss-doc__head">
    <div>
        <p class="ss-doc__title">{{ __('Picking and packing list') }}</p>
        <p class="ss-doc__meta">
            {{ __('Order') }} {{ $order['order_id'] }}
            @if (!empty($order['order_date']))
                &middot; {{ $order['order_date'] }}
            @endif
        </p>
    </div>
    @if (!empty($invoice_logo))
        <img class="ss-doc__logo" src="{{ $invoice_logo }}" alt="" width="120">
    @endif
</div>

@if ($ssSlip['distinct'] === 0)
    <p class="ss-doc__empty">{{ __('This order has no line items to pick.') }}</p>
@else
    <table>
        <caption class="visually-hidden">
            {{ __('Items to pick and pack for this order, with quantities. No prices.') }}
        </caption>
        <thead>
            <tr>
                <th scope="col" class="ss-doc__tick">{{ __('Picked') }}</th>
                <th scope="col">{{ __('Item') }}</th>
                <th scope="col">{{ __('Code') }}</th>
                <th scope="col" class="ss-doc__qty">{{ __('Quantity') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($ssSlip['lines'] as $ssLine)
                <tr>
                    {{-- A printed box, not a checkbox input: the packer ticks it with a pen, and a
                         real input on a printed document is a control that posts nowhere. --}}
                    <td class="ss-doc__tick" aria-hidden="true"><span class="ss-doc__box"></span></td>
                    <th scope="row">
                        {{ $ssLine['name'] }}
                        @if ($ssLine['variant_name'])
                            <br>{{ $ssLine['variant_name'] }}
                        @endif
                        @if ($ssLine['returned'])
                            <br><span class="ss-doc__flag">{{ __('Returned') }}</span>
                        @endif
                    </th>
                    <td class="ss-doc__code">{{ $ssLine['code'] ?: '—' }}</td>
                    <td class="ss-doc__qty">{{ $ssLine['quantity'] }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td class="ss-doc__tick" aria-hidden="true"></td>
                <th scope="row" colspan="2">{{ __('Total units to pack') }}</th>
                <td class="ss-doc__qty">{{ $ssSlip['units'] }}</td>
            </tr>
        </tfoot>
    </table>
@endif
</body>

</html>
