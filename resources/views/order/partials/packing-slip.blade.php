{{--
    MASTER PLAN `ORD-008` (GAP-185) -- THE PICKING AND PACKING DOCUMENT.

    Plan text: docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-WITH-AMENDMENTS.md:287,
    section 04-C -- Picking, Packing, barcodes, labels and invoices. Labels and invoices were
    already built (order/shippinglabel.blade.php, order/receipt.blade.php); this is the picking and
    packing half, which a whole-word census found absent from the entire codebase.

    ONE DOCUMENT FOR BOTH NOUNS, AND THAT IS NOT A SHORTCUT. The plan names Picking and Packing in
    one clause and distinguishes them nowhere, so inventing two documents with different contents
    would be inventing a warehouse process the owner has not described. The one document answers
    both questions a packer asks -- what to pull, and what is in the parcel -- and the report says so
    rather than implying the plan asked for one.

    NO MONEY, DELIBERATELY. App\Support\Orders\PackingSlip carries no price, tax, discount or total,
    and `PackingSlipTest::test_the_slip_carries_no_money` pins the line's keys. The priced document
    is the receipt. That is what makes this engineering rather than a pricing question.

    IT PRINTS ALONE. The @media print block in the stylesheet below blanks the rest of the page while
    `body.ss-print-packing` is set, so the merchant gets the slip and not the admin chrome. The class
    is added by the button and removed on `afterprint`, so a cancelled print leaves nothing behind.
    `inset-inline-start`, not `left`: this page is rendered right-to-left in Arabic.
--}}

@php
    $ssSlip = \App\Support\Orders\PackingSlip::forLines(
        $order['product'] ?? [],
        (int) ($order['store_id'] ?? 0)
    );
@endphp

<div class="ss-card" id="ss-packing-slip-card">
    <div class="ss-card__header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h4 class="mb-0">{{ __('Picking and packing list') }}</h4>
        <button type="button" class="ss-btn ss-btn--sm ss-btn--secondary" id="ss-print-packing-slip">
            <i class="ti ti-printer" aria-hidden="true"></i>
            <span>{{ __('Print packing list') }}</span>
        </button>
    </div>
    <div class="ss-card__body">
        <div id="ss-packing-slip">
            <div class="ss-packing-slip__head">
                <p class="ss-packing-slip__title">{{ __('Picking and packing list') }}</p>
                <p class="ss-packing-slip__meta">
                    {{ __('Order') }} {{ $order['order_id'] }}
                    @if (! empty($order['order_date']))
                        &middot; {{ $order['order_date'] }}
                    @endif
                </p>
            </div>

            @if ($ssSlip['distinct'] === 0)
                <p class="ss-text-muted mb-0">{{ __('This order has no line items to pick.') }}</p>
            @else
                <div class="table-responsive">
                    <table class="table ss-table ss-packing-slip__table">
                        <caption class="visually-hidden">
                            {{ __('Items to pick and pack for this order, with quantities. No prices.') }}
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col" class="ss-packing-slip__tick">{{ __('Picked') }}</th>
                                <th scope="col">{{ __('Item') }}</th>
                                <th scope="col">{{ __('Code') }}</th>
                                <th scope="col">{{ __('Quantity') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ssSlip['lines'] as $ssLine)
                                <tr>
                                    {{-- A printed box, not a checkbox input: the packer ticks it with a pen, and a
                                         real input would be an unlabelled control that posts nowhere. --}}
                                    <td class="ss-packing-slip__tick" aria-hidden="true"><span class="ss-packing-slip__box"></span></td>
                                    <th scope="row" class="ss-packing-slip__item">
                                        <span class="ss-text-strong">{{ $ssLine['name'] }}</span>
                                        @if ($ssLine['variant_name'])
                                            <br><span class="text-sm">{{ $ssLine['variant_name'] }}</span>
                                        @endif
                                        @if ($ssLine['returned'])
                                            <br><span class="ss-badge ss-badge--warning">{{ __('Returned') }}</span>
                                        @endif
                                    </th>
                                    <td class="ss-packing-slip__code">
                                        {{ $ssLine['code'] ?: '—' }}
                                    </td>
                                    <td class="ss-packing-slip__qty">{{ $ssLine['quantity'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="ss-packing-slip__tick" aria-hidden="true"></td>
                                <th scope="row" colspan="2">{{ __('Total units to pack') }}</th>
                                <td class="ss-packing-slip__qty ss-text-strong">{{ $ssSlip['units'] }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
        {{-- An HTML comment, not a Blade one, and deliberately: it is the END ANCHOR the tests
             scope their assertions to. CLAUDE.md §3 records a mutation surviving because an
             assertion over a whole document answers "does this string exist anywhere" -- the
             no-money and no-input assertions here have to see THIS block and nothing else. --}}
        <!-- /ss-packing-slip -->
    </div>
</div>

@push('css')
    <style>
        .ss-packing-slip__head { margin-bottom: 1rem; }
        .ss-packing-slip__title { font-weight: 600; margin-bottom: .125rem; color: var(--ss-text-strong, inherit); }
        .ss-packing-slip__meta { margin-bottom: 0; color: var(--ss-text-muted, inherit); }
        .ss-packing-slip__tick { width: 3rem; }
        .ss-packing-slip__qty { text-align: end; white-space: nowrap; }
        .ss-packing-slip__code { white-space: nowrap; }
        .ss-packing-slip__item { font-weight: 400; }
        .ss-packing-slip__box {
            display: inline-block;
            width: 1.125rem;
            height: 1.125rem;
            border: 1px solid var(--ss-border, currentColor);
            border-radius: 3px;
        }

        @media print {
            /* The print-one-element pattern: `visibility` rather than `display`, so the rest of the
               page keeps its box and nothing reflows while the slip is positioned over it. */
            body.ss-print-packing * { visibility: hidden; }
            body.ss-print-packing #ss-packing-slip,
            body.ss-print-packing #ss-packing-slip * { visibility: visible; }
            body.ss-print-packing #ss-packing-slip {
                position: absolute;
                top: 0;
                /* LOGICAL, not `left`: this page renders right-to-left in Arabic. */
                inset-inline-start: 0;
                width: 100%;
            }
            /* Ink: the printed slip is black on white whatever the screen theme is. */
            body.ss-print-packing #ss-packing-slip,
            body.ss-print-packing #ss-packing-slip * { color: #000; background: transparent; }
            body.ss-print-packing #ss-packing-slip .ss-packing-slip__box { border-color: #000; }
        }
    </style>
@endpush

@push('custom-script')
    <script>
        (function () {
            var trigger = document.getElementById('ss-print-packing-slip');

            if (!trigger) {
                return;
            }

            /* `afterprint` fires on cancel as well as on print, so the class cannot be left behind
               and blank the page the merchant is still looking at. The timeout is the fallback for
               browsers that do not fire it. */
            var clear = function () {
                document.body.classList.remove('ss-print-packing');
            };

            window.addEventListener('afterprint', clear);

            trigger.addEventListener('click', function () {
                document.body.classList.add('ss-print-packing');
                window.print();
                window.setTimeout(clear, 1000);
            });
        })();
    </script>
@endpush
