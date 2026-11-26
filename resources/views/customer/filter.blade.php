@foreach ($customers as $customer)
    <tr class="font-style">
        @php
            if ($customer->regiester_date !== null) {
                $carbonDate = \Carbon\Carbon::parse($customer->regiester_date);
                $formattedDate = $carbonDate->format('F d, Y');
            } else {
                $formattedDate = null;
            }
            if ($customer->last_active !== null) {
                $active = \Carbon\Carbon::parse($customer->last_active);
                $last_active = $active->format('F d, Y');
            } else {
                $last_active = null;
            }

            $AOV = 0;
            if ($customer->total_spend() != 0 && $customer->Ordercount() != 0) {
                $AOV = number_format($customer->total_spend() / $customer->Ordercount(), 2);
            }
            $activityLogEntry = $activitylog->where('user_id', $customer->id)->first();

        @endphp
        <td>
            @if ($activityLogEntry)
                <a href="{{ route('customer.timeline', $customer->id) }}">
                    <span class="btn-inner--icon"></span>
                    <span class="btn-inner--text">{{ $customer->first_name }}
                        {{ $customer->last_name }}</span>
                </a>
            @else
                {{ $customer->first_name }} {{ $customer->last_name }}
            @endif
        </td>
        <td>{{ $last_active }}</td>
        <td>{{ $formattedDate }}</td>
        <td>{{ $customer->email }}</td>
        <td>
            <a href="{{ route('customer.show', $customer->id) }}">
                {{ $customer->Ordercount() }}
            </a>

        </td>
        {{-- GAP-156 / PHASE-05-METRIC-DEFINITIONS.md §15. THREE MONEY CELLS WHERE THERE WAS ONE,
             AND EACH SAYS WHICH QUESTION IT ANSWERS.

             WHAT WAS HERE: one `<td>` holding nothing but the `total_spend` call -- written
             WITHOUT its braces and parens here, because a Blade echo inside a Blade comment is
             both a compiler hazard this project has measured three 500s from and a string a
             source census cannot tell from code (CLAUDE.md records eight such readings).
             That figure sums `orders.final_price` with NO payment predicate of any kind: a
             cancelled order, a refunded order and an order nobody ever paid for all count in full.
             With no title above it and nothing beside it, the only reading available to a merchant
             was "this is what they paid", which is the one thing it is not.

             THE VALUE IS UNCHANGED, DELIBERATELY. Owner Option A makes it unfixable in place --
             `orders.payment_status` is the customer's settlement record and a cancellation
             deliberately does not rewrite it, so no predicate on `orders` could turn this into
             money. It is an order-value figure, it is useful as one, and the two cells beside it
             are the money question it was being read as the answer to.

             LABELLED IN THE CELL, which the shipped list does not need to do and this fragment
             does. `CustomerDataTable` carries its honesty in the column TITLES ("Order Value",
             "Payments Collected", "Net Retained") because yajra renders its own header. This
             fragment is a bare `<tr>` set: it has no header of its own, and it cannot borrow the
             list's by position either, because `buildDataTableColumn()` prepends a hidden `id`
             column and, where bulk delete is on, a checkbox column as well. So the words go in the
             cells, taken from the same vocabulary as those titles so the two copies cannot drift
             apart in wording -- which is precisely what happened to this pair's FILTER (P2.5-R-06,
             three faults in both copies).

             THE SENTENCE IS THE `title`, NOT A VISUALLY HIDDEN SPAN, and the distinction matters
             here: P5-11 found nine honesty sentences clipped to 1x1 with no reveal rule and ruled
             that a caveat must be VISIBLE ("one pixel shows nothing"). The visible disclosure on
             this surface is the label itself; the full sentence is a hover affordance on top of it,
             not a substitute for one. A 150-character sentence repeated on every row of a
             nine-hundred-customer list is noise, and the live list does not do it either.

             `Not available`, NEVER 0.00. `store_order_financials` was never backfilled, so a
             long-standing shopper's orders can be invisible to the ledger. A zero in a money cell
             is a claim that this person has never paid; an absence is the truth. Same words as
             `CustomerDataTable::moneyCell()`. --}}
        <td class="ss-dt-num" title="{{ $customerMoney[$customer->id]['spend_hint'] ?? '' }}">
            <span class="ss-stat__label">{{ __('Order Value') }}</span>
            {{ $customer->total_spend() }}
        </td>
        <td class="ss-dt-num">
            <span class="ss-stat__label">{{ __('Payments Collected') }}</span>
            {{ $customerMoney[$customer->id]['collected']['value'] ?? __('Not available') }}
        </td>
        <td class="ss-dt-num">
            <span class="ss-stat__label">{{ __('Net Retained') }}</span>
            {{ $customerMoney[$customer->id]['retained']['value'] ?? __('Not available') }}
        </td>
        {{-- AOV is order value divided by order count, so it inherits every exclusion the cell
             above does. The arithmetic is untouched -- D-40 reserves financial calculations -- and
             only the label is new. --}}
        <td class="ss-dt-num">
            <span class="ss-stat__label">{{ __('AOV') }}</span>
            {{ $AOV }}
        </td>
        <td>{{ $customer->mobile }}</td>
        <td class="Action ignore">
            <div class="d-flex">
                @if ($activityLogEntry)
                    <a href="{{ route('customer.timeline', $customer->id) }}"
                        class="btn btn-sm btn-icon btn-warning me-2" data-bs-placement="top">
                        <i class="ti ti-eye f-20"></i>
                    </a>
                @endif
                @permission('Show Customer')
                    <a href="{{ route('customer.show', $customer->id) }}" class="btn btn-sm btn-icon btn-info me-2"
                        data-bs-placement="top">
                        <i class="ti ti-shopping-cart f-20"></i>
                    </a>
                @endpermission
            </div>
        </td>
        <td>
            @permission('Status Customer')
                @if ($customer->regiester_date != null)
                    <div class="form-check form-switch">
                        <input class="form-check-input page-checkbox" id="{{ $customer->id }}"
                            aria-label="{{ __('Account status for :name', ['name' => $customer->name]) }}" type="checkbox"
                            name="page_active" data-onstyle="success" data-offstyle="danger" data-toggle="toggle"
                            data-on="off" data-off="on" @if ($customer->status == 1) checked="checked" @endif />
                    </div>
                @endif
            @endpermission
        </td>
    </tr>
@endforeach
