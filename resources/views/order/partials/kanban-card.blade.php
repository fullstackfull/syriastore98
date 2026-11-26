{{--
    P2-04-7 — one order on the board.

    THE STATUS CONTROL POSTS TO `order.status.change`, the SAME route the detail page uses. That
    handler reads the id from the route SEGMENT (not the body — a posted `id` used to win over the
    url and act on another order), checks `OrderPolicy::allow('updateStatus')` operator-first then
    `sameStore`, and its cancel limb is a money write `OrderObserver` forwards to
    `OrderFinancialService`. A board with its own writer would have to re-earn all of that, so it
    does not have one.

    THE MOVES OFFERED ARE THE ONES THE WRITER WILL ACTUALLY HONOUR FOR THIS ORDER. P4-F033. The
    vocabulary comes from `FulfilmentStateMachine`'s matrix (assembled in `order/kanban.blade.php`,
    which also drops the two the board does not carry) and every candidate is then put to
    `permits()` against this card's own `delivered_status` below. Cancel and Return are
    deliberately absent whatever the machine permits: they are irreversible and money-bearing, and
    they belong on the detail page where the merchant sees the whole order before acting. A
    drag-sized control that could cancel an order is one nobody should be able to hit by accident.

    The previous rule — drop the option whose LABEL matches the card's current status — offered
    eleven transitions the writer refuses. The account is on the PHP block that replaced it.

    A NOTE ON WHAT THIS COMMENT MAY NOT CONTAIN, because two drafts of it broke the board and
    neither failure named a comment. `BladeCompiler::compileString()` calls
    `storeUncompiledBlocks()` and then `compileComments()`, in that order, and both are plain
    non-greedy regexes over the whole file. So, written out literally in here:

      - the raw-PHP directive's own name opens a raw block that runs to the next closing one,
        swallowing every byte between — the card compiled to its last nine lines, HTTP 500;
      - a comment delimiter CLOSES THIS COMMENT early, and the prose after it becomes live
        template text — which is how the first repair of the first fault produced the second.

    CLAUDE.md records four censuses satisfied or broken by prose about the thing they measure.
    This is the same hazard with the COMPILER as the reader, so describe Blade syntax in words
    here and put the real tokens in the PHP block below, where they are already quoted.
--}}
{{--
    `<div>` RATHER THAN `<p>` FOR THE META LINES, and that is not a style preference. The host's
    dark stylesheet carries `.dash-sidebar .dash-link, …, p { color: #808191 !important }` — EVERY
    paragraph in the dark admin — which measured 3.42:1 on this card's surface and which no
    component CSS can override, `!important` being the point. Raising the DS selector's
    specificity was tried and measured: it loses, as it must. A `<div>` is not matched by that
    rule, so the card's own colour applies. The theme-wide defect is recorded as F152 rather than
    worked around silently.
--}}
<article class="ss-board__card">
    <a class="ss-board__card-id"
       href="{{ route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($ssOrder->id)) }}">
        {{-- P2.5-PKG-M (F-M-05). `<bdi>` keeps the `#` ON THE NUMBER. Without it the bidi
             algorithm moves a leading neutral to the visual end of a Latin run inside an
             Arabic line, so `#ORD-2043` renders `ORD-2043#`. Seen in the Arabic board capture
             at 1440 and again in the list at 390, so it is not a width artefact. --}}
        <bdi>#{{ $ssOrder->product_order_id }}</bdi>
    </a>

    <div class="ss-board__card-meta">
        {{ $ssOrder->CustomerData->name ?? __('Walk-in Customer') }}
    </div>

    <div class="ss-board__card-meta">
        {{ order_currency_format_with_sym($ssOrder->final_price ?? 0, $ssOrder->id) }}
        @if ($ssOrder->payment_status)
            <span class="ss-badge {{ $ssOrder->payment_status === 'Paid' ? 'ss-badge--success' : '' }}">{{ __($ssOrder->payment_status) }}</span>
        @endif
    </div>

    @php($ssSource = \App\Models\Order::orderSourceLabel($ssOrder->order_source))
    @if ($ssSource !== null)
        <div class="ss-board__card-meta"><span class="ss-badge">{{ $ssSource }}</span></div>
    @endif

    @permission('Manage Order')
        {{--
            P4-F033 — THE OFFER IS THE STATE MACHINE'S ANSWER, NOT A GUESS AT IT.

            WHAT STOOD HERE was a reject() comparing each move's LABEL with the label of the status
            the card is already in. Two faults, both measured on the rendered board:

              1. IT OFFERED ELEVEN REFUSED MOVES. A cancelled or returned order names no move, so
                 it kept all four, and FulfilmentStateMachine permits NOTHING out of a settled
                 order; a delivered one lost only `delivered` and went on offering Confirmed,
                 Picked Up and Shipped, which un-happen a delivery. The merchant picked one,
                 submitted, and got "This order is closed, so its status cannot be changed." The
                 writer was already right; the control should never have been drawn.

              2. IT COMPARED TWO TRANSLATED STRINGS. The move labels and the column labels come
                 from separate __() calls that happen to be worded identically. One reworded
                 catalogue entry and the board would silently offer a DIFFERENT set of moves in
                 Arabic than in English.

            BOTH ARE NOW ONE QUESTION EACH, asked of the machine:

              - permits() — the writer's own rule, so a control cannot outlive the server's
                willingness to honour it. That is the whole fix, and it is one call.
              - the move's target state, from $ssMoveTarget, against stateOf() — a move INTO the
                state the order is already in is not a move. stateOf() rather than an int cast
                because orders.delivered_status really does hold values outside 0..8 (the literal
                '-', Api\ApiController:10471), for which it answers null: no target matches it, so
                such a row keeps every option — exactly what the machine documents for an
                ungoverned state, "as movable as it is today".

            AND THE TWO LINES BELOW STAY IN THE ONE-LINE DIRECTIVE FORM THE REST OF THIS FILE
            USES, which is not a style choice. storePhpBlocks() is a non-greedy regex from the
            raw-PHP directive to the next CLOSING one over the whole file, and it does not care
            which form opened it — so introducing a closing directive here paired it with the
            one-line $ssSource assignment thirty lines ABOVE, wrapped every directive between the
            two in a raw PHP block, and the board answered HTTP 500 with a parse error pointing at
            the compiled file. Measured. Keep the explanation in a comment and the code in
            one-liners, and this file never grows a closing directive.
        --}}
        @php($ssCurrentState = \App\Support\Orders\FulfilmentStateMachine::stateOf($ssOrder->delivered_status))
        @php($ssOffer = collect($ssMoves)->filter(fn ($ssLabel, $ssWord) => ($ssMoveTarget[$ssWord] ?? null) !== $ssCurrentState && \App\Support\Orders\FulfilmentStateMachine::permits($ssOrder->delivered_status, $ssWord)))
        @if ($ssOffer->isNotEmpty())
            <form method="POST" action="{{ route('order.status.change', $ssOrder->id) }}"
                  class="ss-board__card-move">
                @csrf
                <input type="hidden" name="id" value="{{ $ssOrder->id }}">
                <label class="ss-label ss-visually-hidden" for="ss-move-{{ $ssOrder->id }}">
                    {{ __('Move order :ref to', ['ref' => $ssOrder->product_order_id]) }}
                </label>
                {{-- P2.5-R-05. `delivered`, NOT `order_status`. OrderController::order_status_change()
                     reads `$request->delivered` -- the key the detail page's AJAX caller has always
                     sent (order/view.blade.php:853). Named `order_status`, every move on this board
                     was authorised, written to the order-note log, and then discarded, because
                     `$data['order_status']` was null and the writer matched none of its branches.
                     OrderKanbanMoveTest asserts BOTH ends of this contract. --}}
                {{-- P2.5-ORD-12. THE CONTROL CANNOT BE FIRED EMPTY ANY MORE.
                     `required` on the select plus `disabled selected` on the placeholder is the
                     pair that works: `disabled` alone still lets the empty option BE the
                     selection and post; `selected` keeps it the shown default so the control
                     still reads "Move to…" rather than silently pre-arming a real status; and
                     `required` is what makes the browser refuse the submit and focus the select.
                     Removing any one of the three re-opens the defect.
                     WITHOUT THIS the board was the ONE surface that could post an unset
                     `delivered` -- no JavaScript anywhere on the screen, submit always enabled,
                     and three vertically-aligned cards per column whose Move pairs are
                     pixel-identical, so a click on the button before the select is an ordinary
                     slip. It cost the BUYER a "Status Change" e-mail and an SMS reading
                     "Your Order is , !Hi ORD-2043", and put a false line in the order's timeline.
                     THIS IS NOT THE ONLY GUARD, and it must not be: a request that does not come
                     from a browser does not run it. OrderController::order_status_change()
                     refuses an empty `delivered` before the write, and the notification block now
                     sits inside the success branch. --}}
                <select class="ss-select ss-select--sm" id="ss-move-{{ $ssOrder->id }}" name="delivered" required>
                    <option value="" disabled selected>{{ __('Move to…') }}</option>
                    @foreach ($ssOffer as $ssValue => $ssLabel)
                        <option value="{{ $ssValue }}">{{ $ssLabel }}</option>
                    @endforeach
                </select>
                <button type="submit" class="ss-btn ss-btn--secondary ss-btn--sm">{{ __('Move') }}</button>
            </form>
        @endif
    @endpermission
</article>
