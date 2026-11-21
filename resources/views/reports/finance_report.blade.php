@extends('layouts.app')

@section('page-title')
    {{ __('Finance Report') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Finance Report') }}</li>
@endsection

@section('content')
    {{--
        ANA-001 -- FINANCE IN THE REPORTS CENTRE.

        THREE OF OWNER DECISION D2's FOUR FIGURES, under the labels D2 approved and no others. Each
        comes from the assembler `/finance` already uses, so the two screens cannot disagree:
        `FinanceLedgerFigures` for `Payments received` and `Estimated gross profit`,
        `WalletEntitlementFigure` for `Available to withdraw`.

        THE FOURTH, `Order value`, IS DELIBERATELY NOT HERE. It lives on the sales report, where the
        demand figures it belongs beside are, and it is not a ledger figure: putting it on this card
        would invite the comparison D2 exists to prevent -- a merchant reading order value against
        payments received and concluding money is missing, when the difference is unpaid
        cash-on-delivery. The `ANA-005` row records that no single screen yet shows all four, and
        that is still true after this change rather than quietly resolved by it.

        `<x-ss.stat>` NOT A HAND-WRITTEN TILE: it is what carries the stated period, the visible
        caveat associated with its figure by `aria-describedby`, the honest unavailable state for a
        null value, and the bidi isolates that keep `amount CODE` together on an Arabic page.
    --}}
    <div class="row mb-4">
        <div class="col-12">
            <x-ss.stats :label="__('Financial ledger')">
                <x-ss.stat :label="__('Payments received')"
                       :value="$ledgerFigures['payments']['value']"
                       :period="$ledgerFigures['payments']['period']"
                       :hint="$ledgerFigures['payments']['hint']" />

                <x-ss.stat :label="__('Estimated gross profit')"
                       :value="$ledgerFigures['profit']['value']"
                       :period="$ledgerFigures['profit']['period']"
                       :hint="$ledgerFigures['profit']['hint']" />

                <x-ss.stat :label="__('Available to withdraw')"
                       :value="$walletFigure['value']"
                       :period="$walletFigure['period']"
                       :hint="$walletFigure['hint']" />
            </x-ss.stats>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    {{-- WHY THIS SCREEN HAS NO PERIOD PICKER, said on the screen rather than only in
                         the code. `store_order_financials` has no backfill, so a window reaching back
                         past the record would read zero for months that merely have no row -- which a
                         merchant reads as a business that stopped. The tiles state the period they
                         can vouch for; narrowing it is ANA-002's slice. --}}
                    <p class="ss-stat__hint mb-0">
                        {{ __('These figures cover the period each tile states. The financial record has no history before that date, so an earlier period would read as zero rather than as unknown.') }}
                    </p>
                    <p class="ss-stat__hint mb-0">
                        {{ __('For the full finance screen, including wallet movements and withdrawals, open Finance.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
