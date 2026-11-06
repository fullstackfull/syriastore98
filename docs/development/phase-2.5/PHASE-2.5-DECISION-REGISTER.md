# Phase 2.5 — decision register

Continues the single decision sequence of the **Master Product Plan / Decision Register v1.0
(2026-10-14)**, which ran to `D-38` in
[`../phase-2/PHASE-02-DECISION-REGISTER.md`](../phase-2/PHASE-02-DECISION-REGISTER.md).
**`D-01`…`D-38` are historical and are not altered by anything here.** Phase 2.5 decisions begin
at `D-39` because the identifier sequence is the product's, not a phase's.

---

## D-39 — **PHASE 2.5 IMPLEMENTATION IS AUTHORIZED AND STARTED; DEPLOYMENT IS NOT** (owner, 2026-11-03)

### The decision

The owner authorized the **start and implementation** of **Phase 2.5 — Premium Merchant Experience
& Visual Excellence**, an approved additional stage between Phase 02 and Phase 03, **without
renumbering or modifying** the original six-phase Master Product Plan.

Verbatim, the operative parts:

> "I formally AUTHORIZE the START and IMPLEMENTATION of PHASE 2.5 — PREMIUM MERCHANT EXPERIENCE &
> VISUAL EXCELLENCE. This is an approved additional stage between Phase 02 and Phase 03, without
> renumbering or modifying the original six-phase Master Product Plan."

> "This new authorization supersedes the earlier 'Phase 2.5 NOT AUTHORISED / NOT STARTED'
> restriction **for starting Phase 2.5 only**. It does not authorize deployment or production
> release."

> "START IMPLEMENTATION. DO NOT STOP AT PLANNING OR AN AUDIT REPORT."

> "Do not automatically merge Phase 2.5 PRs to `main` or claim the phase is closed without an
> explicit subsequent integration decision from the owner."

### What this supersedes, exactly, and what it does not

[`../phase-2/PHASE-2.5-HANDOFF.md`](../phase-2/PHASE-2.5-HANDOFF.md) §7 records
`PHASE 2.5 AUTHORISED: NO` / `STARTED: NO`, and `D-34` records
`NEXT PHASE IMPLEMENTATION AUTHORIZED: NO`. **Both are superseded for the single question of
whether Phase 2.5 development may begin. Neither is edited** — a historical record of what was
true on 2026-11-02 stays true about 2026-11-02, and this entry is what changes it going forward.

`D-38` closed Phase 02 **for development** and named Phase 2.5 "the approved next stage, PREPARED
AND NOT STARTED". That sentence is now spent; nothing else in `D-38` is.

### The five open questions the handoff left, as the authorization answers them

`PHASE-2.5-HANDOFF.md` §5 asked five questions rather than assume the answers. Four are answered by
the directive and the fifth is answered by this register:

| # | question | answer |
|---|---|---|
| 1 | Which unbuilt Phase 02 rows move into Phase 2.5? | **Visual polish, usability corrections and accessible presentation only.** Unbuilt *functionality* — Kanban behaviour, saved-view persistence — stays a Phase 02 row under its original identifier and must not be reported as Phase 2.5 work. The owner directed this explicitly. |
| 2 | Before or after production release? | **Before.** `RELEASE READY` is **NO** and `DEPLOYED` is **NO**, unchanged. Phase 2.5 runs on an undeployed base. |
| 3 | Which blockers must clear first? | **None, for development.** #120 stays OWNER-DEFERRED/OPEN and blocks any work against real customer data — no fixture here uses any. Blocker #6 (no Android SDK, keystore, device or emulator) still bounds what a Flutter slice can prove on device; it is reported, not worked around. |
| 4 | Is `F126` Phase 2.5 scope? | **Yes**, and the owner named it as the priority item. Carried as `P2.5-A-01`, cross-referenced to the Phase 02 `F126` row, which is not edited. |
| 5 | Are the design skills in play? | **Yes** — `frontend-design`, `ui-ux-pro-max`, `design-system` and `ui-styling` are mandatory for this phase and each must be actively applied and documented. The egress note in the handoff §1 applies and the host ratchet still governs. |

### Status this decision does NOT change

`RELEASE READY` **NO** · `DEPLOYED` **NO** · #120 **OWNER-DEFERRED / OPEN** · **P1-SEC-43 OPEN and
DEPLOYMENT-BLOCKING** · P1-SEC-50, -53, -54, -57 **OPEN** · Paytm **DISABLED** · no production
migration, deployment, credential or provider change · GitHub protection **UNCHANGED** · no merge
to `main`.

**`D-38`'s owner-accepted human-checklist exception does not transfer to Phase 2.5.** It was bounded
to that integration by its own terms. Any Phase 2.5 integration needs its own authorization.

### Baselines this phase starts from, verified rather than assumed

| | | |
|---|---|---|
| Laravel `main` | `53971d4e12fd23b05f809e9b86e14fd001f298c5` | matches the directive; `git rev-parse origin/main` re-read 2026-11-03 |
| Flutter `main` | `94cdcdb0adf01550cfda6530ff551e943f5e1579` | matches the directive; re-read the same way |

The directive named `25cf8182` nowhere and `53971d4e` as the Laravel baseline; `D-38` named
`25cf8182` as the integrated head. **Both are right**: `53971d4e` is the merge of PR #129, which
landed the closure records after `D-38` was written. Checked rather than treated as a conflict.

---

## D-40 — **REPAIR EXISTING BROKEN MERCHANT FUNCTIONALITY, WITHIN BOUNDARIES** (owner, 2026-11-03)

### The decision

> "APPROVED, within the following boundaries. You may repair confirmed defects in existing
> merchant-facing functionality discovered during Phase 2.5, when those defects prevent normal use,
> visual verification, or accessibility."

Named explicitly: `/product-list` returning HTTP 500; the Kanban **Move** action not performing its
intended operation; the customer **Apply** filter control not functioning; the Flutter `iconData`
null-handling / runtime crash.

> "These are repairs to existing behavior, NOT authorization to invent new features."

### The conditions, each of which is a gate on a repair landing

Reproduce the defect before fixing it · confirm the existing intended behaviour · identify the root
cause · preserve tenant isolation and authorization · **do not change business rules, financial
calculations, or order transition rules** · preserve existing routes and Flutter API compatibility ·
prefer backward-compatible repairs · add regression tests **with positive and negative controls** ·
run the relevant existing security and contract tests · use the `tenant-authz-reviewer` for anything
touching authorization, tenancy or order operations · **record each repair separately as a
post-closure Phase 02 defect discovered during Phase 2.5**.

For Kanban specifically: *"repair only the existing authorized action and UI integration. If
restoring it requires changing order state-transition semantics, stop that specific change and
report the owner decision required."*

### And a condition on the inventory itself

> "The 19 blocker findings in the inventory are NOT automatically accepted as verified defects.
> Finish the adversarial verification. Refuted findings must not become implementation tasks."

### What this does NOT authorize

Reopening Phase 02 as a development phase, or absorbing unfinished feature development into
Phase 2.5.

### How it is being honoured

| repair | status | evidence |
|---|---|---|
| `/product-list` 500 | **done** — `P2.5-R-01` | reproduced as `compact(): Undefined variable $filterCategories`; 7 tests; 3 bites; rendered 200 in 4 combinations |
| customer **Apply** | **done** — `P2.5-R-02` | reproduced in a browser (0 requests on click); GET-form repair, no server change; 13 tests; 18 live checks in two directions |
| the sixth filter option | **done** — `P2.5-R-03` | found by the tenant-authz review of R-02; `'Last active'` vs `'Last Active'`; 2 bites; driven live |
| Flutter `iconData` | **done** — `P2.5-R-04` | 9 tests reproduced "Null check operator used on a null value"; repaired backward-compatibly |
| Kanban **Move** | **open** | not yet reproduced |

---

## D-41 — **ONE BRAND ACROSS WEB AND FLUTTER, TAKEN FROM THE REAL LOGO** (owner, 2026-11-03)

### The decision, and its correction the same day

The owner first approved a single cross-platform identity built on the **existing Laravel design
tokens**, naming `#097465` as the primary light-theme brand reference. Within the same session the
owner **corrected that reference** after supplying the actual artwork:

> "IMPORTANT: The logo is primarily a BLUE-TO-CYAN gradient, not a green-teal identity.
> Approximate sampled colors: Brand blue: `#335DA8`. Brand cyan: `#0EAED1`. Black wordmark and
> neutral white backgrounds. The previous instruction to adopt `#097465` as the definitive
> cross-platform brand color must be reconsidered against this actual logo. Use the attached logo
> as the visual source of truth."

> "Audit existing Laravel and Flutter brand tokens, then establish one cohesive identity based on
> the real logo. Do not blindly replace colors. Preserve accessibility, semantic status colors,
> dark-mode contrast and existing functionality. Apply the unified branding gradually across
> Laravel and Flutter, with rendered visual verification. **Do not redesign or replace the logo
> itself.**"

**`#097465` is therefore superseded as the brand reference** and `D-41` is the operative decision.
The earlier wording is preserved above rather than deleted, because the correction is part of the
record.

### What the audit found, which inverted the expected direction of work

Measured, not assumed — the method and the numbers are in
[`PHASE-2.5-BRAND-AUDIT.md`](PHASE-2.5-BRAND-AUDIT.md):

| | hue | inside the logo's 189.4–218.9° gradient? |
|---|---|---|
| web `--ss-brand` `#097465` | 171.6° | **no**, 17.8° outside |
| web host chrome `theme-3` `#10B77F` | 159.9° | **no**, 29.5° outside |
| Flutter `LightThemeColor.cBlue` `#2C57A2` | 218.1° | **yes** — 0.4° from the owner's blue |
| Flutter `LightThemeColor.cAccent` `#00B4D8` | 190.0° | **yes** — 0.8° from the owner's cyan |
| Flutter `LightThemeColor.cPrimary` `#172C4E` | 217.1° | **yes** |
| Flutter `DarkThemeColor.cPrimary` `#10AFC0` | 185.8° | no, 3.6° outside — the app's only off-brand colour |

**The Flutter app was already on the logo's hues; the web design system was the one that had
drifted.** It had been derived — honestly, and documented as such — from
`storage/app/public/uploads/2/*logoStore.svg`, which is an upload under **store id 2**: a tenant's
own teal-green recolour of the same mark, measured at 167.2–197.5°.

### How it is being honoured

| | status | evidence |
|---|---|---|
| web token layer onto the logo's hues | **done** — `P2.5-BR-01` | 58/58 contrast pairs; 8 tests / 131 assertions, two of which decode the committed artwork; 3 bites |
| web host chrome default | **done** — `P2.5-BR-02` | `custom-color` + `StoreSyriaBrand::CHROME_DEFAULT`; rendered in 4 combinations; off-brand scanner clean on `/dashboard` |
| Flutter token centralisation | **done** — `P2.5-BR-03` | `StoreSyriaBrand` mirrors the Laravel constants; 13 tests; the one off-brand colour corrected |
| the 293 hardcoded literals | **deliberately untouched** | the owner's "avoid a blind global replacement"; they are in the right hue family already |

### Status this decision does NOT change

Everything listed under `D-39` stands unchanged: `RELEASE READY` **NO**, `DEPLOYED` **NO**, #120
**OWNER-DEFERRED / OPEN**, P1-SEC-43 **OPEN and DEPLOYMENT-BLOCKING**, Paytm **DISABLED**, no merge
to `main`.

---

## D-42 — **SEVEN FOLLOW-UP REPAIRS AUTHORIZED FOR DEVELOPMENT ONLY** (owner, 2026-11-04)

The owner's follow-up decisions, taken after reading the Phase 2.5 evidence and the two pull
requests. **Development only.** `RELEASE READY` stays **NO**, `DEPLOYED` stays **NO**, and no
production deployment, database migration, payment activation, secret rotation, security-gate
bypass or automatic merge is authorized.

| | decision | scope the owner set |
|---|---|---|
| **D-42.1** | CI merge-base regression gate | **APPROVED**, as a **separate small PR**, kept out of #130. Verify hard-link support rather than assume it; a correct file-copy fallback otherwise; never resolve PHP paths back into the HEAD checkout; **prove it on real GitHub Actions**. "Do not hide errors, skip the baseline, weaken assertions or force a successful exit." |
| **D-42.2** | the ungated settings writer | **APPROVED**, using the **existing** merchant settings policy. No new permission model, no WorkDo behaviour change. Also *investigate and report* `WebhookController` and `PixelFieldsController` without silently widening authorization. |
| **D-42.3** | money display accuracy | **APPROVED** for **display only**. Explicitly not stored amounts, calculations, wallet balances, settlement, payments or conversion rates. Faithful representation, not silent rounding. |
| **D-42.4** | `CurrencyController` | **APPROVED AS A NARROW EXCEPTION ONLY** — see below. |
| **D-42.5** | the security checklist | **NOT a bypass.** "Do NOT create or simulate a human security acknowledgement." A focused security-review summary is required instead; the acknowledgement must follow genuine human review. No merge of #130, #7 or the CI PR without a later explicit integration authorization. |
| **D-42.6** | Flutter `commonToast` | **APPROVED** as an isolated, backward-compatible repair of the **shared mechanism**, not 275 call sites. Reproduce on the real GetX 4.7.3 / Flutter 3.47.7 shape, with **no artificial outer Overlay** masking it. Correct the test that treated the failure as expected **without weakening assertions**. Report any device-verification limitation explicitly. |
| **D-42.7** | the operator's implicit store 1 | **APPROVED** as a narrow financial safety guard: fail closed on the identified financial write paths when no explicit merchant selection exists. Do **not** globally rewrite `getCurrentStore()`. **Owner's own stop condition:** if an explicit choice cannot be reliably distinguished from the fallback, stop and report the blocker. |

### D-42.4 in full, because it is the one that closes rather than opens work

The owner: *"Keep the two already verified CurrencyController validation-message corrections **if
they are strictly presentation-only** and preserve existing Super Admin permissions and behavior.
Record them as a limited post-closure defect correction, not as authorization to begin Phase 06.
No additional Super Admin redesign, currency policy change or feature development is authorized."*

**The condition is met, and it is measured rather than asserted.** The entire change to
`app/Http/Controllers/CurrencyController.php` across this branch is:

```
 app/Http/Controllers/CurrencyController.php | 4 ++--
```

two lines, each the same flash-message expression, at `:51` in `store()` and `:104` in `update()`:

```php
-  return redirect()->back()->with('error', $messages->first());
+  return redirect()->back()->with('error', implode(' ', $messages->all()));
```

Counted over the diff, the number of changed lines touching `isAbleTo`, `permits`, `type`,
`super admin`, `store_id`, `Validator::make` or any validation rule is **zero**. The
`isAbleTo('Create currency')` and `isAbleTo('Edit currency')` guards sit **above** the change and
are untouched, as is every rule in both validators. Same flash key, same 302, same session shape.

**Why it was out of surface in the first place, also measured:** `'Create currency'` sits in
`Utility.php`'s `$superadminNewPermission` and **not** in the company list; `index()` additionally
demands `type == 'super admin'`; and the `currency` table has no `store_id`. So currency
administration is a **platform-operator** surface — Phase 06 by the approved plan — and the two
sites were repaired before that was established.

**Recorded, therefore, as a limited post-closure defect correction and nothing more.** It is not
authorization to begin Phase 06, not a currency-policy change, and not a Super Admin redesign. The
two sites are covered by `tests/Feature/Merchant/CommerceSettingsRefusalNamesEveryFieldTest.php`,
driven and individually bitten.

### Status this decision does NOT change

Everything under `D-39` stands: `RELEASE READY` **NO**, `DEPLOYED` **NO**, #120
**OWNER-DEFERRED / OPEN**, P1-SEC-43 **OPEN and DEPLOYMENT-BLOCKING**, Paytm **DISABLED**, no merge
to `main`. Phase 2.5 is **not** complete, and D-42.5 means the human checklist on #130 stays
unacknowledged until a person reviews it.

---

## Findings raised while executing D-42 and the Premium Design packages — for the owner

Recorded 2026-11-04. **None of these is fixed**; each is either outside an authorisation or needs a
decision. They are listed so they are tracked rather than mentioned once in a commit message.

### F-D42-1 — the unselected operator loses the finance dashboard, and lands on a page that 500s

D-42.7 refuses `StoreFinanceController::index()` for an operator who has chosen no merchant, because
the handler lazily `StoreWallet::create()`s for the fallback tenant and "must not create a wallet"
is the decision's first clause. Everything else in that handler is a **read**. The refusal redirects
to `dashboard`, which **answers 500** for that principal (`HomeController:224` writes undeclared
`users.total_user` / `total_orders` / `total_plan`) — measured, and asserted in the test so the
reading is recorded rather than assumed.

A narrower option exists and was **not** taken: skip the lazy create and render the read from an
unsaved `StoreWallet`. That is a larger change than the decision authorises. **Owner call.**

### F-D42-2 — `StoreFinanceController::withdrawals()` still serves store 1's payout details

The one remaining handler in that file whose tenant comes from the fallback. It is a **read**, so it
sits outside decision 7's enumerated write paths and was deliberately left alone — but what it reads
is another merchant's withdrawal rows including `payment_details`, which is free text that holds
bank and IBAN data. Pinned as a deliberate exclusion in the test rather than left silent.

### F-D42-3 — `CurrencyRateController::update` is reachable on the same fallback

`app/Http/Controllers/CurrencyRateController.php:29-31` scopes on `getCurrentStore()`, and its
permission check (`StoreSettingsAccess::permits`) admits the operator. So an operator who has chosen
no merchant can rewrite **store 1's FX rate**, which is the multiplier every later conversion uses.
Not one of the money tables, so outside D-42.7's scope; money-adjacent rather than a money write.

### F-D42-4 — `OrderController::order_payment_status` credits a wallet with no store selection

`OrderController:1831-1849` → `OrderPolicy::updatePaymentStatus` → the platform-admin limb returns
true before any store test → `OrderObserver` → `OrderFinancialService::markFinancialAsPaid` writes
`store_wallets`, `store_wallet_transactions` and `store_order_financials` for **the order's** store.
A different shape from D-42.7: the tenant comes from the order the caller names, not from the store-1
fallback, so it is outside *"merely because `getCurrentStore()` falls back"*. The surface is not
closed by that decision. **Owner call.**

### F-DESIGN-1 — eight views still fetch a font from Google at runtime

P2.5-TYPE removed the runtime Google `@import` from the four admin sheets and reported *"external
Google font requests: NONE"*. **That was measured on the admin screens only and I did not state the
scope.** `layouts/guest.blade.php` (bunny.net) is fixed in P2.5-DESIGN-B; eight remain, listed with
their phases in `docs/design-system/TYPOGRAPHY-DECISION.md`. One of them is Phase 02 scope:
`order/shippinglabel.blade.php`, which a merchant prints.

### F-DESIGN-2 — the login page's language and theme follow the PLATFORM, not the visitor

`layouts/guest.blade.php` reads `getSuperAdminAllSetting()`, so a Syrian merchant sees whatever
language and theme the platform operator set until they use the page's own switcher. It is WorkDo's
design and no defect was introduced, but it means the default first impression for an Arabic
merchant is an English page. **Owner call**, since changing it is a behaviour change.

It also invalidated a measurement of mine: my first login capture used
`set-admin-preview-state.php`, which writes the **merchant's** row, and so produced the same English
light page four times while reporting four combinations. Corrected by driving
`/change-languages/<code>` and the super admin's `cust_darklayout`.

### F-DESIGN-3 — `CLAUDE.md` §3's test counts were badly stale, and the Unit suite is a CI GATE

It said the Unit suite is 339 tests; measured it is **1079**. Contract said 26; measured **27**.
§3 warns that counts drift, but 339 → 1079 is not drift. **Corrected** — and the correction had to
be made at zero net lines, because `ClaudeConfigurationTest` holds `CLAUDE.md` under 300 lines
deliberately and the file was at 298.

**The fact behind the correction matters more than the numbers.** Both required CI jobs run
`Unit suite -- BLOCKING` and `Contract suite -- BLOCKING` *before* Feature, so one red Unit test
marks the Contract, Feature and known-outcome-gate steps `skipped` — **CI then runs no Feature
suite at all.** That is what happened across the seven commits `81a11798..a160c2e0` on 2026-11-04:
four Unit failures from a malformed comment in `public/css/store-syria-ds.css`, an hour of red
required jobs, and no Feature coverage behind them, while the author was running Feature locally
and not Unit. The full account is in
`docs/design-system/evidence/design-b-2026-11-04/README.md`.

### F-DESIGN-4 — no workflow linter anywhere in CI

Nothing under `.github/` runs `actionlint`, `yamllint` or `bash -n`, so a YAML or shell error in a
workflow is discovered only when the job runs. The only pre-run checks available are a YAML parse
(which `tests/Unit/Ci/RequiredGateWiringTest` does) and `bash -n`, both of which were run by hand
for the D-42.1 change. Worth a small hardening slice.

### F-DESIGN-5 — nothing in the suite asserts on the regression gate's step body

`tests/` contains no reference to `"Run the Feature suite"`, `base-junit` or `/tmp/base`. The gate's
own breakage was therefore invisible to the suite, and a regression of the repair would be too.


### F-DESIGN-6 — the report screens' SQL is MySQL-only, so an entire controller is untestable here

`/order-reports-chart` answers **500 on the dev server and in the test suite**, and it is **not**
broken for merchants:

```
SQLSTATE[HY000]: General error: 1 no such function: MONTH (Connection: sqlite,
  SQL: select orders.*,MONTH(order_date) as month,YEAR(order_date) as year from "orders" ...)
```

`MONTH()`, `YEAR()` and `DATE()` are MySQL functions used in `selectRaw` at **dozens of sites** in
`ReportController` and `HomeController`. Production is MySQL. The dev server and `phpunit.xml` are
SQLite, which has none of them. CLAUDE.md already warns that *"SQLite's type affinity hides real
MySQL defects"*; this is the mirror image — **SQLite manufactures a failure MySQL does not have**,
which makes every dev screenshot of those screens unrepresentative.

**THE CONSEQUENCE IS A TESTING HOLE, NOT A PRODUCTION DEFECT.** No Feature test can exercise what
those handlers return, because the query cannot run at all. And the tests that *do* drive those
routes pass straight through the 500: `ReportAuthorizationTest::refused()` returns true only for a
`403` or a redirect carrying an error, so a 500 is "not refused" and
`test_an_operator_whose_type_is_spelled_non_canonically_is_still_admitted` is green against a route
that cannot answer. It proves the gate admitted the request; it cannot prove the screen works.
`ChartMoneyPrecisionTest:55` already records the same obstacle for a sibling handler.

**Adding `assertNotSame(500, …)` to those cases would redden them immediately**, which is why this
is recorded rather than patched: the assertion would be correct and the suite could not satisfy it.
The options are an owner decision, not an engineering one — port the date extraction to something
portable (`whereYear`/`whereMonth`, or `strftime` vs `DATE_FORMAT` behind one helper), or run these
specific tests against a real MySQL as
`tests/Support/Security/bin/verify-sec13-migration-on-mysql.php` already does for the schema.

### F-DESIGN-7 — `public/storage` is a tracked REGULAR FILE holding another machine's path, so every uploaded image 404s on a fresh checkout

Found by the broken-image check on `/dashboard` while auditing the merchant screens
(`audit-merchant-screens.cjs`), then traced. **Not a UI defect; a repository one with a deployment
consequence, which is why it is recorded here rather than fixed.**

**Measured:**

```
git ls-files -s public/storage   ->  100755 87ecbd81… 0  public/storage
                                     (100755 = REGULAR FILE. A symlink is mode 120000.)
cat public/storage               ->  /var/www/html/Product/EcommerceGo/main_file/storage/app/public
stat public/storage              ->  regular file  mode=755  size=62
php artisan storage:link         ->  ERROR  The [public/storage] link already exists.
```

The file's **content is a symlink target** — an absolute path on a machine that is not this one —
committed with a regular-file mode in `3c39c160 Initial commit 98`.

**CORRECTED 2026-11-06 (P2.5-PKG-A-03).** This paragraph used to read "It is not gitignored;
`.gitignore` covers `/storage/*` but says nothing about `public/storage`". That stopped being true
in `2528db62`, which added `/public/storage` to `.gitignore` — verified with
`git log -S'/public/storage' -- .gitignore`. The rule does not untrack an already-tracked file, so
the defect and every consequence below are unchanged and still measured as stated; what changed is
the SIZE of the remedy, which the owner is being asked to weigh. It is now one command,
`git rm --cached public/storage`, with no `.gitignore` edit needed.

**Consequence on any fresh checkout or deployment that restores this path:**

1. Every `/storage/...` URL returns **404** — merchant avatars, product images, store logos, every
   uploaded file. Measured here: `/storage/uploads/profile/avatar.png` 404s on the dashboard while
   the real file is present at `storage/app/public/uploads/profile/avatar.png`.
2. **The standard remedy is blocked.** `php artisan storage:link` refuses because the path exists,
   so the usual deploy step fails rather than repairing it.

**Why the fallback does not help.** `dashboard.blade.php:263` already falls back to
`asset(Storage::url('uploads/profile/avatar.png'))` when a merchant has no photo — but that
fallback resolves through `/storage/` too, so it 404s by the same mechanism. A fallback that
depends on the broken path is not a fallback.

**The remedy, and why it is not taken here.** Untrack the file and let `storage:link` create the
symlink, which is the standard Laravel layout; `.gitignore` already carries the rule, so the change
is `git rm --cached public/storage` alone. It is one command and it is **deployment-affecting**: a deploy that pulls the deletion and does
not run `storage:link` would be left with no `public/storage` at all. Whether the production host
already has a working symlink — in which case the change is neutral there — **cannot be checked
from here.** Under the owner's escalation rules this is theirs to decide.

**What it does not touch:** no application code reads `public/storage` directly; the path is
produced by `Storage::url()` and `asset()`.


### F-DESIGN-8 — every screen audit this phase ran against a merchant holding 50 of 202 permissions, so half the UI was never measured

Found while trying to reproduce the open item *"the 500 on a valid product-brand / product-label
create"*. **That premise does not reproduce**, and the reason explains something larger.

**What is actually there.** The dev fixture's owner role, `p2dev-owner`, was hand-built with **44**
permissions. `Create Product Brand` is not among them — and the index view gates its create button
on `@permission('Create Product Brand')`, so **no create control renders at all**, and
`ProductBrandController::store()` refuses anything posted directly. UI and handler agree; there is
no 500 and no silent failure in the real flow.

It is a **fixture** gap, not a product one: both permission lists in `Utility::addNewData()` — the
product's own seeder — include `Create Product Brand`, so a real installation grants it.
`database/p2dev.sqlite` is gitignored, so this never entered the repository.

**The consequence for this phase's evidence, measured.** Granting the role the full defined set
(44 → 202) and reloading:

| screen | controls before | controls after |
|---|---:|---:|
| `/customer` | 65 | **139** |
| `/order` | 121 | **185** |
| `/product-brand` | — | 124, of which 15 create-type |

**More than half the merchant UI was not rendering during every audit this phase ran.** 152
permissions were ungranted, 31 of them `Create-*`, so every `@permission`-gated control — and the
modals behind them — was absent from the contrast censuses, the accessible-name census and the
screenshots.

**Re-measured against the full set**, which is the useful half of this finding:

| census | reduced UI | full UI |
|---|---|---|
| unnamed controls (`/customer /order /product-list /dashboard /finance` + brand + label) | 0 | **0** |
| low-contrast text, 8 screens × 2 themes | 0 | **0** |

So P2.5-DESIGN-E/F/G/I/J hold across the whole merchant UI, not only the part that was rendering.
**The earlier figures were not wrong, they were narrow**, and they are now superseded by wider ones.

**One instrument was corrected to get there.** `census-low-contrast-text.cjs` reported 8 failures
at 1.33:1 on `span.ss-visually-hidden` carrying `Previous`/`Next` — labels P2.5-DESIGN-J added on
purpose. They are invisible: `clip: rect(0px, 0px, 0px, 0px)`. The size check alone misses them
because the pager **sizes its own children**, so the spans compute 35×35 and only the clip hides
them — confirmed against the rendered pixels, which show two chevrons and an active `1` and no
stray words. The census is now clip-aware, as `audit-merchant-screens.cjs` already was.

**Not changed:** nothing in the repository. The fixture is gitignored and was corrected locally so
future measurement sees the whole application.


### F-DESIGN-9 — a notification modal nobody can open ships on every admin page, in untranslated English with a typo

Found while closing a gap P2.5-DESIGN-K had recorded about itself.

**`layouts/app.blade.php:669` includes `partision/settingPopup.blade.php` on every admin page.** It
renders `#notification-modal`: a heading reading `settings` (lowercase, no translator call), five
`.form-switch` toggles with **no `name`, no form and no handler**, and body copy reading *"you get
lettest content at a time when data will updated"* — untranslated, ungrammatical, and misspelt.

**Nothing can open it.** No `data-bs-target`, `href` or `data-target` anywhere points at
`#notification-modal`:

| searched | result |
|---|---|
| `resources/views/` (excluding the partial itself) | **0** |
| first-party JS (`public/js/`, `public/custom/`) | **0** |
| the rendered DOM on `/dashboard` | **0 triggers**, modal present |
| inline `<script>` content on the page | **no reference** |
| the 70 vendored modules | only two LandingPage **stylesheets**; no trigger |

**What it costs today:** markup and five controls in the accessibility tree on every admin page,
and three untranslated English strings that an Arabic merchant would see if it ever opened. Two of
its five toggles would fail WCAG 2.2 SC 2.5.8 if reachable (measured with the modal forced open:
three conform through the spacing exception at 66.6px / 32.1px / 43.3px, two do not at 9.9px and
0px).

**Not removed here.** Deleting an include from the shared admin layout is a change to the merchant
shell, and "no trigger exists today" is an argument for removal rather than a proof that nothing
ever relies on it. Recorded with the evidence so the decision can be taken deliberately. If it
stays, its copy needs the translator and a proofread; if it goes, the include and the partial go
together.


### F-DESIGN-10 — the red `composer audit` step is MASKING NINE OTHER CI GUARDS, which have not run since it went red

This is a consequence of `composer audit must not grow` that the earlier write-ups of that failure
— including the PR comment on #130 — described only as a count. It is not.

A GitHub Actions step defaults to `if: success()`, so **every step after the first failure in a
job is skipped.** `composer audit must not grow` is step **7 of 18** in `Static checks (no vendor
needed)`. Measured on job `127006559585` (`9ad85b3b`):

```
success  composer audit (locked set, no install)
failure  composer audit must not grow          <- step 7
skipped  Guard - test-database isolation is intact
skipped  Guard - the known .env exposure is reported, and no new one appeared
skipped  Guard - the attestation gate behaves as specified
skipped  Guard - GATE F money tables are still created by nothing
skipped  Guard - packages/workdo/ is unmodified
skipped  Guard - no NEW user upload is committed to the repository root
skipped  php -l on changed PHP files
skipped  php -l on EVERY tracked PHP file
skipped  Design-system token contrast must stay WCAG-conformant
skipped  Cross-repo API contract - the shipped Flutter app must not be broken
skipped  Admin API auth coverage must not regress
```

**Two of the eleven are covered elsewhere and nine are not.** Checked step-by-step across every
job of a completed run (`41952725436`):

| step | also runs in |
|---|---|
| `Guard - test-database isolation is intact` | both `Tests` jobs |
| `Guard - GATE F money tables are still created by nothing` | one `Tests` job |
| **the other nine** | **nowhere else in the workflow** |

So since the advisory count passed the ceiling, **no commit has been checked** by: the `.env`
exposure guard, the attestation-gate guard, **`packages/workdo/` is unmodified**, **no new user
upload is committed to the repository root**, either `php -l` pass, the **design-system token
contrast** gate, the **cross-repo Flutter API contract** check, or **admin API auth coverage must
not regress**.

Several of those are the controls this project built specifically to protect itself, and two of
them — the vendored-code guard and the uploads guard — were themselves repaired in #125 precisely
because they had been *reporting success without measuring anything*. They are now not reporting
at all.

**The window, measured rather than described.** Surveying the `Static checks` job across every
`ci.yml` run on this branch:

| | |
|---|---|
| last run where the audit step **passed** and all nine ran | `7227d223`, **2026-11-04 17:57:14Z** |
| first failure | `4cf0ba4e`, **2026-11-04 20:18:52Z** |

So the nine have not run for **26 commits across ~8.5 hours** — every commit from `4cf0ba4e`
through `91f977f5`, which is the whole of the Premium Design work plus the typography and brand
packages before it. `php -l on EVERY tracked PHP file` last passed at 17:57Z.

**It applies to `main` too.** `composer.lock` is byte-identical on both (blob
`27f8d118e08cd75ada473444f72c5c64b1bd52a3`), so a run on `main` today fails at the same step and
skips the same nine. `main`'s last CI run was 2026-11-03, before the advisories were published, so
this has never been visible there.

**Not changed here.** The remedies are the owner's: raise `AUDIT_CEILING` (forbidden by a standing
directive — it was *lowered* 70→58 for that reason), update the lock, or make this step
non-blocking / move it last so a growing external database cannot mask nine internal guards. The
last of those is the only one that removes the masking without touching either the ceiling or the
lock, and it is a workflow change outside this phase's authorisation.


### F-DESIGN-11 — I claimed the merge-base regression gate was broken for every PR; it is intermittent, and it passed

**Four public claims of mine were false** — three in PR #130 comment `6636372012`, one in comment
`6644208103`, and two sentences in **PR #130's body**, which is the text a human security reviewer
is asked to read. The body is corrected in place; the comments are superseded by
`6648821775` rather than edited, so the record of what was said survives.

The strongest: *"it will fail identically on **every** PR in this repository, including one that
changes nothing."*

**What is true, across all 32 `ci.yml` runs on this branch.** Five reached the merge-base stage;
the other 27 were cancelled by successive pushes.

| head | merge-base stage | outcome |
|---|---|---|
| `8e13c594` | `cp -al` → `Invalid cross-device link` | **EXDEV** |
| `7227d223` | `cp -al` → `Invalid cross-device link` | **EXDEV** |
| `a160c2e0` | both suites ran | **passed**, new failures: 0 |
| `c3f90298` | both suites ran | **passed**, new failures: 0 |
| `91f977f5` | both suites ran | **passed**, new failures: 0 |

`.github/workflows/ci.yml` is byte-identical across all five, so the only variable is **which
runner the job landed on**. The condition is runner-dependent and intermittent.

**Three things this cost, worth keeping:**

1. **"Deterministic" was the stated reason for not re-running.** A re-run was in fact the cheapest
   available way to discover the claim was wrong, and I argued myself out of it.
2. **An `EXDEV` run is not a fast failure.** It runs the full HEAD suite first — 862s and 837s in
   the two cases — and only then dies at the base `cp` in 4–5s. Each burns ~15 minutes for no
   verdict.
3. **The API's step conclusion lies here.** Both suite steps carry `continue-on-error: true`, so
   the merge-base step reports `success` in both `EXDEV` runs while the log shows
   `##[error]Process completed with exit code 1`. A merge-base step reading *success / 4s* is a
   failure. Only the job conclusion, the duration and the log carry information — which means any
   earlier survey of this gate done by step conclusion is unreliable.

**#131 is still worth landing, for a better reason than the one first given.** Not "the gate is
broken for everyone" but "today it depends on runner luck". Its own run logs the decision:
`candidate /home/runner/work/_temp: device 2049 == vendor device 2049 -- hard links possible,
chosen`. It probes the device ids and falls back to a real copy when they differ, so it is correct
on both kinds of runner.

**And `main` has zero data on this gate.** The job carries
`if: github.event_name == 'pull_request'`; all 21 recent `main` runs are `push` events, so it is
skipped in 0s every time. Nothing about gate health can be read from `main` being green.
### F-DESIGN-12 — `asset('themes/…')` points outside the document root, so 29 views ask for files the web server cannot reach

Found by the 404 check on `/dashboard` during P2.5-PKG-A-03, then traced. **A sibling of
F-DESIGN-7: a repository-and-deployment question, not a UI one, which is why it is recorded here
rather than fixed.** (Numbered 12: 9, 10 and 11 were taken, which a first draft of this entry got
wrong by reusing 9.)

**Measured:**

```
dashboard.blade.php:98   ->  asset('themes/' . APP_THEME() . '/theme_img/img_1.png')
                             i.e. the URL  /themes/stylique/theme_img/img_1.png
curl that URL            ->  404
ls public/themes         ->  No such file or directory
find . -path '*themes/stylique/theme_img*'
                         ->  ./themes/stylique/theme_img/img_1.png     (REPOSITORY ROOT)
git ls-files themes/     ->  265 tracked files
routes/web.php           ->  no route for /themes
public/.htaccess         ->  no rewrite for /themes
.github/workflows/*.yml  ->  no step that copies or links themes into public/
```

`asset()` builds a URL relative to the application URL, which a standard Laravel deployment serves
from `public/`. The files are one directory above it and nothing bridges the gap.

**Extent:** 27 first-party views and 2 vendored ones reference `asset('themes/…')`. They are not
all admin screens — `front_end/layouts/app.blade.php` is the STOREFRONT's layout, so the same
mechanism carries shopper-facing theme assets.

**What CANNOT be checked from here, and why it decides the answer.** Some hosts serve the
repository root rather than `public/`, in which case `/themes/…` resolves today and this is a
non-issue in production. Others symlink `public/themes`. Whether the production host does either
is not observable from this container, and the remedy differs: a symlink created at deploy, a
build step that copies, or changing 29 call sites to a helper that resolves correctly. Under the
owner's escalation rules the choice is theirs.

**What is certain either way:** on a fresh checkout served the standard way — which is how this
container runs, and how CI would run it — those URLs 404. The dashboard's theme card renders a
broken image in that state, which is how this was found.


### F-DESIGN-13 — the merchant header scrolls away on every width EXCEPT the phone, and the obvious fix hides a control

Found while measuring the header for P2.5-PKG-A-05. **Recorded with an attempted fix and its
measured failure, because the failure is the useful part.**

**THE DEFECT, measured on /dashboard by scrolling 1200px and reading the header's box:**

| viewport | `position` | header after scrolling | page height |
|---|---|---|---|
| 1440px | `absolute` | y = **−1192 — gone** | 2738px |
| 1200px | `absolute` | gone | 4171px |
| 1024px | `absolute` | gone | 4112px |
| 768px | `absolute` | gone | 4600px |
| 390px | **`sticky`** | **y = 0 — still there** | 5814px |

Seven to eight controls leave with it: notifications, the store switcher, Quick Add, the language
switcher, the profile menu, the storefront link and the POS button. On pages two to five screens
tall a merchant must scroll back to the top to reach any of them. **The phone is the one width
where it stays**, which is the evidence that this was never a decision — screen space is scarcest
there and the header is pinned; it is the wide screens with room to spare that lose it.

**THE OBVIOUS FIX WAS TRIED AND MEASURED, AND IT HIDES A CONTROL.** Setting
`position: sticky` works for the scrolling, and a second rule is needed for the 70px that only
existed to clear an out-of-flow header (`.dash-container { position: relative; top: 70px }` —
with the header back in the flow that becomes dead space: measured, the gap under the header grew
from 20px to 90px). Both were applied and both behaved. Then the A/B on the real page:

```
BEFORE (absolute)   header x=255 w=1185   all 7 controls visible
AFTER  (sticky)     header x=0   w=1440   "Hi, Amina Haddad!" at [20-222], BEHIND the sidebar
                                           whose right edge is 255
```

`.dash-header` carries `left: 255px; width: 1440px`, written for the absolute case where `left`
POSITIONS the box. Under `sticky`, `left` is only an offset constraint, so the box starts at its
static position — x=0 — and keeps its declared width. The header is a SIBLING of `.dash-container`,
not a child, so it does not inherit the container's `margin-left: 255px` either.

**What a correct fix has to handle, none of which this slice could verify in the time it had:**
the sidebar's width is not a constant (it collapses, and it becomes an off-canvas drawer below
1025px), and in Arabic the sidebar is on the right, so a hard-coded inline-start offset is wrong in
one direction or the other. The honest options are to move the header inside the container that
already carries the offset, which is a markup change to the shell every admin page renders, or to
drive the header's inline-start from the same variable the sidebar's width comes from.

**The sticky change was REVERTED and the tree left clean** — verified: `position` back to
`absolute`, page height back to 2738px, `git status` empty. A half-fix that hides the profile menu
is worse than the defect it repairs.

---

**CLOSED the same day by P2.5-PKG-A-06, with `fixed` rather than `sticky`.** The reason sticky
failed is the reason `fixed` works: `absolute` and `fixed` both treat `left`/`right` as a
POSITION, while `sticky` treats them as offset constraints. So `position: fixed` inherits the
host's own placement — the RTL mirror at `style-rtl.css` included, and the breakpoints where the
sidebar goes off-canvas and those same rules already resolve to x=0 — and changes only whether the
box scrolls away. Nothing in the fix knows a sidebar width, so none of the coupling listed above
applies to it.

It is also out of flow, like `absolute`, so `.dash-container { top: 70px }` stays exactly as
correct as it was and no second rule is needed; the sticky attempt had to zero that offset and
produced a 90px dead band when it did not.

Scoped to `@media (min-width: 576px)`, the exact complement of `custom.css`'s
`max-width: 575.98px` arm, which makes the header sticky-in-flow at phone width for a measured
reason of its own — the icon strip wraps there, so the header's height is not a constant.

**Measured, 24 checks over six viewports in both directions:**

| | before | after |
|---|---|---|
| pinned after a 1100px scroll | 2 of 12 | **12 of 12** |
| header x / width | 255/1185, 255/945, 0/1024, 0/768, 0/576 | **identical, row for row** |
| controls obscured | 0 | **0** |

`MerchantHeaderStaysOnScreenTest` pins it, including that it must NOT be sticky, must not restate
any geometry, must not move the container, and must not reach the phone arm — each mutated and
seen to redden.


### F-DESIGN-14 — a demo footer naming a stranger, with four dead links, renders on five LandingPage screens

Found while measuring the merchant footer for P2.5-PKG-A-08. **Recorded, not repaired, because the
only surfaces it reaches belong to Phase 03.**

`resources/views/partision/footer.blade.php` is first-party and reads, verbatim:

```
© 2021, made with by Joseph for a better web.
Joseph William | About Us | Blog | Library        (four links, every href="javascript:")
```

A person's name who is not connected to this product, a copyright year five years stale, four
controls that go nowhere, and not one string translated.

**WHO SEES IT, measured rather than assumed.** My first reading was that it is dead: no view under
`resources/views/` extends `layouts.main`, which is the only thing that includes it. That reading
was wrong, and it was wrong because the search did not cover `packages/`. **Five vendored
LandingPage views do extend it** — `change_blocks`, `qr_code`, `pwa`, `custom` and `seo` — so the
footer renders on all five.

**Why it is not repaired here.** Those five are storefront and marketing screens: Phase 03 by the
approved plan, and Phase 02 "keeps its original Merchant Experience scope… Phase 2.5, 03 and 06 are
separate and must not be started during it". The partial itself is first-party, so repairing it
needs no vendored edit and is cheap — but it would be Phase 03 work done during Package A.

**What a repair looks like when that phase opens:** the admin's own live footer,
`partision/footerlink.blade.php`, already renders the year and the store's `footer_text` setting
with `config('app.name')` behind it. Pointing `layouts/main` at that same partial would delete the
demo text, the stranger's name and the four dead links in one line, and would give those screens
the merchant footer's translation and contrast for free.



---

### F-DESIGN-15 — the Arabic sidebar's disclosure arrow points the wrong way in BOTH states, and it always has

**Status:** FIXED in P2.5-PKG-A-09. Ratcheted by `tests/Unit/Ui/SidebarDirectionAndSubItemMarkerTest.php`
and by `verify-shell-components.cjs` (152 checks, 0 failed, four combinations).

**Measured** on `/product`, by cropping one collapsed and one expanded row in each direction at 3x
and reading the computed transform — not by reading the stylesheets:

| state | EN / ltr | AR / rtl |
|---|---|---|
| collapsed | `>` right, at the right edge | `>` right, at the **left** edge — pointing back into its own label |
| expanded | `⌄` down | `⌃` **up** |
| transform | `none` / `rotate(90deg)` | `none` / `rotate(-90deg)` |

**One root cause: a half-applied mirror.** The glyph is `ti-chevron-right`, and a font glyph never
mirrors itself. The vendor mirrors the *rotation* instead — `style.css:16541` `rotate(90deg)` becomes
`style-rtl.css:16498` `rotate(-90deg)` — which is only correct if the glyph had already been flipped.
It had not. So an Arabic merchant saw a collapsed section pointing backwards, and an open section
wearing the glyph that everywhere else means "collapse".

**It is not a regression from P2.5-PKG-A-02**, which swapped this icon from feather to Tabler. That
was checked before attributing it: `style-rtl.css:16409` gives the old `<svg>` a width and a height
and nothing else, so no mirror ever existed for the swap to break.

**The rule, stated once:** collapsed follows the reading direction; expanded points down in both.
Only the RTL arm is restated, so the English rendering is untouched *by construction* — and the
mutation run proves it rather than asserting it: removing the fix produced 10 failures, every one of
them `ar_*`, none `en_*`.

---

### F-DESIGN-16 — the sub-item marker is a frozen loading spinner, and dark mode flattens even its accent

**Status:** FIXED in P2.5-PKG-A-09. Same test and harness as F-DESIGN-15.

**What renders.** Every child item in the sidebar carries an 8×8 mark built like this:

```css
/* public/css/custom.css:4030 — and rtl-custom.css:4042, mirrored */
border: 2px solid #ced4dc;
border-right-color: var(--theme-color);
border-radius: 50%;
transform: rotate(45deg);
```

That is the CSS loading-spinner construction, standing still: a grey ring with one quadrant in the
accent colour. The host theme's own copy of the rule (`style.css:16555`) is **commented out**, so the
vendor had already withdrawn it; the live one is this project's override.

**And it renders differently in the two themes, which is how it was found.**
`style-dark.css:29471` sets `border-color: #808191 !important` — a shorthand, so it overrides all
four sides including the accented one. Light shows a grey ring with a brand quarter; dark shows a
uniform grey ring. Two different marks for one meaning, neither deliberate.

**The part that actually costs the merchant something.** P2.5-PKG-A-02 gave the active child a 3px
brand bar and brand-coloured text. The ring took no part in that — in dark mode the **active**
child's mark was the same flat grey as its four inactive siblings. The loudest decoration on the row
carried no information, next to two signals that carried all of it.

**The replacement** is a marker that means something: a quiet dot in `--ss-fg-subtle` for a child
item, a larger dot in `--ss-brand` for the child you are on. One shape, one meaning, two states, and
it now agrees with the bar and the text instead of competing with them. Size *and* colour differ, so
the state does not rest on colour alone (WCAG SC 1.4.1).

**Two implementation notes that are not incidental.** `border-width: 0` rather than a colour
override, because the dark sheet's `!important` cannot be outranked on colour without another
`!important` — at zero width the colour is simply irrelevant. And `transform: scale()` rather than a
new width, because the mark is positioned with a physical offset that is mirrored per direction;
resizing it would move it off its own centre and need that offset restated, which is precisely how
the mobile-drawer defect of P2.5-PKG-A-02 happened. The rule already carries a transform transition,
so the active dot grows into place.

**The dot names `--ss-brand`, not a hue.** That token resolves to cyan in dark mode, which is open
owner decision #35. Naming the token means this marker follows whatever the owner decides instead of
becoming a second place that has to be found and changed.

---

### F-DESIGN-17 — the Arabic breadcrumb root says "house", on all 107 merchant screens

**Status:** FIXED in P2.5-PKG-A-09.

`resources/lang/ar.json` carried `"Home": "البيت"`. البيت is a dwelling — a house, or a line of
verse. It is not the navigational root, which is الرئيسية. The breadcrumb component supplies the
Home item for every merchant screen (`layouts/app.blade.php:660` — "the ONE breadcrumb, for all 107
merchant screens… Home lives in the component so it cannot drift"), so a single wrong word reached
all of them, and it reached them in the one place a merchant looks to find out where they are.

**The repository already knew the right register**, which is what makes this a slip rather than a
judgement call: the same file carries `"Home Page": "الصفحة الرئيسية"`. This is the Wave-19 pattern
again — a literal dictionary equivalent where the interface needs the conventional term.

The key is used in five first-party places and three vendored ones, all of them navigation links to
a site or dashboard root, so correcting the value is right everywhere it lands and needed no
vendored edit. Nothing in `tests/`, `.github/` or the phase-2.5 docs pinned the old string.

---

### M-PKG-A-09 — a measurement note, not a product defect: the harness measured one page four times

Recorded because this phase keeps relearning it and the cost is always the same shape.

`verify-shell-components.cjs`, on its first run, called `set-admin-preview-state.php` with **named
flags** (`--lang en --theme light`). That tool takes its arguments **positionally**
(`<storeId> <light|dark> <ltr|rtl> <lang> <email>`), so it received `--lang` as the store id and
wrote nonsense — and `stdio: 'pipe'` swallowed the complaint. All four combinations then measured the
**same ar/dark page**, and twenty contrast checks reported "ok" against combinations that never
rendered.

**Only the premise checks caught it.** `dir is ltr (got rtl)` and `theme attribute matches (dark)`
are the two assertions that have no product value and exist solely to ask whether the harness got
the state it requested. They are why the readings above can be trusted.

The tool's own docblock already records eight earlier false "dark" runs of exactly this shape. The
harness now passes the dev database, cache and session variables to that one child process itself
rather than trusting how it was launched — **scoped to the child**, because `serve-dev.sh` records,
with dates, that exporting them and running the suite in the same shell destroyed the fixture twice.

**A second one in the same slice, with the opposite outcome.** A probe reported the workspace chip
had `NO TRIGGER` — no button, no `role="button"`. It did `chip.querySelector('button')` where `chip`
was `.ss-workspace`, and `.ss-workspace` **is** the button, so the probe searched inside the element
it was looking for. The chip is in fact a correct listbox: `aria-haspopup`, `aria-expanded`,
`aria-controls`, a 205×65 target, `Enter` moving focus to the *currently selected* option, arrows
wrapping, Home/End, and `Escape` restoring focus to the trigger. The same run's claim that the open
panel was misaligned with the chip was also false — measured, the two boxes are identical
(25/229/204 in LTR, 1210/1415/205 in RTL). Both were eye-and-selector errors, and both were caught
by measuring the thing itself rather than acting on the first reading.

---

### M-PKG-A-09b — the footer evidence needed the dev container's `.env` corrected, and that is not in the repository

Recorded so the capture can be reproduced, and so nobody reads it as a change that shipped.

P2.5-PKG-A-08 fixed `APP_NAME` in **`.env.example`**, which is the file a fresh install copies — it
shipped `Laravel`, so every new deployment put the framework's name in its merchant footer. That fix
is committed and tested.

This container's own **`.env`** still carried `APP_NAME=Laravel`, because it predates that commit and
`.env` is gitignored. So the first four footer captures read **"Laravel 2026"** — evidence about a
stale environment, not about the product. The row was withheld for exactly that reason before being
earned.

The container's `.env` was then aligned with the shipped example and the captures re-taken; they read
**"2026 Store Syria"** at 6.14:1 light and 6.72:1 dark. **To reproduce:** set `APP_NAME="Store Syria"`
in `.env` (matching `.env.example`) before running `verify-shell-components.cjs`. Config is not cached
in this container, so no `config:clear` is needed; if it ever is, that step comes first.

**Nothing about this changes the product.** `.env` is not in the repository and never will be. The
committed state was already correct; the dev environment was not.

---

### M-PKG-A-09c — "no failed request" was trivially true, and a 404 does not recur

Two instrument corrections from the same drive, both of which would otherwise have produced a clean
report about nothing.

**The row names a bundle, so a crop cannot answer it.** "Footer + script bundle" was being argued
from a picture of six words. The drive now records what the browser reports: a script that threw, a
script or stylesheet that never arrived, and any 404 outside a **named** list of three — F-DESIGN-12's
`themes/` path, F-DESIGN-7's `storage/uploads/`, and one dev-fixture image. A *fourth* 404 fails the
check instead of disappearing into a blanket allowance. Result: 35 scripts, 0 errors, 0 code failures,
in all four combinations.

**And "nothing failed" is trivially true of a drive that fetched nothing.** The first version of this
check reported the three known 404s on combination one and `none` on the other three, which looked
like caching and might equally have been a page that stopped asking. Measuring instead of guessing:
the first drive fetches **8** images and three of them 404; every later drive fetches **3** and never
re-requests the avatar. So the later drives are *not* three independent clean readings of the image
set. The likeliest cause is Chromium's in-memory cache, which `Network.setCacheDisabled` does not
clear — written down as a **hypothesis, not a finding**, because it was not established.

It does not weaken what the row is about: every drive made 69–117 real responses, and each is now
pinned to a floor, so a drive that stops fetching fails the floor rather than passing the assertions
above in silence.

---

### F-DESIGN-18 — the bar chart has no magnitude reference, and my earlier note about it was wrong

**Status:** MEASURED and designed, **deferred to Package D** with the reason below. Not fixed in
Package A.

**First, the correction.** P2.5-PKG-A-03 recorded this component as rendering "one solid full-height
bar with no axis and no value labels". Driven and measured, that is **not accurate** and the record
should not stand uncorrected:

| | |
|---|---|
| bars | 7, heights `[2, 2, 2, 2, 2, 2, 119]` — six days at zero with a deliberate baseline stub |
| x-axis | **present** — `31-Oct … 06-Nov` in `.ss-chart__labels` |
| the numbers | **present** — a `<details>` disclosure holding a full `<table>` with per-day values and a total |
| caption | present — "Orders placed each day, last 7 days…" |
| `aria-hidden="true"` | **correct**, and deliberate: the table below is the accessible content, not a fallback |

The component is carefully written: one scale across stacked series, a max floored at zero so a
negative money series cannot invert every height, numeric coercion that refuses NAN/INF, and a
baseline marker that survives an exactly-zero float. None of that deserved the sentence I wrote.

**What is genuinely missing is a magnitude reference.** There is no number anywhere on the *picture*
telling you the height of that bar. A merchant glancing at the dashboard sees six empty days and one
tall column and cannot tell whether it is 3 orders or 300 without opening the disclosure. The
picture, on its own, carries shape but not size.

**The designed fix,** kept to one accessory rather than a full axis: a narrow leading scale column
holding two quiet ticks — the series maximum at the top of the plot and `0` at the baseline —
built with `flex-direction: column; justify-content: space-between` so it needs **no physical
offset** and mirrors in RTL by itself, with `.ss-chart__labels` given a matching
`padding-inline-start` so the date row stays aligned under its columns. `$ssMax` and `$ssFormat`
already exist in the component, so this adds no computation and no new source of truth.

**Why it is not done in Package A.** `x-ss.bar-chart` is used by **ten** views and **nine of them are
report screens** — Package D (Finance & Analytics) by the approved plan. Implementing it here would
change ten surfaces while verifying one, which is the evidence discipline this phase exists to hold.
It belongs in the Package D sweep, where the other nine are driven and captured in the same pass.
The dashboard's `x-ss.bar-chart` row therefore stays **PENDING** rather than being claimed on a
component that still cannot be read at a glance.

**Scope note:** a scale label is presentation only. It adds no computation and changes no figure, so
it stays inside the directive's "financial changes must remain PRESENTATION ONLY" boundary.

---

### F-DESIGN-19 — the Arabic dashboard drew an English axis under its own Arabic caption

**Status:** FIXED in P2.5-PKG-A-10. Ratcheted by `tests/Unit/Ui/DashboardChartAxisLocaleTest.php`
and by `verify-dashboard-components.cjs` (60 checks, 0 failed, four combinations).

**Measured** on `/dashboard`: the "Orders per day" chart labelled its seven columns
`31-Oct, 01-Nov, 02-Nov …` on the **Arabic** page, directly beneath its own caption reading
`الطلبات يومياً — آخر ٧ أيام`. Both sites built the label with `date('d-M', …)`, which has no notion
of a locale.

**One of the two sites looked like it handled this and never had.** `best_selling_brand_chart`
wrapped the call as `__(date('d-M', …))` — which asks the translator for the key `"31-Oct"`.
Measured: `resources/lang/ar.json` holds **no** `DD-Mon` key, and exactly one month name (`"May"`,
present as an ordinary English word). Laravel returns an unresolvable key unchanged, so that `__()`
had never translated a single label. It was decoration that made the defect look handled, which is
why the test asserts the **absence of that shape** rather than only the presence of the fix.

**Carbon, not a translation table.** Carbon is already a dependency and already imported in this
controller, and carries month names for every locale. Adding 365 `DD-Mon` keys would be a table to
maintain forever and still wrong in the next language.

**Western digits, deliberately.** `isoFormat('D MMM')` yields `31 أكتوبر`, not `٣١ أكتوبر`. That
agrees with what the rest of this dashboard renders — the KPI figures measure as `62`, not `٦٢` —
so the axis does not contradict the numbers directly above it.

**Checked before editing, not assumed: this is not an API change.** `getOrderChart` is called from
exactly two web dashboard actions and appears nowhere in `routes/api.php`, so no client contract
reads these strings and the Flutter admin app cannot see them.

| | before | after |
|---|---|---|
| en | `31-Oct, 01-Nov, 02-Nov` | `31 Oct, 1 Nov, 2 Nov` |
| ar | `31-Oct, 01-Nov, 02-Nov` | `31 أكتوبر, 1 نوفمبر, 2 نوفمبر` |

Reverting the helper alone produced 6 failures, so the assertions are not vacuous.

---

### M-PKG-A-10 — the fifth time a detector matched its author's prose

`DashboardChartAxisLocaleTest` failed on its **own docblock**. The explanation quotes both
`date('d-M', ...)` and `__(date('d-M', ...))` verbatim while describing why they are wrong, and the
two assertions searched the raw file — so they matched the explanation and reported the thing it
forbids.

This phase has now done that five times: a `/* end … */` marker inside prose, a `.dash-container {
… top: }` quotation, a `data-feather` reference in a Blade comment, an `rgba(146, 44, 136, 0.3)`
in prose, and now this. `MerchantHeaderStaysOnScreenTest` and `SidebarDirectionAndSubItemMarkerTest`
already strip comments before asserting for exactly this reason; this test now does too.

Worth stating as a rule rather than a recurring surprise: **a detector that reads source must read
declarations, not prose** — otherwise the only way to keep it green is to stop writing down the
evidence beside the rule, and the evidence is the more valuable half.

---

### D-NOTE — `x-ss.progress` and `x-ss.setup-checklist` render zero times on the dashboard

Measured in all four combinations, not inferred from a grep: `.ss-progress` and `.ss-setup` are
present in the component library and appear **0 times** on `/dashboard`. Their matrix rows therefore
stay PENDING — a component that does not render cannot be verified from a page that does not use
it, and a harness that silently measures nothing while reporting no failure is the shape of mistake
recorded in M-PKG-A-09c. They need a surface that actually uses them, which is a later package's
work to find.

---

### M-PKG-A-10b — REFUTED: the Arabic "Quantity" header is not truncated, and I filed it from a screenshot

Filed from the P2.5-PKG-A-09 shell capture: the product table's Arabic quantity header appeared to
read `كمي` where `ar.json` holds `كمية`. **It does not reproduce, and the finding is withdrawn.**

Measured on the running page, in the DOM rather than in an image:

```
  8: "حالة المخزون"  len=12  box=133x49  hOverflow=0  vOverflow=0
  9: "كمية المخزون"  len=12  box=111x49  hOverflow=0  vOverflow=0
```

The header is `كمية المخزون` — "stock quantity", twelve characters, rendered whole, clipped on
neither axis. A census of all five reachable merchant list screens in **both** languages found
**0 clipped headers out of 50**. What I read as `كمي` was a misreading of a downscaled screenshot.

**This is the fourth reading of mine refuted by measurement in this session alone**, and they are all
the same mistake:

| claimed from | reality, measured |
|---|---|
| the chip has `NO TRIGGER` | a correct listbox button — the probe searched inside the element that *was* the button |
| the open panel is misaligned | identical boxes: 25/229/204 LTR, 1210/1415/205 RTL |
| the chart has "no axis and no value labels" | both present; `aria-hidden` correct *because* the table exists |
| the Arabic quantity header is clipped | renders whole, zero overflow, 0 of 50 headers clipped |

**The rule this earns:** a screenshot is good for noticing that something *might* be wrong and worth
nothing as the basis of a finding. Nothing goes in the register, the matrix or the ledger from an
image alone — the DOM is measured first. Three of the four above would have become committed work
on surfaces that had no defect, and the fourth would have put a wrong sentence about a well-built
component into a document the owner reads.

The corresponding tracker row is closed as refuted rather than carried into Package B.

---

### D-NOTE-2 — why `x-ss.setup-checklist` and `x-ss.progress` cannot be verified on this fixture, precisely

The earlier note said only that they "render zero times on the dashboard". That is true and
incomplete, and the difference matters for how their rows get closed.

**`x-ss.setup-checklist` is used in exactly one place** — `dashboard/overview.blade.php` — behind
`@unless ($ssSetupComplete)`. The component's own design is that **it disappears when it is done**:
"an onboarding panel that stays forever stops being onboarding and becomes furniture." The dev
merchant has completed every step she can act on, so the panel is correctly absent. Its absence is
the `@unless` working, not a defect, and not evidence about the component.

**`x-ss.progress` has no usage anywhere** in `resources/views/` or `packages/workdo/` — measured, not
inferred. It renders only as part of `setup-checklist`, which emits `.ss-progress` internally. So it
is gated by the same condition, one level down.

**What closing these rows actually needs:** a merchant whose setup is *incomplete*. That means
changing fixture data — unsetting a setting or removing a seeded record — and that is the one class
of change `serve-dev.sh` records as having destroyed this fixture twice. It is doable safely and
reversibly, but it is a deliberate piece of work with its own verification, not a side effect of a
dashboard pass.

**They stay PENDING, and the route is written down** rather than the rows being claimed on a
component that was never drawn, or quietly dropped. A Blade-render test would prove the component's
markup but would not be a capture in four combinations, which is what the matrix asks of a row.

---

### R-PKG-B-01 — the authorization review of the attribute-option fix, and the defect it found in my own test

The `tenant-authz-reviewer` cleared the change — *"Both defaults are safe… I could not construct any
request that reaches further after the change than before it"* — and then found five things worth
recording. Two of them were mine, and one of those was the kind this project cares about most.

**It answered the question I put to it, and the answer changes nothing but is worth having in
writing.** I asked whether the absent operator-first limb was a defect I was leaving in place.
It is not, and adding one would itself be a defect: `create()` and `edit()` render the composers for
`store()` and `update()`, which are **writes**, and owner decision F206 — recorded verbatim at
`app/Services/OperatorCatalogueWrite.php:8-15` — says the platform operator *"can read merchant
catalogues and diagnose their problems, but cannot edit merchants' products, their prices, their
stock or their variants"*. Gating these on `Create` / `Edit Attributes Option`, which `isAbleTo()`
denies a roleless operator, produces exactly what F206 already enforces explicitly in six other
places. CLAUDE.md's operator-first rule is about checks that *should* admit the operator; this is
not one.

#### My test could not fail for the thing it claimed — MEASURED, not argued

`test_the_permission_check_still_runs_first` ran ONE `/s` regex over the whole comment-stripped
file, so `.*?` crossed method boundaries. With `create()` reordered to check ownership *before* the
permission — the exact mutant the assertion exists to catch — the pattern still matched, by
borrowing `store()`'s `isAbleTo('Create Attributes Option')` and its `ownedAttribute($id)` further
down the file.

I applied that mutant rather than taking the claim on trust. **The test printed a tick.**

It now extracts ONE METHOD BODY and compares positions within it, with anti-vacuity assertions that
the body is neither empty nor the whole file. Re-measured against the same mutant: it fails, with
the reason. 21 assertions became 34.

**The general rule, because this will recur:** asserting an ORDER over a whole file asserts nothing
— every needle is somewhere later in some other method. An ordering assertion must be scoped to the
unit whose order it describes.

#### A docblock of mine stated a false reason for the guard the whole fix rests on

`ownedOption()`'s comment justified its `empty($store)` check by saying `where('store_id', null)`
*"would match nothing"*. Both halves are false, and I verified them rather than accepting the
report:

```
ProductAttributeOption::where('store_id', null)->toSql()
  -> select * from "product_attribute_options" where "store_id" is null
```

`Builder::where()` converts a null value to `whereNull()`, and the column is `nullable()`
(migration :19). So the guard is **required**, not belt-and-braces: without it a caller with no
store context gets every ORPHAN row on the platform, not an empty list. Corrected in place —
and it matters because that false sentence is what makes the next finding look harmless.

#### F1 — `index()` would leak across tenants, and is unreachable

`ProductAttributeOptionController::index()` queries `where('store_id', getCurrentStore())` with **no**
`empty($store)` guard, unlike every other row-touching path in the file. With a null store that is
`store_id IS NULL` against a nullable column.

**It has no route.** `routes/web.php` declares seven routes for this controller (:984, :986, :988,
:990, :992, :995, :997) and none points at `index()`; it also returns `view('attribute.index')` with
the options in `$ProductAttributes`, the *attribute* list's variable — vestigial vendored code, not a
screen. Not fixed here, because fixing an unreachable method inside a change about two others is
noise. A docblock on the method now says so, and says the guard must be added **before** anyone
routes it.

#### F3 — three more route-optional / signature-required mismatches, deferred

Same shape, all POST/DELETE: `store-attribute-option/{id?}` vs `store(Request, $id)`,
`delete-attribute-option/{id?}` vs `destroy(ProductAttributeOption, $id)`, and
`update-attribute-option/{id?}` vs `update(Request, ProductAttributeOption, $id)`. Each throws
`ArgumentCountError` → 500 when the id is omitted.

**Not holes** — the crash precedes every check, so nothing is served. Lower priority than
create/edit for two measured reasons: no `route()` call anywhere generates the bare form, and
reaching them needs an authenticated session *plus* a valid CSRF token. They have no
layout-render blast radius, which is the thing that forced create/edit to be fixed in the
controller. Next small slice; the id is positional-last in all three, so a default cannot shift a
route-model binding.

#### F5 — the fix was asserted about text while its sibling was asserted about behaviour

Closed. Three tests added to `AttributeOptionAndCustomerBoundaryTest` in that file's own idiom: both
bare URLs refuse with 302 **and** `assertSessionHas('error')` — the session key because a bare 302
is also what a middleware redirect produces — plus a **positive control** asserting a real owned id
still renders the composer, because a refusal test alone is satisfied by a controller that refuses
everything. Reverting the defaults fails exactly the two refusal tests and leaves the other twenty
green.

---

### F-DESIGN-20 — the required-field marker fails AA in both themes, on 145 fields across 52 views

**Status:** MEASURED and verified. Fix held until the in-flight Feature suite reports, because
Feature tests read `store-syria-ds.css` and editing it mid-run invalidates them.

The marker Wave 18 added to every required field is `<span class="text-danger">*</span>`, and it is
painted with a **hard-coded pure red** rather than a design-system token:

| | light | dark |
|---|---|---|
| colour | `rgb(255, 0, 0)` | `rgb(255, 0, 0)` |
| background | `rgb(249, 250, 251)` | `rgb(34, 36, 44)` |
| **ratio** | **3.83:1** | **3.87:1** |
| size / weight | 14px / 400 | 14px / 400 |

Both fail SC 1.4.3 (4.5:1 for text under 18.66px). `#FF0000` on near-white is a classic trap: it
*looks* loud and measures badly, because pure red is dark in luminance terms only by a little.

**It is the same figure in both themes, which is the tell** that no token is involved: a
theme-aware colour would differ. Six occurrences on `/product/create` alone, and Wave 18 put this
marker on **145 required fields across 52 merchant views**, so the fix is one rule at the component
and reaches all of them.

**Two other things measured about it, neither of them contrast.** It carries no `aria-hidden`, so a
screen reader announces a bare "star" beside the label with nothing to say what it means; and it
carries no `title`, so a sighted merchant who does not already know the convention is not told
either. The accessible way to mark a required field is the `required` attribute plus a visible
convention explained once — worth settling when the contrast is fixed rather than separately.

---

### F-DESIGN-21 — the product editor's attribute chip is white text at 2.31:1

**Status:** MEASURED and verified, after a first reading that was wrong. Fix held with F-DESIGN-20.

On `/product/{id}`, the selected-attribute chip in the variant composer renders **white text at
12px** on a mid-tone background: **2.31:1**. At 12px the floor is 4.5:1, so it is less than half of
what it needs, and it sits on the surface merchants use to build every variant.

**The first reading of this was mine and it was wrong**, which is worth recording because it nearly
became a finding about the wrong element. The harness reported
`div.choices__item.choices__item--selectable` with the text `"ColourRemove item"` — a concatenation
of a visible label and its remove button's text, because `textContent` includes descendants. Sampling
"a chip" then measured a *different* chip:

```
  Textiles          rgb(41, 50, 64)   10.90:1
  Select Category   rgb(91, 95, 102)   6.42:1
  Homeware          rgb(41, 50, 64)   12.93:1
  Scarves & Shawls  rgb(41, 50, 64)   12.93:1
  Soaps             rgb(41, 50, 64)   12.93:1
  Textiles          rgb(41, 50, 64)   12.93:1
```

Every one of those is fine, and on that evidence the finding looked like a false positive. It is
not: filtering for the element whose own text is `Colour` finds it —
`rgb(255, 255, 255)`, 12px, **2.31:1**. The lesson is the same one this phase keeps paying for:
**measure the element the reading came from, not one like it.**

---

### M-PKG-B-01 — the Package B harness reported ~30 failures that were all its own

Recorded because the first run of `verify-package-b-pages.cjs` produced a page of red that would
have become a page of findings.

**"Emoji used as an interface icon", 4 to 14 per page — every one false.** The check used the range
`☀-➿`, which is *Miscellaneous Symbols and Dingbats*: the block a UI legitimately draws
from. MEASURED, the only character it ever matched was `☆` U+2606 WHITE STAR, in a rating control.
There are **no emoji** on any Package B page. The check now uses `\p{Emoji_Presentation}`, verified
in this runtime to be false for star, check, chevron and warning-sign and true for rocket and
glowing-star.

**Visually-hidden text reported at 1.33:1 — and the box was not the test.** `.ss-visually-hidden`
declares `width:1px; height:1px`, so excluding by box size looked sufficient. MEASURED on
`/category`, the pagination's "Previous"/"Next" spans report **35x35**: something overrides their
size. What actually hides them is `clip: rect(0px, 0px, 0px, 0px)`, which clips to nothing whatever
the box says. Excluding on the clip rather than the box is correct, and the earlier version would
have had me "fix" the contrast of text no sighted reader can see.

**252 checks, 10 failed** after both corrections, and the 10 are the two findings above. The ~30
that disappeared were the instrument, not the product — which is the whole reason a harness states
what it excluded and why, rather than printing a number.

---

### F-DESIGN-20 — CLOSED. The required marker now takes the design-system colour

Fixed in the markup, as the sheet's own P2.5-WAVE-33 and P2.5-MONEY blocks had already settled it
must be. `<span class="text-danger">*</span>` became `<span class="ss-text-danger">*</span>` in the
two views that actually render it.

| | before | after |
|---|---|---|
| light | `rgb(255,0,0)` → **3.83:1** | `rgb(140,29,23)` = `#8c1d17` → **9.12:1** |
| dark | `rgb(255,0,0)` → **3.87:1** | `rgb(243,169,164)` = `#f3a9a4` → **7.48:1** |

`span.text-danger` now matches **zero** elements on those pages, which is the check that the swap is
complete rather than partial. The live sweep went from 10 failures to 2.

**THE LIVE CENSUS IS WHY THIS WAS 2 FILES AND NOT 36.** A static grep finds `class="text-danger"`
in 36 views, 68 times. Driving the pages finds **12 rendered markers, on exactly two screens** —
`/product/create` and `/product/1/edit`, six each: Name, Category, Price, Cost Price, Upload Product
Images, Upload Cover Image. `/product/1`, `/category`, `/coupon`, `/product-brand`,
`/product-attributes` and `/product-label` render **none**. Of the other 56 uses, most are notes and
examples in the `plans` views — Phase 06 — and repainting those would have changed super-admin copy
for a defect measured on the product editor.

**What was deliberately NOT touched:** `edit.blade.php:1586/1597/1617`, where javascript does
`addClass('text-danger')` on upload ERROR text. That is a message, not a marker; it has the same
colour problem and belongs to whoever fixes error presentation.

---

### F-DESIGN-22 — the required marker is decorative: five of six controls are not programmatically required

**Status:** MEASURED, NOT FIXED — fixing it changes form behaviour, which is beyond a presentation
pass.

Found while deciding whether to add `aria-hidden="true"` to the marker. The accessible pattern is
`required` on the control plus a visible convention, with the `*` hidden from assistive technology
as a duplicate. That is only correct if the control really is required. Measured on
`/product/create`:

| field | control | `required` | `aria-required` |
|---|---|---|---|
| Name | `input[text]` | **false** | none |
| Category | `select` | **false** | none |
| Price | `input[number]` | **false** | none |
| Cost Price | `input[number]` | true | none |
| Upload Product Images | *no control in the group* | — | — |
| Upload Cover Image | `input[file]` | **false** | none |

**So the asterisk is the ONLY indication of requirement on five of six fields, and it carries no
semantics at all** — a screen reader announces "star" beside the label and nothing more. Adding
`aria-hidden` would have removed the sole signal and made the screen without it *less* usable than
the one with it, which is why this was checked before the attribute was added rather than after.

**Why it is not fixed here.** Adding `required` to Name, Category, Price and Cover Image changes
what the browser will submit: a merchant mid-edit who leaves one blank would be blocked where today
they are not. That is a change to a merchant workflow, not to its presentation, and the directive
reserves those. The honest fix is `aria-required="true"` plus server-side validation that already
agrees — which needs checking against the controller's rules first.

---

### F-DESIGN-23 — a variant name renders at 4.03:1 in dark mode, and it was hidden behind the marker

`span.d-flex.align-items-center` carrying a variant name — measured on `"S-Natural"` — renders at
**4.03:1** at 16px/700 on `/product/1/edit`, dark only. At 16px it is not "large text" under SC
1.4.3 (which needs ≥18.66px *and* ≥700, or ≥24px), so its floor is 4.5:1 and it misses.

**It is not a regression and it is not new** — it was always 4.03:1. The harness reports the WORST
text node per page, and the required marker at 3.87:1 was worse, so this sat behind it. Fixing the
worst reveals the next, which is the expected behaviour of a worst-case instrument and worth saying
plainly rather than letting it read as damage from the marker change: the diff for that change is
two Blade files and no stylesheet.

The name is not in `edit.blade.php` — it is rendered from variant data — so the fix is a rule, not a
markup swap. Next slice.

---

### F-DESIGN-23 — CLOSED, and the cause was FAQ styling reaching the product editor

The variant panel's heading — the text naming which variant you are about to edit, "S-Natural",
"M/L-Indigo" — had two dark-mode defects. Measured in all four states, because measuring one would
have fixed the better half:

| theme | state | before | after |
|---|---|---|---|
| light | collapsed | 12.37:1 | 12.37:1 (untouched) |
| light | expanded | 5.28:1 | 5.28:1 (untouched) |
| dark | collapsed | **4.03:1 — fails** | **12.84:1** |
| dark | expanded | 5.28:1 **on a near-white bar** | **10.68:1 on a dark surface** |

**1. The collapsed heading was the host's muted grey**, `#808191` at 16px/700 — 4.03:1, under the
floor because 16px/700 is not "large text" (SC 1.4.3 needs 18.66px *and* 700, or 24px). The boldest
text on the panel was also the quietest.

**2. The expanded header bar was a light surface on a dark page.** Its background is
`--bs-accordion-active-bg: #e8e9ea`, and that declaration is **byte-identical** in `style.css:5216`
and `style-dark.css:5222` — the vendor never gave the accordion a dark-mode active state. **Its
5.28:1 PASSES**, which is exactly why a contrast sweep alone would never have flagged it: the defect
is not the ratio, it is a near-white bar in a dark interface. Same shape as F151 on the breadcrumb.

#### The root cause is worth recording on its own

The first collapsed rule was written without `!important`, applied, and **measured not to work** —
the button stayed `#808191`. Enumerating every rule matching the button found the winner, and it is
not an accordion rule:

```
.plan_card .card.price-card .card-body span.text-sm,
.faq .accordion .accordion-item .accordion-button.collapsed
    { color: rgb(128,129,145) !important }
```

**A rule written for the FAQ screen.** It reaches the product editor because the editor wraps its
variant accordion in `<div class="faq">` — verified directly: `btn.closest('.faq')` returns a
`div.faq`, and the button matches that selector while matching the `.plan_card` one not at all. So
FAQ styling paints variant headings.

It also explains why the expanded arm needed no `!important`: the FAQ rule carries `.collapsed`, so
it never competes for the open state. Two arms of one defect, two different cascade situations.

#### Why `!important` is right here and was wrong for `.text-danger`

Both decisions were made in the same session and they point opposite ways, so the distinction
matters. `.text-danger` is a **Bootstrap utility**: out-importanting it repaints every use in all 70
vendored WorkDo modules, which is why that one was fixed in the markup instead. This rule is scoped
to `.attribute_option_data` — the product editor's variant list and nothing else. FAQs keep their
own colour. Narrowness is the whole difference.

**Light is untouched by construction**: both arms are `[data-ss-theme="dark"]`-scoped, and the light
figures above are identical before and after.

After: the Package B page sweep reports **252 checks, 0 failed**.

---

### F-SEC-01 — a merchant could read, rename, TAKE and delete another merchant's brands and labels

**Status:** FIXED and proved, both controllers. **This is a tenancy finding, not a design one**, and
it is recorded here because it was found during the Package B catalogue pass.

#### What was reachable

Measured with a failing test first (`tests/Feature/Security/BrandCrossTenantReadWriteTest.php`),
not inferred from reading. Before the fix: **4 failed, 1 passed** — the one that passed was the
positive control, so the fixture was sound.

| request by a merchant in store A | result |
|---|---|
| `GET /product-brand/{B}/edit` | **200, carrying store B's brand name** |
| `PUT /product-brand/{B}` | **renamed store B's brand** |
| the same `PUT` | **moved the brand into store A** |
| `DELETE /product-brand/{B}` | **deleted store B's brand** |

`ProductLabelController` has the identical shape, confirmed the same way, including
`edit()` with **no permission check at all** — any authenticated web principal, with zero label
permissions, could read another store's label.

#### Two things made it reachable rather than theoretical

**Route model binding is not tenant scoped.** `ProductBrand` and `ProductLabel` register no global
scope and neither is in `config/tenancy.php`'s `scoped_models`, so a bound instance — or a
`find($id)` — is any row in the table. This is the trap CLAUDE.md already records for `Product`.

**And `update()` ASSIGNED the owner rather than checking it:**

```php
$productBrand->store_id = getCurrentStore();   // and the identical line in ProductLabelController
```

That is the difference between an unauthorised edit and a **takeover**: a foreign row reaching that
line changed hands.

#### I understated the severity, and the review corrected me

I wrote that the attacker held "ordinary catalogue permissions". The authorization review traced the
seeder: `Utility::addNewData()` grants `Edit Product Brand` and `Delete Product Brand` to the role
named `admin` that **self-registered merchants are given** (`app/Models/Utility.php:3184-3185`,
granted at `:3233-3234`). So this was live for **every merchant by default**, not only one with a
configured role.

#### Why the existing coverage never saw it

`BrandLabelStatusAuthorizationTest` has fifteen tests and every one drives `changeStatus` or
`changePopular` — the two methods that **were** hardened, via a real `resolveOwnedBrand()` check.
Nothing in the suite reached the editor. That helper's own docblock even says
`Edit Product Brand` "is the permission `edit()` and `update()` on this same row already use", so
the gap was in view and left: a census keyed on the surface somebody was looking at rather than on
the model, which is the same shape its own comment warns about for the API sibling.

#### The fix

One pattern, both controllers: a `callerOwns…()` helper comparing `store_id` against
`getCurrentStore($user->id)` and failing closed when either side is empty; `abort(404)` for a
foreign or absent row so the two are indistinguishable; the permission check added to `edit()` and
`create()` so a form refuses exactly when its submit would; and **the `store_id` assignment deleted
rather than guarded**, because leaving it means any future weakening of the guard re-opens a
transfer instead of an edit.

**No operator limb, deliberately.** The review verified this rather than taking my word: the
`super admin` role is granted none of these permissions (`Utility.php:3240-3270`,
`PermissionTableSeeder.php:55-66`), `User` does not override `isAbleTo`, so all these methods
already refused the operator — the change removes nothing. Owner decision F206 says the operator may
read merchant catalogues but not edit them, and an operator limb here would widen what F206 narrows.

#### Recorded, not fixed

- **`show()` on both controllers is an existence oracle.** Its body is a bare `//`, so it leaks no
  content — but `SubstituteBindings` resolves the row first, so it answers 200 for any id that
  exists in any store and 404 for one that does not, with no permission check. That partly
  undermines the property `edit()`'s 404 was chosen for. One-line close if wanted:
  `->except(['show'])` on the resource route; nothing references `product-brand.show`.
- **The toggles are now the inconsistent half.** `resolveOwnedBrand()` grants the operator a pass,
  so the operator may still flip `status`/`is_popular` on any store's brand — and `status` is what
  the storefront filters on. If F206 is meant to reach brands, that argues for narrowing the
  toggles, not widening the editor. **An owner decision, not mine.**
- `product_brand/action.blade.php` renders its pencil and delete controls with no `@permission`
  wrapper, so adding the permission check to `edit()` makes the pencil a dead control for a
  `Manage`-only staff member. UI follow-up, not security.
- The vendored `ImportExport` module's `exportBrand()`/`fileImport()` have no permission check,
  though both are tenant-scoped. Vendored — not ours to edit.

#### And a ninth instrument error, caught by its own positive control

The first ProductLabel fixture invented a `color` column. `product_labels` has none, so all four
label tests died inside the fixture before reaching a route — and three of them "failed" in a way
that looked **exactly** like the cross-tenant hole they were written to prove. The positive control
failing alongside them is what gave it away. A test that fails for the wrong reason proves nothing,
and three of those failures would have been reported as evidence.

---

### F-SEC-02 — the F-SEC-01 shape was not confined to brands and labels

**Status:** FIXED and proved on four more controllers plus one endpoint that had **no authorization
of any kind**. **A tenancy finding, not a design one**, recorded here because it was found during
the Package B catalogue pass. Independently reviewed by `tenant-authz-reviewer`, which found one
live hole the census had missed and refuted two of my own claims.

#### Why a census, and why its output is not evidence

F-SEC-01 was fixed on two controllers. The obvious next question — how many more — needed a
measurement, because the existing coverage had already *looked* like coverage and wasn't:
`BrandLabelStatusAuthorizationTest` has fifteen tests and every one drives the two brand methods
that **were** hardened.

`docs/development/phase-2.5/tools/census-unowned-model-resolution.php` walks PHP's own token stream
over all 132 controllers in `app/Http/Controllers/` and reports single-record methods that resolve
a model from caller-supplied id where the model is absent from `config/tenancy.php`'s
`scoped_models` — so `StoreScope::isActiveFor()` short-circuits and no predicate is added for any
principal.

**The census was wrong four times before it was believed, and every correction mattered:**

| reading | why it was wrong | consequence |
|---|---|---|
| `$x->store_id = getCurrentStore()` counted as an owner check | an ownership **assignment** is not an ownership **check** | hid **both** `update()` methods — i.e. the takeover primitive itself |
| `\Auth::user()->creatorId()` counted as an owner check | it bills a storage quota to the caller's own account; the caller naming *themselves* says nothing about who owns the row | hid `ProductBrandController::destroy`, a **proved** hole |
| `update()` reported as TAKEOVER on the *fixed* tree | the detector matched the comment quoting the line it had **deleted** | the eighth time a detector in this phase matched its author's prose |
| `CategoryController::update` reported unguarded | its guard is `categoryIsOutsideCurrentStore()`, a name my hand-written list did not hold | **contradicted a correct human review**; a list of guard names can only recognise guards its author already knew |

Fixed respectively by stripping assignments before the scan, requiring every owner marker to sit in
a comparison / query predicate / refusing call, excluding `T_COMMENT` from the token stream, and
resolving same-class guard helpers transitively to a fixpoint. It now passes four oracles — all
seven proved-holed pre-fix methods surface; the fixed tree reads as fixed; it agrees with the human
review on `CategoryController`; and it agrees with the registered policy on `SavedViewController`
(`$user->can('delete', $view)`, which it had to learn to credit, since a **one**-argument
`->can('Permission')` is always false here while a **two**-argument one is a real policy call).

**It is still only a shape detector.** Every row below was driven with a real request before being
called anything.

#### What was reachable

`tests/Feature/Security/SettingsAndContentCrossTenantTest.php`, failing first. **Reverted: 12
failed, 5 passed** — the 5 are every positive control, so no fixture was broken. **With the fix: 17
passed.**

| request | by a principal holding | result before |
|---|---|---|
| `GET /pixel-setting/{B}/edit` | **nothing at all** | **200, carrying store B's tracking id** |
| `PUT /pixel-setting/{B}` | **nothing at all** | **rewrote the id, and moved the row into store A** |
| `DELETE /pixel-setting/{B}` | any merchant owner | **deleted store B's pixel row** |
| `GET /taxes/{B}/edit`, `PUT`, `DELETE` | `Edit Tax` / `Delete Tax` | **opened, renamed, took, deleted** |
| `GET /taxes/{B}` | `Show Tax` | **200, store B's tax name in the breadcrumb** |
| `PUT /pages/{B}`, `DELETE`, `GET …/edit` | `Edit Menu` / `Delete Page` | **rewrote, took, deleted, opened** |
| **`POST /update-page-status`** | **nothing at all** | **took store B's live storefront page offline** |

#### Severity, stated accurately — it was live for every merchant by default

Understated once on F-SEC-01, so measured this time. `Utility::addNewData()` runs on **every
login** and grants the shared global `admin` role — the role every self-registered merchant is
attached at registration — `Edit Menu`, `Manage/Create/Edit/Delete Page` and
`Manage/Create/Show/Edit/Delete Tax`. **No admin intervention, no role editing, no privilege grant
was required.** PixelFields and `update-page-status` were broader still: reachable by *any*
authenticated, verified principal, including a staff member holding zero permissions.

The pixel write is the worst of them, because the Marketing module emits those rows as **script
into the victim's storefront pages** — so it executes in the victim's visitors' browsers.

#### The one the census never looked at

`PageController::updateStatus()` was found by the **human review**, not the census: the census
filters on a list of method names that held `changeStatus` and not `updateStatus`.
`resolvedModels()` would have matched its `Page::findOrFail(` perfectly well — only the spelling
excluded it. The list has been widened to every status-write spelling actually present in the tree,
and re-running it found no further unguarded status endpoint. **It remains a list, and that remains
its limit — which is the argument for not trusting a census alone.**

#### Shape of the fix

As F-SEC-01, reviewed and accepted there: a private `callerOwnsX()` helper failing closed when
either side is empty; `abort(404)` for foreign-or-absent so the response is not an existence
oracle; the guard above every validator and every write; and the `store_id` assignment **deleted**
rather than guarded, so a future weakening re-opens an unauthorised edit and not a transfer.
404 rather than 401 throughout — these are web routes the Flutter app does not call, so no forced
logout.

`show()` was closed on five controllers (Page, PixelFields, ProductBrand, Category, Tax). Four have
an empty body, but binding resolves the row first, so the response was an empty 200 for a row
existing in **any** store and 404 only for one existing nowhere — an existence oracle over every
id. `TaxController::show` was not an oracle but a real read: `taxes/show.blade.php:7` renders the
name. The table beneath it was never the leak — `TaxMethodDataTable::query()` already filters
`store_id` as well as `tax_id`.

#### Claims of mine the review refuted, corrected in place

1. **"No pixel permission exists to check."** False. `business pixel settings` is seeded in the
   Marketing module. The decision not to gate on it stands for a better reason: it is a *module*
   permission, `Utility::addNewData()` never grants it, the module seeder's own grant is skipped on
   any install where the row already exists, and the first-party UI renders Add/Edit/Delete with no
   `@permission` wrapper — so gating would refuse merchants their own settings on an
   install-dependent basis.
2. **"These methods already refuse the operator because `isAbleTo()` alone does."** True in effect,
   wrong in mechanism. It holds only because `$superadminNewPermission` contains no Tax or Page
   permission. If an operator is ever granted one, the helper fires and `getCurrentStore()` returns
   the hardcoded literal **1** for a super admin with an empty `current_store`, so the operator
   would be silently scoped to store 1 rather than refused.
3. **"`destroy()`'s `type == 'admin'` is not a gate at all."** Overstated. It is not a *tenant*
   gate and admits every merchant owner, but it does exclude `staff` and `super admin` — which is
   the reason to **leave** it, not merely a reason not to bother: removing it would *grant* staff a
   delete they do not have today.

#### Recorded, NOT fixed

- **Still the same shape, not yet driven by a request:** `BlogController::update/destroy`,
  `ContactController::update/destroy`, `CouponController`, `FaqController`, `TagController`,
  `TestimonialController`, `ProductQuestionController`, `NewsletterController`,
  `BlogCategoryController`. All are tenant data (`store_id` present, verified against the live
  schema). 33 unguarded writes remain in the census. Each is a **shape**, not a finding.
- **A separate concern, not cross-tenant:** `CityController`, `CountryController`,
  `StateController`, `TaxMethodController` write with **no permission check**, but City/Country/
  State/Plan/PlanCoupon/Store/User carry no `store_id` at all — these are platform surfaces, so
  the question there is authorization, not tenancy.
- **Intra-tenant gap (owner decision):** any principal inside a tenant, staff holding nothing
  included, can still read and rewrite their own store's pixel ids. Pre-existing.
- **Permission mismatch (owner decision):** `PageController::update()` demands `Edit Menu` while
  `page/action.blade.php` gates the Edit button on `Edit Page`. Changing which permission the
  handler demands is a business-rule change. `taxes/action.blade.php` wraps its Edit button in no
  `@permission` at all, so a principal with only `Manage Tax` now sees "Permission denied" one
  click earlier than before.
- **Lockout edge, shared with the accepted F-SEC-01 fix:** `getCurrentStore($user->id)` falls back
  to `Store::where('created_by', $user->id)` — the staff member's **own** id, not `creatorId()` —
  so a staff member with an empty `users.current_store` now gets 404 on their *own* store's pixel,
  tax and page rows. Not a live regression (`UserController:161` copies the creator's
  `current_store` into every staff row at creation) and identical to `callerOwnsBrand`, so
  consistency says accept — but it is the one way this change produces a support ticket.
- **Form-vs-submit asymmetry:** `TaxController::create()` and `PageController::create()` render
  their forms with no permission check while `store()` requires one. Discloses nothing
  cross-tenant; left as hardening.

#### Evidence

`SettingsAndContentCrossTenantTest` 17 passed (12 failed on revert); `BrandCrossTenantReadWriteTest`
9 passed; `ContentAndIntegrationRefusalNamesEveryFieldTest` 24 passed; Unit 1414 passed; Contract 27
passed; `tests/Feature/Security` + `tests/Feature/Tenancy` **5326 passed, 1 declared skip**.

---

### M-PKG-B-02 — the two Package B modal "findings" were both instrument errors

**Status:** CLOSED by measurement. Neither was a defect. Recorded because both were written down as
findings and would otherwise have been "fixed".

#### 1. `.modal-footer` nested inside a `.row` — a shape with no consequence

`verify-package-b-modals.cjs` asserted `!dlg.querySelector('.row .modal-footer')` and reported
**both** opened modals as defective in **all four** combinations. The markup really is nested, in
**81 views**. But that is deliberate: **P2.5-WAVE-20** (`3a1f98f7`) measured the actual defect — the
divider ran 12px wider than the field above it, because Bootstrap gives every `.row > *` the 12px
gutter while `.modal-footer` keeps its own 16px, and `border-top` is drawn on the padding box — and
corrected **the box** in `custom.css` / `rtl-custom.css` rather than restructuring 81 templates.

So my harness tested a markup proxy for a property another slice already owned and had measured
away. `tests/Unit/Ui/ModalFooterDividerAlignmentTest` **5 passed** on the current tree. The
assertion is replaced by a `note()` that records the nesting and names the owning test.

**The lesson, which is the reason this entry exists:** duplicating an authoritative measurement
with a weaker proxy is how a harness ends up contradicting a correct fix. The census in F-SEC-02 hit
the same class of error from the other direction.

#### 2. "`attribute-create` measured only 3 text nodes for 4 fields" — a conflation in my own note

`measured` counts text nodes that survived the **contrast filter**. It is not a field count. The
same harness reports, on the same run, `attribute-create modal really opened with fields (4)` — the
four fields are there. A field whose label is `Form::label('')` contributes no text node at all, and
**P2.5-WAVE-26 found 127 of those**, so a low count is normal and says nothing about missing
fields.

The check is now a stated **premise check on the instrument** with a justified floor of 2 (every
modal in this family renders at least a title and one button label, so fewer than 2 means the dialog
did not really open and the contrast verdict above it is worthless).

#### What the corrected harness actually measures

**64 checks, 0 failed. 16 captures, 4 modals × 4 combinations**, under
`evidence/pkg-b-modals/after/`.

| modal | fields | worst text contrast (worst of 4 combinations) |
|---|---|---|
| category-create | 11 | 6.42:1 |
| attribute-create | 4 | 6.54:1 |
| brand-create | 9 | 12.93:1 |
| label-create | 6 | 12.93:1 |

All four carry an accessible name (`aria-labelledby=modelCommanModelLabel`).

#### The remaining note is a fixture gap, not a defect

`brand-create` and `label-create` showed "no VISIBLE trigger" until the dev merchant was granted
`Create Product Brand` / `Create Product Label` — both index views wrap the trigger in
`@permission(...)`, which is correct behaviour and correct Laratrust (`@permission` works here;
`@can` does not). Granted with `grant-dev-screen-permissions.php`, both modals then opened and
passed. `coupon-create` still has no visible trigger and that is also correct: `/coupon/create` is
permission-refused for this fixture.

**This is the trap `grant-dev-screen-permissions.php` exists for:** a screen the fixture role cannot
open answers with a *redirect to the dashboard*, so a harness records HTTP 200 with the wrong title
and a screenshot of the dashboard. Four Package B surfaces were "no visible trigger" for exactly
this reason and none of them was a defect.

#### An unplanned reproducibility check, worth keeping

The category and attribute captures were already committed in `d6d25a1b`. Re-running the harness
regenerated them **byte-identically** (sha256 `3410d4e4195b8920…` and `aa5d55f8414e8432…` before and
after), which establishes two things for free: the harness is deterministic across runs, and nothing
changed between `d6d25a1b` and `014da134` — the `.ss-text-danger` markup sweep and the four ds.css
regions included — altered those two modals in any of the four combinations. Only the 8 new
brand/label captures are new content.

---

### M-PKG-B-03 — all 37 Package B surfaces are now reachable; two of the three blockers were mine

**Status:** CLOSED. The reachability probe went from **PAGE=9 FRAGMENT=13 REFUSED=9 NO-FIXTURE=6**
to **PAGE=16 FRAGMENT=21, nothing refused, nothing unfixtured, no 500s.**

Fifteen Package B surfaces had never been rendered in any locale or theme. None of the three causes
was a product defect, and two of them were errors in my own instruments.

#### 1. Nine "REFUSED" — seven were a real fixture gap, two were a wrong URL

Seven needed permissions the dev merchant did not hold (`Manage Attributes Option`,
`Create Attributes Option`, `Manage Tag`, `Create Coupon`, `Manage/Create Flash Sale`,
`Manage Product Question`, `Manage Testimonial`, `Manage Faqs`, then `Edit Coupon`,
`Edit Flash Sale`, `Edit Faqs`). Granted with `grant-dev-screen-permissions.php`. Correct
behaviour throughout — and `brand-create` / `label-create` were refused by `@permission(...)` in
the index views, which is correct *Laratrust*: `@permission` works here, `@can` never does.

**The two attribute-option rows were my probe's fault.** It requested `/attribute-option` and
`/add-attribute-option` with no id. Those routes are `{id?}`, the id is an **attribute** id, and
`show()` calls `ownedAttribute(null)` which fails closed and returns `denied()` — correct
behaviour, and *not* a permission refusal. The permissions were held and active the whole time.
Measured after correcting the URL: `/attribute-option/1` renders, `/add-attribute-option/1` returns
the fragment.

#### 2. Six "NO FIXTURE" — `coupons`, `flash_sales`, `product_questions`, `testimonials` and `faqs` all held zero rows

An edit modal with no row to edit cannot be driven at all. `seed-dev-interaction-fixtures.php` was
extended with one tagged row per table, following the discipline that file already sets: idempotent
(verified — a second run changes nothing), and `--remove` deletes exactly those rows and nothing
else (verified — the five tables go to 0 while `products` 32, `categories` 4 and `customers` 3 are
untouched). Each row is shaped for the surface rather than merely present: the product question is
left **unanswered**, because an answered one renders no Reply control, and the coupon expiry is in
the **future**, because an expired coupon renders a different state.

#### 3. One HTTP 500 — and it was my fixture, not the product

`/faqs/{id}/edit` answered **HTTP 500** at `faq/edit.blade.php:12`,
`foreach() argument must be of type array|object, null given`. It read as a textbook defect: the
view guards with `@if(!empty($faq->description))`, which tests for EMPTY and not for VALID JSON, so
`json_decode()` returned null and `foreach(null)` threw.

**The app cannot reach that state.** Driven, in this order:

- `FaqController::store()` writes only `topic` and `store_id` — it never touches `description` — so
  every FAQ created through the UI has `description` NULL. Driven: NULL renders **HTTP 200** via the
  `@else` branch.
- `FaqController::update()` writes `$request->kt_docs_repeater_basic`, and `repeater.js` rewrites
  the field names on init to `kt_docs_repeater_basic[0][question]` / `[0][answer]` — read off the
  opened modal, not assumed. Driven end to end through the modal: the stored value is
  `[{"question":"…","answer":"…"}]`, valid JSON, and `edit()` then renders it at **HTTP 200**.

So the only way prose gets into that column is by hand, which is what my first fixture did. The
fixture now writes the shape `update()` writes, with **two** items, so the `@foreach` branch — the
actual repeater, and the reason the surface is called "FAQ edit (modal, repeater)" — is exercised
instead of the empty `@else` fallback. The seed tool now **exits non-zero** if that column does not
decode to an array, so the same mistake cannot be made silently again.

#### The pattern across all three

Of the fifteen blocked surfaces, **thirteen were fixture or probe state and two were my own
instruments reporting their own errors as product defects.** This is the third time in Package B:
the modal harness asserted a markup shape another slice had measured away (M-PKG-B-02), and the
census read an ownership assignment as an ownership check (F-SEC-02). The useful discipline is the
one `grant-dev-screen-permissions.php` already documents — a screen the fixture cannot open answers
with a *redirect to the dashboard*, which a harness records as HTTP 200 with the wrong title and a
screenshot of the dashboard, and that is the single most dangerous reading this project can take,
because it looks like evidence.

#### A scratch probe nearly shipped, and the ratchet for it was DELIBERATELY NOT built

`git add -A` swept `tests/Feature/Merchant/ZzScratchStockSortProbeTest.php` into the M-PKG-B-03
commit. It was a diagnostic left from earlier in the session: four methods named `probe`,
`controls`, `search debug` and `header affordance`, writing measurements to STDERR, and its only
assertion was **`$this->assertTrue(true)`** — an assertion that cannot fail, which is the exact
anti-pattern this project's TDD rule exists for. Removed from the (unpushed) commit and deleted.

It passed, so the known-outcome gate would not have caught it, and
`NoTrackedTestArtefactsTest` covers generated uploads and fixtures rather than scratch tests — so
**nothing in CI would have stopped it.** Its measurement was real but not a finding: the product
DataTable's columns are all `orderable=true` and `"ordering":false` is absent, i.e. the table sorts
as intended.

A ratchet against "a test method whose only assertion is `assertTrue(true)`" was considered and
**rejected on measurement**. Five tracked files contain that call; four of them
(`PlanActivationReplayGuardTest`, `StoredFilePathSafetyTest`, `NullTenantGuardCanaryTest`,
`LynomiaAuditPreservationTest`) use it incidentally alongside between two and nine other assertion
types, so a blanket ban would redden four passing tests and force unrelated edits. The only
assertion-free file is `tests/Unit/ExampleTest.php` — **Laravel's shipped scaffolding**. So the
ratchet would need an exemption for framework scaffolding in order to guard against one file that
is already gone, and an exemption list on a hygiene check is the kind that rots into noise.
Recorded rather than silently skipped.

---

### M-PKG-B-04 — the 16 Package B pages, driven in all four combinations: 448 checks, 0 failed

**Status:** VERIFIED. `verify-package-b-pages.cjs` extended from 9 pages to **16** — the seven that
M-PKG-B-03 unblocked — and run across AR RTL / EN LTR × light / dark. **448 checks, 0 failed, 64
captures** under `evidence/pkg-b-pages/after/`.

Every surface passes, in every combination:

| check | result |
|---|---|
| premises (the combination asked for is the one that rendered, on the page asked for) | pass |
| measured text nodes (not an empty page) | 31–144 per page |
| no sideways scroll at 1440 | 0px everywhere |
| no emoji used as an interface icon | 0 |
| one icon family | tabler only, **0 feather** |
| worst text contrast | **4.89:1** ≥ 4.5 |
| first real content | **174–375px** |

The first-content figure is the one worth keeping: WAVE-24 found merchant screens whose first data
sat at **1358–2131px**. These answer the merchant inside the first screenful.

#### The ids are read from the probe's JSON, not written into the harness

The seeded rows' ids move every time the fixture is removed and re-seeded — the coupon went **2 → 3
within one session**, because sqlite's AUTOINCREMENT does not reuse a deleted id. A literal
`/coupon/3` in the harness would read as a 404, and then as a defect, the next time anybody runs
the seed tool. `verify-package-b-pages.cjs` now reads `package-b-reachability.json`'s `ids`.

#### Why twelve of these supersede `evidence/pkg-b/after/`

Compared against the earlier `pkg-b` capture set: the six product-editor captures are
**byte-identical**, and twelve **list-page** captures differ. The difference is explained and
expected — it is the permission grants and the seeded rows, measured:

- `/product-brand` now renders a **"+ Add Brand"** control in the page header. Before the
  `Create Product Brand` grant the probe measured *"no VISIBLE trigger fetching
  /product-brand/create (0 matched)"*; after it, the modal opens. The screenshot corroborates the
  measurement rather than standing in for it.
- The sidebar's Catalogue zone now carries **Testimonial** and **Question Answer**, from
  `Manage Testimonial` and `Manage Product Question`.
- The coupon list has a row where it had none.

So the earlier set shows a merchant missing half their own controls, and this one shows the UI a
merchant with ordinary permissions actually sees. `evidence/pkg-b/after/` is kept as the record the
earlier wave was accepted on; for the twelve list pages it is **superseded**, and it should not be
cited as current.

All 64 come from **one run** of the tool that produced the numbers, so the pictures and the figures
cannot disagree — the discipline WAVE-20 set.

---

### F-EVIDENCE-01 — SIX surfaces were marked PREMIUM VERIFIED on pictures of a permission refusal

**Status:** CORRECTED, with two gates added so it cannot recur silently. **This is the most
serious finding of Package B**, because it is not a defect in the product — it is a defect in the
*evidence*, and the directive's §6 turns on evidence being true: *"Do not mark a screen premium
simply because it renders successfully"*, *"Do not fabricate, duplicate or overclaim evidence."*

#### What was wrong

Six surfaces in `wave-33` carried four captures each that photograph a **permission refusal**
rather than the screen claimed:

| surface | what the capture actually shows |
|---|---|
| FAQ list | the **Dashboard** + "Permission denied." |
| Flash sale list | the **Dashboard** + "Permission denied." |
| Delivery boys | the **Dashboard** + "تم رفض الإذن" (AR) |
| Tag list | **Stock Reports** + "Permission denied." |
| Testimonial list | **Stock Reports** (byte-identical to Tag's) |
| Product question list | the **Product grid** + "Permission denied." |

All six were **PREMIUM VERIFIED** in the coverage matrix. The cause is the trap
`grant-dev-screen-permissions.php` already documents: the dev fixture held no `Manage *`
permission, a Laratrust refusal is `redirect()->back()`, and the sweep followed the redirect and
photographed the landing page — *"the single most dangerous reading this project can take, because
it looks like evidence."* `build-coverage-matrix.py` checks the capture **files exist**; it cannot
see inside them, so a refusal satisfied it perfectly.

Three more surfaces had the same false captures but were **not claimed** in the matrix
(`_shipping__`, `_pos__`, `_shopify_product__`), so they were dead files rather than false claims.
All 36 false captures are deleted: a file named `_faqs__en_ltr_light.png` that shows the dashboard
is not evidence, it is the hazard.

#### The correction

Five of the six now point at **real** captures taken in this package after the permissions were
granted and the fixture rows seeded (`evidence/pkg-b-pages/after/`). **`Delivery boys` has no real
capture and is therefore back to PENDING** — which is the truth: it has never been rendered. The
matrix reads 295 surfaces, PENDING 237, PREMIUM VERIFIED 24, REDESIGNED AND VERIFIED 24.

#### Two gates, and the three wrong tools it took to get there

Recorded because the dead ends are the useful part:

1. **Whole-frame comparison against the dashboard: no separation at all.** Legitimate captures
   scored 89.8–90.0% and the impostors 90.0–91.5%, because every merchant screen shares the
   sidebar, header and background with the dashboard. At a 0.90 threshold it **accused about
   thirty good captures**.
2. **Content-region comparison against the dashboard: worked, but only for three of the six.**
   `Tag list` and `Testimonial list` had landed on Stock Reports and `Product question list` on
   the Product grid, because `back()` returns to whatever the sweep visited previously. A
   destination-based detector cannot see those, and it did not.
3. **Greyscale over the toast region: no separation either** — 93.6% for a legitimate footer
   capture, because that region is mostly page background.

What works is the **refusal toast's saturated red**, which is the one thing all six share whatever
the destination. Calibrated by eye on both sides: confirmed refusals run **1.13–1.80%** toast-red
in the top-right corner; the closest legitimate capture is `_coupon-empty__ar_rtl_light.png` at
**0.47%**, whose red is the bulk-delete trash button in the Arabic empty state. The threshold sits
in that gap.

| tool | finds | dependency | wired |
|---|---|---|---|
| `check-distinct-surface-captures.py` | two surfaces filed with one picture, **within one wave** | none — hashes files | **CI, every push** |
| `detect-refusal-toast-captures.py` | a **lone** impostor, by the toast itself | Pillow | manual |

The second is not in CI because adding an image library to every run is a dependency decision for
the owner — the same reason the design system's browser half stays manual.

The first needed one narrowing, and it is structural rather than an exemption: the comparison is
made **within one wave's directory**. Across waves two names legitimately describe one rendering —
wave-16b captured `_customer_timeline_1__` as a surface and wave-16c captured `timeline-pills__` as
the component verified on that same page, identical files and both correct. Every true positive was
intra-wave.

Both gates were **premise-tested**: restoring the `_tag__`/`_testimonial__` pair from git history
makes the first report a collision and the second flag both files, and removing it returns both to
clean.

#### What a clean result does not mean

Neither gate proves a capture shows the *right* screen. A redirect that produced no toast, a
capture of the right screen in the wrong state, and a lone impostor in a wave where nothing else
collides all still pass the CI half. **Every remaining wave-33 claim should be re-verified against
a correctly-permissioned fixture before Phase 2.5 is presented for acceptance** — this finding says
the sweep that produced them could not tell a refusal from a screen, not that the rest are wrong.

---

### F-SEC-03 — a cross-tenant catalogue read, and a cross-tenant WRITE in the same file

**Status:** FIXED and proved. Four handlers in `TestimonialController`. Reviewed by
`tenant-authz-reviewer`, which confirmed the first fix, **refuted a claim in my own docblock**, and
found the write — which is worse than the read I had set out to close.

#### 1. `POST get-product` returned every tenant's product names and ids

```php
$Product = Product::where('category_id', $request->id)->get();   // no store predicate
```

No permission check, no ownership check — and `Product` is absent from `config/tenancy.php`'s
`scoped_models`, so `StoreScope::isActiveFor()` short-circuits and the global scope adds **no
predicate for any principal**. CLAUDE.md records that property and records that it already produced
a live cross-tenant stock drain (S19).

**Reachable:** `routes/web.php:944` registers it as `POST get-product` inside
`['auth','xss','setlocate','verified']` — a *separate* declaration, not part of
`Route::resource('testimonial')`.

**Severity:** any authenticated web principal in any tenant, **holding zero permissions**. Category
ids are small per-store integers with an FK to `categories(id)`, so this is enumeration, and the
product ids it returns then feed the handlers in the "not fixed" list below.

**The strongest evidence the fix restores intended behaviour rather than inventing it:** the
server-rendered twin of this exact query, `edit()` at `:140`, **already had**
`->where('store_id', getCurrentStore())`, and so does the ProductReview module's controller. Only
the AJAX path was missing it.

#### 2. `edit()`, `update()` and `destroy()` — a cross-tenant WRITE

Found by the review of the above, not by me. `Testimonial` uses only `HasFactory, Cachable` and is
deliberately absent from `scoped_models`, so `Route::resource`'s implicit binding resolves **any**
row by bare primary key:

| handler | gate before | effect |
|---|---|---|
| `edit()` | **nothing at all** | read another store's testimonial |
| `update()` | `Edit Testimonial` only | **rewrote** title, description, rating, status, username on a foreign row |
| `destroy()` | `Delete Testimonial` only | **deleted** it — and `changeStorageLimit(creatorId(), $avatar)` ran first, so the **attacker's own** storage quota was debited for the victim's file |

The index DataTable **is** store-filtered, so an attacker is never *shown* a foreign id — they are
small sequential integers, the same enumeration argument as above.

#### A claim of mine the review refuted

My docblock said the lookup needs no permission check because *"the form that calls it is already
gated"*. **That is false:** `create()` has no permission check at all, so `GET /testimonial/create`
renders for any authenticated principal including staff holding nothing. The conclusion survives
for a better reason, now recorded in the code: the endpoint is **shared by three unrelated
features** — the testimonial form, `wishlist/index.blade.php`, and the ProductReview module — so
any single permission name would break the dropdown for the other two.

#### Evidence

`TestimonialProductLookupCrossTenantTest`: **8 passed**. Reverted: **6 failed, 2 passed** — the two
being the positive controls, which hold in both states.

Two fixture errors were caught on the way, both by a failing positive control rather than by luck:
`products.category_id` and `testimonials.category_id` each carry an FK to `categories(id)` (the
latter **defaults to 0**, which is not a valid id), and `testimonials.status` has no `->nullable()`,
so a payload omitting it writes NULL and the row is rejected by the column — which made the
**owner's** own rename fail and would have rendered the three cross-tenant passes meaningless.

#### A branch that could not fail, now ratcheted

The review found that reverting the `empty($currentStore)` short-circuit **alone** left all tests
green — half the fix was untestable. `products.store_id` is nullable and `where('store_id', null)`
compiles to `store_id is null`, so without it a principal with no current store is served every
**orphan** product on the platform. There is now a test with exactly that principal and an orphan
row.

#### Recorded, NOT fixed — named with file:line by the review

- **`ProductController::getProductsVariantQuantity()` (`:5369`, routed `GET
  get-products-variant-quantity` at `routes/web.php:1350`)** — returns another tenant's **price and
  per-variant stock** as JSON. Certain and live for any authenticated principal. **This is the
  sharpest of the remainder.**
- `ProductController::productVariant()` (`:5361`), `PreOrderSettingController::getVariants()`,
  `ProductPricingController` (`:199`, another store's discount rules),
  `CustomerDonationController::get_donation_amount()` — same shape.
- **Unescaped output:** `$Category->name` is concatenated into `<option>` with no `e()`
  (`TestimonialController:284`, same at `CategoryController:656`), and the `xss` middleware strips
  only `&lt;`, `&gt;`, `javascript` and `alert`, so `<img onerror=…>` survives storage. My fix
  **narrowed** this from cross-tenant stored-XSS to intra-tenant; it is a one-token fix and belongs
  in its own change.
- **`create()` has no permission check** while `store()` requires `Create Testimonial` — the
  form-vs-submit asymmetry, same as the ones already recorded.

#### Why neither census found the read

The controller census filters to **single-record method names** (`show`, `edit`, `update`,
`destroy`, status toggles). `get_product` is a **list query** under none of them. The review names
the productive signature instead: `->where('<x>_id', $request->…)` or `::find($request->…)` with no
sibling `store_id` term, plus `<option value=` concatenation in a controller — which matches exactly
one handler in `app/Http/Controllers/` and two under `packages/workdo/`.

---

### F-SEC-04 (QUEUED, NOT YET DRIVEN) — seven more controllers of the F-SEC-02 shape, independently confirmed

**Status:** CONFIRMED BY ANALYSIS, **not yet driven by a request**, therefore **not yet a finding**
by this project's own rule. Recorded so the next slice starts from evidence rather than from the
census's raw output.

The 33 unguarded writes F-SEC-02 left in the census were each a *shape*. Ten controllers were read
independently — one agent per controller, read-only, briefed on this codebase's counter-intuitive
facts — and every hole claimed was then put to **two adversarial verifiers with distinct lenses**:
one hunting for the guard the report missed, one hunting for a reason the row is unreachable
regardless of any guard.

**8 of 10 survived 2/2. One of those eight was `TestimonialController`, fixed in F-SEC-03.** Seven
remain:

| controller | shape |
|---|---|
| `BlogController` | `update`/`destroy` permission-checked, owner never; **`edit()` has no permission check at all** |
| `BlogCategoryController` | same, cascading to `Blog` |
| `ContactController` | bound `Contact`, no owner check |
| `FaqController` | `edit`/`create` ungated; `update`/`destroy` owner-less |
| `NewsletterController` | `destroy` owner-less |
| `ProductQuestionController` | reply modal has no permission check **and** no tenant predicate |
| `TagController` | `edit`/`update` use `Tag::find($id)` with no store predicate |

They share one remedy — the `callerOwnsX()` + `abort(404)` pattern already accepted on F-SEC-01/02/03
— which makes them **one coherent slice rather than seven waves**, as §1 asks.

**`BlogController` is the sharpest, and it shows my census UNDER-reported:** `edit()` has no
permission check whatsoever, so any authenticated web user — staff holding zero permissions — reads
any tenant's full blog post by id. The census never listed `edit()` at all, because it only reports
a *read* when the method also lacks an owner marker **and** it had classified that method as
guarded. `Edit Blog` and `Delete Blog` are both granted to the shared global `admin` role on every
login, so the write holes were live for every self-registered merchant.

#### The two that were refuted, and why the second matters

- **`PlanCouponController` — correctly restricted.** `plan_coupons` has **no `store_id` column at
  all** (verified independently in my own triage), so there is no cross-tenant question; all seven
  routes carry `PlatformOperatorOnly`, which admits only `'super admin'` and `abort(403)`s everyone
  else.
- **`WoocomCouponController` — a FALSE POSITIVE my census could not have seen.** Its
  `Coupon::find($id)` is `Codexshaper\WooCommerce\Facades\Coupon` — a **remote HTTP call to the
  merchant's own WooCommerce shop**, not an Eloquent read of a local row. The local writes are two
  INSERTs, so the `store_id` assignments stamp new rows rather than moving existing ones.

**That is a real limitation of the census, now known:** `resolvedModels()` matches
`ClassName::find(` **lexically** and never resolves the name through the file's `use` statements,
so any imported facade or third-party class sharing a model's name reads as that model. It is why
the takeover column fired on a row that creates records remotely. Worth fixing before the census is
pointed at `packages/workdo/`, where imported facades are common.

#### What is NOT claimed here

Nothing in this entry has been driven by a request. Static analysis plus two refuters is a strong
reason to **look**, and this project has measured repeatedly that it is not a substitute for
looking: the census was wrong four times before it was believed, and three of the F-SEC-03 "failures"
were a fixture inventing a column. Each of the seven gets a failing test, a positive control, and a
mutation check before it is called fixed.

---

### F-SEC-05 — seven more controllers, driven and fixed; and four corrections the review forced

**Status:** FIXED and proved on all seven. **20 guards, 7 helpers.** Independently reviewed, which
corrected four of my own claims and found one branch of my fix that could not fail.

#### What was reachable, pre-fix, by any self-registered merchant incrementing an id

Every permission family below is granted to the shared global `admin` role on every login
(`Utility::addNewData`, `$companyNewPermission`), so no admin intervention was required.

| controller | cross-tenant read | write | delete |
|---|---|---|---|
| Blog | **yes, NO PERMISSION NEEDED** (`edit()` ungated) — full post | yes | yes |
| BlogCategory | yes, ungated | yes | yes |
| Faq | yes, ungated | yes | yes |
| Tag | yes, ungated | yes | yes |
| ProductQuestion | yes, ungated — question text **and the victim's product name** | yes | yes |
| **Contact** | **yes, ungated — first/last name, email, phone, subject and message body of people who wrote to another merchant** | yes | yes |
| **Newsletter** | **no** | n/a (inert) | **yes** |

Measured: 7 failed / 7 passed before, 16 passed after, 7 failed / 7 passed on revert.

#### Four claims of mine the review corrected

1. **"21 guards" — wrong, there are 20.** I counted `abort(404)` occurrences; `NewsletterController`
   already contained one. Newsletter has 2 guards (edit, destroy) because its `update()` is inert.
2. **"The guard is above every validator" — wrong for two of them.** In `BlogController::update`
   and `BlogCategoryController::update` the guard sat *below* the validator. No write happens in a
   validator, so it was not a hole — but it left a response-shape oracle: an **invalid** payload
   returned the validation redirect and a **valid** one returned 404, which distinguishes "exists
   elsewhere" from "refused" for a caller who can vary the payload. **Both moved**; the invariant
   the comment claims now actually holds.
3. **"Answering is the only write" in `ProductQuestionController::update` — wrong, and this RAISES
   the pre-fix severity.** It also overwrites `question` from request input and rewrites
   `created_by`, so a foreign merchant could put words in a shopper's mouth and claim authorship.
4. **Newsletter subscriber emails were NOT readable cross-tenant.** Both read paths
   (`NewsletterDataTable:40`, `NewsletterExport:35-36`) are store-scoped. They were **deletable**.
   I had stated otherwise in conversation; the real finding is destruction of another merchant's
   subscriber list, not disclosure of it.

#### A branch of my own fix that could not fail

The guard in `NewsletterController::edit()` had **no test**: reverting it left the suite green,
because the Newsletter case drove `destroy` only. Now covered by an oracle test that compares the
foreign-row status against an existing-nowhere status — and **mutation-checked in isolation**:
reverting only that guard fails exactly that test and nothing else.

A second gap the review named is also closed: nothing pinned the **non-oracle property** the whole
fix is justified by. There is now a test asserting that a foreign row and an absent row both answer
404, driven on one controller of each binding style.

#### A wrong justification left in the code, and why the code is still right

Each helper's docblock says *"No operator limb, as on `ProductBrandController::callerOwnsBrand()`"*.
That precedent's stated reason is "the methods already refuse the operator, because `isAbleTo()`
alone does" — **false for these `edit()` methods, which check no permission at all.** So the fix
does narrow the platform operator here. It is **theoretical, not a live regression**: the
`super admin` role is granted none of these permissions (`Utility.php:3240-3270`), so the operator
has no menu entry and no index page to reach an id from.

#### Recorded, NOT fixed — named with file:line by the review

- ~~`NewsletterController::fileExport()`~~ **— FIXED, see F-SEC-06 below.**
- **`edit()` still checks ownership only on six of the seven**, departing from the
  ProductBrand/Page precedent which added the permission. A zero-permission staff member of the
  tenant can still read Contact submissions and full blog posts. Within-tenant bypass on personal
  data. The review confirms adding the permission would lock nobody out.
- Three more empty-body `show()` oracles: `ContactController:82`, `FaqController:70`,
  `NewsletterController:84`.
- `newsletters.email` is `unique` **globally, not per store**, so an address subscribed to store A
  cannot subscribe to store B — and an unauthenticated caller learns whether an address is a
  subscriber anywhere on the platform.
- `ProductQuestionController::more_question()` (`:221`) uses `getCurrentStore()` on a **storefront**
  route, so a merchant browsing another store's storefront sees their **own** questions rendered on
  it. Own data, so no leak — a wrong-source tenant predicate and a functional bug.
- `BlogController` has **no `show()` method** while `Route::resource` registers `blog.show`, so
  `GET /blog/{id}` is a 500 for everyone. Pre-existing availability bug, not authorization.
- **Side effect of this fix, accepted:** anonymous `POST /contacts` writes `store_id = null`; such
  rows were already invisible to the list and are now also permanently un-editable through the UI.


---

### F-SEC-06 — the newsletter export had no permission check at all

**Status:** FIXED and proved. An **authorization** gap, not a tenancy one — which is precisely why
neither census surfaced it: both look for a model resolved from caller-supplied id, and this
handler takes no id at all.

`NewsletterController::fileExport()` was four lines with no `isAbleTo` of any kind, routed at
`routes/web.php:1402` inside the `['auth','xss','setlocate','verified']` group, returning the
store's **entire subscriber email list** as a spreadsheet. `NewsletterExport::collection()` scopes
to `getCurrentStore()`, so the payload is within-tenant — but it is personal data, and a **staff
member holding zero permissions** could download it.

**Measured:** before the gate, a staff principal created with `makeStaff()` and granted nothing
received HTTP 200 and the subscriber address in the body. After: refused. Reverting the gate alone
fails exactly that test and nothing else; the positive control passes in both states.

**`Manage Newsletter`, not a new permission name.** `index()` already requires exactly that, and
the export button lives on `newsletter/index.blade.php:24` — a page the refused principal cannot
reach. So anyone who can *see* the button already holds it, and the gate locks nobody out.

Found by the tenant-authz review of F-SEC-05, not by either census. Worth noting as the cost of a
census keyed on one defect shape: it finds that shape thoroughly and is blind to every other.
