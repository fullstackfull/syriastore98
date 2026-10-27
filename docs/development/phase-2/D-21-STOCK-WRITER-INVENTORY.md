# D-21 — the stock writers outside D-18, inventoried before anything is changed

The owner's instruction was explicit about the order of work:

> ابدأ بجرد كل مسار وشروط نجاحه وفشله وإعادة الإرسال، ثم نفذ شرائح مستقلة.

This is that inventory. It answers the five questions the owner set, for every writer, **before**
any of them is touched.

---

## 0. The scope is four writers, not five — measured

The register names five. A census with **comments stripped** (`token_get_all`, because this
project has had a comment satisfy a census before) returns:

| writer | raw read-modify-write sites | through the service |
|---|---|---|
| `OrderController::store` | **0** | **2** |
| `AuthorizeNetPaymnetController::getOrderPaymentStatus` | 3 | 0 |
| `PayUPaymentController::getProductStatus` | 3 | 0 |
| `Api/ApiController::place_order` | 3 | 0 |
| `Api/ApiController::ordersave` | 3 | 0 |
| `Api/DashboardController::createOrderViaApi` | 2 | 0 |

**`OrderController::store` is already done** and was already correct: its decrements at `:203`
and `:233` go through `$this->stockService()`, inside the `DB::beginTransaction()` opened at
`:96` with `DB::rollBack()` in the catch at `:410`. A naive grep counts 2 "raw" lines there, and
both are **comments quoting the form that was replaced** (`:182`, `:229`). It is removed from the
work list, and the reason is recorded so nobody re-does it.

So: **four writers, eleven sites.**

---

## 1. The five questions, answered per writer

### Common to all four: the decrement precedes the order row, and nothing wraps it

In every one of the four, the stock loop runs **before** `new Order()`, and in three of them
there is **no transaction at all**. So a failure between the decrement and the order write
consumes stock and leaves nothing behind.

| writer | decrement at | `new Order()` at | transaction |
|---|---|---|---|
| AuthorizeNet | 796, 824, 889 | 993 | **none** |
| PayU | 492, 520, 585 | 685 | **none** |
| `place_order` | 3643, 3662, 3713 | 3785 | **none** |
| `ordersave` | 8717, 8736, 8787 | 8951 | **none** |
| `createOrderViaApi` | 5834, 5860 | ~5899 | **yes**, `beginTransaction` + 6 `rollBack` exits |

### Q1 — what proves the payment succeeded, or that a decrement is earned?

| writer | the answer | assessment |
|---|---|---|
| **AuthorizeNet** | `recordVerification()` at `:1145` and `gatewayReturnPaymentStatus()` at `:1159` | **Verification exists, but it happens ~350 lines AFTER the stock is taken.** The decrement is not conditioned on it in any way. |
| **PayU** | `gatewayReturnPaymentStatus()` at `:784`, and **zero** `recordVerification()` call sites anywhere in the file | **Nothing verifies the payment.** The status is derived from the return's own fields. |
| **`place_order`** | nothing — the order is written `payment_status = 'Unpaid'` (`:3786`) | **Not a defect by itself.** This is the unpaid/COD shape: the order is honestly recorded as unpaid. Whether an unpaid order should consume stock is a *business rule*, not a bug, and it is not this slice's to change. |
| **`ordersave`** | `Utility::payment_data($request->payment_type)` | The payment type is **caller-supplied**. |
| **`createOrderViaApi`** | `payment_status` is a **validated request field**: `'required|in:Paid,Unpaid'` (`:5693`) | **There is no gateway on this path at all.** It is the merchant's own admin app recording a sale they took. "Proof" is the merchant's assertion, which is legitimate for a manual order and must not be turned into a refusal. |

### Q2 — when does the decrement happen relative to the money being taken?

- **AuthorizeNet and PayU**: after the gateway has returned, so the money may already be captured —
  and **before** this server has established that it was. Both are "post-capture, pre-proof".
- **`place_order` / `ordersave`**: no capture on this path; the order is unpaid or the payment type
  is declared.
- **`createOrderViaApi`**: no capture; the merchant declares the status.

### Q3 — shortfall, concurrency, resend

| | shortfall | concurrency | resend / replay |
|---|---|---|---|
| AuthorizeNet | **none.** Subtracts unconditionally, can go negative | **lost update.** Read-modify-write | `GuardsGatewayReturn` claim applies (shared concern) |
| PayU | **none** | **lost update** | same |
| `place_order` | **none** | **lost update** | no claim seen on this path |
| `ordersave` | **none** | **lost update** | no claim seen on this path |
| `createOrderViaApi` | **refuses**: `if ($variant->stock < $qty) { rollBack; return 400; }` (`:5826`) | **check-then-act** — the read and the write are separate statements, so two concurrent orders can both pass the test and both decrement | the transaction rolls back on failure |

### Q4 — what happens if the request fails after the stock is taken, or after the money is?

- **AuthorizeNet, PayU, `place_order`, `ordersave`**: the stock is **gone**. No transaction, no
  give-back, and on the two gateway paths the per-line cart rows have already been deleted. This
  is the same shape D-18 bounded on its four handlers, and it is the substance of this slice.
- **`createOrderViaApi`**: the transaction rolls the decrement back. **This one is already safe in
  that respect** — its defect is atomicity, not reversibility.

### Q5 — is the response the contract its client consumes?

This is the owner's sharpest condition and it decides what may be touched.

- **`createOrderViaApi`** is `POST api/admin/createOrderViaApi` behind `custom.auth`, i.e. **the
  shipped Flutter admin app's own order writer**. It currently answers a shortfall with
  **HTTP 400** and `{'status' => 'error', 'message' => "Insufficient stock for variant: …"}`.
  **That shape must not change.** In particular a refusal must never become a **401**: the shipped
  client maps any 401 to a forced logout that clears stored preferences, so a business refusal
  would sign the merchant out.
- **`place_order` / `ordersave`** are the storefront API. CLAUDE.md records that no client in
  either repository calls the storefront API, but that is a statement about *this* repository's
  clients, not about the world, so their envelopes are treated as contracts too.
- **AuthorizeNet / PayU** return to a browser, not to an API client.

---

## 2. What this inventory decides about the work

1. **`OrderController::store` is out** — already correct.
2. **AuthorizeNet and PayU take the D-18 treatment unchanged**: route the three sites each through
   `AppliesCartStockChanges` with `$rejectOnShortfall = false`, and give back on the in-loop
   exits. They are post-capture, so a refusal is not available — exactly the case the trait's
   `false` branch exists for.
3. **`place_order` and `ordersave` take the same treatment**, with one difference: they are not
   post-capture, so a shortfall *could* legitimately refuse. **It will not be made to**, because
   that changes what a storefront client receives, and the owner reserves that.
4. **`createOrderViaApi` is different and must be treated differently.** It already refuses and
   already rolls back; what it lacks is atomicity. The change there is to make the availability
   test and the decrement **one statement** — which alters nothing the client can observe as long
   as the refusal keeps its status code and message shape. That is the compatibility claim to
   test, and it is the one thing on this path that could break the shipped app.

## 3. What stays BLOCKED, and is not allowed to stop anything

- Whether an **unpaid** order should consume stock (`place_order`) is a business rule; recorded,
  not changed.
- Whether **AuthorizeNet/PayU** should decrement before verification is the same question D-13
  holds open, and it needs E13's durable evidence. These slices **bound** the damage; they do not
  close it, and nothing here should be read as closing it.

---

## 4. What the implementation actually changed — written after the fact, and after a review

The sections above were written before the change. This one records what the change turned out to
be, including the three things the first pass got wrong. It is kept separate so the prediction and
the outcome can be read against each other.

### 4.1 A defect in the fix itself: two exits that took stock and gave nothing back

`AuthorizeNetPaymnetController`'s product loop has **five** error exits, not three. The first pass
found them with a detector keyed on `return $this->error(` and therefore never saw the two that
read `return redirect()->back()->with('error', ...)` — the foreign-product refusal (now `:778`)
and the missing-variant refusal (now `:818`). Both are **post-capture**: the Authorize.Net capture
has already happened, so each one left the shopper charged, no order row written, and the stock of
every basket line already processed consumed with nothing to show for it.

Found by an independent security review of the change, not by CI, and the reason CI could not see
it is worth keeping: the ratchet asserted
`assertGreaterThanOrEqual($loopCount, substr_count(..., 'restoreStockDecrements'))` — a floor of
one restore per loop. Three of the five exits did restore, so the floor was satisfied.

Fixed, and the ratchet replaced with the property that actually matters:
`GatewayStockDecrementRoutingTest::test_each_authorised_handler_gives_back_what_it_took_on_every_in_loop_exit`
now locates each product loop by brace depth and asserts a restore stands immediately before
**every** `return` inside it. Comments are stripped with line numbering preserved, because
`AuthorizeNetPaymnetController:727` contains the literal text `return $this->error(...)` inside a
block comment.

It carries **no list of exit spellings**, and that is deliberate: the first version of the
replacement did carry one — `$this->error(`, `redirect()`, `response()->json(`, `abort()` — and it
immediately reported `EasebuzzController` as having *no exits at all*, because that handler returns
`Utility::error(...)`. A detector defeated by a spelling it has not met is the exact failure being
guarded against. A census of all seven handlers found **30 returns inside a stock-taking loop and
all 30 are error exits**, so the assertion simply applies to every `return`; if a legitimate value
return is ever added inside one of these loops the test will fail and the exception belongs in the
test, named, rather than in a pattern that quietly stops matching.

Verified by reverting: with the two restores removed the test fails naming line 776; with them
present, 4 tests / 252 assertions pass.

### 4.2 PayU and `Api/ApiController` have no quantity pre-flight, so the conversion is NOT inert there

Five of the seven handlers call `basketHasUnusableQuantity()` before their stock loop — Khalti,
CinetPay, Easebuzz, AuthorizeNet and NMIPay. **`PayUPaymentController` and `Api/ApiController` do
not.** On those two a negative `$qtyy` reached the raw decrement, and
`$remain_stock = $Product->product_stock - $qtyy` with a negative quantity **adds** stock.

`ProductStockService::normaliseQuantity()` (`:272-281`) returns `null` for anything non-numeric or
`<= 0`, and both decrement entry points return `SKIPPED` with no write when it does. So on those
two handlers the conversion **closes a stock-inflation path**. That is a real behaviour change in
the safe direction, recorded here rather than left under a "byte-identical" claim that would have
been false for two of the seven.

### 4.3 PayU's restore at `:605` is unreachable — pre-existing, and it belongs to D-22/F121

`PayUPaymentController:507` evaluates `explode(',', $ProductStock->variation_option)` **before**
`:509`'s `if (!empty($ProductStock))`. A cart line naming a variant that resolves to no row
therefore raises `Attempt to read property on null` — HTTP 500 — before the `else` branch at
`:604-607` can run, so the restore added there is defensive only.

This is the same shape that was reordered in `AuthorizeNetPaymnetController` (see its `:790-813`
comment) and it is **post-capture**, so it strands a paid shopper with no order. It is *not* fixed
here, because it is not a stock-writer defect: it is F121 — a deleted product or variant crashing a
payment return — which the owner gave its own slice as **D-22**. Reordering it under D-21 would
widen this slice and would land the change without the before-decrement / after-capture tests D-22
requires. Listed so the D-22 slice starts from a known site rather than rediscovering it.

### 4.4 The Flutter writer's conversion is invisible to behaviour, and that was measured

`Api/DashboardController::createOrderViaApi` keeps its refusal byte for byte: same `status` and
`message` keys, same wording, same HTTP **400**, same `rollBack()`. Reverting the conversion to its
original check-then-act and re-running
`tests/Feature/Security/AdminApiStockRefusalContractTest.php` leaves **all four behavioural tests
passing** — the shortfall still answers 400, the boundary case still succeeds, the happy path still
takes the stock.

That is the point of the owner's Flutter condition, and it is also why behaviour is the wrong place
to look for evidence that the conversion happened. A fifth test,
`test_create_order_via_api_delegates_stock_to_the_service_and_keeps_no_read_modify_write`, pins it
in source instead: both branches must call the service, both must pass `$requireAvailability = true`
(without it the service oversells instead of refusing, turning the pinned 400 into a 200 with an
order written against stock that is not there), and the four spellings of the read-modify-write must
not return. Comments are stripped first. Reverting the conversion fails that test and only that
test — measured, not asserted.

**What none of this proves.** Converting a writer to `ProductStockService` is not, on its own,
evidence that the path is protected — the owner's instruction is explicit on the point. The
lost-update property these conversions buy cannot be driven in this suite at all: SQLite
`:memory:` with `CACHE_STORE=array` makes `Cache::lock` process-local, so no Feature test can stage
two overlapping requests. It is proven against the service itself by `OrderStockConcurrencyTest`;
what the per-writer tests prove is only the thing that would silently undo it — that each writer
delegates rather than doing its own arithmetic. The unverified decrement on the gateway paths
stays open and stays D-13/E13's.
