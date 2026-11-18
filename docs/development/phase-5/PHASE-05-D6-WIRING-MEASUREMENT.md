# D6 — reward-redemption eligibility: what was measured, what stage 1 changed, and what it leaves to the owner

> **STAGE 1 IS IMPLEMENTED AND TESTED LOCALLY. D6 IS NOT CLOSED.** The server now decides, once per checkout,
> whether a signed-in customer may redeem — at the quote, and again at the one order writer that a direct POST can
> reach. **The gateway-return handlers are not touched**, and five of them carry a defect that a threshold check does
> not repair (§6). Nothing here is a production change, a migration, or a statement about what production does. CI's
> result for this change is the pull request's, not this file's.
>
> **AN INDEPENDENT REVIEW OF THIS SLICE FOUND A REAL LEAK IN ITS FIRST VERSION, AND IT IS FIXED (§3 item 6).** The first
> version read the signed-in customer's balance at the COD writer. A multipart *file* part named `customer_id` then made the
> request commit an order **with** the discount and fail **before** the event that debits the points — executed: order
> total 50.00 on a 100.00 basket, balance still 500, no ledger row, HTTP 500, repeatable. My own test had seen it and I
> had written it up as "not an exploit"; that was wrong, and this document said so until the review caught it. The same
> trigger already existed on the WhatsApp writer (it priced from the principal before D6). Both are closed at the intake.

Companion documents: the code is `app/Support/Money/RewardPointDiscount.php` (`isRedeemable`, `forOrder`); the vendored
defects are in [`../../security/P05-VENDOR-DEFECTS-REWARDCLUBPOINT.md`](../../security/P05-VENDOR-DEFECTS-REWARDCLUBPOINT.md);
the gap rows are `GAP-203` and `GAP-214` in [`../MASTER-PLAN-GAP-REGISTER.md`](../MASTER-PLAN-GAP-REGISTER.md).

## 1. The decision, and the defect it answers

**D6 (owner, 2026-11-17):** *enforce redemption thresholds and eligibility server-side.* Before this slice the two
thresholds (`checkout_points`, `checkout_price`) lived in two Blade partials and nowhere on the server. The server's own
test, at every site, was `isset($requests_data['club_point_is_active']) && … == 'on'` — the caller's posted string
compared with itself. So a hand-built `club_point_is_active=on` redeemed below both thresholds, with the programme
switched **off** (`club_point_enabled` is in neither the module's validator nor either partial), and — through a path
described in §3 — as a guest.

**Why "just add the check" would have been worse.** The vendored listener `AddRewardClubPointLis` runs after the order
is written and, whenever the flag is truthy, **replaces** the customer's whole balance with the order's accrual. A site
that refused the discount but left the flag on would take the balance and give nothing for it. A refusal therefore has
to reach the event as well as the total. (Mechanism: `NormaliseRewardRedemptionFlag` already proves that an absent or
`'off'` flag makes the listener take its accrual branch.)

## 2. What was measured (eleven sites and one quote)

An agent measured each site against six questions — whose balance is read, what value is tested against the threshold
versus what the Blade gate compares, whether the dispatch shares the method, whether `$requests_data` is mutated in
between, whether the listener debits the same customer, and whether the charge and the written total agree — and a
second agent tried to refute each claim. The challenger corrected a claim at **five of the eleven** and the corrections
are folded in below. **Line numbers in that measurement drifted by 5–7 lines between passes, so sites are named by
method here.** Nothing in this table was re-read line by line by me except the rows marked *read*; the rest is a
measurement, not an execution.

| Site | Role | Whose balance | Verdict |
|---|---|---|---|
| `PaymentController::processRewardPoints` | the **quote** — what a gateway charges | the signed-in customer (`$customer->id` on the only arm that reaches it) — *read* | needs a different mechanism: there is no `$requests_data` here and no event |
| `PaymentService::getProductStatus` (COD, bank transfer) | order writer, same request as the quote | request-bag `customer_id`, equal to the principal only because `processBillingInfo()` merges over it — *read, and executed* | wire with the quote's base; read the principal's balance |
| `PaymentController::whatsapp` (WhatsApp, Telegram) | order writer, **reachable by a direct POST** | the signed-in customer (`$authenticatedCustomerId ?? …`) — *read* | wire with the Blade's base |
| `PaymentController::getProductStatus` (generic return) | gateway return | the **stored** `customer_id` — guest-chosen when the initiator was a guest | **unsafe today** |
| `AuthorizeNet`, `Easebuzz`, `PaiementPro`, `CinetPay` returns | gateway return | the stored `customer_id` | **unsafe today** |
| `NMIPay` return | gateway return | the stored `customer_id` | needs design: its shipping-ON arm never calls `applyTo` |
| `Khalti` return | gateway return | the stored `customer_id` | needs design: it **applies** the discount and **never dispatches** the event, so nothing is debited (the leak already recorded under D4) |
| `PayU` return | gateway return | the stored `customer_id` | needs design: the route is unrouted, so the block is dead code |

**The base is not one number.** The Blade gate compares `$response->data->final_price`, the cart **Subtotal** — before
coupon, tax and shipping (tax-bearing only because an exclusive-tax store's *line prices* already carry it). The figure
each order writer passes to `applyTo()` differs from that by coupon, tax and shipping, in either direction. The quote's
own base (`$cartlist['final_price']`) is exactly the Blade's. So the gate tests the Subtotal everywhere and `applyTo()`
keeps its own base (D5 is untouched).

## 3. The design: decide once, at the quote, and write the decision down

1. **The quote decides.** `processRewardPoints()` calls `RewardPointDiscount::forOrder(balance, subtotal, settings)`
   — the server-side form of the Blade gate, plus the programme switch and numeric thresholds — for the signed-in
   customer's own balance, and writes the answer **back onto the request** as `'on'` or `'off'`, overriding whatever the
   caller sent. Everything later reads that: the stored checkout state (`$request->except(…)` is what every gateway arm
   saves), the COD / bank writer (`$request->all()` in the same request), and the event.
2. **A merge, not a removal.** `Request::input()` is `body + query`, so removing the key from the body leaves a
   `?club_point_is_active=on` copy to be read straight back. Executed: `test_a_refusal_overrides_a_copy_of_the_flag_in_the_query_string`,
   and mutation Q3 (replace the merge with a body-only removal) turns it red.
3. **A guest cannot redeem.** The quote is only reached on the signed-in arm. A guest checkout adopts whichever store
   customer owns the e-mail typed into the billing form, with no proof of ownership (`handleCustomerRegistration()`),
   and stores that customer's id — so a guest's posted flag used to ride into the stored state **next to a victim's id**,
   for a gateway return to act on. The guest arm now overwrites the flag with `'off'` when it is present. Executed:
   `test_a_guest_cannot_carry_a_redemption_into_the_stored_state`, whose premise assertion proves the adoption.
4. **The COD / bank writer consumes the decision and is otherwise unchanged.** It runs in the quote's own request with the
   quote's own principal, basket and settings, so re-evaluating could only agree; a check that cannot disagree cannot
   fail a test, which this codebase's own rule (`RewardPointDiscount::for()`) calls a trap. **There is deliberately no code
   change here.** The first version read `$orderCustomerId` (the principal) instead of the request bag's `customer_id`, as a
   consistency fix; that is what made the leak in item 6 reachable on COD, and it is reverted, with a note at the site
   saying why not to tidy it.
5. **The WhatsApp / Telegram writer re-checks**, because `POST {slug}/whatsapp` falls back to `$request->all()` when the
   session holds no state, so a direct POST reaches it without any quote. It tests the same Subtotal (`$final_price`)
   and, on refusal, removes the flag from `$requests_data` — the array the event is built from.

6. **The intake refuses a request whose bag disagrees with it about who the customer is** (`refuseACustomerIdTheIntakeCannotTrust()`,
   called after the id is merged in `processOrder()` and in `whatsapp()`). `Request::all()` is
   `array_replace_recursive(input, files)`, so an uploaded file named `customer_id` replaces the merged id for every reader of
   `$request->customer_id`. The post-commit `OrderNote::order_note_data()` calls read it, and `order_notes.customer_id` is a
   foreign key, so the request fails — **after the order row and before the event** — leaving a discounted order that was never
   paid for in points. The trigger is closed rather than one consumer, because the same file breaks anything else that reads
   the bag after the commit. Refusing early is also kinder: before, the shopper got a 500 with stock decremented and the basket
   emptied. **Known limit:** a file part can replace *any* field the intake merges (`slug`, `store_id`, `payment_type` …); only
   `customer_id`, the one that decides whose balance moves, is closed (`GAP-214`).
7. **`isRedeemable()` also requires the accrual arithmetic to be computable.** The listener divides by `order_point_price` and
   multiplies by `order_points` *before* its redemption branch; the settings validator is a bare `required`. A zero divisor is a
   `DivisionByZeroError` and junk is a `TypeError`, thrown after the commit, so a granted discount would again have no debit.
   Same rationale as `club_point_enabled`: a granted discount must imply a matching debit.

## 4. What stage 1 changes

| File | Change |
|---|---|
| `PaymentController::processRewardPoints` | `for()` → `forOrder()`; the decision is merged onto the request (`'on'` / `'off'`); an accepted redemption is canonicalised to `'on'` |
| `PaymentController::getCartData` (guest arm) | the flag is overwritten with `'off'` when the caller sent it **and the module is active** |
| `PaymentController::processOrder`, `::whatsapp` | a request whose bag disagrees with the intake about `customer_id` is refused before anything is written |
| `PaymentController::whatsapp` | the reward arm gates with `forOrder()` against `$final_price` and removes the flag on refusal |
| `PaymentService::getProductStatus` | **comments only** — the code is as it was (a first-version change here caused the leak) |
| `RewardPointDiscount::isRedeemable` | also requires `order_points` numeric and ≥ 0 and `order_point_price` numeric and > 0 |
| `tests/Unit/Loyalty/RewardRedemptionEligibilityTest` | the "zero call sites" census was updated **on purpose**: it now pins exactly two call sites, both in `PaymentController`, and names what is deliberately unwired |

For an **eligible**, signed-in shopper the arithmetic is unchanged: the same `for()` balance, the same `applyTo()` bases.
**For a store whose module is not active, exactly one thing of this change still runs:** the intake guard, a comparison that
refuses a request whose `customer_id` was replaced by an uploaded file. (The guest override is gated on the module; it was
not, and an earlier version of this sentence said no line ran, which was false.)

## 5. Evidence

* **`tests/Feature/Commerce/RewardRedemptionCheckoutGateTest` — 29 tests, 111 assertions** (the review added the two file-part
  refusals, five flag shapes that are not the string `on`, and the module-off guest case). Driven through the real
  `process-order` route with the module enabled **through the production loader** (`EnablesWorkdoModules`), because a
  suite that never loads the module cannot see this code. Two premise tests prove the module is reached (500 points →
  50.00 off a 100.00 basket, balance replaced by the order's accrual, the vendored ledger row written). Before the fix
  five of the nine first tests failed — a 40.00 basket was written at **0.00**, a switched-off programme still discounted.
  Every refusal test asserts the **balance as well as the total**, because the failure that matters is points spent for
  nothing.
* **Mutation: 19 of 19 killed** (`/tmp` harness, files restored byte-for-byte, md5 checked): gate removed at the quote;
  refusal override deleted; merge → body-only removal; accepted flag not canonicalised; value threshold disabled at the
  quote; guest override deleted / made unconditional / un-gated from the module; WhatsApp value threshold disabled;
  WhatsApp refusal not reaching the event; WhatsApp gate removed; intake guard deleted at either entry, made to never refuse,
  made to always refuse; the accrual requirement removed, a zero divisor allowed, a negative multiplier allowed, a zero
  multiplier refused (`RewardRedemptionEligibilityTest`, 75 loyalty unit tests).
* **What the review's finding looked like before the fix** (executed, both writers): `final 50.00 · balance 500 · ledger rows 0 · HTTP 500`.
* **Not killable, and therefore not written:** a second `forOrder()` at the COD writer, propagation of a zero discount
  there, and the `$orderCustomerId` read. All three are unreachable through HTTP once the intake guard holds (the quote has
  already decided in the same request and the bag equals the principal), so none could fail a test — and the third was the bug.
* **Executed versus read.** *Executed:* everything in §3 items 1–7 above, on SQLite. *Read, not executed:* the
  gateway-return rows of §2 and the principal-switch path in §6. The suite runs on SQLite with `CACHE_STORE=array`;
  nothing here touched MySQL.

## 6. What stage 1 does not do — and the decisions it hands to the owner

These are **pre-existing** and stage 1 neither causes nor fixes them. They sit in handlers the owner's D-13 / D-18 ruling
already holds for the `$plan` guard (the generic `getProductStatus`, `PaiementPro`, `CinetPay`, `Easebuzz`, `PayU`), so
changing their reward block is not a "small slice" and was not done.

1. **The principal can change between initiation and return.** `adoptStoredCheckoutState()` pins `customer_id` to the
   stored value, but the return handlers price the discount from `Customer::find($requests_data['customer_id'])` and
   write the order for `auth('customers')->id()` when someone is signed in at return. The vendored listener debits the
   *order's* customer. So a shopper who starts a checkout as customer **A** (stored id **A**, flag stored `'on'`) and
   completes the return signed in as customer **B** receives a discount priced from **A's** balance while **B** is
   debited — **A's points are never spent and can be redeemed again.** Stage 1 closes the *guest* version of this (a
   guest can no longer store a flag beside a victim's id) and narrows the rest to a shopper who really holds an eligible
   balance and a second account; it does not close it. *Read in code; not executed.*
2. **Quote and write disagree at gateway returns.** The charge is fixed at initiation from the quote; the order total is
   decided again at the return from the stored id, the current balance and a different base (`S − coupon + shipping +
   tax`, or `S + tax` with the coupon not subtracted). They can differ in both directions.
3. **Khalti discounts and never debits; NMI's shipping-ON arm never applies the discount; PayU's block is dead code.**
4. **The vendored listener** replaces the whole balance on a redemption whatever the discount was worth, and its
   `final_amount_point` anti-tamper comparison has never executed (precedence), both recorded in the vendor-defects
   document. D4's obligation record is the owner-authorised answer; it is not built.
5. **No lock.** Two concurrent redeeming checkouts can each read the same balance. D4.
6. **The PartialPayments arm** never evaluates the reward branch (`PaymentService::getProductStatus`, the arm taken when that module
   is active for a signed-in customer with `enable_partial_payment` on), yet an eligible shopper's `'on'` still reaches the
   event: the balance is replaced and **no discount is in the total**. Pre-existing, unchanged; ineligible shoppers are now
   refused at the quote. Reported by the review; not fixed because it needs the PartialPayments fixture and a decision about
   what a deposit total means for a reward.
7. **The refusal's `'off'` is stripped by `NormaliseRewardRedemptionFlag`, not by the quote.** The vendored listener treats
   `'off'` as truthy (`$flag ?? …` precedence), so the COD and stored-state paths depend on that first-party listener being
   registered (`AppServiceProvider`). It is covered (`RewardRedemptionFlagTest`), but it is a dependency, and the WhatsApp
   writer, which unsets, does not share it.
8. **Deploy window.** A session stored before this change holds an unsanitised `'on'`. The WhatsApp writer re-checks it; the
   held gateway returns do not.
9. **A refusal is silent.** Where the Blade offers *Apply points* but the server refuses (programme off, or the boundary), the
   shopper may see a discounted total in the browser and be charged in full with no message. The server and the control
   agree on the thresholds but the control does not know about `club_point_enabled` or the accrual settings.
10. **Legacy customers with a NULL `store_id`** pass the storefront customer predicate; the quote has no tenant predicate of its
    own and relies on `cart_list()` refusing a foreign-store customer. Speculative; noted.

**Options for stage 2 (owner's call).** (a) At each return, refuse a redemption unless the signed-in customer *is* the
stored customer — the smallest change; it turns the principal-switch into a refused checkout. (b) Read the balance for
the signed-in customer at return and gate with `forOrder()` there, accepting a charge/total disagreement the shopper can
see. (c) Disable redemption on gateway flows until D4's obligation record exists. Each changes a payment path; (a) is the
only one that does not alter an amount.

## 7. Limits of this document

It does not claim D6 is complete, that any gateway-return path is safe, or that production is or is not exposed
(whether any store has the module enabled with its settings saved is a question about the production database, which
this environment cannot see — D3). It does not close `GAP-203`; it narrows it and opens `GAP-214`.
