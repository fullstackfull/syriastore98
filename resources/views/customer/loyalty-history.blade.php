{{--
     MKT-005 / P05-11 — «سجل حركات», THE LOYALTY POINT HISTORY A MERCHANT CAN READ.
     ============================================================================================

     Included from `customer/view.blade.php` (customer.show), which is where a merchant already
     goes to answer a question about one customer.

     WHY A SEPARATE FILE AND NOT MARKUP IN THAT VIEW. Three ratchets read
     `customer/view.blade.php` as WHOLE-FILE text — `CustomerOrdersTableInDataTableScopeTest` looks
     for a `<table class="…dataTable…">` and for a `card-body table-border-style ss-dt-card-body`
     wrapper anywhere in it. A second table in that file could satisfy both regexes on its own,
     leaving the orders table free to drift out of the DataTable scope with the ratchet still green.
     CLAUDE.md records exactly that failure: *"an assertion over a whole 7,000-line stylesheet
     answers 'does this string exist anywhere', and a mutation dropping one component's 44px target
     survived because another component had the same rule."* Keeping this markup in its own file
     leaves those reads measuring the table they were written for.

     `.ss-table` AND NOT `.dataTable`, for the same reason and one more: `public/js/custom.js`
     builds a simpleDatatables instance on every `.dataTable` and paginates it to ten rows while
     discarding the handle, so a history table would silently show ten of N movements with nothing
     to say it was truncated. `.ss-table` carries the design system's own 38 rules, including the
     `[dir="rtl"]` undo of the vendored `text-transform: uppercase` that does nothing for Arabic.

     BIDI: `<bdi>` ON EVERY NUMERIC RUN, never `dir="ltr"` on a block. A signed figure is the worst
     case in the whole product for this — in an RTL paragraph an unisolated `-490` can render with
     the minus at the wrong end, which for a points movement inverts its meaning. `customer/
     grid.blade.php` records why the attribute form is wrong: it flips the block's inherited
     `text-align: start`, so one cell right-aligns and its neighbour left-aligns.

     EVERY STRING GOES THROUGH `__()` AND HAS AN ARABIC ENTRY, pinned by
     `LoyaltyStringsAreTranslatedTest`. A missing entry returns the ENGLISH string, so the Arabic
     page renders perfectly, in the right direction, with English words in it — HTTP 200 and no log
     line. Only a census of the keys catches that.

     THE KIND IS TRANSLATED FROM THE STORED MACHINE NAME, never stored as a sentence: a stored
     English sentence cannot be translated after the fact.
--}}

@php
    /*
     * `$loyalty` is built by `CustomerController::show()`. Read defensively: this partial is
     * included from a view that other code may render, and a missing key must not 500 a screen a
     * merchant reaches with `Show Customer`.
     */
    $ssLoyaltyAvailable = (bool) ($loyalty['available'] ?? false);
    $ssLoyaltyBalance = $loyalty['balance'] ?? null;
    $ssLoyaltyHistory = $loyalty['history'] ?? collect();
    $ssLoyaltyVerdict = $loyalty['verdict'] ?? null;

    $ssPointKindLabels = [
        'earn' => __('Earned'),
        'redeem' => __('Redeemed'),
        'expire' => __('Expired'),
        'adjust' => __('Adjustment'),
    ];

    $ssPointKindTones = [
        'earn' => 'success',
        'redeem' => 'info',
        'expire' => 'warning',
        'adjust' => 'neutral',
    ];

    /* A points figure is a count, not money: no currency symbol and no currency formatter. */
    $ssPoints = static fn ($value) => number_format((float) $value, 2);
    $ssSignedPoints = static fn ($value) => ((float) $value > 0 ? '+' : '') . number_format((float) $value, 2);
@endphp

<div class="row mt-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">{{ __('Loyalty points history') }}</h5>
            </div>
            <div class="card-body">

                @if (! $ssLoyaltyAvailable)
                    {{--
                         THE HONEST EMPTY STATE, and the reason this branch exists at all.
                         `customers.total_club_point` is declared by a VENDORED migration that only
                         runs when `RewardClubPoint` is installed, so on most environments there is
                         no balance and no movement. An empty table here would read as "this
                         customer has never earned a point", which is a different and false
                         statement. `GAP-191`.
                    --}}
                    <div class="alert alert-info mb-0" role="status">
                        {{ __('The loyalty points programme is not installed on this store, so there are no points and no history to show.') }}
                    </div>
                @else

                    <div class="d-flex flex-wrap align-items-center gap-4 mb-3">
                        <div>
                            <span class="d-block ss-text-muted">{{ __('Current points balance') }}</span>
                            <span class="d-block h4 mb-0">
                                @if ($ssLoyaltyBalance === null)
                                    {{ __('Not available') }}
                                @else
                                    <bdi>{{ $ssPoints($ssLoyaltyBalance) }}</bdi>
                                @endif
                            </span>
                        </div>

                        @if ($ssLoyaltyVerdict !== null)
                            <div>
                                <span class="d-block ss-text-muted">{{ __('Reconciliation') }}</span>
                                <span class="d-block">
                                    @if ($ssLoyaltyVerdict->isFinding())
                                        <x-ss.status-badge tone="warning" :label="__('Needs review')" />
                                    @else
                                        <x-ss.status-badge tone="success" :label="__('Balance matches the history')" />
                                    @endif
                                </span>
                            </div>
                        @endif
                    </div>

                    @if ($ssLoyaltyVerdict !== null)
                        {{--
                             THE VERDICT IN WORDS. Six readings, and they are different facts — see
                             `LoyaltyReconciliation`'s docblock. "No history" is emphatically NOT a
                             finding: it is the ordinary state of every customer in a store that
                             installed the ledger today, and reporting it as a problem is how a
                             control gets switched off on its first day.
                        --}}
                        <p class="ss-text-muted">
                            @switch($ssLoyaltyVerdict->status)
                                @case('no_history')
                                    {{ __('This balance was earned before the points history began, so it cannot be checked against it. New movements will be recorded from now on.') }}
                                    @break
                                @case('reconciled')
                                    {{ __('Every recorded movement adds up to the balance above.') }}
                                    @break
                                @case('balance_drift')
                                    {{ __('The recorded movements add up to a different balance, so something changed these points without being recorded. The balance above is what the customer can spend; nothing here has been corrected.') }}
                                    @break
                                @case('chain_broken')
                                    {{ __('There is a gap in the recorded movements, so at least one change to these points was never recorded.') }}
                                    @break
                                @case('incomplete_history')
                                    {{ __('Part of this history could not be checked, so the total cannot be confirmed.') }}
                                    @break
                                @default
                                    {{ __('This history cannot be checked on this store.') }}
                            @endswitch

                            @if ($ssLoyaltyVerdict->expected !== null && $ssLoyaltyVerdict->isFinding())
                                <span class="d-block">
                                    {{ __('Recorded movements add up to') }}
                                    <bdi>{{ $ssPoints($ssLoyaltyVerdict->expected) }}</bdi>
                                </span>
                            @endif
                        </p>
                    @endif

                    @if ($ssLoyaltyHistory->isEmpty())
                        <x-ss.empty
                            icon="history"
                            :title="__('No points movements recorded yet')"
                            :body="__('Earning, redeeming and adjusting points will appear here as they happen.')" />
                    @else
                        <div class="table-responsive">
                            <table class="table ss-table">
                                <caption class="visually-hidden">
                                    {{ __('Every recorded movement of this customer\'s loyalty points, newest first') }}
                                </caption>
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('Date') }}</th>
                                        <th scope="col">{{ __('Movement') }}</th>
                                        <th scope="col" class="ss-table__num">{{ __('Change') }}</th>
                                        <th scope="col" class="ss-table__num">{{ __('Balance after') }}</th>
                                        <th scope="col">{{ __('Order') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($ssLoyaltyHistory as $ssMovement)
                                        <tr>
                                            <td>
                                                <bdi>{{ \App\Models\Utility::dateFormat($ssMovement->created_at) }}</bdi>
                                            </td>
                                            <td>
                                                <x-ss.status-badge
                                                    :tone="$ssPointKindTones[$ssMovement->kind] ?? 'neutral'"
                                                    :label="$ssPointKindLabels[$ssMovement->kind] ?? __('Adjustment')" />

                                                @if ($ssMovement->redemption_claimed && $ssMovement->kind !== 'redeem')
                                                    {{--
                                                         THE CLAIM, SHOWN AS A CLAIM. The order asked
                                                         to spend points and the balance did not go
                                                         down overall — which happens when the award
                                                         for that same order was larger than the
                                                         balance spent. Saying so is the difference
                                                         between a log and a label.
                                                    --}}
                                                    <span class="d-block ss-text-muted">{{ __('A redemption was requested on this order') }}</span>
                                                @endif
                                            </td>
                                            <td class="ss-table__num ss-num">
                                                <bdi>{{ $ssSignedPoints($ssMovement->points_delta) }}</bdi>
                                            </td>
                                            <td class="ss-table__num ss-num">
                                                @if ($ssMovement->balance_after === null)
                                                    {{ __('Not available') }}
                                                @else
                                                    <bdi>{{ $ssPoints($ssMovement->balance_after) }}</bdi>
                                                @endif
                                            </td>
                                            <td>
                                                @if ($ssMovement->order_id)
                                                    <bdi>#{{ $ssMovement->order_id }}</bdi>
                                                @else
                                                    {{ __('Not recorded') }}
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <p class="ss-text-muted mt-3 mb-0">
                            {{ __('This history is a record only. Nothing on this page changes a customer\'s points balance.') }}
                        </p>
                    @endif

                @endif

            </div>
        </div>
    </div>
</div>
