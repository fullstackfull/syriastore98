# Phase 06 — switching an application off removed nothing

**Status: PARTIALLY FIXED, and the remainder is an owner decision, not an omission.** Written after
the patch, because this repository is public.

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

## 4. What is deliberately NOT covered, and why

Only routes carrying their own authentication: **1,012 of the 1,464**. An anonymous request passes
through untouched, and that is the only part of this boundary engineering may settle alone.
`ModuleRouteManifest::ANONYMOUS_BY_DESIGN`'s own classification, counted:

| reason | routes |
|---|---|
| storefront | 100 |
| **payment-callback** | **87** |
| guarded (another host guard owns it) | 22 |
| oauth | 16 |
| inert | 8 |
| own-credential | 6 |
| webhook | 2 |
| owner-exemption | 2 |

**Refusing a disabled module's payment callback can lose a captured payment.** If an application is
switched off while a transaction is in flight, the gateway's return or notify URL is how the capture
gets recorded. Whether a switched-off application still records money already taken is a settlement
rule, and the directive reserves those for the owner. The storefront question has the same shape.

`test_an_anonymous_route_of_a_disabled_module_is_not_refused_pending_an_owner_decision` **pins the
current bounded behaviour**, so widening it is a decision somebody makes on purpose rather than a
side effect — and the day it is answered, the test that must change says why.

The owner has the counts and the question. **This requirement is PARTIAL by design and is recorded as
such**, not as complete.

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

**Why the decision logic is tested on synthetic routes.** CLAUDE.md records that no WorkDo module
loads in any test run unless the test inserts an `add_on_managers` row — and `route-census.php`'s own
docblock records why loading one *in-process* is worse than useless: `loadAddons()` adds PSR-4
prefixes to the process-global Composer ClassLoader and pollutes every later test in the run (it moved
`ResidualTenancyExposureTest`'s count from 23 to 21 once). So the real-module measurement lives in a
separate process, as the census does, and what the suite asserts is the guard's decision on routes the
test constructs — which is the part that can be wrong in a way nobody notices.
