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
`0`, which is savable because `RewardClubPointController:26-33` validates them with a bare
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

**Evidence.** `tests/Unit/Loyalty/RewardRedemptionFlagTest` — 18 tests, 57 assertions. 11 failed
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
