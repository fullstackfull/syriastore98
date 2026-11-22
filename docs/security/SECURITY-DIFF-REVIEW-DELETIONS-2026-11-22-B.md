# Deletion audit for the security checklist — the twenty commits to `15fcca9b`

**This is an UNSIGNED engineering draft, not a review.** `.github/SECURITY-DIFF-REVIEW.md` is
titled *"Human review checklist for security-sensitive diffs"* and exists because an audit emitter
was once overwritten by unrelated validation while every test still passed. Adding
`SECURITY-DIFF-REVIEW: completed` to the pull request description is a human signature; an
automated agent adding it would defeat the control rather than satisfy it. So this file answers the
questions and stops there. **No marker is added, and the check stays red for a person to clear.**

It extends `SECURITY-DIFF-REVIEW-DELETIONS-2026-11-22.md`, which was measured at head `39e188ae`
and audited seven deleted lines. Twenty commits have landed since, deleting **149** lines under
`app/` and `resources/`, so that draft no longer covers the head — and the precedent this branch
set is its own: *an unsigned draft that quietly stops covering the head is worse than none, because
it still reads as coverage.* This file closes that window.

**The two audits abut with no gap.** `39e188ae` is an ancestor of this range's base `d60fed3a`
(`git merge-base --is-ancestor`), and the commits between them deleted **0** lines under `app/` or
`resources/` — checked per commit, not only in the net diff.

## Scope

Head `15fcca9b`. Range `d60fed3a..15fcca9b`, twenty commits. Deletions under `app/` and
`resources/`:

| deleted | file |
|---|---|
| 34 | `resources/views/superadmin/dashboard.blade.php` |
| 24 | `app/Http/Controllers/SettingController.php` |
| 20 | `app/Support/Metrics/GrossMerchandiseValue.php` |
| 19 | `app/Http/Controllers/Api/DashboardController.php` |
| 18 | `app/Http/Controllers/HomeController.php` |
| 7 | `app/Support/ModuleRouteManifest.php` |
| 6 | `app/Models/Order.php` |
| 5 | `resources/views/analytics/index.blade.php` |
| 4 | `app/Support/Metrics/PlatformAnalyticsFigures.php` |
| 4 | `app/Http/Controllers/Api/ApiController.php` |
| 2 | `resources/lang/en.json` |
| 2 | `resources/lang/ar.json` |
| 2 | `app/Http/Controllers/OrderController.php` |
| 1 | `app/Services/StoreSettingsAccess.php` |
| 1 | `app/Http/Middleware/ModuleAdministrativeRouteGuard.php` |

**149 lines, 15 files.** 304 across all paths, the remainder being tests and documentation.

**STILL CURRENT AT `e238f444`**, measured rather than assumed, and measured PER COMMIT rather than
only in the range diff: the six commits after `15fcca9b` — `fe715dbe`, `9ab4bddc`, `e05a9c3a`,
`42be2742`, `a1da84c6`, `e238f444` (the reviewer-notes move, P1-SEC-83, P1-SEC-84, P1-SEC-85 and
their queue entries) — add **951 lines and delete ZERO**, in every path and in each commit
separately. So this audit covers the head exactly, and the figures above need no revision. This
line exists so the next reader does not have to recompute it to find that out.

In the paths the checklist flags as sensitive, the range deletes **one** line in total:
`app/Http/Middleware` 1; `app/Policies` 0, `app/Providers` 0, `config/` 0, `routes/` 0,
`database/migrations/` 0, `.github/` 0.

## Method, and a measured bound on the method itself

Eight auditors, one per file group, classified every deleted line as *no behaviour* / *moved* /
*replaced* / *removed* with a `file:line` citation for where the behaviour now lives. Every *moved*
or *replaced* verdict was then handed to a separate agent whose instruction was to **refute** it.
Sixteen agents, **72 claims**, of which **57 survived** and **15 were refuted**.

**A range diff cannot see a line that one commit deleted and a later one re-added**, which is
precisely the shape of the incident this checklist exists for. That is not a hypothetical here: the
per-commit deletion sum is **220** against the combined diff's **149**, so **71** deletions are
invisible to a range-diff audit. They were run down rather than waved past:

- 54 of them are in `app/Http/Middleware/CampaignIntentOwnershipGuard.php` (41) and
  `app/DataTables/TenantScopedCampaignsData.php` (13), and **neither file exists at the base** —
  both are created inside this range and later amended, so no pre-existing line can be involved.
- The remaining 17 are in files that DO exist at the base (`PlatformAnalyticsFigures.php` 12,
  `HomeController.php` 1, the two language files 2 each). For the two code files this was checked
  mechanically rather than argued: every hidden deleted line was compared against the file's blob
  at `d60fed3a`, and **none of them is present there.** Each was introduced by an earlier commit in
  this range and changed by a later one.

So for this range the combined diff is the correct question, and the blind spot is empty. It is
recorded because the next range's may not be.

## Q1 — What does this diff REMOVE or NARROW?

Of the 72 audited claims, **none** identified a pre-existing control that is simply gone. Every
deleted line is *no behaviour*, *moved* or *replaced*, with the replacement cited. The three
largest groups:

- **`superadmin/dashboard.blade.php` (34) and `HomeController.php` (18)** — the operator's
  top-stores panel moved out of the view and into `PlatformAnalyticsFigures::topStoresByOrderValue()`
  and `GrossMerchandiseValue::perStore()`. The deleted `Store::find()` per row, the inline
  `currency_format_with_sym(...)` cell and the `@foreach ($allStores …)` loop all have named
  replacements. The cross-tenant read is now **declared** through a `MetricScope`, which it was not
  before, and `OperatorDashboardTopStoresTest::test_the_cross_tenant_read_is_declared_through_a_metric_scope`
  holds it there.
- **`SettingController.php` (24)** — the four questions the currency screen asked inline (is there
  an active rate row, is a non-USD rate usable, is the currency being left usable, which symbol is
  authoritative) moved into `App\Services\StoreCurrencyChange::prepare()` so that the admin API
  door asks them too. Nothing was dropped; the API door went from asking **none** of them to
  asking all four.
- **`ApiController.php` (4), `OrderController.php` (2), `Api/DashboardController.php` (19),
  `Order.php` (6)** — six `Utility::GetValueByName(...)` reads replaced by
  `OrderCurrencyStamp::codeFor()/symbolFor()`, which is the same read plus a second link.

**`ModuleRouteManifest.php` (7) and `ModuleAdministrativeRouteGuard.php` (1) deserve their own
sentence, because deletions there would be the serious kind.** They are not removals: the six
`campaigns/campaigns-card-payment` keys moved from the rule `payment-callback` to
`guarded:CampaignIntentOwnershipGuard`, a **stricter** rule, and the guard line is a `match (true)`
arm replacing an `if` chain that now dispatches two more rule prefixes. `RouteCensusGateTest` holds both
ends of that: `test_the_gate_fails_on_a_deliberately_unclassified_administrative_route` proves the
census bites on an unclassified administrative route, and
`test_the_guard_is_really_on_every_route_it_is_supposed_to_protect` fails if a named guard stops
running on a route the manifest assigns it to.

## Q2 — Did any file in this diff already exist and do something else?

No path was overwritten. The new first-party files — `CampaignIntentOwnershipGuard`,
`TenantScopedCampaignsData`, `CampaignsTenantFilterScope`, `StoreCurrencyChange`,
`OrderCurrencyStamp` — are all new paths. `TenantScopedCampaignsData` *subclasses* a vendored
class and is bound over it in `AppServiceProvider`; the vendored file is untouched, which is the
P1-SEC-75 precedent.

## Q3 — What existing control assumed the surface you widened was narrow?

One answer here is a real behaviour change and is stated rather than buried. `prepare()` applies
`strtoupper(trim(...))` before its rate lookup, and the web currency screen applies no validation
to that field. Under `utf8mb4_general_ci` — a PAD SPACE collation — trailing spaces were already
insignificant and **leading** ones were not, so a code arriving with leading whitespace previously
missed the store's canonical rate row and was refused. It now matches. The control is unchanged
(an active `currency_rates` row for the store is still required); what moved is the spelling of the
needle. `b2bbd1b6` says so in its own message.

## Q4 — Is any audit, logging or compliance emission removed, skipped, or made conditional?

**Yes — one, and it was found by the adversarial stage, not by the auditors.**
`StoreCurrencyConversionService::convertStoreCurrentValues()` emits
`Log::info('🚀 Starting Store Currency Conversion', [...])` *before* its own same-currency early
return. `StoreCurrencyChange::apply()` now tests `strtoupper(trim(...))` equality and returns
before calling the service at all, so for a pair that is raw-unequal but normalised-equal
(`'SYP '` → `'SYP'`, `'syp'` → `'SYP'` — both reachable, because the old door could store either)
that line is no longer written.

Severity is low and is stated as low rather than dismissed: it is a `Log::info`, and
`docs/security/P01-OBSERVABILITY-INVENTORY.md` carries only the `critical` and `warning` sections
and names no line of this service. But the claim it refuted asserted the fold was *unobservable*,
and this is the incident class the checklist names — an emission that became conditional while the
response stayed 2xx. Recorded, not fixed.

Ten inventoried `OrderController` log lines shifted position by three and were **re-pinned** in
`df148a1f`; none was removed.

## Q5 — Does any failure become silent?

No. The refusals this range moved all still return their original translated message and redirect
or status: `StoreCurrencyChange::prepare()` returns the same four strings the screen used to build
inline, and the API door maps them to 403 (operator) or 422 — previously it refused nothing at all.
`CampaignIntentOwnershipGuard` refuses with a response that a test asserts on, rather than falling
through. Two of the range's own commits exist because a refusal was *not* silent enough to be
noticed: `eed982c3` fixed two ways past my own guard, and `4f800737` fixed my own fix.

## Q6 — Is method-resolution order involved?

**Yes, deliberately, twice, and neither is a prepend.** `TenantScopedCampaignsData` and the
`CampaignsTenantFilterScope` view composer both work by container binding and view-composer
registration, which are explicit and ordered, not by prepending a module path. No module namespace
was prepended and no vendored file was edited. `packages/workdo/` is byte-identical across this
range except where it already was.

## Q7 — Was the change verified by removing it?

Yes, for every control in the range. Counted from the commit messages rather than from memory:

| commit | control | mutations |
|---|---|---|
| `07ce1e41` | the Campaigns list's tenant predicate | 8, all caught |
| `a0a3c89e` | the Campaigns screen's filter lists | 13, all caught |
| `e4e2f9b3` | the campaign writer / intent guard | 16, **13 caught, 3 survivors recorded** |
| `eed982c3` | the two bypasses found by review | 7, all caught |
| `20536d0c` | the list's cell escaping | 7, all caught |
| `b2bbd1b6` | the currency writer | 3, all caught |
| `15fcca9b` | the symbol fold | 6, all caught |

**61 mutations, 58 caught, 3 recorded as survivors rather than quietly re-filed** — one as
equivalent, and one whose docblock was corrected after the run disproved what it claimed. One
review finding was **refuted on reachability** and pinned in both directions rather than dropped.

## Q8 — Does the merge-base regression gate report zero new failures?

On `a3cf6590`, required CI run **45047193729 (#590)** concluded `success` — seven jobs including
Tests ×2 with the Feature outcome-set assertion and the merge-base regression gate. The two
commits after it (`b2bbd1b6`, `15fcca9b`) have not yet had a required run; local evidence on
`15fcca9b` is Unit 6,028 passed with the one declared skip, plus 202 Feature tests across every
reader of the changed metric class and 284 across the currency writers. **That is local evidence,
not the gate**, and this answer will be wrong until a required run concludes on this head.

## What the adversarial stage actually produced

The refuters were pointed at the *deleted* lines. Fourteen of the fifteen refutations are about the
branch's **own new code** instead — which is the useful outcome, and worth stating plainly rather
than scored as a miss.

| refutation | disposition |
|---|---|
| A recorded currency **symbol** is taken for a currency, so a store appears twice with partial subtotals under a `$` heading | **CONFIRMED and FIXED** — `15fcca9b`, `CurrencySymbol::code()`. The refuters overstated it (they had a single-currency store splitting *in general*, which it does not, because the per-store sum folds buckets that normalise alike); the symbol case they built it on is real and is the measured production condition (D-N34) |
| The web currency door stores the selection, not the normalised decision, so wallet and setting diverge | **CONFIRMED and FIXED** — `b2bbd1b6`. Their framing was that a refusal stopped firing; the larger finding underneath it was the divergent write |
| The top-stores panel lost its SQL `limit(5)` and now materialises every (store, currency) bucket, plus three unbounded reads | **CONFIRMED, RECORDED** — a resource regression, not a correctness or security one. A *global* cap would be wrong, because amounts in different currencies are not comparable; the correct design is one query per currency. Not rushed at the end of a session |
| A `Log::info` became conditional | **CONFIRMED, RECORDED** — Q4 above |
| The panel resolves a duplicate settings row to the **oldest**, disagreeing with the product convention | **PARTLY REFUTED** — `ReportController` really does use `->value('value')` (no `ORDER BY`, so the oldest), which is what the code's comment cites, so "disagrees with every other reader" is false. But `Utility::GetValueByName` takes the **newest**, and that is the reader that stamps `orders.currency_code`. The product holds **both** conventions; a three-way divergence that predates this branch, recorded rather than resolved by moving one panel between two defensible choices |
| `OrderCurrencyStamp`'s new second link reads settings rows an unprivileged `web` principal can write, through two vendored module handlers with no `StoreSettingsAccess` check | **REFUTED BY MEASUREMENT.** Both routes are in `ModuleRouteManifest` with the rule `settings:store` and the eight sibling resource routes carry `auth`, so the first-party `ModuleAdministrativeRouteGuard` runs in the `web` group **in front of** the vendored handler — the module's own `['web']`-only declaration is not what governs. And every name they named (`defult_currancy`, `defult_currancy_symbol`, `currency_name`, `currency_symbol`, `currency`) is in `StoreSettingsAccess::RESTRICTED_EXACT`; `ModuleSettingsWriterGuardTest` asserts an anonymous caller writes nothing, staff without the permission write nothing, and a restricted name refuses the **whole** post. 48 tests green. They read the vendored route file and missed the interception — the mirror image of the trap `CLAUDE.md` records |
| The six `GetValueByName(...) ?: 'USD'` → `OrderCurrencyStamp` replacements are not all covered by the claim's "except" clause | **ACCEPTED as a wording defect, not a behaviour one.** One finding reported six times: the auditor's except-clause named the code column and not the symbol, and not all six sites. The replacement's behaviour is unchanged except for `?:` → `??` on an empty value, which `OrderCurrencyStamp`'s own docblock already states |

## Not fixed, and not claimed

- `MetricResult::make()` keys a money bucket by the raw `currency_code`, so the **merchant**-side
  figures still see a symbol as a second currency — which adds `MIXED_CURRENCY` and makes
  `total()` throw rather than return a number. Wider blast radius than this slice, predates this
  branch, and `assumingUnknownCurrencyIs()` folds only the null bucket. Its own change.
- The unbounded operator-dashboard reads, above.
- The settings-row reader divergence (oldest vs newest), above.
