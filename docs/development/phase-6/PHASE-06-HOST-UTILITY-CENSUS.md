# GAP-179's population had a hole by construction — this is how big

measured 2026-11-26 · `tests/Support/bin/census-host-utility-surfaces.php` · gated by
`tests/Feature/Ui/HostUtilityCensusPopulationTest`

**The short answer for the `D-25` ruling: the two defect classes GAP-179 has already repaired
appear 425 more times on panel-reachable surfaces, and 421 of those are on surfaces GAP-179's
population cannot see.** The remainder of this document is how that number was obtained, what it
does and does not mean, and the three ways the instrument was wrong before it was right.

---

## 1. Why a new population rather than more looking

GAP-179's surface list is derived from one middleware — `route:list --json` filtered on
`App\Http\Middleware\PlatformOperatorOnly`. That is a correct way to find the operator's screens
and a structurally blind way to find anything else. The blindness has now been measured twice:

| surface | its gate | what GAP-179 saw | what was there |
|---|---|---|---|
| `packages/workdo/LynomiaChat` merchant pages | `PlanModuleCheck` + `EnsureStoreOwnsLink` | nothing | `a.nav-link.active` 2.41:1 dark, `.btn-primary` 2.58:1 dark |
| the theme editor | `theme.editor` | nothing (`GAP-113`: *"zero rendered evidence"*) | 14 findings, **12** of them the same two classes at the same two ratios |

**A census whose population comes from one middleware cannot see surfaces behind another.** So the
population is replaced rather than re-walked: every route's **resolved** middleware stack
(`gatherRouteMiddleware`), with all 70 modules loaded through the production loader.

---

## 2. The two populations

| | definition | routes | views attributed |
|---|---|---|---|
| **OLD** (GAP-179) | GET, not `api/`, carries `PlatformOperatorOnly` | **19** | 22 |
| **NEW** | GET, not `api/`, authenticated | **853** | 394 |

3,066 resolved route rows in total (1,920 distinct URIs), 70 modules, 0 load failures.

**The old population is 2.2% of the new one by routes.** GAP-179 states *"37 routes, 19 GET"* for
its own filter; this reproduces the **19** independently, which is what makes the comparison a
measurement rather than a rhetorical one.

### RE-MEASURED 2026-11-27 on the integration candidate — the figures above are kept as the dated original

The table above is the 2026-11-26 measurement and is **not corrected in place**, because every ratio
and estimate in the rest of this document was formed from it. The same script, same command
(`php tests/Support/bin/census-host-utility-surfaces.php`), on `int/checkpoint` at `b2e823561`:

| | definition | routes | views attributed |
|---|---|---|---|
| **OLD** (GAP-179) | GET, not `api/`, carries `PlatformOperatorOnly` | **26** | 31 |
| **NEW** | GET, not `api/`, authenticated | **838** | 408 |

3,018 resolved route rows, 70 modules, 0 load failures. The old population is **3.1%** of the new.
Two already-repaired defect classes: `btn-primary` **421** occurrences (417 outside the old
population), `badge bg-warning` **7** (7 outside), across **243** views (240 outside).

**WHY THE OLD POPULATION GREW 19 → 26, AND IT IS NOT A CENSUS REGRESSION.** Seven operator-gated GET
routes landed between the two measurements, every one a real new operator screen — `kyc.queue`
(`4774ddbc8`), `platform.approvals.index` (`1a722c08e`), `platform.incidents.index` and
`platform.incidents.show` (`7006d474d`), `platform.operations` (`3056c1dae`),
`platform.stores.index` and `platform.stores.show` (`3d72820e6`). Established by `git show
60e7ef589:routes/web.php`, the commit that pinned the 19: all five of their uri prefixes are absent
from it, and 26 − 7 = 19 closes the arithmetic.

**AND THE RATCHET NOW ASSERTS THE MEMBERSHIP RATHER THAN THE NUMBER.**
`HostUtilityCensusPopulationTest` held `assertSame(19, …)` and failed `26 !== 19` — true, not a
regression, and silent about which route moved; worse, nineteen routes swapped for nineteen others
would have passed it. It now declares GAP-179's nineteen and the seven additions by name and
asserts both directions, and the census script emits `old_population_route_names` for it to read.
`new_population_routes` fell 853 → 838 and `routes_total_rows` 3,066 → 3,018 over the same window;
both floors in that test (≥ 800 routes, ≥ 380 views) still hold, and the test's own 5% convergence
bound is satisfied at 3.1%.

### Why 505 of the 853 routes attribute to no view — classified, not left as a residue

| | |
|---|---|
| 293 | renders no view at all (redirect, response, json, or a stub) |
| 151 | names a view whose **file does not exist** — resource-controller scaffolding (`auction-product::show`, `::edit`, `::create` …) |
| 56 | the method is inherited, from a trait, or magic |
| 5 | a closure or invokable controller |

**None of those four is a resolver bug**, and the gate asserts that the one category that *would*
be — "controller file not found" — is absent. So the 394-view figure is honest coverage of 348 of
853 routes, and the occurrence counts below are a **floor**, not a ceiling.

The 151 is worth a second look by somebody: a route whose controller names a view that is not on
disk answers 500, and three more name namespaces no provider registers (`googleauthentication::`,
`signinwithfacebook::` — the registered names are `google-authentication` and
`sign-in-with-facebook`). Not this slice's to fix; recorded because the census walked past it.

---

## 3. The findings, by class

Two classes, both with a repair that is already shipped and already measured:

| class | measured ratio | repair | measured after |
|---|---|---|---|
| `btn-primary` host fill | rgb(4,34,42) on rgb(51,93,168) = **2.58:1** dark | `.ss-btn ss-btn--primary` | 7.92:1 dark / 6.42:1 light |
| `badge bg-warning` | rgb(244,180,26) on rgb(253,244,221) = **1.68:1** light | `.ss-badge ss-badge--warning`, host utility **removed** (`!important`) | 8.83:1 light / 9.81:1 dark |

In the **new** population:

| | occurrences | of which outside the old population |
|---|---|---|
| `btn-primary` | **418** | **414** |
| `badge bg-warning` | **7** | **7** |
| **total** | **425** | **421** |
| views affected | **241** | **238** |

Repo-wide, across all 1,286 Blade files (the superset, including storefront and unreachable
views): **698 `btn-primary` + 10 `bg-warning` = 708 occurrences in 444 views.** So the panel-
reachable share is 425 of 708.

### Shape of it, because 425 is not 425 equal pieces

- **114 host views** carry 224 occurrences; **127 module views** carry 201. It is not a
  vendored-module problem that first-party code is innocent of.
- **50% of all occurrences sit in the top 50 views; 80% in the top 156.** The head is steep:
  `resources/views/plans/stripe.blade.php` alone has **50** — a payment-gateway surface under the
  standing financial-configuration escalation, which GAP-179 slice 2 already declined for that
  reason.
- The 7 warning chips are in 5 views: `LynomiaChat/merchant/overview` (3), `support/edit`,
  `store/plan`, `LotteryPrizes/admin/mail/show`, `email_templates/show`.

---

## 4. What this number is, and what it is not

**It is a source census.** It counts occurrences of two class strings in Blade files a panel GET can
reach. It is **not** a contrast measurement. A class inside a `display:none` block, an inactive tab
pane or an unrendered branch paints nothing — measured in this repository in both directions
(`setting/index.blade.php:283` was converted and produced byte-identical captures). So **425 is an
upper bound on findings and a count of exposure.** The live harnesses
(`measure-superadmin-premium-live.cjs`, `measure-lynomia-merchant-live.cjs`) remain the instrument
for ratios.

### The sharpest illustration is on the surface GAP-179 declared closed

`packages/workdo/LynomiaChat/src/resources/views/superadmin/integration.blade.php` was re-measured
in all four combinations for this workstream: **2 findings, both the excluded `D-25` item.** Its
source carries **two more** `btn btn-sm btn-primary` occurrences:

- `Save` inside `#lynomia-seats` — an **inactive** tab pane
- `Approve` inside `#lynomia-accounts` — an inactive pane, *and* behind
  `@if ($row->state === LinkState::PENDING_VERIFICATION)`

`data-bs-toggle="tab"` paints only the active pane, so neither is reachable by a first-paint
capture; both appear on the first click. This is the same reasoning that made this project wrap all
five tab labels rather than the active one. They are **declared, not repaired** — repairing them
would produce a change this slice's instrument cannot re-measure, and an unmeasured repair reads as
a finished one. The next step for them is a Tier-C capture that clicks the tab first, which
`measure-superadmin-premium-live.cjs` already does for modal fragments.
`HostUtilityCensusPopulationTest` pins the count at exactly 2, so a third appearing fails, and so
does repairing these two without re-reading the note.

---

## 5. What this means for the `D-25` ruling

`D-25` is the owner's decision on the shared chrome — `span.dash-mtext` and the settings tab rail
at 2.58:1, 22 findings across 11 surfaces. The estimate behind it was formed from a 19-route
population.

> **The decision is not 22 findings wide. On the same two already-known classes, a population that
> cannot have this hole finds 425 panel-reachable occurrences in 241 views, 421 of them on surfaces
> the old population cannot see.**

That does not make `D-25` bigger — the sidebar is still the sidebar. It means the *rest* of the
estimate was measured against 2.2% of the reachable surface, and a ruling that treats the shared
chrome as the last remaining concentration would be wrong. Three things follow, all of them the
owner's to weigh rather than a slice's:

1. **The head is where the value is.** 50 of 241 views hold half the occurrences. A sweep ordered
   by that head is a different proposition from "241 views".
2. **The biggest single view is a payment surface.** `plans/stripe.blade.php` (50) is under the
   standing financial-configuration escalation and should not move on a slice's authority.
3. **Exposure is not failure.** 425 is a floor on exposure and an upper bound on findings; turning
   it into a finding count needs the live harness per surface, which is a different and much larger
   job than the census.

---

## 6. Reproducing it

```sh
php tests/Support/bin/census-host-utility-surfaces.php           # human-readable
php tests/Support/bin/census-host-utility-surfaces.php --json    # machine-readable
php artisan test tests/Feature/Ui/HostUtilityCensusPopulationTest.php
```

**Measure with `APP_DEBUG=false`** for anything that renders: with debug on, the PHP Debugbar
injects `span.phpdebugbar-badge` at 3.89:1 and a text walk counts it as product markup — 8 spurious
findings per surface across four combinations.

### Three ways the instrument was wrong before it was right

Recorded because each was silent, and each made the population *smaller* — the same failure mode the
census exists to fix:

1. **Kebab-casing a module directory to get its view namespace.** Right for most, wrong for `Api`
   (`api`) and `HubSpot` (`hub-spot`).
2. **Globbing `src/app/Providers/*`.** `LandingPage`'s provider is at `src/Providers/` and its views
   at `src/Resources/views` (capital R). **97 routes** named `landing-page::` and resolved to
   nothing. Fixed by resolving each `loadViewsFrom(__DIR__ . '…', '…')` against the provider's own
   directory — the same computation Laravel performs, so it cannot drift.
3. **An unexplained residue.** The first run reported "561 routes named no view" out of 853, which
   is unreadable: two resolver bugs were hiding in it. Every unresolved route now carries a
   classified reason and the gate asserts the bug category is empty.

The gate's own bite was checked with six mutations, all caught. **Two of those mutations were wrong
first time and that is worth more than the six:** one broadened the `btn-primary` pattern by
dropping its lookarounds, which changed nothing at all, because `btn-outline-primary` does not
*contain* the substring `btn-primary` — an **inert mutation reads as a surviving one**, and the fix
was to broaden it to `/primary/` so it really did start matching the control that passes. The other
anchored on a string occurring twice and `mutate.py` refused it outright: *"anchor appears 2 times
— NOT APPLIED, proves nothing."*
