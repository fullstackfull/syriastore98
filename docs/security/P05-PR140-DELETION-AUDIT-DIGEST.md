# PR #140 deletion audit — digest, dispositions, and PROPOSED answers for the human checklist

> **UNSIGNED ENGINEERING DRAFT. THIS IS NOT A SECURITY REVIEW.**
>
> Prepared by the engineering assistant from a multi-agent audit and from reading the code. It was
> **not** written or checked by the human reviewer the checklist names, and nothing in it satisfies
> `.github/SECURITY-DIFF-REVIEW.md`. A green CI job reporting on the human-approval gate is
> **not** a signed security review either — that job reports; it does not review.
>
> The acknowledgement line the checklist workflow looks for in the pull-request body is **not**
> written here and is not mine to write. The human reviewer should read §7 as a *starting point*,
> verify each claim against the code, correct what is wrong, and only then sign in their own words.
> Where this document says "I verified", it means the assistant ran the command or read the lines
> named — not that a person did.

| | |
|---|---|
| **Audited** | the diff `4e878b8d..b7a7e70a` of [PR #140](https://github.com/fullstackfull/syriastore98/pull/140): 1,511 changed paths — **1,375 added, 136 modified, 0 deleted, 0 renamed** |
| **Read in depth** | the **24 files** that carry deleted behaviour: **120** behaviour-bearing deletions |
| **Method** | one reader per file listing every deleted line that implemented behaviour, then a *second, adversarial* reader per file whose only job was to find what the first one misclassified |
| **Result** | the second reader overturned **18** of 24 first-pass verdicts; **4** controls were lost (all deliberate); **22** failures became silent or quieter |
| **State at time of writing** | the fixes in §3 are in the working tree and verified locally; whether they are pushed, and what CI says, is in the pull request, not here |

## 0. The one-paragraph version

The first reader's answer to "what did this diff remove?" was mostly *nothing important*, and was
wrong in eighteen places. Four of those were **live defects my own earlier changes caused**: order
writers that would die with an undefined variable *after* a gateway had already taken the shopper's
money; a return calculation that wrote a **higher** amount owed because goods came back; a merchant
filter that could not find the wallet transactions this branch had taught the ledger to write; and a
"top five" chart that silently dropped the two alphabetically-first methods. **Three are fixed in full
and pinned by tests that fail without the fix. The fourth, the undefined `$plan`, is fixed in part, on
purpose:** I landed the guard on five handlers and *reverted it on five others*, because an owner ruling I had not read (D-13 / D-18,
"the `$plan` fix stays suspended") reserves exactly those, and independent review of my fix caught it.
Whether to lift that hold is now a question for the owner, with new information (§3.1, GAP-210). The
rest were either confirmed intentional and pinned, or are **owner decisions I have recorded and
deliberately not made** (§5).

Independent review of *this change* also corrected six of my own fixes before they shipped — among them
a tax-form comment that said a bad row could not be built by clicking (it could), a tie-break that
compared bytewise, and a Benefit comment that claimed more than its condition does. They are listed in
§6, because a reader who wants to know whether the method can be trusted should see where it was wrong.

## 1. How to read the dispositions

| Label | Meaning |
|---|---|
| **FIXED** | changed in code, with a test that fails without the change |
| **INTENDED** | the deleted behaviour was removed on purpose, the replacement was located and read, and a test pins it |
| **CORRECTED COMMENT** | the code was right and a comment claimed something false; the comment is now true |
| **OWNER** | a real finding that changes money, a business rule or what a merchant sees. **Recorded, not changed.** |
| **NOT A DEFECT** | the audit asked a fair question and the answer was no |

## 2. Result at a glance

| # | Finding | Severity | Disposition |
|---|---|---|---|
| AUD-1 | `$plan` undefined at an unguarded read in **10** order-writing handlers (the census finds the shape in 12; two already carried the guard); on a redirect gateway's return it fires *after* the capture | **High** | **PARTLY FIXED** §3.1 — guard landed on **5**, deliberately **held back on 5** by owner ruling D-13/D-18; the question for the owner is GAP-210 |
| AUD-2 | `Order::product_return` rebuilt its base from `return_price`, which the return-**request** flow overwrites; amount owed **rose** | **High** | **FIXED** §3.2 |
| FIN-F | `/finance` type filter had no option for `order_cancelled` / `order_refunded` | Medium | **FIXED** in `b7a7e70a` §3.3 |
| AUD-4a | Benefit sends `"url":null`, a shape no other Tap flow sends and nobody can verify | Medium | **FIXED** §3.4 |
| AUD-4b | `ThemeLanguage`: `{slug}/…` routes ignored the store's language | Low | **FIXED** §3.4 |
| AUD-4c | "Top 5" payment methods dropped `a`,`b` on a tie, and a bytewise label compare would have put capitals first; product chart tie order depended on row order | Low | **FIXED** §3.4 |
| AUD-4d | Partial-jurisdiction tax rows are charged to nobody since P4-F070, **and the tax-method forms could post one by clicks alone** (change the country and the city stays selected) | Low–Medium | **FIXED** (both writers and both forms) §3.4; existing rows **OWNER** §5 |
| AUD-4e | Cart comment said the inclusive-tax change moves the *charge*; true for signed-in carts, false for guest carts | Low | **CORRECTED COMMENT** §3.5 |
| AUD-4f | `routes/web.php` comment claimed the controller's `isAbleTo()` checks were left in place | Low | **CORRECTED COMMENT** §3.5 |
| AUD-4g | Dashboard comment claimed `social_media` condition unchanged; it is not | Low | **CORRECTED COMMENT** §3.5 |
| AUD-1b | with the guard landed, a store whose **owner row is gone** now fails *after* the commit (`$owner->email`, 10 sites) instead of before the first write | Low (needs a deleted owner) | **OWNER** §5.10 · GAP-210(b) |
| AUD-1c | three quote-side handlers read a null plan unguarded (`CartController::get_shipping_data`, `::get_tax_data`, `Api\ApiController::confirm_order`); **pre-existing** | Low–Medium | **OWNER** §5.11 · GAP-210(c) |
| AUD-4h | `Cart::getProductTaxAmount` is called with its arguments shifted by one by `FrequentlyProduct`, `LotteryPrizes` and `Product::productTaxIncludeAmount`; display only, vendored | Low | **UPSTREAM / OWNER** §5.12 · GAP-211 |
| AUD-3 | `syncFromOrder` early return blocks a late-collection wallet credit on a cancelled order | — | **NOT A DEFECT** — it is the owner-approved Option A (P5-FIN-1) §4 |
| — | the four "controls lost" (two Cart tax fallbacks, theme-editor read gate, wallet `firstOrFail`) | — | **INTENDED** §4 |
| — | reward fail-closed removed by D5 (`RewardPointDiscount::for()` returns 0 where the old code threw) | **High if the module is live** | **OPEN — D6**, tracked as GAP-202/203; blocked on D3 §5 |
| — | nine further items | various | **OWNER** §5 |

## 3. Fixed in this change

### 3.1 AUD-1 — `$plan` is undefined after the money has moved: **guard landed on 5 handlers, held back on 5**

**What happened.** P4-F014 routed the order writers through `StorePlan::ownerOf()`, which returns the
store owner with **no `type` filter**. Each site then read `if ($user->type == 'admin') { $plan =
Plan::find($user->plan_id); }` — and the old query, `User::where('type','admin')->first()`, had made
that `if` **impossible to fail**. Deleting the `where()` and keeping the `if` turned a tautology into
the only live type filter, and left `$plan` *assigned only inside it*. A store whose owner row is
gone, is the platform operator, or is a self-demoted merchant — or whose `admin` owner has a null or
deleted `plan_id` — then reached an unguarded `$plan->shipping_method` and threw. On a redirect
gateway's return that is **after the capture**: the shopper has paid, no order exists, the 48-hour
`storefront-return` claim is held, and a retry is refused.

**Counts, corrected.** The census in `ReturnHandlerPlanInitialiserTest` finds the shape **13 times in 12
handlers** (`ordersave` carries it twice). Two handlers already carried the guard when this audit began
(`NMIPayController`, `PaymentController::whatsapp()`), so the defect lived in **ten handlers / eleven
sites**. An earlier draft of this digest said "twelve sites"; that was the handler count, not the count
of the unguarded ones.

**What I did first, and why it was wrong.** I landed the guard on all ten. Independent review of that
change found an **owner ruling I had not read**: `docs/development/phase-2/PHASE-02-DECISION-REGISTER.md`
D-13 / D-18 — *"The `$plan` fix stays suspended"* — because landing it on a handler that decrements
stock **before the payment is proven** "would convert a pre-decrement crash into a working unverified
decrement". The register names PaiementPro, CinetPay and Easebuzz as BLOCKED on that ground, discharges
the condition for Khalti only, and allows the fix later "as its own reviewed commit, with tests proving
no illegitimate decrement path was opened". The same reasoning reaches the generic gateway return
(`PaymentController::getProductStatus` writes the order and moves stock before
`gatewayReturnPaymentStatus()` decides Paid or Pending) and PayU's copy (no route, no caller). **Those
five are reverted to their original text.** The crash is back on them exactly as it was, each with a
twelve-line comment naming the ruling, GAP-210 and the test that pins the hold.

| | Handler | Why |
|---|---|---|
| **Landed** (5) | `PaymentService::getProductStatus` | serves `cod` and `bank_transfer` only: no payment is expected at the return, so there is no evidence for a decrement to precede |
| | `Api\ApiController::place_order`, `::ordersave` (two blocks) | behind `custom.auth` (`routes/api.php:140/141/165`): not an anonymous drain |
| | `AuthorizeNetPaymnetController::getOrderPaymentStatus` | the handler **is** the charge (`authCaptureTransaction`); the plan is read *before* it, so a null plan used to fail before the card was touched |
| | `KhaltiPaymnetController::getOrderPaymentStatus` | verification precedes the stock loop; the register discharges the condition for it |
| **Held back** (5) | `PaymentController::getProductStatus` (the generic redirect-gateway return), `PaiementProPaymentController`, `CinetPayController`, `EasebuzzController`, `PayUPaymentController` (unrouted) | the decrement precedes proof of payment; the original crash is the only thing between an anonymous request and an unverified decrement |

**Why holding back is not free — new information for the ruling (GAP-210a).** The ruling was written
when the crash was true only of "a broken install", because the plan came from the first admin row.
P4-F014 moved the lookup to the store's *own* owner, so the crash is now true of **every store whose
owner is not a plan-holding admin**: the operator's own store, a self-demoted merchant, a deleted
owner, an admin with no plan. A shopper at such a store who pays at a redirect gateway gets a 500
*after* the capture and no order. That changes the cost side of a trade-off the owner reserved; it is
theirs to weigh. The condition for lifting the hold is the register's own: close the
inventory-security dependency, prove no illegitimate decrement path was opened, land it as its own
reviewed commit. §8 query 6 counts the stores this affects.

**What the landed guard does NOT cover (GAP-210b).** `$plan` is null in four ways: owner row gone,
operator-owned, self-demoted, or an admin with a null or deleted `plan_id`. For the first, the handler
now gets *past* the plan and dies a few lines later at `$owner = User::find($store->created_by);
$owner_email = $owner->email;` — **after the commit**, so the order exists and the shopper sees a 500.
Ten sites share that read (line numbers as of this change: `PaymentService.php:3354`, `PaymentController.php:4279` and `:5715`,
`CinetPayController.php:1233`, `EasebuzzController.php:1216`, `KhaltiPaymnetController.php:1262`,
`PaiementProPaymentController.php:1248`, `AuthorizeNetPaymnetController.php:1413`,
`PayUPaymentController.php:993`, `Api\ApiController.php:4271`). It is pre-existing and **not changed
here**; it needs a deleted owner row, which the P0 user-deletion mitigation is meant to prevent;
I did not prove that no path can produce one.

**The quote side was never guarded either (GAP-210c).** `CartController::get_shipping_data` (`:795`),
`::get_tax_data` (`:1049`, `:1074`, `:1121`, `:1130`) and `Api\ApiController::confirm_order`
(`:7612-7615`) read `$plan->shipping_method` with no guard, resolving the owner through
`User::where('id', $store->created_by)` — so a shopper at such a store already could not *price* a
basket. Those lines are **unchanged by PR #140** (checked against the merge-base), and are why "no plan ⇒ shipping off" is the rule of the order
writers and of `processOrder`/`processShipping` but not of every quote endpoint.

**Evidence.** `Tests\Feature\Commerce\ReturnHandlerPlanInitialiserTest` — **9 tests / 51 assertions.**
*Landed:* a census asserts each `Plan::find` assignment is *immediately* preceded by `$plan = null;`
(a looser census let a mutant through) and that no raw `$plan->shipping_method` read remains; two
driven cases (`PaymentService`'s COD writer and `ordersave`). *Held back:* a second census pins the
suspension in **both directions** — landing the guard on one of the five without moving it to the
landed list fails, and so does losing the comment that names the ruling. A *discovery* census counts
the shape across all of `app/`, so a thirteenth writer pasted from one of these fails until it is
classified on purpose. Mutants run against the landed handlers: raw read restored → killed by the
driven case *and* the census; initialiser removed → killed by the census (the driven case cannot see
it, because the only reads of `$plan` that need the initialiser sit inside module-gated branches and no
WorkDo module loads under the suite — **stated, not hidden**).

**A neighbouring test pins the old crash — on purpose.** `PreTransactionStockGiveBackTest::
test_the_plan_throw_happens_before_any_stock_is_taken` drives `/{slug}/get-payment-status` (the generic
gateway return) and asserts that a null plan *throws* before any stock is taken. While the guard was
landed there I rewrote it to assert the opposite; with that handler held back it is **restored to its
original text, byte-for-byte identical to the base**, and is again the test that proves the suspended
handler's behaviour.

**Behaviour change the owner should know about.** On the five landed handlers, a store owned by the
platform operator (`type = 'super admin'`, e.g. the seeded `my-store`) now completes the order with **no
delivery charge** instead of crashing. That matches what the shopper was quoted by `processOrder` and
`processShipping`; it is still a silent absence of a charge for that one kind of store.

### 3.2 AUD-2 — a return made the amount owed go **up**

**What happened.** `Order::product_return()` (P4-F082) rebuilt the original total as
`final_price + return_price`, on the premise that `return_price` holds exactly what has been taken
off. It does not:
`OrderController::order_return_request` runs `$order->return_price = $order->final_price;` on
**approve and on reject**, without reducing `final_price`. After either, the next line return
doubled the base — **205.00 on a 147.50 order returning a 90.00 line, where the code before F082
wrote 57.50.** A second ordering made it worse: return A, then a request overwrites the column with
57.50, then return B wrote **−15.00** (an instruction to hand cash to the customer at the door).
No cited test reached it: every fixture seeded `return_price = 0`.

**Fix.** The base is the current `final_price` plus what *earlier returns* took off, derived from
the **line flags** (only `product_return` ever sets one) and captured *before* this call flags its
own line, using the same formula as the existing summation. No new column, no migration.

**Two further defects in the same method, found by independent review of the fix.**

* *A call that names no line of the order changed money.* `$product_id` and `$variant_id` are request
  input and nothing refused a pair that matches no line. With nothing flagged the summed return value
  is 0, a **flat** coupon then makes it `0 − c`, and the money write subtracts that: `final_price` went
  **up by the coupon amount on every such call** (147.50 → 157.50 → 167.50 → 177.50 on a 10.00 coupon,
  measured). Reachable by the owning customer, an assigned delivery boy, a merchant holding
  `Manage Order` and the operator. It is now refused **before the first write** with
  `__('The product is not part of this order.')` (translated in both languages).
* *The return was not atomic.* Restock, line flags and the money write were three separate writes. The
  whole method is now one transaction, and the inner write transaction nests as a savepoint.

**Evidence.** `SecondReturnTotalTest` 5 → **16 tests (54 assertions)**: three request-flow arithmetic
cases (approved, rejected, request-after-partial), a premise test that pins the controller really
does write that assignment, three helper-agreement cases (no / flat / percentage coupon) that keep the
two copies of the formula equal, and the no-match, atomicity and replay cases. **Without the fix the
cases fail with 205.0 (want 57.5) and −15.0 (want 17.5) — the audit's own numbers.** Mutants **run again for this digest, all killed:** base read from the `return_price` column (the original bug); no prior derivation; helper ignoring the coupon; percentage coupon on the wrong base; the no-match refusal removed; the whole-method transaction removed. (An earlier run also killed a capture that includes the call's own line, seven in all; that one was not re-run today.)

**Not repaired, by design.** An order already driven negative by the pre-F082 compounding bug is
not corrected — writing a plausible number into a money column is what this project refuses. Finding
them needs a production read: §8 query 3.

### 3.3 FIN-F — the merchant could not filter for the transactions this branch writes

`OrderEntitlementReversal` writes `store_wallet_transactions.type` = `order_cancelled` /
`order_refunded`; the `/finance` dropdown offered neither, and the backend match is exact, so the one
plausible choice (*Order Payment Reversed*) returned an empty table, which reads as "there were
none". Both are now offered, translated into Arabic, and
`FinanceLedgerTypeFilterTest` (5 tests / 29 assertions) is keyed on the real
`TRANSACTION_TYPES` map by reflection so a third reversal reason cannot repeat the defect. Nothing
computes; only what a merchant can select changes. **Landed in `b7a7e70a`.**

### 3.4 AUD-4 — the smaller findings

| | Change | Evidence |
|---|---|---|
| **a** Benefit | `post.url` is now `''`, not `null`. `processTap`/`TapPaymnetController` pass PHP `null` into `App\Package\Payment::charge()`, which builds its JSON by **string concatenation**, so the card flows send `"url":""`; the two Benefit sites `json_encode()` an array and sent `"url":null` — unverified, because no credential exists here. A refused Benefit charge is shown to the shopper as a *currency* problem, so an unverifiable shape on the payment rail would fail quietly. The Benefit-plan `catch` now logs the reason through `PaymentFailure` (gateway, exception class, first 300 characters of the message — never the headers) and keeps the currency sentence only for a `ClientException`. **That is *every* 4xx, so a 401 from a wrong platform key still reads as a currency problem;** Tap's real status codes for a rejected currency cannot be verified here (no credential), so it was not narrowed further, and the comment at the catch says so. What changed is the other side: a connection failure, a 5xx and a malformed body no longer claim to be a currency problem. The key is the *platform's* (`benefit_secret_key`), so the merchant who sees the message cannot fix it; the log now tells the operator who can. **Owner decision P1-SEC-55 (where a callback *should* go) is untouched.** | `GatewayCallbackDestinationTest`: new case asserts the shape, its premise (the concatenation) and that both sites send `''`; **fails on the previous code**. `BenefitPlanRailFailureReasonTest` (3 tests / 17 assertions): the catch records through `PaymentFailure`, and the sentence is attached only to a `ClientException`; **5 mutants killed** |
| **b** ThemeLanguage | A store named as the **first path segment** (`{slug}/product-wishlist`) is not `{storeSlug}`, so `getStore()` could not see it and the locale was the platform default. Falls back to `getRouteStoreId()`, which resolves exactly that spelling and no other and **refuses `custom-msg/{slug?}` on purpose**. | `StorefrontLocaleFollowsTheStoreTest` +2: first-segment case (**fails before**), and a negative control that kills a "widen to any `slug`" mutant |
| **c** Top 5 ties | `sortByDesc(fn => [orders,label])` compares *both* elements descending, so ties came out in reverse alphabet (g,f,e,d,c; `a`,`b` dropped). Now orders descending, then label ascending **and case-folded** (`mb_strtolower`): the first replacement compared bytewise, which puts every capital before every lower-case letter where the SQL charts' collation is case-insensitive (found by review; `B, D, F, a, c` against `a, B, c, D, e`). **Not closed:** a non-Latin name still orders by code point in PHP and by collation weight in the database; the comment says so. Product chart: `sortKeys()` first, so ties break by id, not by row order. | **Run on MariaDB 10.11.14** through the real handler: pre-fix `products=[Prod 7..3]`, `methods=[g-pay..c-pay]` (2 of 18 checks fail); post-fix **19/19** (`verify-top-five-reports-on-mysql.php`, sections E and **E2**, mixed case; E2 fails against a bytewise comparator — measured). Plus `TopFiveTieBreakTest` (**6 tests / 10 assertions**) with a controller census — 3 mutants killed |
| **d** Tax jurisdiction | `TaxMethodController::store`/`update` now refuse **a state with no country** and **a city with no state**. `Cart::getProductTaxAmount` takes four shapes (country+state+city, country+state, country, none); two more were writable and match nobody, and P4-F070 removed the sweep that caught them by accident. The form's `0` placeholder still means *unset*. Arabic string uses the sanctioned terms (البلد / المحافظة / المدينة). **The forms did not prevent it, and the first version of this row said they did:** pick a country, a state and a city, then *change the country* — the state select is rebuilt but the city select keeps its old choice, so the form posts a city with no state, by clicks alone. Review disproved the claim; both `create` and `edit` views now clear the city in the country handler. A legacy row of that shape also cannot be renamed or re-rated until its jurisdiction is repaired in the same submit, which the edit form allows. | `TaxMethodJurisdictionCoherenceTest` **21 tests / 73 assertions** (18 server-side, 3 source-level for the script); 8 server-side fail without the change; **a first mutant (read `0` as a selection) survived and the test was strengthened until it died**; the script census was mutated with *both* views changed identically, so the equality test is not what kills it — **4 mutants killed** |

### 3.5 Comments that claimed something false

Three comments in the code PR #140 shipped asserted behaviour the code does not have, and independent
review of *this* change caught four more in my own drafts before they landed (the last four bullets). In
a project that ratchets its own comment pointers, a wrong comment is treated as worse than wrong code.

* **`Cart.php`, the inclusive-tax branch.** The first version of the correction I drafted said the
  P4-F070 change moves *only the recorded tax* and leaves the charge bit-identical. **That is true
  for the guest cart and false for the signed-in cart**, and I caught it before it shipped:
  `Cart::cart_list_cookie` adds `($is_inclusive_tax ? 0 : $tax_price)`, but
  `ApiController::cart_list` adds `$tax_price` to `total_sub_price` with **no inclusive
  exemption**, and the COD/bank-transfer writer records that key as `orders.final_price`. So for a
  signed-in shopper matching no jurisdiction on an inclusive store, the charge *does* fall. The
  comment now says so, per cart. See §5 item 2.
* **`routes/web.php`.** Said the controller's `isAbleTo()` checks were "LEFT IN PLACE". The same PR
  removed all four read-side checks and replaced them with `mayManageThemes()`, which asks the
  same gate as the middleware. Now says that, and says the operator and verified owner are
  deliberately admitted.
* **`DashboardController`.** Said the `social_media` condition was unchanged. It is now appended to
  `updated_fields` only when something was actually written.
* **`TaxMethodController::jurisdictionRefusal` and the test's header** said the create form's cascade
  "does not let a person build one". It does (§3.4 d). Corrected, and the forms fixed.
* **`BenefitPaymentController`'s catch** said the currency sentence "survives only where it can be
  true". A 401 from a wrong key is a 4xx too. Rewritten to say exactly what the condition separates
  and what it does not.
* **`ReportController`'s tie-break** said "all four charts break ties the same way". PHP compares
  bytes, the SQL charts compare by collation. Now case-folded and the residue stated.
* **`Cart.php`'s caller note** named two callers of `getProductTaxAmount`. There are ten call sites in ten
  files; each was read, and the note now says which ones add the figure to a charge, which only display it, and
  which pass the arguments shifted by one (§5.12).

## 4. Verified intended — no change

**The four controls lost.** Each was deleted on purpose; the replacement was located and read.

| Deleted | Replaced by | Pinned by |
|---|---|---|
| Cart, **inclusive** branch: "charge every method if nothing matched" | nothing (the fallback charged a Damascus shopper Aleppo's tax: 7.67 where 0.00 is owed) | `CartTaxJurisdictionTest::test_the_inclusive_branch_does_not_sweep_in_every_method_either` |
| Cart, **exclusive** branch: charge a non-matching method when the never-reset flag was false | nothing (8.00 charged where 5.00 owed, priority-dependent) | `CartTaxJurisdictionTest` :199, :219, :240 (the allow path, so the fix cannot be `return 0`), :285, :306 |
| `ThemeCustomizeController` read gate `isAbleTo('Manage Themes')` ×4 | `mayManageThemes()` → `ThemeEditorGate`, plus the `theme.editor` middleware on all eight routes | `ThemeEditorAuthorizationMatrixTest` (incl. the owner-without-permission case) |
| `OrderFinancialService`: `StoreWallet … lockForUpdate()->firstOrFail()` | `StoreWalletProvisioning::ensure()` — **`where('store_id', …)` and `lockForUpdate()` preserved verbatim**; only the abort-on-missing-row became provisioning | `StoreWalletProvisioningTest` and `verify-store-wallet-provisioning-on-mysql.php`; unique + FK constraints verified in the migration *and* the measured production DDL |

**Widened surfaces, named and bounded.** (Answer to checklist question 3, in full, in §7.)

**AUD-3 — `syncFromOrder`'s early return.** The audit's adversarial pass observed that returning
early for a `cancelled`/`refunded` ledger row deletes the fall-through to `markFinancialAsPaid()`, so
a *late* collection on a cancelled COD order credits nothing. That is not a regression: it is
**Option A of owner decision P5-FIN-1** ("cancelled/refunded ledger rows are not resurrected by a
late `Paid`"), pinned by `CancellationLedgerSemanticsTest`. The one genuine observation — the early
return is **silent** — is item 8 in §5.

## 5. Recorded for the owner — NOT changed

None of these is mine to decide: each changes money, a business rule, or what a merchant sees.

| # | Item | Why it matters | What I recommend | Gap |
|---|---|---|---|---|
| 1 | **Reward fail-closed was removed by D5.** `RewardPointDiscount::for()` returns `0.0` where the old expression *threw* (misconfigured `reward_points`, non-numeric price, **or a null customer — guest, deleted, other-tenant id**). The throw sat before `$order->save()` and restored the stock; now the order is written **undiscounted** while `club_point_is_active == 'on'` still reaches the vendored listener, which debits the customer's **whole** balance. Present at **11 sites**. | A customer loses all points on an order that redeemed nothing. | Wire the D6 gate **and** unset the flag when nothing is redeemable, in one slice, with a negative test. Containment of the listener's `??` precedence bug is already in (`NormaliseRewardRedemptionFlag`). | GAP-202, GAP-203 |
| 2 | **Inclusive tax.** (a) Guest cart: the **recorded** VAT (`orders.tax_price`, `order_tax_details.tax_final_amount`) for a non-matching shopper is now `0.00` where it was the sum of every method's embedded share; the charge is unchanged. (b) Signed-in cart: the charge falls by that amount (§3.5). (c) **Pre-existing and untouched:** the signed-in cart *adds* an already-included tax on top of an inclusive price for a shopper who *does* match. | Anyone reporting VAT from the old recorded figure; a possible double-charge on inclusive stores. | Confirm which stores run `price_type=inclusive` (§8 query 5) before deciding anything. | GAP-205 |
| 3 | **A rejected return request still writes `return_price = final_price`.** | Order page shows the whole total as "Return Price"; `ApiController` (the customer's returned-orders list) selects `return_price > 0`. §3.2 makes the *arithmetic* robust to it; it does not stop the write. | Write `return_price` only on approval (`status == 2`). Changes what a merchant sees, so yours. | GAP-204 |
| 4 | **NMI: an order fully covered by reward points now lands `Paid` at 0.00.** D5 floors the total on both sides of an enforced gateway's amount check, so they match; before D5 the negative total landed `Pending` with a `Log::error`. A wallet credit of 0.00 and a suppressed error log are the consequences. | Probably the correct outcome for a fully-redeemed order — but it is a status change on a gateway path. | Confirm that `Paid` at 0.00 is intended; if not, route a 0.00 total away from the gateway. | GAP-207 |
| 5 | **Theme editor.** (a) `ThemeCustomizeOrder::resolveFor()` falls back to `whereNull('theme_id')`, so one legacy row serves as the ordering for all three themes in the same store. (b) A store whose `theme_id` is `''` cannot save social links through the admin API, which still answers `200 success` (a different status would force a logout in the shipped app). (c) The platform operator and a verified tenant owner are now admitted to the four editor GETs. (d) `themeCustomizeUpdate()` has no handler-level check (the middleware is the only gate) and writes uploads before its first authorization test — safe behind the middleware, **fragile if a route is ever moved out of the group**. | Phase 03 territory; none is exploitable by another tenant as far as I could tell. | Decide (a)–(c); for (d), add the handler-level check as defence in depth. | GAP-208 |
| 6 | **A store already holding the slug `platform`** would be shadowed by the eleven new `/platform/*` routes. The reserved-slug check guards *new* slugs; I found no query or migration that detects an existing one. | A store silently unreachable on the paths the platform owns. | Run §8 query 2. | GAP-206 |
| 7 | **Existing partial-jurisdiction tax rows** (state without country, city without state). §3.4 d closes the *write* path only. | Charged to nobody. | Run §8 query 1; repair is a data decision. | GAP-206 |
| 8 | `OrderFinancialService::syncFromOrder`'s Option-A early return is **silent**. | A late collection on a cancelled COD order leaves no trace. | Add a `Log::warning` there. Not done: it would add a row to the observability inventory and is a behaviour-visible choice. | GAP-209 |
| 9 | **Eight gateway return URLs** now carry `return_type=cancel&payment_processor=<x>`, built by passing a numeric-keyed leftover to `route()` whose leading `&` Laravel trims. It works (nothing narrowed; the shopper can only cancel *their own* payment by supplying it) but the spelling is fragile. | A future framework change to `getRouteQueryString()` would break the cancel return silently. | Build the query string explicitly. Low priority. | — |
| 10 | **A store whose owner row is gone now fails after the commit instead of before the write** (§3.1, GAP-210b), at the ten `$owner->email` reads. | An order exists and the shopper sees a 500. Needs a deleted owner. | Guard `$owner` at the ten sites, or refuse checkout up front for an owner-less store. A behaviour choice, so yours. | GAP-210 |
| 11 | **The hold on the `$plan` fix (D-13/D-18) now costs more than when it was written** (§3.1, GAP-210a), and three quote handlers never had the guard (GAP-210c). | A shopper at a store whose owner is not a plan-holding admin cannot pay at a redirect gateway: a 500 after the capture and no order. | Decide whether to lift the hold on the generic gateway return, under the register's own condition (prove no illegitimate decrement path). §8 query 6 counts the stores. | GAP-210 |
| 12 | **Vendored callers pass `Cart::getProductTaxAmount` its arguments shifted by one:** `FrequentlyProduct` (×2), the `LotteryPrizes` detail view (which also passes a sub-total of 0, so it can only show `0.00`) and the unreferenced `Product::productTaxIncludeAmount`. Display only. | The upsell widgets show a tax figure computed against the wrong address ids. | Report upstream; do not edit `packages/workdo/` (D7). | GAP-211 |

## 6. Where the first reader was wrong

The 18 refuted verdicts, by what the first reader missed. This is the section to read if you wonder
whether the *method* can be trusted: the first pass is **not** sufficient, and the second is why
this document exists.

| File | What the first pass missed | Result |
|---|---|---|
| `PaymentController`, `PaymentService`, `Khalti`, `PayU`, `PaiementPro`, `Easebuzz`, `CinetPay`, `AuthorizeNet`, `NMIPay` (9) | The removed `where('type','admin')` was an **invariant**; keeping the `if` made it falsifiable and left `$plan` unassigned | **AUD-1** |
| `Order` | Replacement base read a column that the request flow overwrites | **AUD-2** |
| `OrderFinancialService` | A *new early `return`* deletes the old fall-through; `grep '^-'` on a 38-insertion/0-deletion commit cannot see it | **AUD-3** (not a defect) |
| `Cart` | Two deletions mis-classified as narrowing; the inclusive one changes a VAT *record* on one cart and the *charge* on the other | **AUD-4 d/e**, §5.2 |
| `ReportController` | The "same tie-break" comment named a tie-break the code did not implement; a stale docblock | **AUD-4 c** |
| `ThemeLanguage` | Replacement never fires on 50 of 161 routes, nor on `{slug}`-spelled storefront routes | **AUD-4 b** |
| `BenefitPaymentController` | The comment's claim that `null` is "the shape `processTap` already ships" is false on the wire | **AUD-4 a** |
| `DashboardController` | An unconditional write and an unconditional `updated_fields` append became conditional | comment **corrected**, §5.5 b |
| `ThemeCustomizeController` | An advertised audit log is unreachable (the gate already ran); `themeCustomizeUpdate()` kept no handler check; a removed `where()` widened a fallback | §5.5 |
| `routes/web.php` | The comment swore the controller checks were kept | comment **corrected** |

**And where my own fixes were wrong.** Independent review of this change — not the first reader — found:

| My change | What was wrong | Result |
|---|---|---|
| AUD-1 landed on all ten handlers | an **owner ruling** (D-13/D-18) reserves five of them; I had not read the register | five reverted, hold pinned in both directions, GAP-210 |
| AUD-2 base derived from line flags | a call naming no line **raised** `final_price` by the coupon on every call; the return was three writes, not one | refusal before the first write; one transaction |
| tax-jurisdiction comment | said the form's cascade makes the bad row unbuildable; a change of country leaves the city selected | forms fixed, comment corrected, script census added |
| top-5 tie-break | compared bytewise (capitals first) where the SQL charts are case-insensitive | case-folded; MySQL section E2 |
| Benefit catch comment | "survives only where it can be true" — a 401 is a 4xx | comment rewritten; census added |
| Cart caller note | named two of ten call sites | all read; shifted-argument callers recorded (GAP-211) |

## 7. PROPOSED answers to the eight checklist questions

*A starting point for the human reviewer. Verify before signing; correct what is wrong.*

**1. What does this diff REMOVE or NARROW?** 120 behaviour-bearing deleted lines in 24 files.
Grouped: (i) the global `User::where('type','admin')->first()` plan/language lookup, in the 12 order-writing
handlers and `ThemeLanguage`, replaced by the store's own owner/language — *a narrowing of whose
settings apply, and the source of AUD-1*; (ii) two tax fallbacks in `Cart` (INTENDED, §4); (iii) the
inline reward-discount arithmetic in 11 sites, replaced by `RewardPointDiscount` — *this one removed
a fail-closed behaviour, §5.1*; (iv) the unconditional write in two Dashboard handlers; (v) the
`firstOrFail()` wallet abort; (vi) the four read-side `isAbleTo('Manage Themes')` checks; (vii) a
public `webhook.site` callback URL (deliberate: P4-F046). The first reader missed the *consequence*
of (i) and (iii); both are above.

**2. Did any file already exist and do something else?** Mechanically: of 1,511 paths, **0 were
deleted or renamed**, and **no file with ≥ 40 prior lines lost ≥ 50 % of its content** (command in
§8). No path was overwritten rather than extended. Semantic replacement *inside* a file is
question 1.

**3. What existing control assumed the surface you widened was narrow?** The widenings, each with
the control checked: *theme editor reads* — operator and verified owner now admitted
(`ThemeEditorGate`; `resolveStore()` refuses an operator who named no store); *`UserPolicy::
tenantRootIsAMerchant` protected → public* — inert, no ability string of that shape exists, the
`int|string` signature would `TypeError` on a `User`, `isOperator` was already public; *order
creation can now INSERT into `store_wallets`* — unique `store_id` + FK present in the migration and
the measured production DDL, balances created at 0, an existing row untouched; *eleven new public
`/platform/*` routes* — `setlocate` only, `Store::RESERVED_SLUGS` guards new slugs, **an existing
store with that slug is unguarded (§5.6)**; *`payment_status` validator `in:Paid,Unpaid` → `max:50`*
(P4-F145, deliberate) — the column cannot take a non-`{Paid,Unpaid}` value by this route; *report
`whereDate` upper bounds* — admit the final day, all behind `store_id`.

**4. Is any audit, logging or compliance emission removed, skipped, or made conditional?** None
deleted. **Three qualifications:** (i) one emission was **renamed** (`order_payment_reversed` →
`order_cancelled`/`order_refunded`) and lost its only filter — **fixed, §3.3**; (ii) the new
`'Theme editor read refused'` log is **unreachable** (the gate already ran), so the four GET refusals
still log nothing, as before; (iii) on NMI, `Log::error('Order NOT marked Paid: verified amount does
not match')` **no longer fires** for an over-discounted order, because that order now matches and
lands `Paid` (§5.4).

**5. Does any failure become silent?** Yes, 22 candidates; the material ones: AUD-1 (was a loud 500
*after* capture; **on the five landed handlers** it now completes with shipping off — a loud-and-wrong
failure became a quiet-and-correct one, with a silent absence of a delivery charge for operator-owned
stores — and **on the five held-back handlers it is unchanged, still a loud 500 after capture, by owner
ruling D-13/D-18**, §3.1); the reward
fail-closed removal (§5.1 — **this is the serious one**); the Dashboard social-links write returning
`200 success` for a refused write (§5.5 b); the `syncFromOrder` early return (§5.8); the Benefit-plan
`catch` that labelled every failure a currency problem and recorded none (**fixed, §3.4 a**).

**6. Is method-resolution order involved?** Not in the Ruby sense this question was written for. The
PHP analogue is **event-listener order**, and it is involved: `NormaliseRewardRedemptionFlag` must
run *before* the vendored `AddRewardClubPointLis`, which holds only because module providers register
from `app->booted()` after the host's own boot. That ordering is stated in `AppServiceProvider` and
pinned by `RewardRedemptionFlagTest::test_a_listener_registered_the_way_module_providers_arrive_sees_the_normalised_array`
and `::test_the_real_dispatcher_normalises_the_event`.

**7. Was the change verified by removing it?** Yes, for every fix in §3. Failing-before / passing-
after for each; mutants for AUD-2 (6 killed), the locale fix (widening mutant killed), the tie-break
census (3 killed) and its MySQL section E2 (killed against a bytewise comparator), the tax refusal
(4 killed; **one survived at first and the test was strengthened**) and its form-script census (4
killed), the Benefit catch (5 killed), and the AUD-1 census (landed and held-back halves). The tie-break was also run on a real MariaDB through the real handler. One
honest gap: the AUD-1 initialiser cannot be removed *observably* in a driven test, because the reads
that need it sit inside module-gated branches and no WorkDo module loads under the suite; the census
is what pins it. The behavioural cases drive two of the five landed handlers; the other three are held by
the census alone.

**8. Does the merge-base regression gate report zero new failures?** *Read this from the pull
request's checks for the head you are reviewing; I will not assert it here.* Locally, the full
Feature suite against the known-outcome manifest was an exact match (2 declared failures, 1 declared
skip, 0 undeclared) before this change; the post-change run is recorded in the pull request.

## 8. Read-only queries for the owner (NOTHING HERE MODIFIES DATA)

I have **not** run any of these — there is no production access from the engineering environment,
and that is as it should be. Run them yourself, or authorise a specific read, and I will fold the
answers into the gap register.

```sql
-- 1. Tax methods that match no shopper (charged to nobody since P4-F070)   [GAP-206]
SELECT id, store_id, name, tax_rate, country_id, state_id, city_id
FROM   tax_methods
WHERE  (state_id IS NOT NULL AND country_id IS NULL)
   OR  (city_id  IS NOT NULL AND state_id   IS NULL);

-- 2. A store already holding the slug the platform website now owns        [GAP-206]
SELECT id, name, slug, created_by FROM stores WHERE slug = 'platform';

-- 3. Orders already driven negative by the pre-F082 second-return bug       [AUD-2]
SELECT id, store_id, product_order_id, final_price, return_price, return_status
FROM   orders
WHERE  final_price < 0;

-- 4. Orders showing a whole-order return that was REJECTED                  [GAP-204]
SELECT COUNT(*) AS rejected_but_marked_returned
FROM   orders
WHERE  return_status = 3 AND return_price > 0 AND return_price = final_price;

-- 5. Which stores run tax-inclusive pricing                                 [GAP-205]
SELECT store_id, name, value
FROM   tax_options
WHERE  name IN ('price_type', 'shop_price')
ORDER  BY store_id, name;

-- 6. Stores that reach the AUD-1 crash today: owner row missing, owner not an admin, or an admin
--    whose plan is missing. These are the stores a redirect-gateway shopper cannot pay at while the
--    D-13/D-18 hold stands.                                                [GAP-210]
SELECT s.id AS store_id, s.slug, s.created_by,
       u.id AS owner_row, u.type AS owner_type, u.plan_id, p.id AS plan_row
FROM   stores s
LEFT   JOIN users u ON u.id = s.created_by
LEFT   JOIN plans p ON p.id = u.plan_id
WHERE  u.id IS NULL
   OR  u.type <> 'admin'
   OR  p.id IS NULL;
```

And the commands behind the answer to checklist question 2, which read nothing but git history:

```bash
# added / modified / deleted / renamed
git diff --name-status 4e878b8d..b7a7e70a | awk '{print $1}' | sort | uniq -c

# any file with >= 40 prior lines that lost >= 50 % of them (prints nothing when none did)
git diff --numstat 4e878b8d..b7a7e70a | awk '$1!="-"{print $1" "$2" "$3}' | while read a d f; do
  old=$(git show 4e878b8d:"$f" 2>/dev/null | wc -l)
  [ "$old" -ge 40 ] && [ "$d" -gt 0 ] && [ $((d*2)) -ge "$old" ] && echo "added=$a deleted=$d old=$old $f"
done
```

## 9. What this audit did not do

* It read the **diff**, not the base. A defect that *both* revisions share is outside it.
* It audited **deletions and narrowings**. It did not re-review the 1,375 added files; the earlier
  independent reviews and the suite are the evidence there.
* It did not run anything against production. Every "verified" is a code read or a local/MariaDB run.
* The 24 per-file verdicts and the 18 overturned ones are the assistant's output. **A second model
  disagreeing with a first is evidence, not proof**, and I have said where I checked a claim myself
  (the AUD-1 guard *and then the owner ruling that limits it*, the base arithmetic with the numbers 205.00 and −15.00, the wire shape, the
  `?:` semantics, the inclusive-cart behaviour) and where I did not.
* **It is not a security review, and it does not close P1-SEC-40 / issue #120**, which is a
  different matter and remains open.
