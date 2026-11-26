# Phase 06 — the owner decisions that must come first

**Created 2026-11-24, from a measured inventory of the code rather than from the plan text.** Six areas
were mapped by independent readers and each map was then handed to a separate reader instructed to
REFUTE it; five came back `SOUND_WITH_CORRECTIONS` and one came back `REFUTED`, and the corrections are
folded into `PHASE-06-INVENTORY-2026-11-24.md` beside what they corrected.

## How this page differs from Phase 05's register, and it differs in the way that matters

`docs/development/phase-5/PHASE-05-OWNER-DECISION-REGISTER.md` opens by saying **"these are not stopping
points"** — every row's supporting code was already built, so an answer released a held change rather
than starting work. **That is not true here, and saying so would be the most useful lie in this
document.** Of the six areas, FIVE returned `buildable_without_owner_decision: false`. These are
stopping points. An answer to most of them is the thing that decides what gets written at all.

The one exception is area D, platform admin and its premium UX, which is buildable now: its engineering
default needs no owner input and its questions are about what the operator is TOLD, not about how to
build the screen.

## The id space, and why it is not `D-N##`

Phase 05's register numbers its questions `D-N1`...`D-N43`. These are `D-P6-01`...`D-P6-51`: a separate,
self-describing space, because fifty-one new rows in one sequence would make every later reference
ambiguous about which phase asked, and because two ids were already reused by accident in this project
once (`D-N39`/`D-N40`, renumbered to `D-N41`/`D-N42` on 2026-11-24).

## What engineering has NOT done

**No default has been chosen in any row below.** A quiet default is the decision taken by another route,
and in this project it has been the wrong one more than once. Where a question has defensible options
they are named as options, not ranked. Nothing in Phase 06 has been built against an assumed answer.

Two items are deliberately absent: a plan-purchase gateway finding and the Phase 01 critical item are
tracked privately and recorded in `docs/security/P01-SECURITY-WORK-QUEUE.md` by id only. This repository
is public.

---

## Area A — SaaS subscriptions, plans and subscription billing

**Requirement rows:** `P06-02`  ·  **Questions:** 10 (`D-P6-01`–`D-P6-10`)  ·  **Buildable without an owner decision:** **NO**  ·  **Refutation verdict on the map:** `SOUND_WITH_CORRECTIONS`

**NOTHING HAPPENS WHEN A PAID PLAN LAPSES.** `SetLocate.php:63-81` computes the days remaining and,
when they are gone, acts ONLY IF `plan_id` is empty; the refusal for the normal case is commented
out at :79. No downgrade, no block, no notice. Trial expiry at :82-90 does silently `assignPlan(1)`
and its redirect is commented out too. **There is no recurring billing:** `laravel/cashier-paddle
^1.9` is installed and `Billable` is on `User` and `Customer`, but `newSubscription(`/`subscribed(`
return ZERO hits across `app/` and `packages/`, and the nine handlers that call
`$authuser->cancel_subscription(...)` call a method that does not exist anywhere, behind a guard on
a column (`payment_subscription_id`) that does not exist either -- so the branch is unreachable.
Renewal is manual re-purchase.

### `D-P6-01`

**Lapse policy — the single biggest blocker, and the one P06-02's own wording ('lose access when a
subscription lapses') does not resolve.** When a paid plan's `plan_expire_date` passes, what should
happen? Specifically: (a) downgrade to which plan — plan id 1 is hardcoded as the free target at
PlanController.php:487/:499 and SetLocate.php:86, while SetLocate.php:73 instead picks
`Plan::where('duration','Unlimited')->first()`, two different answers in one codebase; (b) is there
a grace period, and how long; (c) is the merchant admin blocked, made read-only, or left alone with
a banner; (d) **are the tenant's STOREFRONTS taken down, or do shoppers keep buying?** Today the
answer to all four is 'nothing happens' (SetLocate.php:63-81, redirect commented out at :79).
Implementing any of them changes whether a paying customer's shop stays open. I must not invent
this.

### `D-P6-02`

**Does automatic recurring billing exist in Phase 06's scope at all, or is renewal manual re-
purchase?** cashier-paddle ^1.9 is installed, `Billable` is on User and Customer, and the
`subscriptions`/`receipts` tables exist — but zero lines use them, and the only 'cancel
subscription' code is unreachable and calls a method that does not exist. Building recurring billing
means choosing a provider, a currency posture and a dunning policy; leaving it manual means the
installed package and the two Billable traits are dead weight worth removing. Either way it is a
business decision, and it interacts with the Syrian-market payment reality the owner knows and I do
not.

### `D-P6-03`

**Which plan-purchase gateways may be ENABLED, and on what evidence?** All 26 `plan_activation` keys
are `false` today, and config/payments.php's per-provider comments each state a 'RE-ENABLE WHEN'
condition that requires a provider contract this repository has no captured evidence for (a
signature field order, a status endpoint, a checksum formula). Flipping any flag converts a
documented forgeable activation into a live one. `PlanPaymentPostureTest` pins the key set, so this
cannot be done quietly.

### `D-P6-04`

**Is a per-plan limit PER STORE or PER TENANT?** The merchant form says 'Maximum Products Per Store'
and 'Maximum Users Per Store' (resources/views/plans/create.blade.php:57, :64), the products gate
counts per store (User::countProducts(), store_id = getCurrentStore()), and the Flutter report
counts per tenant (indexAllPlan:8068). Under the per-store reading a 5-store tenant on a 100-product
plan legitimately holds 500 products. The two users counters also disagree on whether sub-`admin`
rows count. Choosing either answer changes what existing paying merchants may do, so it is a pricing
decision.

### `D-P6-05`

**On a DOWNGRADE, what happens to usage already above the new limit?** `assignPlan`
(User.php:361-404) currently flips `is_active = 0` on the excess users and stores in arbitrary
collection order — nobody chose WHICH store gets switched off — and does nothing at all about
products or storage. Deciding the rule (refuse the downgrade / keep over-limit resources read-only /
deactivate by a stated order / grandfather) is product policy with direct customer impact.

### `D-P6-06`

**Should the 5 HELD_BACK `$plan` handlers get the guard (GAP-210)?** The register's disposition is
explicitly '**OWNER.**' and the ratchet test fails until the const is moved. The ruling's premise
changed (P4-F014 moved the lookup from the first admin row to the store's own owner), so the crash
now reaches the operator's own store, a self-demoted merchant and a deleted owner. Related and also
owner's: GAP-210(c) — `CartController::get_shipping_data`, `::get_tax_data` and
`Api\ApiController::confirm_order` read a null plan with no guard and never had one, so a store
whose owner has no plan cannot price a basket either. The underlying question is the same as the
lapse question: **may a store with no resolvable plan sell?**

### `D-P6-07`

**What is a plan coupon's redemption scope?** `PlanCoupon::used_coupon()` counts every user's
redemptions, so `limit` is platform-wide and exhausting it sets `is_active = 0` for everybody
(BankTransferController.php:90-93). Per-coupon-total and per-user-per-coupon are both defensible;
only the owner can say which was sold.

### `D-P6-08`

**Is 'one free trial per account, forever' the intended rule?** `users.trial_plan` is written at
PlanController.php:427 and reset NOWHERE in the codebase, and the first-party comment at :413-415
states that as deliberate. It is currently enforced server-side. Worth confirming before Phase 06
builds anything that depends on it (e.g. a win-back offer).

### `D-P6-09`

**Refund semantics for a plan order.** `PlanController::refund` marks `is_refund = 1`, downgrades
the beneficiary to plan id 1, and writes an AuditLog row — no money moves and no provider is
contacted. Is that the intended meaning of the operator's 'Refund' button, and is plan id 1
guaranteed to be the free plan in every installation? (:487 verifies the row exists but not that it
is free.)

### `D-P6-10`

**Does a merchant get a platform-issued INVOICE or receipt for a subscription payment?**
`plan_orders.receipt` holds the merchant's own uploaded bank slip. Nothing issues a document from
the platform. Tax/VAT treatment of a SaaS subscription in the target market is a business and legal
question, not an engineering one.

---

## Area B — Support

**Requirement rows:** `P06-03`  ·  **Questions:** 10 (`D-P6-11`–`D-P6-20`)  ·  **Buildable without an owner decision:** **NO**  ·  **Refutation verdict on the map:** `SOUND_WITH_CORRECTIONS`

The codebase holds TWO incompatible support relationships and the requirement's one word chooses
neither: first-party customer-to-merchant tickets (`App\Models\SupportTicket`, 19 live routes) and
the vendored `packages/workdo/Helpdesk` merchant-to-operator module (0 live routes, not loaded).
Phase 06 is "SaaS & Super Admin", which ARGUES for the second -- but that is reading, not a
decision, and it is the one that cannot be built without editing vendored code or extending an
unratified override convention.

### `D-P6-11`

WHICH SUPPORT RELATIONSHIP IS P06-03? The codebase holds two incompatible answers and the word
chooses neither: (a) CUSTOMER -> MERCHANT (first-party, live, App\Models\SupportTicket, 19 routes),
or (b) MERCHANT -> PLATFORM OPERATOR (vendored packages/workdo/Helpdesk, 0 live routes, its own
copy: "communication between businesses and super admins"), or (c) both. Phase 06 is "SaaS & Super
Admin", which ARGUES for (b) — but that is reading, not an owner decision, and (b) is the one that
cannot be built without editing vendored code or extending an unratified override convention.

### `D-P6-12`

MAY THE PLATFORM OPERATOR READ AND ACT ON MERCHANTS' CUSTOMER SUPPORT TICKETS? Today it cannot, by
construction (SupportTicketController.php:19 excludes type 'super admin'; the `super admin` role is
granted none of the three permissions). Admitting the operator means the operator reads SHOPPERS'
complaint text, order references and attachments across every tenant. That is a privacy and data-
protection decision about personal data, explicitly reserved by the standing escalation rules.
Discovery lists "super-admin view" as a gap (03-FEATURE-MATRIX.md:147) but that is an engineering
rating, not an owner decision — and the opposite behaviour is what currently SHIPS.

### `D-P6-13`

IF THE OPERATOR IS ADMITTED, UNDER WHICH PERMISSION, AND GRANTED TO WHOM BY DEFAULT? The precedent
set at Utility.php:3085-3097 is that a sensitive capability gets a Permission ROW (so it is tickable
in the role editor) and NO default grant, because "WHICH role holds them by default is a privilege
decision for the owner". The same question applies to every new support permission, and to whether
the 'Replay Support Ticket' misspelling may be corrected at the cost of orphaning existing role
grants.

### `D-P6-14`

SHOULD ticket_status_change BE GATED, AND BY WHICH PERMISSION? It has none today, so any
authenticated web principal with a verified e-mail can change a ticket's status — including staff
who cannot open the list. Adding a gate is a BEHAVIOUR REDUCTION for whoever is relying on it, and
this project has already had a 'fix' refused because a permission gate turned away the store OWNER
that two tests encoded as the shipped contract. There is NO test on this route in either direction,
so nothing records the intended audience.

### `D-P6-15`

WHAT IS THE TICKET LIFECYCLE? The statuses are free text with a three-value writer and a six-value
renderer that disagree. Any SLA, assignment, reporting or escalation work needs an owner-agreed
state set and the allowed transitions first. Engineering cannot pick these: 'Solved' versus 'Closed'
versus 'On Hold' are commitments to shoppers and to merchants.

### `D-P6-16`

IS THERE AN SLA AT ALL, AND WHAT IS IT? There is no SLA concept anywhere (0 columns, 0 code hits).
First-response and resolution targets, business hours, the Syria time zone, and what happens on a
breach are all business policy with no code to infer from.

### `D-P6-17`

WHO WORKS A TICKET — IS THERE ASSIGNMENT, AND TO WHOM? No assignment exists on either ticket table.
The only assignment primitive is `Assign Lynomia Chat` on an external service's conversations.
Whether a merchant assigns to their own staff, whether the operator can assign, and whether a ticket
can be assigned across tenants are all policy.

### `D-P6-18`

DOES SUPPORT NEED TO REACH THE FLUTTER ADMIN APP? routes/api.php has ZERO support or helpdesk
endpoints, so there is no contract to preserve and no client to regress — but adding one is new
public API surface, which the standing rules reserve.

### `D-P6-19`

SHOULD THE HELPDESK MODULE BE ENABLED, BOUGHT INTO, OR REPLACED BY FIRST-PARTY CODE? It is not
loaded (0 routes) and nothing first-party enables it. Enabling it in production is a live-data and
entitlement action; replacing it is a build decision; hardening it from the host side extends the
convention GAP-169/`D-N39` says was never put to the owner. All three are the owner's.

### `D-P6-20`

MAY A SHOPPER'S TICKET LIST SHOW TICKETS RAISED IN A DIFFERENT STORE? It currently can
(AccountProfileController.php:741 filters on customer_id only, one `customers` session across
storefronts, nullable customers.store_id). Narrowing it to the route's store is a visible product
change to a live storefront page, and this project has shipped a storefront-blanking control twice.

---

## Area C — Platform controls and operations

**Requirement rows:** `P06-04`  ·  **Questions:** 9 (`D-P6-21`–`D-P6-29`)  ·  **Buildable without an owner decision:** **NO**  ·  **Refutation verdict on the map:** `SOUND_WITH_CORRECTIONS`

`P06-01` records that **no Phase 06 source document exists**, so "controls" and "operations" are two
words in a derived summary. What was measured: two disable doors, one live hard-delete door,
impersonation that writes no audit row and has no time limit, plan quotas, ONE audited action and
ZERO audit readers, and no bulk action over stores. Suspending a store today leaves its storefront
serving: `ActiveTheme.php:24-26`'s abort is commented out and 0 of 81 storefront-API routes are
gated on it.

### `D-P6-21`

SCOPE, AND IT BLOCKS EVERYTHING ELSE: what are 'controls' and 'operations'? P06-01 records that no
Phase 06 source exists, and the P06-04 row's own next action is 'Do not infer scope from the WorkDo
super-admin surface; get the owner's definition first.' Offer the owner the measured inventory above
(two disable doors, one live delete door, impersonation, plan quotas, one audited action, zero audit
readers, zero bulk actions) and ask which of them the requirement means.

### `D-P6-22`

WHAT DOES SUSPENDING A STORE DO TO SHOPPERS? Today `is_active = 0` leaves the storefront serving
(ActiveTheme.php:24-26 abort commented out; `getStore()` unfiltered; 0 of 81 storefront-API routes
gated). Uncommenting the abort would make 136 web routes answer 403 for every currently-disabled
store. Does a suspended store (a) go fully dark, (b) show a branded 'temporarily unavailable' page,
(c) stay readable but refuse checkout, or (d) keep today's behaviour? This decides live shopper-
facing behaviour and in-flight carts and orders, so it is the owner's.

### `D-P6-23`

SHOULD SUSPENSION BE REVERSIBLE AND STATEFUL, OR STAY A BOOLEAN? `stores` has only `is_active
integer default 1` — no reason, no actor, no timestamp, no status enum. A real governance control
needs columns (reason, suspended_by, suspended_at, state). That is a PERSISTENT MIGRATION on a live
table, which CLAUDE.md §2 reserves to the owner.

### `D-P6-24`

SHOULD DELETING A TENANT BE REVERSIBLE AT ALL? `Store` has no `SoftDeletes`; `ownerstore-remove`
hard-deletes the store, its settings, pages, categories, products and product media from disk, while
orders and PlanOrders are deliberately preserved pointing at a dead `store_id` (owner decision D14).
Making it reversible means soft-deleting `Store` and retaining media — a retention-policy decision
touching the same ground as the recorded 'financial-record deletion is prohibited pending an
approved retention and correction policy' instruction.

### `D-P6-25`

MUST THE OPERATOR HOLD LARATRUST PERMISSIONS, OR IS `users.type` THE AUTHORITY?
`UserPolicy::update()` is ordered permission-first, the seeded `super admin` role carries no
permissions, and two passing tests pin the resulting refusal as the contract — while the policy
comment cites an owner instruction forbidding moving the operator check past the permission check.
Either the operator role gets granted the governance permissions, or governance doors must stop
going through a permission-first policy. Both are owner calls, and GAP-218 is the precedent.

### `D-P6-26`

SHOULD THE OPERATOR'S REACH BE AUDITED AND TIME-BOUNDED? Impersonation writes no audit row, has no
time limit, and `OperatorCatalogueWrite.php:40-57` records that acts performed while impersonating
are indistinguishable from the merchant's own. Adding an audit trail is engineering; deciding
whether the operator may act inside a tenant at all, and whether merchants are told when it happens,
is product and arguably legal policy.

### `D-P6-27`

WHO MAY READ THE AUDIT TRAIL? `audit_logs` has zero production readers. Building a reader means
deciding whether a merchant sees the rows for their own tenant (including rows recording the
operator acting on them) or only the operator does. This is the same unresolved 'reader decision'
already recorded against the finance audit rows.

### `D-P6-28`

IS A BULK ACTION OVER STORES WANTED, AND WITH WHAT CONFIRMATION? There is none today and the
vendored `BulkDelete` map excludes `Store` and `User`. Bulk-suspending or bulk-deleting tenants is a
high-blast-radius capability; whether it should exist, and behind what confirmation and audit, is
the owner's.

### `D-P6-29`

WHAT DOES A LAPSED PLAN DO? Plan-expiry enforcement is still commented out (SetLocate.php:79,
:87-88) while `assignPlan()` silently disables overflow stores and users, and trial expiry hard-
codes plan id 1. Whether a lapsed merchant is redirected to billing, read-only, or storefront-dark
is a commercial decision — and turning the commented redirect back on would immediately lock out
every merchant with a past `plan_expire_date`.

---

## Area D — Platform admin and its premium UX

**Requirement rows:** `P06-05, P06-06`  ·  **Questions:** 7 (`D-P6-30`–`D-P6-36`)  ·  **Buildable without an owner decision:** YES  ·  **Refutation verdict on the map:** `SOUND_WITH_CORRECTIONS`

**THIS IS THE ONE AREA THE INVENTORY MARKED BUILDABLE WITHOUT AN OWNER DECISION.** The engineering
default needs no input: reuse the Phase 2.5 harness and its four-combination AR/EN x light/dark
evidence rule rather than invent a second standard. The questions below are about WHAT THE OPERATOR
IS TOLD -- three cards whose label and value disagree -- and about the acceptance bar, not about how
to build it.

### `D-P6-30`

Is *Total Store* supposed to count STORES or MERCHANTS? Both readings are coherent and the code is
self-consistent for merchants — `countCompany()` counts type-`admin` users, the card links to
`stores.index` which lists merchants, and that screen's own breadcrumb says `__('Users')`. So the
fix is either renaming the card (cheap, no figure change) or changing the figure to `Store::count()`
(which also breaks OperatorDashboardWithoutAStoreTest:112). Which the owner wants decides whether
this is a one-word change or a figure change plus a test rewrite. ENGINEERING CANNOT PICK: it is
what the operator is meant to be told.

### `D-P6-31`

Which subscription-order states count toward *Total Plan Orders* and toward *Popular Plan*? Today it
is every `plan_orders` row regardless of `payment_status` — pending, failed, rejected and refunded
all included. Phase 05 established a precedent for this class of question (D1/D2 labels, the
"Payments collected" vs "order value" distinction, and the caveat slot on `<x-ss.stat>`), but the
inclusion RULE for subscription orders is a business rule about the platform's own revenue and has
no owner decision behind it. Do NOT infer it from the merchant-side order rules — those are a
different ledger (`store_order_financials.order_id` is a foreign key to the CUSTOMER orders table,
so a plan_orders row can never have a ledger row, as the view's own comment at
dashboard.blade.php:32-36 records).

### `D-P6-32`

Is *Total Coupon* meant to be a COUNT or the most-used coupon's NAME? The card is labelled
`__('Total Coupon')` and passed `$couponName`, a string. Same for *Popular Plan*, which is at least
labelled consistently. If these are meant to be names, they belong in a different component from the
numeric stat cards; if they are meant to be counts, the figures are missing. Either way the label
and the value must be made to agree, and which way is a product choice.

### `D-P6-33`

Is Storage Settings (and Cache Settings) an OPERATOR-only capability or a merchant one? The Blade
`@if` at setting/index.blade.php:75 hides both tabs from merchants, while
`SettingController::StorageSettings():215` admits the operator, the store OWNER and any `Manage
Setting` holder. The Phase 2.5 matrix deferred both rows to Phase 06 calling them "super-admin
only". The handler and the UI disagree about the audience; closing the gap in either direction
changes who can configure storage, so it needs the owner. (This is a policy question, not an
unguarded hole — the handler IS guarded.)

### `D-P6-34`

Should `GET /config-cache` be narrowed to the operator? F197 records the decision as deliberately
deferred ("narrowing who may press a button that now only clears compiled artefacts is a smaller
matter than the flush was"). Narrowing it is a one-line change and almost certainly right, but it
removes a capability some merchant may be using, so it is the owner's call to close F197's residual
rather than engineering's to assume.

### `D-P6-35`

What does P06-06's "premium" mean on an operator screen, given Phase 2.5 was explicitly merchant-
scoped? GAP-179's own next action says to reuse the Phase 2.5 harness and its four-combination
evidence rule rather than invent a second standard — that is a sound engineering default and needs
no owner input. What DOES need input is the acceptance bar: Phase 2.5's 24 PREMIUM VERIFIED surfaces
went through owner VISUAL acceptance (PROJECT-STATE §3). Whether the operator surface requires the
same owner sign-off, and over which screens, is a process decision only the owner can set.

### `D-P6-36`

Which operator screens are in P06-05's scope at all? P06-01 records that no Phase 06 source document
exists, so "platform admin" is two words in a derived summary. The measurable candidate set is: the
operator dashboard, platform/analytics, 7 plan-coupon screens, plans/, plan_request/, store/ (plus
store/plan, store/custom_domain, store/subdomain, store/reset, store/view-storelinks), addon/ +
addon-apps, country/city/state, language/, currency/, the 3 Phase-2.5-deferred settings surfaces,
and the Lynomia integration screen. That is a candidate list, not a scope. Asking the owner to
confirm the set is cheaper than building the wrong half.

### `D-P6-36` — MEASURED INPUT, added 2026-11-26 (`GAP-178` / `P06-05`)

`D-P6-36` asks which operator screens are in `P06-05`'s scope and gives a candidate list. That list
was written from screen names. This section replaces the names with the ROUTE TABLE, because the
question is answerable only against what is actually gated, and adds nothing to the scope — the
ruling is still the owner's and engineering has not taken a default.

**THE MEASURED OPERATOR-GATED SURFACE**, from the booted route table (declared middleware, so a
reader can check it against `routes/`):

| gate | routes | what they serve |
|---|---|---|
| `PlatformOperatorOnly` | **37** | LynomiaChat super-admin group (15), `platform/*` operator screens (9), `plan-coupon` (7), `plan.order.refund` + `plan.disable` + `order.approve` + `order.reject` (4), `zid/{storeId}/*` (2) |
| `can:manage-platform-addons` | **7** | `addon` (4), `install-modules`, `theme-addon`, `theme-enable` |

And the `platform/` prefix is a partition, measured: **20 routes — 9 operator-only, 11 public** (the
official website and the documentation centre, Phase 03). `AnalyticsRouteAuthorizationCensusTest::
test_everything_under_the_platform_prefix_is_public_by_declaration_or_operator_only` holds that
partition against a declared public-controller allowlist, including for a controller that does not
exist yet — mutation-verified on 2026-11-26 by removing the gate from `platform/revenue`, which
reddens two cases and names the route and the controller.

**`D-P6-36`'s CANDIDATE LIST PREDATES FOUR OPERATOR SCREENS**, which is the concrete correction this
section exists to make. The list names the operator dashboard, `platform/analytics`, the plan-coupon
screens, plans, plan requests, stores, addons, geography, language, currency, the three deferred
settings surfaces and the Lynomia screen. It does **not** name:

| screen | routes | added by |
|---|---|---|
| `/platform/resources` | 1 | `GAP-195` / `OPS-002` |
| `/platform/revenue` | 1 | `GAP-192` / `SAAS-007` |
| `/platform/site-content` | 3 | `GAP-107` / `P03-D-04` |
| `/platform/documentation` | 2 | `GAP-107` / `P03-D-04` |

Two of those four ARE this row's whole subject: `app/Http/Controllers/` holds exactly two
`SuperAdmin*` controllers, `SuperAdminResourcesController` and `SuperAdminRevenueController`, both
added in commit `1cde58005` and nowhere else in history (verified with `git log --all --diff-filter=A`) — so the surface is as thin as `GAP-178`
says, and the candidate set it belongs to is six routes larger than the list above.

**THE PRIOR QUESTION, AND IT MAY DISSOLVE `P06-05` RATHER THAN ANSWER IT.** `GAP-174` established
that `P06-NN` is the DERIVED summary's numbering and that each row must be read against the family id
it traces to; `P06-01`…`P06-08` appear nowhere in the recovered plan. The platform-admin family in
`DECISION-ID-REGISTER.md` is `ADM-001` ("لوحة قيادة شاملة للمنصة" — a comprehensive platform command
dashboard) and `ADM-002` ("مساحة Store 360°" — review and manage each store from its own systems),
and **both are already tracked separately, as `GAP-231` and `GAP-232`**.
`PHASE-06-SCOPE-RECONCILIATION.md` records `ADM-001` as overlapping `P06-05`/`P06-06` "by subject
only". So the first thing to rule on is not "what goes in `P06-05`" but:

> **Is `P06-05` the same requirement as `ADM-001` + `ADM-002`** — in which case it is a derived
> duplicate of `GAP-231`/`GAP-232` and should be re-keyed per `GAP-174`'s own next action rather
> than built — **or does it name something additional?**

Engineering cannot answer that: `P06-05`'s entire requirement text is the two words "platform admin"
from `CLAUDE.md §4`, with no original wording and no owner decision behind it, and `GAP-177` is a
standing owner instruction not to infer scope from the WorkDo super-admin surface. Ruling on the
mapping first is cheaper than ruling on a candidate set that may belong to a different row.

**WHAT ENGINEERING CLOSED WITHOUT A RULING**, so the pending decision is not holding up a defect
fix: of the four operator-only controllers under `platform/`, only `PlatformContentController` had
an in-controller authorization check; the two `SuperAdmin*` ones relied on route middleware alone.
Both now carry the sibling's predicate — `! $user instanceof User || !
UserPolicy::isOperator($user)` — driven by `PlatformAdminSurfaceDefenceInDepthTest`, which removes
the route gate and asserts exactly 403 for a merchant and for a `Customer` row typed `super admin`.
Before the fix those answered **200** and **500** respectively. No screen, route or capability was
added.

**ONE STALE FIGURE WORTH CORRECTING WHEN SOMEBODY OWNS THOSE FILES:** `PlatformOperatorOnly`'s
docblock and `PlatformOperatorOnlyPrincipalTest`'s ("one of the fourteen") both put the gate's blast
radius at **14 routes**. Measured 2026-11-26: it is **37**. The figure grew with the LynomiaChat
super-admin group and the `platform/*` screens. Nothing behaves wrongly; a reader's threat model is
understated by 23 routes.

---

## Area E — Payment-gateway configuration in the Flutter admin app

**Requirement rows:** `P06-07`  ·  **Questions:** 6 (`D-P6-37`–`D-P6-42`)  ·  **Buildable without an owner decision:** **NO**  ·  **Refutation verdict on the map:** `SOUND_WITH_CORRECTIONS`

Six files, 815 lines, **100% commented out**. The matrix records that NO evidence was found that the
owner approved deferring this to Phase 06, so it must not be treated as an owner-approved boundary.
Every question here touches payment configuration or a live gateway credential, which the standing
escalation rules reserve.

### `D-P6-37`

PRODUCT POLICY — the one the requirement row itself names. Should a merchant be able to configure
payment gateways from the Flutter admin app at all? The honest framing for the owner is parity, not
new exposure: the web already does this for 52 gateways behind `Manage Setting`. Answers: (a) yes,
full parity; (b) yes, but read-only status display (which is what the live hub already does via
getActivePaymentMethods); (c) yes for COD and bank transfer only, not for card credentials; (d) no,
gateway configuration stays web-only and the six files are DELETED rather than left as dead code. No
engineering evidence can settle this and CLAUDE.md reserves material changes to payments to the
owner.

### `D-P6-38`

PRODUCT POLICY — should a live Stripe secret key be readable into, and displayed on, a mobile
device? The backend already serves it (DashboardController:4701) and three tests pin that as the
contract, but the web editor deliberately MASKS its 58 secret fields
(PaymentSettingController:220-225, F117). Reviving the Flutter editor forces a choice between those
two postures. Answers: (a) mask on mobile as on web, write-only, keep stored value when nothing
typed; (b) display as the dead code does; (c) stop serving `stripe_secret` in the GET body at all,
which is a breaking API change the owner must accept.

### `D-P6-39`

PRODUCT POLICY, AND FINANCIAL — which setting row is the canonical Stripe secret, `stripe_secret` or
`stripe_secret_key`? Both are live, written by different editors and read by different checkout
paths (measured above). Converging them touches stored merchant credentials and could change which
configuration charges a card, so it is a change to payments, not a refactor. The owner must choose
the canonical name AND authorise (or refuse) a reconciliation over existing rows. Until then, do not
revive the Flutter stripe editor: it writes the name the web storefront checkout does not read.

### `D-P6-40`

PRODUCT POLICY — should a COD or bank-transfer save be allowed to write the store CURRENCY? The
commented Flutter setters post CURRENCY_NAME/CURRENCY and the handlers require and write them.
Answers: (a) drop them from the gateway payload and make currency its own screen (an API contract
change: the handlers validate them as required, so removing them from a client without changing the
server yields a validation error); (b) keep them, with the currency rendered read-only in the
gateway form; (c) keep as-is. Currency decides what a shopper is charged, so this is reserved.

### `D-P6-41`

PRODUCT POLICY — may staff who do not hold `Manage Setting` READ the merchant's COD text and bank-
account details? This is the one question behind the measured gate gap on get-cod-payment and get-
bank-payment. The default implied by the four gated siblings and by
StoreSettingsAccess::RESTRICTED_EXACT:74 is no. Closing the gap is a tiny, bounded, first-party
change needing no new product rule — but it WILL change behaviour for any merchant whose staff
currently read those two endpoints, so the owner should be told rather than asked. If a decision is
wanted, the question is only whether to close it now or with the Phase 06 package.

### `D-P6-42`

SCOPE — does P06-07 mean these six files, or gateway configuration at web parity (52 gateways)? Only
three gateways have admin-API handlers at all. The answer changes the work by an order of magnitude
and should be fixed before any ledger row is written.

---

## Area F — Scope, and the requirements that were never tracked

**Requirement rows:** `P06-08 + the recovered sections`  ·  **Questions:** 9 (`D-P6-43`–`D-P6-51`)  ·  **Buildable without an owner decision:** **NO**  ·  **Refutation verdict on the map:** `REFUTED`

The eight recovered sections `06-A`...`06-H` and the eight derived `P06-*` rows **do not
correspond**, and the derived set was missing Store 360 entirely until `GAP-232` was raised on
2026-11-24. `KYC-001..003` and `MARKET-001..005` are one sentence each and are regulatory and
commercial respectively.

### `D-P6-43`

STORE 360 (ADM-002) HAS NO REQUIREMENT ROW ANYWHERE — register it before building. The recovered
plan asks for 'مساحة Store 360° لمراجعة حالة كل متجر وإدارته من أنظمته الأصلية' / 'A Store 360°
space to review and manage each store from its own systems' (MASTER-PRODUCT-PLAN-v1.0-BASE.md:384,
DECISION-ID-REGISTER.md:165). That is the entire specification. 'Review the state of each store AND
MANAGE IT from its own systems' is a product-policy question with a security edge: does 'manage from
its own systems' mean the operator ACTS AS the merchant (which is the impersonation door that
already exists, unaudited), or that Store 360 is read-only with management staying in the merchant's
own session? The two differ in blast radius, not just in UI. Do not choose this for the owner.

### `D-P6-44`

Does ADM-002's 'manage' authorise cross-tenant WRITES by the operator, and if so, under what record?
This bears directly on an existing engineering decision the owner has not ratified:
`app/Services/OperatorCatalogueWrite.php` BLOCKS the operator's direct catalogue writes and
documents that impersonation is 'not the explicit and specific delegation the owner's decision asks
for'. If Store 360 grants operator write access, that service's premise changes.

### `D-P6-45`

SUPPORT-004's four properties each need an owner rule, and none can be inferred from code: (a) what
counts as MERCHANT CONSENT (per-session approval, standing opt-in, a support ticket implying it?);
(b) how long 'TEMPORARY' is; (c) what 'SCOPED' means when `Impersonate::take()` currently grants the
merchant's whole identity — which subsystems may a support agent see; (d) who may READ the audit
trail. Measured: none of the four exists today, and the audit log is the one piece engineering could
add without a policy answer.

### `D-P6-46`

KYC-001..003 are three sentences and nothing else, and they are regulatory, not technical. KYC-001
'Verification of individuals and companies' — WHICH documents, verified by WHOM, and is a third-
party provider in scope? KYC-003 'Separate Store Syria verification from payment-provider and
government-licence verification' states a boundary without saying what falls on each side. KYC-002
names 'private documents, reviews, renewal and access logs' — identity-document storage is sensitive
personal data under the standing safety boundary, so retention, encryption and who may view a
document are owner decisions. Nothing about this can be built from the three sentences.

### `D-P6-47`

MARKET-001..005 need a commercial model before any code. MARKET-003 says developer prices and
revenue apply 'عند اعتماد نموذجها التجاري' / 'when its commercial model is adopted' — i.e. the plan
itself defers to an owner decision. MARKET-002 (security review, compatibility, versioning,
permissions) needs a review standard; MARKET-004 and EX-010 forbid executing untrusted third-party
code in production, which constrains the architecture of the whole feature; MARKET-005 requires
suspending an app without destroying merchant data. Also: is the marketplace for THIRD-PARTY
developers at all, or only for Store Syria's own 70 add-ons? The existing catalogue serves the
latter.

### `D-P6-48`

Is the Phase 06 scope the EIGHT recovered sections (06-A..06-H) or the eight derived P06-* rows?
They do not correspond, and the derived set is missing Store 360 entirely. Reconciling them is a
scope decision, and the standing rule forbids silently reducing approved scope.

### `D-P6-49`

Must the three-way 'Phase 6' collision be resolved by RENAMING, or only by annotation? P06-08's Next
action says 'State the three-way collision wherever Phase 06 is discussed', which is documentation.
Renaming the Lynomia documents or the discovery roadmap's phases would touch delivered records of
another project and is not engineering's call.

### `D-P6-50`

P06-07 remains an explicit owner decision and has not moved: whether merchants configure
COD/Stripe/Bank-transfer gateways from the Flutter admin app (six files, 815 lines, 100% commented
out). The matrix row records that NO evidence was found that the owner approved deferring it to
Phase 06, so it must not be treated as an owner-approved boundary. It concerns financial
configuration, which the standing escalation rules reserve.

### `D-P6-51`

Before any Phase 06 subscription work: EX-011 / SAAS-003 ('do NOT delete store data when a
subscription ends') is recorded as UNVERIFIED at DECISION-ID-REGISTER.md:464, with Discovery noting
plan-expiry enforcement was commented out in SetLocate.php. Whether the current behaviour already
violates an approved prohibition is a question to answer by measurement first, then put to the owner
if it does.

---

## Reading order, if only some of this can be answered now

Engineering's view of which answers unblock the most, offered as a view and not as a ranking of
importance:

1. **`D-P6-46`** — is Phase 06's scope the eight recovered sections or the eight derived rows? Every
   other area's scope sits under this one.
2. **`D-P6-01`** and **`D-P6-39`** — what a lapsed plan does. The same question reaches subscriptions,
   governance and the storefront, and the code that would enforce it is commented out in one place.
3. **`D-P6-11`** — which support relationship `P06-03` means. The answer decides whether the work is
   first-party or needs a vendored module the project may not edit.
4. **Area D's seven** — the cheapest to answer and the only area that can proceed meanwhile.

## What happens to this page

Answers are recorded here beside their question, dated, with the decision's consequence named — not in a
commit message and not in a task description. An answer that changes a figure a merchant or an operator
already sees is also traced into `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md`, because that is the document
the phase is judged against.
