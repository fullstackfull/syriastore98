# Security-review summary for PR #130 (Phase 2.5, merchant web)

Owner decision **D-42.5**, 2026-11-04: *"Prepare a focused security-review summary for PR #130,
identifying permission, tenancy, money and API risks, with the tests and evidence for each. Keep
the human checklist honest. Its acknowledgement must follow genuine human review."*

> **THIS DOCUMENT IS NOT AN ACKNOWLEDGEMENT AND DOES NOT SATISFY ANY GATE.** It is the material a
> human reviewer needs in order to work `.github/SECURITY-DIFF-REVIEW.md` themselves. The
> acknowledgement line is the reviewer's act. `flag-sensitive-diff.sh` records why that matters:
> writing PR #125's description, an agent added a warning that the checklist was *not* acknowledged
> and **quoted the marker inside that warning — and the quote satisfied the gate.** Three check runs
> on one commit, prose the only variable, filed as **P1-SEC-53**. The same header notes GitHub
> records no distinction between a line the owner typed and one an automation generated, since both
> act as the same login here.

Branch `claude/busy-edison-k0pjwa` against merge base `53971d4e`.

---

## 1. API risk — **none, and this is the strongest claim in the document**

Not "reviewed and judged safe": **the contract surface is not in the diff at all.**

| probe | result |
|---|---|
| `git diff --name-only 53971d4e..HEAD -- app/Http/Controllers/Api/` | **0 files** |
| `git diff --name-only 53971d4e..HEAD -- tests/Contract/` | **0 files** |
| `git diff --name-only 53971d4e..HEAD -- routes/api.php` | **0 files** |
| `routes/web.php` changes | **3 lines, all inside comments** — stale view line-number references. No route, middleware, name or method changed. |

Consequences worth stating because they are the ones that would bite:

* **121 of the 213 `messages->first()` refusal sites are under `Api/` and were deliberately left
  alone.** Changing an API error payload is a public-API compatibility change, which is owner
  escalation only. They remain exactly as they were.
* **No new HTTP 401 anywhere.** `ApiResponser::error()` returns HTTP 200 with `{status: 0, ...}` by
  default, and the shipped Flutter app **forces a logout on any 401** — so a new 401 on a path a
  client calls is a user-visible regression. Nothing in this diff introduces one; every refusal
  added is a `redirect()->back()->with('error', ...)` on a web route.
* `Contract` suite: **27 passed (92 assertions)** on the final tree, and CI's `Cross-repo API
  contract — the shipped Flutter app must not be broken` step passed on head `7227d223`.

---

## 2. Tenancy risk — one real disclosure closed, one exemption deliberately preserved

### Closed: the report chart named any tenant's categories and brands

`ReportController::order_category_reports` and `order_brand_reports` took their selection straight
off the query string and resolved each id with a bare `Category::find()` / `ProductBrand::find()` —
nine input points across the two handlers. **Neither model filters by tenant:** `Category`
registers `App\Scopes\StoreScope` and carries `BelongsToStore` but is **absent from
`config/tenancy.php`'s `scoped_models`**, so `StoreScope::isActiveFor()` short-circuits and the
scope adds **no predicate for any principal**; `ProductBrand` registers no global scope at all.
Verified: `scoped_models` has 11 entries, `Customer` is in it, `Category`, `ProductBrand` and
`Product` are not.

**Measured live before the fix:** `selectedBrand[]=3` returned another store's brand name, and
`selectedCategory[]=999999` returned `[]` while a real foreign id returned a series — an
**existence oracle** on top of the name itself.

**What the risk was NOT, and the diff says so rather than letting the worse reading stand:** the
money was never reachable. Every period branch keeps its own
`Order::where('store_id', getCurrentStore())`, which is why this is a **name disclosure, not a data
breach**. The new predicate reads the *same* `getCurrentStore()`, so the two cannot drift apart.

| evidence | |
|---|---|
| test | `tests/Feature/Security/ReportCategoryBrandTenantScopeTest.php` — **57 tests / 528 assertions** |
| bite | neutralising **only** the guard gives **31 red**; restoring greens them |
| route gating | `ReportAuthorizationTest::test_every_routed_report_action_is_mapped_and_gated()` walks the whole route table and asserts `ReportPermission::class` is in `gatherRouteMiddleware()` for **every** `ReportController` route — so both endpoints are gated, proved by census rather than by an enumerated list |

### Preserved deliberately: the platform operator's exemption

The guard exempts the operator with the **exact-case** comparison
`$actor->type === UserPolicy::PLATFORM_ADMIN`, character-for-character the predicate
`CategoryController::categoryIsOutsideCurrentStore()` already uses. It **fails safe**: a
non-canonical `'Super Admin'` is treated as a merchant and narrowed, never exempted — and a test
drives that case. Narrowing the operator would be an **authorization-policy change**, so it was
escalated, not taken.

### Return-type behaviour a reviewer should check deliberately

An empty selection returns **unchanged**, so each caller's own `empty()` default still fires. A
selection that survives nothing returns an **empty Collection** — and `empty()` on an object is
always false in PHP, which is what preserves today's "empty chart" rather than silently falling
back to the caller's whole catalogue. Returning `[]` would have inverted that.

---

## 3. Permission risk — four handlers hardened, three findings escalated

### Hardened: four finance handlers did not refuse a storeless principal

`index()`, `requestWithdrawal()`, `withdrawals()`, `updateFinancialPaymentStatus()`; six siblings
already did.

**This is hardening, not an exposure, and the distinction is measured.** `store_id` is **NOT NULL**
in `store_wallets`, `store_withdrawals` and `store_order_financials` — the measured production
structure in `App\Support\Database\GateFProductionSchema::DDL` — so `where('store_id', null)`
compiles to `store_id IS NULL` and matches **no row in any tenant**. There is no shared null-store
wallet to credit and the matching INSERT cannot succeed. **Nothing was disclosed and no money could
move.**

What it really was: a **500** on the finance dashboard; a raw `ModelNotFoundException` naming
`App\Models\StoreWallet` **to the user**; an **empty money list** on the withdrawals screen; and a
refusal that blamed the *record* for a condition of the *session*.

| evidence | |
|---|---|
| test | `tests/Feature/Security/FinanceNullStoreGuardTest.php` — **11 tests / 129 assertions**, including a NOT-NULL premise read from `GateFProductionSchema::DDL`, an assertion that **no API route reaches any of the four handlers**, and four positive controls |
| bite | neutralising the four guards gives **5 red** |
| chain termination | a test reddens if any finance refusal closes a redirect loop — `index()` cannot use `back()`, because Laravel 11's `StartSession::storeCurrentUrl()` records the previous URL on a **redirecting GET**, so for a merchant whose last page was `/finance` it returns `/finance` forever |

### Flashed-input hardening

`UserController::editprofile` now excludes `'type'` from its `withInput($request->except([...]))`
list, matching `password_update`. **Latent, not live:** `editprofile` persists only
name/email/mobile/profile_image by explicit assignment, so this closes a trap rather than an
escalation. A negative control reads `users.type` **DB-direct** rather than through the model.

A separate review concern here was **refuted by measurement** and the refutation is recorded: a
credential was *not* reaching `_old_input`. `App\Http\Middleware\FlashedCredentialScrubber`
(registered at `bootstrap/app.php:54`) is the actual control, not any controller's `except()` list —
proved by a bite that removed the middleware, after which `mail_password` really did reach
`_old_input`.

### Escalated, NOT fixed — each needs the owner

1. **`SettingController::customMassage` has no permission check at all.** It writes `settings` rows,
   and its only live route is `routes/customer.php:188`, inside a group carrying just
   `[ActiveTheme, 'themelanguage']` — **no auth, no permission** — while every sibling in the file
   is gated on `StoreSettingsAccess::permits()`. Not an *anonymous* write only because
   `Auth::user()->id` at `:1289` throws before `updateOrCreate` reaches the database. **Live
   exposure: any authenticated `web` user, including a staff member with no Settings permission, can
   overwrite their own store's WhatsApp template.** Same-tenant, authenticated, no permission check.
   Pinned by a test asserting both the untouched site and the still-ungated route, so adding a gate
   reddens it. **Now authorized as D-42.2 and in progress.**
2. **The operator's implicit store 1.** `getCurrentStore()` returns the literal `1` for a
   `'super admin'` whose `current_store` is empty, so the four new `empty($storeId)` guards do not
   catch the operator — `empty(1)` is false. **Now authorized as D-42.7 and in progress.**
3. **Reported, not repaired:** `/dashboard` and every merchant screen 500 for a storeless principal,
   because `partision/header.blade.php` hands the null store to `HomeController::getThemeUrl()`,
   which reads `$store->slug` at `:610`/`:612`. The declared-failure note for `ProfileTest` names
   `:367`/`:369`, which have drifted.

---

## 4. Money risk — **no calculation, balance or settlement path was changed**

Every money-adjacent change on this branch is **presentation or refusal**, never arithmetic.

| change | why it cannot move money |
|---|---|
| ledger money tones (`finance/index.blade.php`, `store-syria-ds.css`) | CSS classes and a `<span>` wrapper. The figures themselves are the same `number_format(...)` calls. |
| the four finance guards | they refuse **before** the write, on a condition under which the write was already impossible (NOT NULL) |
| the report chart narrowing | the money predicate is untouched; only which *names* resolve changed |
| 34 validation-message joins | the flash string only. A driven **positive control on every repaired screen** asserts a valid post still **succeeds**, so acceptance is unchanged. |

Corroborating probes:

* `0 Log::` lines added or removed anywhere in `app/` — no audit emission was removed, added or made
  conditional. `resync-observability-inventory.py` reports **217 source == 217 documented**.
* CI's `Guard - GATE F money tables are still created by nothing` passed on head `7227d223`.
* `packages/workdo/` is **empty in the diff**; CI's own `Guard - packages/workdo/ is unmodified`
  asserts it independently.

**One money defect is known and deliberately NOT in this PR:** `array_map('intval', ...)` truncates
displayed money in the chart callers. It is a **display** truncation, confirmed independently on two
period windows, and it is now authorized separately as **D-42.3** rather than folded in here.

---

## 5. What a reviewer should check that this document cannot settle for them

1. **Whether the operator exemptions are the policy you want.** Three of them are preserved
   deliberately — the report-chart exemption, `getCurrentStore()`'s store-1 fallback (now D-42.7),
   and `unreconciledCaptures`' asymmetry. Each is a *decision*, not an oversight, and each is
   reversible.
2. **Whether `CurrencyController`'s two sites belong here at all.** They are a platform-operator
   surface; the owner allowed them as a narrow exception under **D-42.4**, recorded in the decision
   register with the measurement.
3. **Whether the 43 single-field validation sites should also carry the expression.** They were left
   because the join is byte-identical to `first()` there — correct today, and the expression is the
   one that stays correct when a rule is added.
4. **The two declared Feature failures.** Both are real `app/`-owned defects that predate this
   branch (`RegistrationTest::test_new_users_can_register`, `ProfileTest::test_profile_page_is_displayed`),
   and the known-outcome gate asserts the set in **both** directions, so an unexpectedly-passing
   declared failure would fail it too.

## 6. Gate status, stated honestly

| check on `7227d223` | |
|---|---|
| `Tests (REQUIRED — PayHere excluded)` incl. the known-outcome gate | **success** |
| `Static checks`, `Secret scan`, PayHere report | **success** |
| `Security-sensitive diff: human checklist` | **failure — awaiting genuine human review. Not to be satisfied by automation.** |
| `Merge-base regression gate` | **failure — cannot build its baseline.** `cp -al` across `/tmp` → `/home/runner/work`, now different filesystems. Same job passed 2026-11-03 with that step taking 12m22s; it now takes 4s. Branch touches nothing under `.github/`. Authorized separately as **D-42.1**. |

Local, on the final tree: Unit **1077**, Contract **27**, Feature **7876 passed / 2 declared
failures / 1 declared skip** (41418 assertions), known-outcome gate **passes** — and the growth
**7806 → 7876 is +70 exactly**, which is 56 + 14, the new cases and nothing else.
