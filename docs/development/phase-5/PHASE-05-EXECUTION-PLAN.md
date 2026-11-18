# Phase 05 — Marketing & Analytics: executable plan

**Grounded in the actual repository and the existing WorkDo modules, not in a feature wish-list.**
Owner instruction: *"Create an executable Phase 05 plan grounded in the actual repository and
existing WorkDo modules"*, and *"Do not create a second analytics or campaign engine if equivalent
functionality already exists."*

Read first: `PHASE-05-METRIC-DEFINITIONS.md` (what each number means) and
`PHASE-05-REPORTING-SURFACE-INVENTORY.md` (the 70 surfaces, 53 ledger-dependent).

---

## 0. The dependency gate, and its current state

Phase 05 could not start until the ledger meant something. That work is on this branch:

| finding | state |
|---|---|
| **P4-F145** COD/Pending delivery-status 422 | **DONE** — echo is not a transition; 15 cases; both limbs falsified |
| **P4-F032** wallet-less store loses every credit | **DONE** — single provisioning service, locking-read recovery, verified on real MariaDB (13 checks) |
| **P4-F124** cancellation ledger semantics | **ENGINE DONE** — explicit, idempotent, reason-carrying reversal; settlement no longer rewritten. Three non-engine doors outstanding |
| **P4-F127** partial refunds | **DETERMINED BLOCKED** and documented, per instruction. Full verified refunds outstanding |
| **P4-F126** COD asserted Paid at creation | **DONE** — both manual doors refuse `cod` + `Paid`; counter sales preserved via the existing `POS` type. 10 cases, both guards falsified |
| **P4-F127** full verified refund | outstanding |
| **P4-F128** reconciliation + finance-screen audit | outstanding |
| **P4-F046** webhook.site egress + Benefit capture | outstanding |

**No Phase 05 metric may be built on an outstanding row above.** §9 of this plan says which
surfaces each one blocks.

**VOCABULARY, used consistently from here on.** A row is *approved policy* when the owner has
decided it; *implemented in development* when code exists; *verified by local tests* when a suite
measured it on a clean tree; *verified by CI* when GitHub's own check-runs API says so on that head;
*externally blocked* when a provider or access this session does not have is what stops it;
*awaiting human approval* when only a person can clear it; and *released to production* — which
**nothing in this branch is**. An approved design is never described as implemented until its
behaviour has been measured, and engineering completion is never described as production
readiness.

---

## 1. What already exists, and must not be rebuilt

Measured from the repository. The single biggest risk to this phase is building a second engine
beside one of these.

| capability | where it already lives | verdict |
|---|---|---|
| order/sales reporting | `app/Http/Controllers/ReportController.php`, 3660 lines | **REUSE THE SURFACE, REPLACE THE PREDICATES.** It has zero `payment_status` predicates, so every figure counts orders irrespective of settlement. The screens, filters, exports and permissions are sound; the SQL is not |
| order data tables & filters | `app/DataTables/OrderDataTable.php` + `order/partials/filters.blade.php` | reuse; the filter list is already derived from constants rather than hand-typed |
| coupons & promotions | first-party coupon tables + `order_coupon_details`, `user_coupons` | reuse — but see §9: redemption is **not authoritative** |
| wallet / finance screens | `app/Http/Controllers/StoreFinanceController.php` | reuse; needs the new statuses admitted (§4) |
| campaigns | vendored `packages/workdo/` modules | **inventory before building.** Vendored code must not be edited; a first-party campaign surface may wrap it but must not fork it |
| notifications | `StoreNotificationService`, `FirebaseNotificationService` | reuse |
| design system | `public/css/store-syria-ds.css`, `docs/design-system/` | authoritative for all new UI |
| Flutter admin | `StoreSyria_app98` | parity surface; any API change is a contract change |

---

## 2. Slices, in dependency order

Each slice is independently committable, independently testable, and names the gate it needs.

### P5-1 — Finance ledger surfacing (no new metrics)
Admit `cancelled` and `refunded` to the finance screens and validators that today accept only
`in:paid,unpaid`, so a merchant can see and act on a reversed order. **Gate: P4-F124, P4-F127.**
Without it a reversed row is invisible to the one screen that owns it.

### P5-2 — The metric layer — **ENGINEERING COMPLETE (2026-11-17)**
One query object per metric in §1–§10 of `PHASE-05-METRIC-DEFINITIONS.md`, each with its tenant
predicate at the call site, its currency handling, and its caveat flag. **No controller computes
money inline.** Every metric gets the verification test its definition names.
**Gate: P4-F124, P4-F128.** Both closed before the slice began.

Built in `app/Support/Metrics/`. Evidence, the three choices the definitions left open, the five
`ANA-*` decisions it answers to and the 29-of-29 mutation battery are in
`PHASE-05-METRIC-LAYER.md`. **Not wired to any surface** — no merchant-visible figure has changed.

### P5-3 — ReportController predicate replacement
Point the existing report surfaces at the P5-2 metric layer, one surface at a time, with a
before/after figure recorded for each. **This changes numbers merchants currently see** — every
surface needs its delta stated, not just its code changed. **Gate: P5-2.**

### P5-4 — Merchant analytics dashboard
Sales, orders, conversion and customer insight, bilingual (AR RTL / EN LTR), contrast-checked in
light and dark, permission-gated and tenant-isolated. Built on P5-2 only.

### P5-5 — Campaign & promotion analytics
**Gate: P4-F134 / P4-F135.** Until those are resolved, coupon redemption is reportable only with
its two-directional error displayed. Campaign *management* is not blocked; campaign *outcome
measurement* is.

### P5-6 — Retention and repeat purchase — **METRIC COMPLETE (2026-11-17), no surface**
Derived from `orders` + `customers`, no new events invented. **Gate: P5-2** (a repeat-purchase rate
computed over unsettled orders is not a retention figure).

`App\Support\Metrics\CustomerRetention` + `RetentionFigures`. The gate note is honoured by
**stating the basis** rather than picking one: `settled_only` (default) reads the ledger and is
authoritative but blind to orders with no financial row — reported by **count**, not by a hardcoded
cut-over date — while `all_orders` covers all history and overstates retention by however much of
the book was never paid. `notes()['basis']` always says which.

**No surface renders it yet.** A retention tile needs the §3 decision in
`PHASE-05-FOUR-FIGURE-LABELLING-PROPOSAL.md`, because the settled basis has the same ledger-history
limit as payments received and profit.

### P5-7 — Platform/operator reporting
The super-admin view of the same metrics across tenants. **Operator-first authorization**
(`MerchantPermissions::allowsAny()`), never bare `isAbleTo()`.

### P5-8 — Export
Wherever the existing export surfaces already support it. Exports carry the same caveats as the
screens; an export that drops a caveat is a defect.

### P5-9 — Loyalty — **ADDED 2026-11-17. One fix shipped; MKT-006 needs an owner decision**
This slice did not exist. The word "loyalty" was in §5's scope prose and in no slice, which is the
exact failure the owner's accounting rule exists to catch, and the `P05-11` matrix row's next
action was "add a loyalty slice or obtain a decision to defer". Added rather than deferred: an
engineering report deferring something is not an owner decision.

**Recovered decision IDs:** `MKT-005` (loyalty points, VIP, referrals, transaction log) —
**PARTIAL**, points and the log exist, VIP and referrals unlocated (`GAP-191`). `MKT-006` (reverse
rewards on cancellation and refund) — **NOT MET**, measured.

**Shipped, because it changes no price.** The eleven first-party paths that *spend* points each
carried one copy-pasted expression that threw `DivisionByZeroError` during payment confirmation
whenever `reward_points` was absent or `0` — both reachable, the latter because Laravel's `required`
admits `"0"`. Replaced by `App\Support\Money\RewardPointDiscount`, which returns the **same
number on every configured input** (asserted against the shipped expression reproduced verbatim in
the test) and no discount where there is no configured rate. 22 unit tests, seven-mutation battery,
three guards proved dead by mutation and removed, one real hole found the same way: a
`reward_point_price` of `'2xyz'` had produced a 20.00 discount.

**Also shipped: P1-SEC-63a.** Measuring the loyalty READ path found
`ApiController::loyality_program_json()` serving **another tenant's guest orders**, all 40 columns,
to any merchant or driver token naming that store's slug — the documented `custom.auth` trap, in the
sibling of a handler already fixed for the same family. Demonstrated by execution, fixed with the
storefront's shared `resolveStoreCustomer()` + 401, and the `point` query's missing `store_id`
predicate closed with it. The first version of those tests **passed on a 500** and was replaced; see
`docs/security/P01-SECURITY-WORK-QUEUE.md` **P1-SEC-63**. The display half — a `point` figure that
always reads `0.00` — is left open, because fixing it yields an accurate but mislabelled number.

**NOT shipped — each changes a customer-visible amount or a vendored module.** Reward reversal
itself; the missing `max(0, …)` that lets a large balance write a **negative order total** (measured
-30.00 on a 20.00 basket); the `checkout_points`/`checkout_price` thresholds enforced in Blade only
while the server-side guard reads a caller-supplied `club_point_is_active`; and three defects inside
the vendored listener, including a validation check that **never runs** because `??` binds looser
than `==` and `&&`. Full evidence, four options and their consequences:
`PHASE-05-MKT-006-REWARD-REVERSAL.md`. **A backfill of historical balances is out of scope.**

---

### P5-10 — `/finance` profit figures — **ADDED AND SHIPPED 2026-11-17**
Found by measuring `ANA-006` ("do NOT claim precise profitability when cost or fee data is
missing"). `/finance` is the only surface in either repository that renders profit, and it renders
it twice. Both were wrong, in opposite directions, and neither disclosed anything.

**Recovered decision IDs:** `ANA-006` — **PARTIAL**, the two named figures are now disclosed;
fee data remains absent entirely. `ANA-007` — unchanged, see P5-11.

**A fallback that could never fire.** `$product->sale_price ?? $product->price ?? 0` — and
`products.sale_price` is `float NOT NULL DEFAULT 0`, so `??` never reached `price`. A product
simply not on sale was valued at **0** retail while still carrying its full cost, and
`Potential Profit` rendered `0 − cost`: negative, in debit red, for an ordinary store. Measured:
retail `0.00` where `1,000.00` is right, profit `−600.00` where `+400.00` is right. The variant
branch twenty lines up has the identical spelling and is **correct**, because
`product_variants.variation_price` is nullable — the asymmetry is pinned by two tests. The
replacement is the product's own convention (`Product.php:323`, `product_price.blade.php:4`,
`preview.blade.php:59`, `ApiController 499/781/804`), not a new judgement.

**Two disclosures, both measured.** `products.cost_price` and
`store_order_financials.product_cost` are each `decimal NOT NULL DEFAULT 0.00`, so an unrecorded
cost is indistinguishable from a free item and hands its whole value to profit. Neither figure can
be corrected without the missing data, so each is qualified with a live count and, for the tile,
the exact amount exposed. They self-adjust and fall silent when the books are complete. **These are
the first caveats any figure in this product carries on screen** — matrix row `P05-04`, which said
"no figure in the product carries a caveat today", is corrected.

**Nothing stored moved.** Both figures are derived on read; no order total, ledger row, wallet
balance or customer-facing price is touched. The block was inherited at the Phase 2.5 merge
`4e878b8d` and is not Phase 05's own code.

**Evidence.** `tests/Feature/Merchant/FinanceInventoryValuationTest.php` — 17 tests, 69 assertions.
**14 mutations run, 14 reddened, none survived.** Two survivors along the way, both informative:
the `$productUnits > 0` guard looked dead because the simple branch's `continue` already covers it,
until a variant product with no stock anywhere was tested; and `> 0.0` → `!= 0.0` survived behind a
**claim of mine that independent review proved false** — the host validators do enforce
`sale_price >= 0`, but `ImportExport` (`required|numeric`, no `min:0`), `AuctionProduct` and
`DigitalProduct` (`required` alone) all write that column and do not, so a negative IS reachable and
the guard IS load-bearing. A test now pins it. **The census failure is the lesson**: an inventory
keyed on `app/` alone, which is this repository's recurring defect, reproduced exactly.

Review also caught two assertions that could not fail (`assertStringContainsString('200.00', …)` is
satisfied by the `1,200.00` in an adjacent tile; the catalogue tenancy test asserted only
pre-existing view data and was green with the slice reverted) and a **caveat that itself
overstated** — `gross_profit` with no cost is the discounted product subtotal, not the order value,
which is the neighbouring `Total` column. All four corrected. Bilingual (ar/en), RTL-safe via
`border-inline-start`, contrast 8.28:1 light and 8.25:1 dark.

### P5-11 — `<x-ss.stat>` could not display a caveat — **SHIPPED 2026-11-17**
The component rendered its `hint` inside `<span class="ss-visually-hidden">`, which
`store-syria-ds.css:2238-2246` clips to 1×1 with `clip: rect(0,0,0,0)` and **no `:hover`, `:focus`
or `:focus-within` rule anywhere** that reveals it. So it was not hover-only — it was unreachable
by sight.

**Recovered decision IDs:** `ANA-007` ("show data quality, estimates and omissions") — the display
half, now delivered for this component. `ANA-006` — reinforced, since four of the ten hints qualify
money figures. `GAP-201` closed for the component; its `MetricCaveat` half remains open.

**TEN hints across SIX files, nine of them the honesty sentences** — "This is not payments
received, and not profit", "How many units were ordered, not how many were delivered or paid for",
and the corrected "before discounts" wording from the owner's third recommendation. The tenth
(`customer/timeline.blade.php:73`) is a navigation hint and was equally invisible.

*(That count was first measured as nine across five and reached three committed documents before
the recount caught it. A forward regex over the component tag body is truncated by a Blade comment
containing `>`, which misattributes the attribute. The count is now taken by walking BACK from each
`:hint=` to its nearest `<x-` tag, and pinned by a test so it cannot drift again.)*

**The three sibling components already rendered theirs visibly** — `ss-advanced__hint`,
`ss-field__hint`, and `choice-group`'s `aria-describedby` target — and **no test pinned the
difference**, so nothing in the suite would have objected either way. The component arrived with
the Phase 2.5 merge `4e878b8d`; it is not Phase 05's own code.

**Accessibility improved rather than merely preserved.** The hint used to sit INSIDE
`.ss-stat__period`, so a screen reader announced it as a continuation of the period with no link to
the figure it qualifies — the markup implied the wrong relationship. It is now its own `<p>` with a
per-instance id, and the VALUE carries `aria-describedby` pointing at it. Per-instance matters:
`_sales_stats` renders four hints in one grid, and a static id would make every `aria-describedby`
after the first resolve to the wrong tile. Nothing is focusable, so there is no keyboard surface to
regress — which is also why a tooltip was rejected.

**Nothing computes.** No figure, period, label or amount changes; only whether the caveat can be
seen. The hint text is unchanged at all ten call sites.

**Evidence, measured in Chromium against the composited background** (a computed-style read is
known to give false numbers here, because `.dash-content` is transparent and every probe then
compares against black):

| | light | dark |
|---|---|---|
| contrast | **5.98:1** | **7.18:1** |
| visible / clip / position | true / `auto` / `static` | true / `auto` / `static` |
| `aria-describedby` → hint id | matches, unique per tile | matches, unique per tile |

All four language/theme combinations (en-LTR, ar-RTL, light, dark) rendered through the real Blade
compiler by `tests/Support/DesignSystem/bin/render-ds-gallery.php`, with **zero** clipped spans
remaining. Arabic RTL verified by capture: right-aligned, tiles ordered right-to-left, caveat fully
legible. `.ss-stat__hint` uses only `margin-block-start` and inherited direction, so one declaration
is correct both ways, and it joins the dark-mode muted-colour list at `store-syria-ds.css:1864` —
the block that exists because this project has already measured a `<p>` hint rendering at 1.00:1.

**THE COST, MEASURED AND NOT HIDDEN.** A visible caveat makes stat rows taller, and the grid
equalises row height so every tile grows together:

| grid | before | after |
|---|---|---|
| 3 tiles at 1180px (380px cells) | 107px | 169px (+58%) |
| **6 tiles at 1180px (190px cells) — the real `_sales_stats` shape** | **123px** | **257px (+109%)** |

A 160-character sentence wraps to about seven lines in a 190px cell. The row more than doubles, which
on a laptop pushes the chart below it further down. This was NOT traded away by clamping the text —
a clamped caveat re-hides the thing the requirement exists to show. If the owner wants it tighter,
the cheapest lever is `.ss-stats`' `minmax(10rem, 1fr)`: widening the minimum trades columns for
fewer wrapped lines. That is a tuning decision with the numbers above in hand, not a blocker.

**Tests.** `tests/Unit/Ui/StatHintIsVisibleTest.php` — 7 tests, 15 assertions: the hint is not
inside a visually-hidden element, it carries the DS hint class, it is associated with the value,
two tiles do not share an id, a tile without a hint gains neither element nor dangling
`aria-describedby`, the style exists and is on the dark-mode list, and the 10/6 census holds.
The gallery's `stats` specimen now carries two REAL shipped hints plus one tile with none, because
a short placeholder shows a tidy grid and proves nothing about the surface that ships.

### P5-12 — the vendored reward listener destroys balances on orders that redeemed nothing — **SHIPPED 2026-11-17**

**Owner decision D7.** `packages/workdo/RewardClubPoint/.../AddRewardClubPointLis.php:38` is

```php
if ($requests_data['club_point_is_active'] ?? '' == 'on' && $requests_data['final_amount_point'] == $order['final_price']) {
```

`==` and `&&` bind tighter than `??`, so it parses as `$flag ?? (('' == 'on') && (…))` — i.e. the
**truthiness of the posted flag**, with the anti-tamper comparison stranded in a dead operand that
has never executed. `'off'` is a non-empty string, so it is truthy, so the redemption branch runs.
That branch does `$totalClubPoint = $orderClubPoint;` — an **assignment, not `+=`** — and saves it,
so **`customers.total_club_point` is replaced by just that order's earned points** and a ledger row
claims the whole prior balance was spent.

**And `'off'` is the value the shipped checkout posts.** *(This paragraph was WRONG in its first
version and the test pinning it could not fail — both corrected by independent review. It traced
the flag through `clubPointFormField.blade.php` → `checkout_hide_field.blade.php` →
`common/checkout/checkout_form.blade.php:125` and concluded "every order". **Nothing renders that
last file**; its only reference anywhere is LynomiaChat's ViewComposer naming it as a composer
target, and the live checkout includes a different file of nearly the same name.)*

The input that actually posts is `clubPointApply.blade.php:25`, and it sits **inside an eligibility
gate** at `:1-9` — six settings present, `total_club_point >= checkout_points`, and
`final_price >= checkout_price`. It reaches the browser via `ViewComposer.php:27-32` →
`CartController.php:114` → `checkout-cart-list.blade.php:97` → `checkout_list.blade.php:2` →
`front-theme.js:224`, injected into `.checkout_page_cart`, which sits between `Form::open` and
`Form::close` at `checkout.blade.php:25/256/263`. The module's JavaScript flips it to `'on'` only
when the shopper applies points.

**So the corrected severity is: a logged-in customer who already qualifies to redeem and did not
press Apply** — narrower than "every checkout", and still precisely the population whose balance is
worth destroying. It becomes every logged-in checkout when the two thresholds are configured at
`0`, which is savable because `RewardClubPointController:26-31` validates them with a bare
`'required'`. A hand-crafted POST sets the flag regardless of what any view renders, so the
containment is justified independently of this chain.

**Contained first-party by `App\Listeners\NormaliseRewardRedemptionFlag`**, which reduces the flag
to `'on'`-or-absent and touches nothing else. It is a **shape normalisation, not a second
decision** — it reads no balance, consults no threshold and computes no money — so it cannot drift
from the ELEVEN first-party sites that apply the discount. (Eleven apply it; only NINE dispatch the event. `KhaltiPaymnetController:972` applies and dispatches nothing, so a Khalti shopper takes the discount and keeps their points -- a pre-existing leak recorded at `PHASE-05-OWNER-DECISION-PACKAGE.md:145` and assigned to D4, which this listener neither causes nor can fix.) No input moves from accrue to redeem; only
`'off'` moves, and it moves to the branch the vendor's own `else` was written for. Loose `==`
deliberately, matching every first-party site, because an API client can post JSON `true` and
`true == 'on'` is TRUE in PHP 8 — a strict comparison would strip a flag those sites honour and
debit no points, which is the mirror-image free-money bug.

**The ordering is the load-bearing claim and it is measured.** Module providers arrive from
`app->booted()`, strictly after this listener is registered; a probe registered that way observed
the flag as `ABSENT`.

**Registered twice, disclosed rather than tidied away.** `Application::configure()` calls
`->withEvents()` unconditionally and the framework `EventServiceProvider` has discovery ON over
`app/Listeners`, so the class is auto-registered by its `handle()` signature as well as explicitly.
`App\Providers\EventServiceProvider::shouldDiscoverEvents()` returns FALSE and does **not** turn
that off — it applies only to the app's own instance. The explicit registration is kept because it
is the one a reader can see; the handler is idempotent and a test pins that.

**Evidence.** `tests/Unit/Loyalty/RewardRedemptionFlagTest` — 19 tests, 68 assertions. 11 failed
before the listener existed. **8 mutations run, 8 reddened**, so every line is load-bearing. One
guard of mine (`!== null`) was proved dead across eight inputs and **removed**, following the rule
`RewardPointDiscount` records. Premise tests pin the vendored source text, so an upstream fix makes
CI say so rather than letting the containment silently become redundant.

**What it does NOT do, each stated rather than implied.** It does not enforce eligibility —
`checkout_points`/`checkout_price` are still checked in Blade only, so a hand-crafted
`club_point_is_active=on` still redeems below both thresholds; that is **D6 and still open**. It
does not repair any balance already overwritten — D4 forbids retrospective balance changes without
separate approval, and a ledger row cannot distinguish a real redemption from this defect because
the `'off'` path wrote those rows too. It does not touch the **two other defects in the same
vendored file**, which cannot be intercepted first-party at all: both are recorded with measured
failure modes in `docs/security/P05-VENDOR-DEFECTS-REWARDCLUBPOINT.md`, which is also the upstream
report.

**Severity is conditional on D3**, which is unresolved: whether `RewardClubPoint` is enabled in
production is not answerable from this container, where `add_on_managers` has zero rows and no
WorkDo module loads at all.

### P5-13 — D6 server-side redemption eligibility — **STAGE 1 WIRED LOCALLY (quote + WhatsApp writer); GATEWAY RETURNS NOT WIRED — OWNER DECISION (GAP-214); D6 NOT CLOSED**

**Owner decision D6:** *"Enforce reward redemption thresholds and eligibility server-side.
Client-submitted values must not bypass them."*

**STAGE 1 (2026-11-18) — what changed, in one paragraph.** The redemption is now decided **once per checkout, at the
quote** (`PaymentController::processRewardPoints()`), from the signed-in customer's own balance, with
`RewardPointDiscount::forOrder()`; the decision is written back onto the request as `'on'` / `'off'` (a *merge*, because
a query-string copy survives a removal) so the stored state, the COD/bank writer and the event all read it. A guest's flag
is overwritten at the guest arm (a guest checkout adopts whichever store customer owns the typed e-mail). The COD/bank
writer now reads the signed-in customer's balance; the WhatsApp/Telegram writer — reachable by a direct POST — re-checks
and removes the flag on refusal. `RewardRedemptionCheckoutGateTest`: 34 tests / 139 assertions through the real
`process-order` route with the module enabled via the production loader; **25 of 25 mutants killed**. **An independent review
found a real leak in the first version** (a multipart file part named `customer_id` committed a discounted order and failed
before the debit — executed: 50.00 on a 100.00 basket, balance 500, no ledger row) and it is fixed at the intake; the COD
writer's code is therefore unchanged and carries a note not to "tidy" it. **Not done:** the
gateway-return handlers (a defect there is not a threshold problem — the stored customer id and the signed-in one can
differ) — measurement, options and recommendation in
[`PHASE-05-D6-WIRING-MEASUREMENT.md`](PHASE-05-D6-WIRING-MEASUREMENT.md); gap rows `GAP-203` (narrowed) and `GAP-214`.
The paragraphs below are the measurement that led here and are kept as written.

**The gap, measured.** Both module partials carry the identical gate
(`clubPointApply.blade.php:1-9`, `clubPointPriceShow.blade.php:1-10`): the six reward settings
present, `total_club_point >= checkout_points`, `final_price >= checkout_price`. Server-side there
is no check at all — all **eleven** sites that apply a discount test
`isset($requests_data['club_point_is_active']) && … == 'on'`, which compares the caller's own
posted string with itself. A hand-crafted POST redeems below both thresholds.

**A third free-money path, found while measuring this one.** `RewardClubPointController:45` writes
`club_point_enabled` as `$request->club_point_enabled ?? 'off'` and leaves it out of the validator,
so a store can save all six settings with the programme **off**. Neither partial tests it; the
server does not test it; but the vendored listener's `:35` does, and it guards the whole debit
block. In that configuration the discount is applied and **no points are debited and no ledger row
is written** — recurring, unrecorded. `isRedeemable()` therefore treats it as part of eligibility.

**WHY IT IS NOT WIRED, and this is a finding rather than unfinished effort.** Adding the gate to
the eleven sites *and nothing else* produces a **worse** defect than it closes:

```
the eleven sites refuse        -> no discount applied
the client's club_point_is_active=on still rides into AddRewardClubPoint
the vendored listener          -> spends the customer's WHOLE balance anyway
```

Points debited, no discount given — the mirror image of the Khalti leak, and pointing **against**
the customer. The server's refusal must reach the event as well as the total. The mechanism
already exists: `NormaliseRewardRedemptionFlag`'s tests prove an absent flag makes the vendored
listener take its accrual branch, so a refusing site must `unset($requests_data['club_point_is_active'])`
too. **Measured that this is reachable:** at `PaymentService` the decision (`:3057`) and the
dispatch (`:3435`) are both inside `getProductStatus()` declared at `:2443`, so the same
`$requests_data` is in scope. Confirming that for the other ten, and wiring both halves together
with the negative case tested, is the next slice.

**The helper was labelled dead rather than shipped quietly** — and the label did its job: stage 1 wired it, the census
(`test_the_gate_is_wired_at_the_two_decision_points_and_deliberately_nowhere_else`) was updated on purpose to pin the two
call sites. Before that, `test_it_is_not_wired_yet_and_that_is_deliberate` asserted **zero call sites** via a
comment-stripped, class-qualified census, so the day it is wired the assertion fails and must be
updated on purpose. An unwired gate reads as protection, and this repository's own rule — written
into `RewardPointDiscount` after three mutually-covering guards each proved deletable — is that a
check which cannot fail is a trap.

**Two divergences from Blade, both deliberate and measured.** The thresholds must be numeric:
`100 >= "abc"` is already false so junk fails closed either way, but **`100 >= ""` is TRUE** and
`isset('')` is true, so Blade reads an empty threshold as *no* threshold. And `club_point_enabled`
is required, per the paragraph above. Everything else matches Blade exactly, including `>=` and
including that thresholds of `0` opt every logged-in customer in — a savable configuration, since
`required` passes `0`.

**Evidence.** `tests/Unit/Loyalty/RewardRedemptionEligibilityTest` — 16 tests, 47 assertions, all
14 behavioural ones failing before the helper existed. **8 mutations run: 7 redden, 1 proved
equivalent.** The eighth (`!=` → `!==` on the master switch) is equivalent over every value a
`settings` row can hold, proved by exhaustive check rather than waved through; the one value that
does differ, boolean `true`, is not reachable from the settings table and is recorded as the bound
on that claim. **Mutation also caught a real coverage gap of mine:** dropping the negative floor
left the suite green, because the negative-value test used thresholds of `0` where `-1.0 >= 0.0`
already refuses. The floor only bites against a *negative* threshold — savable, since the validator
is a bare `required` with no `min:0`, the same hole that made `> 0.0` load-bearing in `for()`.

Also recorded: `StockMovement::forOrder($storeId, $orderId)` at `app/Services/StockMovement.php:343`
is an unrelated pre-existing method with the same name and entirely different argument meanings.
The census is class-qualified because of it.

### P5-14 — the PR #140 deletion audit — **FIXES LANDED LOCALLY; OWNER ITEMS RECORDED, NOT DECIDED**

Question asked: *what did this diff REMOVE or NARROW?* — of every one of the 120 behaviour-bearing
deleted lines in 24 files, by a first reader and then an **adversarial second reader per file**.
The second reader overturned **18 of 24** first-pass verdicts. Full digest, the eight checklist
answers as an **unsigned draft for the human reviewer**, and the owner-run read-only queries:
[`docs/security/P05-PR140-DELETION-AUDIT-DIGEST.md`](../../security/P05-PR140-DELETION-AUDIT-DIGEST.md).
**That document is not a security review and does not satisfy the human checklist.**

| Item | State |
|---|---|
| AUD-1 — `$plan` undefined after gateway capture; the census finds the shape in **12** handlers, **10** of them unguarded | **PARTLY FIXED, ON PURPOSE** — guard landed on **5**; the other **5** are **held back by owner ruling D-13/D-18** ("the `$plan` fix stays suspended"), the original crash preserved and pinned in both directions. `ReturnHandlerPlanInitialiserTest` 9 tests. Whether to lift the hold is the owner's: **GAP-210** |
| AUD-2 — `product_return` base doubled after a return **request** (205.00 where 57.50 is owed; −15.00 in a second ordering) | **FIXED**; `SecondReturnTotalTest` 5 → 16. Review added two more defects in the same method — a call naming no line raised `final_price` by a flat coupon on every call, and the return was three writes — both fixed (refusal before the first write; one transaction) |
| `/finance` filter missing `order_cancelled` / `order_refunded` | **FIXED** in `b7a7e70a` |
| AUD-4 — Benefit wire shape and catch, `{slug}` locale, top-5 ties (case-folded; **run on MariaDB 10.11.14**), tax-jurisdiction write path **and both tax forms**, seven false comments | **FIXED / CORRECTED** |
| AUD-3 — `syncFromOrder` early return | **NOT A DEFECT** (owner-approved Option A); silence recorded as GAP-209 |
| Reward fail-closed removed by D5 (11 sites) | **OPEN** — D6 wiring, GAP-202/203; blocked on D3 |
| Return-reject marks whole order returned; inclusive-tax guest/signed-in split; NMI `Paid` at 0.00; theme-editor items; existing production data; the `$plan` hold and the owner-row-missing / quote-side findings; vendored callers passing tax arguments shifted by one | **OWNER** — GAP-204 … GAP-211 |

**What went wrong in my own working, kept because it is the instructive part.** I staged a comment
correction saying the inclusive-tax change moves *only the recorded tax* and leaves the charge
bit-identical, on the strength of the audit's reading of `Cart::cart_list_cookie`. Before applying
it I read the other caller: `ApiController::cart_list` adds `$tax_price` with no inclusive
exemption, so for a signed-in shopper the charge *does* fall. The correction would have replaced
one false comment with another. The cure was reading every call site of the function rather than
the one the finding named.

**And where independent review of the fixes themselves found me wrong.** (1) I landed the `$plan` guard
on all ten handlers; an owner ruling I had not read reserves five of them, so five are reverted and the
question went to the owner. (2) I wrote that the tax form's cascade makes a city-with-no-state
unbuildable; changing the country leaves the city selected, so both forms are fixed and the comment
corrected. (3) My top-five tie-break compared bytewise; the SQL charts are case-insensitive. (4) My
Benefit comment claimed the currency sentence "survives only where it can be true"; a 401 is a 4xx.
(5) My `Cart` caller note named two of ten call sites. Each correction is pinned by a test that was
mutation-checked; the list is in the digest, §6.

### P5-16 — D1 and D2 on `/finance` — **BUILT LOCALLY; THE REPORTS AND EXPORTS ARE NOT DONE**

**Owner decisions.** **D1, Option A:** ledger metrics only for periods the ledger can vouch for, the start
stated, no backfill. **D2:** four figures, four separate bilingual labels — *Order value*, *Payments
received*, *Available to withdraw*, *Estimated gross profit with limitations*.

**What shipped on `/finance`** (the first screen to read the P5-2 metric layer):

| Figure | Where it comes from | What the tile says |
|---|---|---|
| **Available to withdraw** (AR المتاح للسحب) | `store_wallets.available_balance`, relabelled from "Available Balance" | "A running balance, not revenue." |
| **Payments received** (AR المبالغ المحصّلة) | `CollectedCustomerPayments` over the verified period | the period ("all time" or "from 31 October 2026"), **counted on the day the money was paid**, how many dated orders before the start have no record (and, separately, how many have no date), an assumed-currency amount if any |
| **Estimated gross profit** (AR إجمالي الربح التقديري) | `StoreRevenueMargin(settled only)` over the same period | "Shipping is not included. **If your prices include tax, the tax is part of the sales value**" (the first wording said tax was always excluded, which is false for inclusive-tax stores); "**At least** N paid orders have no product cost on record, so their profit **may** be overstated" |

**REVIEWED TWICE, 2026-11-18.** The first review's findings were fixed in `e91e9200` (CI green on it). A second,
three-lens review of those fixes (SQL and period rule; what the tiles claim and their currency handling; do the
tests bite and is tenancy intact) found **no defect in a number** and **two medium and several low findings in
wording, direction and test strength**, all verified by reading or running and all now fixed: the profit sentence
that was false for inclusive-tax stores; Arabic (RTL) amounts that could detach from their currency code or lose a
negative sign (every amount is now an isolated left-to-right unit); a count that sat in a sentence about "this
period" but covered all time (the dated and undated gaps are now two disjoint numbers); a hint that predicted
"complete from tomorrow"; a clock read twice; currency codes compared case-sensitively and empty buckets counted
as currencies; and — the one that mattered most for tenancy — **a test suite whose tenant was store 1**, so a
`forStore(1, …)` regression passed every page test. The fixture now puts a distinctive decoy in store 1.
**18 of 18 mutants killed** (one survived the first pass and was pinned); section J of the MariaDB script is 65
checks, 0 failed. Owner items in `GAP-213(a)–(h)`. **The Arabic-page claims were first made from a hand simulation; they are now
measured in a real engine** (`tests/Browser/finance-rtl-money-isolation.mjs`, Chromium, no server needed): on the strings the first
version produced a negative profit lays out as `USD 20.00 -` and a list of amounts as `USD 15,000,000.00 SYP 1,000.00` (pairing
broken) — the review was right; on the strings the presenter produces now they lay out as `- 20.00 USD` and `1,000.00 USD
15,000,000.00 SYP`, and the script **fails all three checks if the isolates are removed**. **Not done, unchanged:** the sales report
and the exports.

**The D1 rule is `LedgerCoverage`**, and it is data-driven: the verified period starts on **the day after the
latest order the ledger cannot see** — no hard-coded cut-over date, so a single order lost to a race in the
middle of the history moves the start past it. Stores with no orders, or with every order covered, read all
time; a store whose orders have **no** ledger row at all shows "Not available" and why, never a zero. An
order with no `order_date` and no row cannot be placed in any window, so it is counted and disclosed.

**Currency is not guessed.** Unrecorded currency is folded into the store currency and *stated with the
amount*; two real currencies are not combined and the tile says so (an unguarded `->total()` would be an
HTTP 500 for every store with history, which is why the metric layer refuses it).

**Evidence.** `LedgerCoverageTest` — 14 tests / 40 assertions. `FinanceLedgerFiguresTest` — 24 tests / 176
assertions. **19 mutants killed across the two**; a survivor (`StoreRevenueMargin(false)`) exposed assertions that
matched by substring (`1,295.50` contains `95.50`, the P5-10 trap again), so every value is now compared exactly.
Two census ratchets moved deliberately: x-ss.stat hints 10 → 12 and raw-query sites 190 → 191 (one scoped site).
**On a real server:** `verify-metric-layer-on-mysql.php` section J — **18 new checks, 0 failed (62 in all)**, run
under `ONLY_FULL_GROUP_BY` and strict mode on MariaDB 10.11, including that coverage is **one statement** and that a
DATETIME `MAX()` lands on the same midnight the suite asserts.

**Independent review, and what it changed** (a read-only reviewer; its report is model output, so each finding was
checked against the code before acting). Two **Medium**: (1) the period is set by *order* date but the figures are
dated by *payment*, so cash for an order the ledger never saw is invisible while the tile said only "not included" —
it now says *even if they were paid inside this period*; (2) a gap dated **today** put the start in the future and the
tile printed **0.00** — it now reads "Not available" and names the date the record becomes complete. Plus: a **blank**
store-currency setting (`?? 'USD'` does not catch `''`) printed "N.NN unknown"; the unrecorded-currency note keyed on the
sign of the amount and vanished for a negative profit; two real currencies were a dead end and are now **listed**;
`forStore(0)` answered "complete"; four statements became one. Added: operator-with-chosen-store and staff principals, an
undated-only fixture, a store-currency setting, a future-start case. Owner questions it surfaced: **GAP-213**.

**Both directions and both themes.** The two tiles are `<x-ss.stats>` / `<x-ss.stat>`, whose hint was measured
in Chromium in P5-11 (5.98:1 light, 7.18:1 dark, four combinations of theme and direction). The one new piece
of markup is the wallet tile's note, which uses the design system's own `.ss-stat__hint` rather than Bootstrap's
`.text-muted`; its contrast is **computed from the tokens, not measured in a browser**: `--ss-fg-muted` `#5b5f66`
on the white card is **6.41:1**, and `#a7abb2` on the dark card `#292A33` is **6.19:1**. The Arabic page is
asserted to render with its placeholders substituted (`FinanceLedgerFiguresTest`); I did not capture
screenshots for this slice.

**NOT done, and the proposal's §4 lists them:** the **sales report** still carries only *Order value*, because
`ReportController` cannot run under the suite (36 `MONTH(` / 9 `YEAR(` calls) so its evidence has to be a
MariaDB script; the **four JSON/CSV exports** do not yet carry the figures or their caveats; and "Total
Income" on the wallet row is untouched and unexamined. Nothing stored moved: every figure is derived on read.

### P5-15 — D8 first-party consent store — **BUILT LOCALLY, UNWIRED, GAP-200 STAYS OPEN**

Owner decision D8: a first-party, persistent, tenant-aware consent and suppression store with append-only
evidence and a secure unsubscribe; per-channel enforcement; no inferred consent and no backfill;
transactional messages never blocked indiscriminately; the unreviewed sender sites classified; **not marked
complete while any promotional path can bypass enforcement**. Design and honest state:
[`PHASE-05-MKT-008-ENFORCEMENT-DESIGN.md`](PHASE-05-MKT-008-ENFORCEMENT-DESIGN.md).

| Slice | State |
|---|---|
| D8-1 — events (append-only), projection, ledger, hashed single-use unsubscribe tokens, send gate (`off` / `report` / `enforce`) | **BUILT, dev/test only.** 99 tests / 322 assertions, mutation-checked. Three migrations **applied nowhere**. Gate **called by nothing**. `MARKETING_CONSENT_ENFORCEMENT` defaults to `off` and the gate then issues no query |
| D8-2 — seams at the send boundaries (mail listener, `sendEmailTemplate`, `SendMsgs`, `send_twilio_msg`, `sendFCM`), `report` mode first, a census per seam | **NOT STARTED** |
| D8-3 — a place to opt in, the unsubscribe route and page (bilingual, RTL and LTR) | **NOT STARTED** |
| D8-B — classify the 214 sender sites | **40 of 214** classified in the committed census, ten of those refuted; a further run was in progress and is **not relied on** |
| Production migration, `report`, `enforce` | **each needs the owner's separate authorisation** — none is implied by anything here |

## 3. What this phase will NOT do

- **Invent metrics, customer events or campaign outcomes.** If the data does not record it, the
  metric does not exist. There is no event stream in this repository and none is being created.
- **Build a second analytics or campaign engine.** See §1.
- **Change live coupon eligibility, quotas or redemption semantics.** P4-F134/F135 are to be
  *reviewed before relying on the data*, not fixed by this phase without a decision.
- **Edit vendored `packages/workdo/`.**
- **Migrate production, deploy, or run a real financial operation.**

---

## 4. Verification standard, per slice

Same as the financial work, because the owner's standard does not change between phases:

1. a demonstrated failing case;
2. a positive control;
3. a negative regression;
4. falsification — revert the fix, confirm the test reddens;
5. independent review for anything touching money, authorization or tenancy;
6. real MySQL where SQLite's type affinity or writer serialisation could hide the defect;
7. the full applicable gates on the final committed tree;
8. honest documentation, including every caveat the metric carries.

---

## 5. Honest statement of scope

Phase 05 as scoped by the owner — merchant analytics, platform reporting, sales and order metrics,
conversion and customer insight, campaign management, coupon and promotion analytics, loyalty,
retention, marketing performance, bilingual premium dashboards, permissions, tenant isolation and
export — is **substantially larger than the financial-policy work that gates it**, and the financial
work is not finished.

This plan is therefore written so that a partial delivery is still coherent: P5-1 and P5-2 are the
foundation everything else reads, and each later slice is independently valuable and independently
blocked. **Work completed will be reported as completed and work not started will be reported as not
started** — the final report states, per slice, which of the two it is, with evidence.
