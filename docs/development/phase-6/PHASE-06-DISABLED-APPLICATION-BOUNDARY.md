# Phase 06 — switching an application off removed nothing

**Status: FIXED for everything the owner has decided; TWO CATEGORIES REMAIN THE OWNER'S, named in
§4.** Written after the patch, because this repository is public.

> **Updated 2026-11-25 (#204).** §4 recorded the whole anonymous half as *"PARTIAL by design — the
> owner has the counts and the question"*. The owner has since answered the payment part, and §4 is
> rewritten to say what was built against that answer and what is still open. Nothing above §4
> changed: the original measurement, the fix and its evidence stand as they were.

Directive item 2(B): *"disabled applications must not retain usable capabilities — enforce through
first-party code without editing vendored WorkDo modules."*

---

## 1. The defect

`app/Providers/PackageServiceProvider.php:47`

```php
$dbAddons = AddOnManager::pluck('module')->toArray();   // no is_enable predicate
```

feeds `loadAddons()`, which registers each module's PSR-4 prefix and service provider. So disabling
an application removed **nothing**: its code stayed loaded, its providers stayed registered, its
routes stayed mounted. The only thing consulting the flag was the per-call `module_is_active()`
helper, wherever a developer remembered to call it.

It is a **divergence inside this codebase**, not a stated design: `app/Classes/Module.php:114`
(`where('is_enable', 1)`) and `:141` both filter on the same flag.

## 2. What that amounted to, measured

Through the project's own harness — `tests/Support/Security/bin/route-census.php`, all 70 modules
loaded via the production loader on a throwaway in-memory database:

| | |
|---|---|
| modules loaded · load failures | **70** · **0** |
| routes in the table | **3,058** |
| owned by a module | **1,464**, across **68** modules |
| owned by the host | 1,594 |
| module routes **with** route-level authentication | **1,012** |
| module routes **without** | **452** |
| module routes outside the `web` group | **18** (LynomiaChat 8, CartQuantityControl 8, LandingPage 1, ProductsPDF 1) |

Two modules own no routes at all (`CheckoutAttachment`, `ProductImageZoom`). The namespace→directory
mapping is **exact in both directions** — every module route's action matches `Workdo\<Module>\`, every
such `<Module>` is a real directory under `packages/workdo`, zero mismatches — which is what makes a
host-side guard possible. (The 452 figure independently reproduces P1-SEC-04's, a useful check that
the harness still measures what it did before.)

## 3. The fix, and why it is a request guard

`App\Http\Middleware\DisabledModuleRouteGuard`, registered in **both** the `web` and `api` groups
(the 18 routes above are why `web` alone would have been wrong), with the predicate in
`App\Support\Modules\ModuleAvailability`.

**Host-side, because `packages/workdo/` is vendored.** The same reason
`ModuleAdministrativeRouteGuard` exists, whose refusal shape this copies exactly (`:365-375`): JSON
403 when the caller expects JSON, otherwise a redirect with an error — **never a 401**, because
CLAUDE.md records that the shipped Flutter admin app forces a logout on any 401.

**Not `where('is_enable', 1)` at the loader.** That looks like the obvious one-line fix and is the
wrong one: `loadAddons()` registers the module's migrations, console commands and event listeners
too. Filtering there would mean a switched-off application could not be migrated, could not be
re-enabled cleanly, and would silently stop whatever scheduled work it owns — a far larger behaviour
change than the requirement asks for, failing by **breaking** rather than by refusing.

### An absent row is not a disabled application

The most important line in the predicate. A missing `add_on_managers` row means "not managed through
the add-on manager", and here that has a concrete victim: `PackageServiceProvider:49-51` **force-adds
`LandingPage`** whether or not it has a row, and `LandingPage` owns **275 routes — more than any other
module**, including the public landing pages. A guard reading "no row" as "disabled" would take them
down. A mutation that made exactly that change is caught by
`test_a_module_with_no_row_is_not_disabled`.

Measured against the booted schema: `is_enable` is `$table->boolean(...)` — `tinyint(1)`, NOT NULL,
**default 0** — so a stored row always carries 0 or 1 and `value()` returns null only when the row is
absent. (The default being 0 means a row inserted without an explicit flag is *disabled*, which is
the install screen's own flow.)

## 4. The anonymous half: what the owner decided, what was built, and what is still open

### 4.1 The answer that arrived

The owner's clarification, verbatim:

> *"Implement effective, immediate account and application suspension."* · *"Preserve all existing
> data, orders, balances and audit records. Disabling is not deletion."* · *"Keep a safe,
> authenticated and idempotent reconciliation mechanism for previously initiated payments; do not
> use it to permit new transactions through disabled applications."* · *"Do not stop for these same
> three questions again."*

So the payment question is decided, and `payment-callback` (87) plus `webhook` (2) stop being open.
`own-credential` (6) and `owner-exemption` (2) were already settled; `guarded` (22) and `inert` (8)
are orthogonal. **`storefront` (100) and `oauth` (16) remain the owner's** — whether switching a
merchant *application* off should also take down shopper-facing pages, or break an OAuth return
already in flight, is not inventable and is not invented here.

### 4.2 THE CATEGORY WAS THE WRONG THING TO BUILD ON, and that is the finding of this slice

The rule was first written against `ModuleRouteManifest`'s `payment-callback` label. Read against
the three modules that own all 87 of those keys — Donation 37, LotteryPrizes 33, Campaigns 17 — the
label does not follow the code in either direction:

| | measured |
|---|---|
| Donation mounts `StoreDonationPayment` on **49** keys | 37 `payment-callback` + **12 `storefront`** (`donation-telegram`, `donation-whatsapp`, six verbs each). One method, two labels. |
| LotteryPrizes mounts `StoreLotteryPayment` on **43** keys | 33 + **10**. Same. |
| `GET {storeSlug?}/ticket-telegram` is `payment-callback` | its five sibling verbs on the same `Route::any` are `storefront`, and the module issues no GET at all |
| **5 of the 17 Campaigns keys START a payment** | a card-entry form, a DPO charge, a PowerTranz sale, a PayU redirect, a Braintree drop-in. Honouring those as "returns" would be honouring an initiation. |
| the places a payment is STARTED are not in the category | Donation's and Lottery's are `storefront`; Campaigns' is `ADMINISTRATIVE` |

A boundary drawn on the label is walked around by changing the last word of a URL. The manifest is
not wrong — it answers *"may this be reached without signing in"*, which is what it was built for.
It was never asked what a route does to a payment.

So the rule is applied to what the owner stated it about, **the payment**, keyed on the controller
action: `App\Support\Modules\ModulePaymentFlows` classifies thirteen vendored handlers as `START`,
`STEP`, `RELAY` or `COMPLETE`. `tests/Unit/Modules/ModulePaymentFlowsTest` parses the three vendored
route files and fails if any of the 87 `payment-callback` keys resolves to a handler the table does
not classify — so a module update that adds a payment route reddens the build instead of slipping
past the rule. It also pins the per-category reach: the payment rule touches all 87
`payment-callback` keys, **24 `storefront` keys** (2 that start a payment, 22 that reach a completion
writer), 6 `guarded` and 26 `ADMINISTRATIVE`.

**The other 76 `storefront` keys and all 16 `oauth` keys are untouched**, which is the open decision
kept open: a disabled module's donation form still renders, and a shopper who submits it is told the
service is not available.

### 4.3 AND THE PREREQUISITE WAS A BUILD, NOT A FLAG

*"Previously initiated"* was not a question this application could answer. Read end to end, **none
of the three modules writes a database row when a payment is initiated** — the only trace is the
payer's session — and the completion handlers are, in order: unverified against the provider (one
gateway of thirty-five excepted, Khalti), not idempotent (a new row per call, no unique key), and
for Donation willing to build the record from the request when the session is empty.

Nor did a reconciliation surface exist: `PlanPurchaseIntent::unreconciled()`, the query the F229
design *"points a human at"*, **had no reader anywhere** in `app/`, `routes/` or `resources/views`,
and `app/Console/Commands/` held twelve commands and none about payments. That is P4-F128 /
P5-FIN-4 and review finding F13, and it is why widening the refusal first would have lost captured
payments.

Both were built in the same change:

| | |
|---|---|
| `module_payment_initiations` + `App\Services\ModulePaymentLedger` | the host's own durable record that a payment was started through a module, written by the guard because `packages/workdo/` is not edited |
| `payments:reconcile` (`App\Console\Commands\ReconcileInitiatedPayments`) | the reconciliation surface. Asks each provider that CAN be asked about every previously initiated plan payment; settles a confirmed, matching one exactly once; records a discrepancy; lists what only a person can resolve. **Report-only unless `--apply`.** `docs/finance/P204-PAYMENT-RECONCILIATION.md` has the design |

### 4.4 What the boundary now does, by what a route IS

| | |
|---|---|
| **authenticated** (1,012) | REFUSED, as before |
| **starts a payment** (`START`/`STEP`) | **REFUSED.** Nothing has been charged when either runs, so the refusal costs the payer nothing |
| **finishes a payment** (`RELAY`/`COMPLETE`) | honoured **ONCE**, for an initiation the host recorded while the application was still enabled, proven by the 128-bit reference in the payer's own session; one conditional `UPDATE … WHERE status = 'initiated'`, so a refresh, a replay or a second tab finds nothing left to spend. A return that cannot show one is refused **and written down**, because the session may have expired on a payer whose money was taken |
| `webhook`, `own-credential`, `owner-exemption`, `guarded`, `inert` | pass, as before |
| `storefront` (the other 76), `oauth` (16) | pass — **THE OWNER'S, STILL OPEN** |

**"Authenticated" here is not a login.** It is that the request comes from the session that started
the payment. That is stronger than anything these handlers ever asked for.

**WHAT IT DOES NOT ESTABLISH, said plainly: that a provider took money.** None of these modules
verifies a capture and nothing here pretends to. The boundary answers the question the owner's rule
asks — was this payment started before the application was switched off — and leaves what the
vendored handler does with it exactly as it was.

**WHERE THE RECORD IS ABSENT THE PAYMENT HALF IS INERT, WHICH INCLUDES PRODUCTION.** The migration
is development-and-test-only, like `plan_purchase_intents` and `order_gateway_references` before it;
promoting it is an owner decision. Without the table a return cannot be told from a new
transaction, so it passes through exactly as it did before — refusing it would turn an outstanding
migration into a lost payment. **Starting** a payment through a disabled application is refused
either way, because that needs no record.

`test_an_anonymous_route_of_a_disabled_module_is_not_refused_pending_an_owner_decision` has been
narrowed to
`test_a_non_payment_anonymous_route_of_a_disabled_module_still_passes_pending_the_owners_decision`,
and its docblock quotes the answer that narrowed it. It now pins only the two categories that are
genuinely still open.

## 4.5 What the mandatory independent review found, after this was built

The `tenant-authz-reviewer` pass read the guard, the flow table, the ledger, the migration, the
command, the three vendored route files and their controllers, `bootstrap/app.php`'s ordering, and
the four sibling host guards. **Its headline was that there is no authorization hole in the new
code**: nothing a caller sends is authority anywhere, the reference is read from the session and
never from the request, a disabled module cannot mint one, and the owner's two undecided categories
are untouched. What it found is that **the boundary did less than its own docblocks claimed**, in
five places. All five are fixed.

### 4.5.1 The highest-severity one: a Campaigns return was recorded as honoured and then refused

`ROUTE-LEVEL MIDDLEWARE RUNS AFTER THE GROUP'S.` Campaigns declares its writer inside
`Route::group(['middleware' => 'PlanModuleCheck:Campaigns'])`, and neither that middleware nor this
guard is in Laravel's priority list — so the guard decided first, spent the initiation, and
`PlanModuleCheck` refused the request afterwards. The row said `completed` for a payment that was
never written, and `payments:reconcile` showed nothing, because the row was neither open nor refused.

**And the PowerTranz relay is the sharp edge.** `campaignsGetPowertranzStatus` sits OUTSIDE that
group and is the step that CAPTURES (it posts the SpiToken to `/api/spi/Payment`) before redirecting
into the writer. The boundary's own "continuity" was therefore taking the money and then being
refused at a door this application controls.

Fixed two ways, because one alone would be wrong: `ModulePaymentFlows::completionIsEntitlementGated()`
refuses the whole Campaigns return path while that module is off, **leaving the initiation open** so
the payer can still complete if the operator switches it back on; and every other module's claim is
**put back** when the request is refused downstream (`reopenInitiation()`), with the refusal
recorded. The flag is derived from the vendored route files by
`ModulePaymentFlowsTest::test_the_entitlement_gate_flag_matches_the_vendored_route_files`, in both
directions, so a module update that moves a writer in or out of such a group reddens the build.

**Why the suite was green:** every case drove a synthetic `['web']` route and called the guard
directly, so nothing in it could see middleware ordering at all.

### 4.5.2 A payment could be completed in a different store

The reference said *whose* payment it was and nothing about *where*. One session could start a
donation at store A and, after the switch-off, present the same session at store B — and the
vendored handler writes the row into whichever store the slug names. The completion now has to
happen in the store the initiation recorded, which compares the request against the server's own
record rather than against another value the caller chose.

**And the tests could not have caught it**, which is the part worth keeping: they sent requests to
`/my-store/...` with no such store row, so `storeIdOf()` resolved NULL for every request in the file
and every store-shaped assertion in it was vacuous. The fixture now creates two real stores.

### 4.5.3 With no record installed, one anonymous request still recorded a donation

`CustomerDonationController::StoreDonationPayment` builds its record from the REQUEST when the
session is empty. So on an installation without `module_payment_initiations` — which is every
production installation until the owner promotes it — a single anonymous GET to a disabled module's
return URL still wrote a `customer_donations` row. That is the *new transaction through a disabled
application* the owner's sentence forbids, in exactly the environment the document called inert.

Closed without a migration: that path now requires the **vendored** flow key
(`donation_request_data` and its two siblings), the key each module's own service writes when a
payment is initiated. A disabled module cannot initiate one, so nothing can newly obtain it and an
in-flight payer has it. Weaker than the reference — not single-use, not store-bound — and the
strongest thing available with no table. `LotteryTicketWriteGuard` already makes this argument for
its own handler.

### 4.5.4 Anonymous traffic could suppress and misattribute refusal records

The repeat-dedupe matched on (module, route, payload_hash) and the route is the URI **pattern**,
while the hash covers only `$request->all()`. So one anonymous empty request to any slug's return
URL created the row that every later genuine refusal at every other store merely incremented,
attributed to the first caller's store. The store is now part of what makes a refusal "the same
one".

### 4.5.5 Four smaller corrections, each a claim that was not true

| | |
|---|---|
| the abandon heuristic fired on a **stale** flash | `has('error')` also reads a message flashed by the PREVIOUS request, so a legitimate redirect to a gateway immediately after any error redirect was marked abandoned. Now `_flash.new`, which holds only what this request flashed |
| `deny()` decided shopper-versus-panel by `auth('web')->check()` | CLAUDE.md's rule is that *a `web` login is not a role*: a shopper who also holds a panel login was sent to `/dashboard` from a storefront page they were buying on. The ROUTE decides now |
| the ledger logged driver messages | a `QueryException` renders the SQL with its bindings, and one binding is the reference that authenticates a payer's return. The exception CLASS is logged instead |
| `bootstrap/app.php` said the guard *"reads the ROUTE, not the session"* | no longer true, and the ordering now has two measurable consequences. Corrected in place, with both named |

### 4.5.6 One suggestion taken and then reverted, which is worth recording

The review noted `Schema::hasTable()` runs up to three times per payment request and suggested
memoising it. A per-process memo was written and **broke four tests**: the suite creates this table
per test and drops it in one of them, so every later test believed the table was absent and the
ledger silently did nothing while the assertions read as the code's own behaviour. That is the
false-reading shape CLAUDE.md spends a section on, and a memo the suite must remember to reset is
the same trap with a reset method bolted on. Reverted, with the reasoning in the method.

### 4.5.7 Carried forward, not closed

- **The expired-session case is recorded for Donation only.** `LotteryTicketWriteGuard` (registered
  earlier) and `CampaignIntentOwnershipGuard` redirect a session-less return before this guard is
  reached. Moving this guard ahead of them would widen the record *and* move it ahead of two
  ownership checks, which is a decision rather than a tidy-up.
- **An initiation has no age limit** beyond the session's own 120 minutes, and minting one is free
  while a module is enabled (one row per anonymous POST, valid or not).
- **`POST {storeSlug}/refund-lottery-form-data` stays reachable** for a disabled module and DELETES
  a ticket row. It is not a payment, so the payment rule does not reach it — and the owner's
  *"disabling is not deletion"* bears on it. **For the owner's list.**
- **The one `exit;` gateway's initiation can never be redeemed**, because the session is saved after
  the response and that service never returns. Not a regression — the vendored flow loses its own
  session state the same way — and now said in the docblock rather than implied away.

## 5. Evidence

**8 mutations: 7 CAUGHT, 1 NOT DISTINGUISHABLE — and that one taught something.**

| Mutation | |
|---|---|
| an absent row reads as DISABLED (the LandingPage outage) | CAUGHT |
| nothing is ever disabled | CAUGHT |
| everything is disabled | CAUGHT |
| the guard drops the authentication check (widens to callbacks) | CAUGHT |
| the guard refuses host routes too | CAUGHT |
| the refusal becomes a 401 (the Flutter logout) | CAUGHT |
| `moduleOf` loses its namespace anchor | CAUGHT |
| the flag read becomes strict (`== 0` → `=== 0`) | **SURVIVED — not a gap, see below** |

That survivor is reported as survived rather than quietly dropped. On SQLite the driver returns an
**integer** whichever way the flag was written — measured for all four of `0`, `'0'`, `1`, `'1'` — so
`==` and `===` are indistinguishable in this suite and **no test here can tell them apart**. It also
refuted a claim in my own first draft, which said the codebase writes the flag as both an int and a
string; that was unverified and is not true of what comes back out. The loose form is kept for a
reason the suite cannot reach: production is MySQL, whose PDO driver can hand back a `tinyint(1)` as
the string `"0"`, and `=== 0` would then read a disabled module as **enabled** — a silent failure in
the permissive direction. CLAUDE.md records SQLite's type affinity hiding a real MySQL defect as how
P01-SEC-13 survived.

```
tests/Feature/Modules/   15 passed (38 assertions)
Unit suite               6,166 passed, 1 skipped (37,440 assertions)
Contract suite              45 passed (182 assertions)
```

### 5.1 The payment boundary's own evidence (#204, 2026-11-25)

```
tests/Feature/Modules/           34 passed      (the 15 above plus 19 new boundary cases)
tests/Unit/Modules/               4 passed      the flow table against the vendored route files
tests/Feature/Payments/          32 passed      the reconciliation surface
verify-module-payment-boundary-on-mysql.php    16 checks, 0 failed, on MariaDB 10.11
```

Every refusal case carries its control in the same test — the identical request with one value
changed, reaching the handler — because "nothing was written" is also what a broken fixture
produces.

**THE MySQL HARNESS FOUND A REAL DEFECT AND CHANGED THE CODE, which is the most useful thing in this
section.** Under `utf8mb4_general_ci` — what the server actually reports, not a hypothetical — the
completion claim matched a reference differing only in letter case: the harness spent an initiation
with `strtolower()` of its reference and the claim returned 1. That is the P1-INV-18 shape, and no
SQLite test can see it because SQLite compares binary. Not reachable in practice (the reference is
minted lower-case and lives in the payer's own session) and closed anyway, in two places:
`ModulePaymentLedger::isWellFormedReference()` refuses anything that is not 32 canonical lower-case
hex characters, on every engine, and the column is `utf8mb4_bin` on MySQL.

**And the first draft of that column was wrong in a way the suite caught instantly:** it declared
the collation unconditionally on the claim that *"SQLite ignores the collation name"*. SQLite does
not ignore it — `collate 'utf8mb4_bin'` is `SQLSTATE[HY000]: no such collation sequence` — and every
test touching the table died on it. It is applied per driver, and the claim is corrected in place
rather than deleted.

**Why the decision logic is tested on synthetic routes.** CLAUDE.md records that no WorkDo module
loads in any test run unless the test inserts an `add_on_managers` row — and `route-census.php`'s own
docblock records why loading one *in-process* is worse than useless: `loadAddons()` adds PSR-4
prefixes to the process-global Composer ClassLoader and pollutes every later test in the run (it moved
`ResidualTenancyExposureTest`'s count from 23 to 21 once). So the real-module measurement lives in a
separate process, as the census does, and what the suite asserts is the guard's decision on routes the
test constructs — which is the part that can be wrong in a way nobody notices.
