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

