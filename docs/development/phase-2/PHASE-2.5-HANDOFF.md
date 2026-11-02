# Phase 2.5 — handoff. PREPARED, NOT STARTED.

> ## READ THIS LINE FIRST
>
> **Phase 2.5 is NOT authorised to begin.** Directive E §10 asked for the handoff to be *prepared*
> and said, in as many words, **do not begin its implementation**. Nothing in this file is a task
> list to pick up; it is the state of the ground Phase 2.5 would stand on, written while that
> ground is fresh.
>
> `CLAUDE.md` §4 records what Phase 2.5 is: *"a separately approved FUTURE polish stage over Phase
> 02's surfaces — not started — must not be merged into Phase 02"*. `D-34` closes Phase 02
> engineering and says **NEXT PHASE IMPLEMENTATION AUTHORIZED: NO**. That is unchanged by the
> release-integration work, and this document does not change it.

---

## 1. What Phase 2.5 inherits, and where to look

| | |
|---|---|
| the approved scope | the six-phase Master Product Plan / Decision Register v1.0 (2026-10-14), `CLAUDE.md` §4 |
| Phase 02's requirements, verbatim | [`PHASE-02-MASTER-EXECUTION-PROMPT.md`](PHASE-02-MASTER-EXECUTION-PROMPT.md) — **not to be edited** |
| which of them are tracked | [`PHASE-02-COVERAGE-AUDIT-2026-10-25.md`](PHASE-02-COVERAGE-AUDIT-2026-10-25.md) |
| per-row status | [`PHASE-02-TASK-LEDGER.md`](PHASE-02-TASK-LEDGER.md) |
| what was built and what was not | [`PHASE-02-HANDOFF.md`](PHASE-02-HANDOFF.md) §3b and the ledger's TODO rows |
| every owner decision that governs it | [`PHASE-02-DECISION-REGISTER.md`](PHASE-02-DECISION-REGISTER.md) — `D-01`…`D-35` |
| the design system it must build on | `public/css/store-syria-ds.css`, `docs/design-system/README.md` |
| the counter-intuitive facts of this codebase | `CLAUDE.md` §1 and `.claude/skills/store-syria-laravel-tenancy/` |

**The design system is authoritative, and the design skills advise it.** `CLAUDE.md` §6: the
committed `frontend-design`, `ui-ux-pro-max`, `design`, `design-system`, `ui-styling`, `brand`,
`banner-design` and `slides` skills **advise** the Store Syria Design System; they do not replace
it. Phase 02 called none of them. Unlike the vendored superpowers subset they are **not
egress-free** — they reference external documentation, font and API hosts, one behind a credential
— and the host set is ratcheted by
`ClaudeConfigurationTest::test_the_design_skills_declare_every_external_host_they_reference`, so a
new host fails CI until somebody reads it.

---

## 2. The UI surfaces Phase 02 left unbuilt — Phase 2.5's most likely raw material

Taken from `PHASE-02-HANDOFF.md`'s own table rather than restated from memory. **These are Phase 02
rows, not Phase 2.5 scope**; whether any of them belongs to Phase 2.5 or is finished as Phase 02
work is an owner decision.

| row | what is missing |
|---|---|
| P2-03 products | the full-page editor's **media/variant UX**, contextual help, and the status/stock indicators |
| P2-04 orders | **Kanban** and **saved views** |
| P2-04-6 | the design-system restyle of that page's **shell** (its blocker is cleared; the slice is unstarted) |
| P2-05 common merchant screens | the design-system pass over **customers, stock, categories, staff and settings** |
| P2-07-8 Flutter | broader accessibility |

**One standing defect that any UI phase will meet immediately.** `custom.css:4482` carries
`html[data-ss-theme="dark"] body label { color: #9DA3B4 !important }` — a **bare element selector**
added for sidebar and DataTables chrome that catches **every** label in the application. On a white
modal that measures **2.52:1**, and roughly sixty other modal forms still carry it (ledger
**F126**). It predates the design-system conversions; the conversions are simply the first thing
that measured it.

**The rule Phase 02 learned the hard way, worth carrying:** any UI change must be checked in **both
directions (Arabic RTL and English LTR)** and **contrast-checked**. An independent verification
found option names rendering at **1.00:1 white-on-white** in dark mode, and the order status column
was below AA in **44 of 56** readings with two at 1.03:1 and 1.12:1.

---

## 3. What the release-integration work changed under Phase 2.5's feet

This is the part that is new on 2026-11-02 and the reason this handoff is being written now rather
than at the start of Phase 2.5.

### 3.1 GATE F is closed, so the financial surface is testable

Until now, six money tables and nine others had **no migration**, so a test touching a merchant
wallet, a withdrawal, an order's P&L, an FX rate or a driver's earnings either skipped or raised
"no such table". Sixteen declared skips recorded that.

All 15 tables and 3 columns now have migrations whose structure was **measured** on a restored copy
of production (`docs/GATE-F-VERIFIED-SCHEMA.md` §2, carried verbatim by
`App\Support\Database\GateFProductionSchema`). **What this means for a UI phase:** a finance screen,
a wallet widget, an earnings view or a currency-change flow can now be driven end to end in a test
instead of being asserted at source. `FinancialSurfaceCoverageTest` is the worked example.

**Three things about it a UI phase must not get wrong:**

1. **Promotion is a no-op against production.** The tables are already there with live data. A
   migration here aligns *fresh* environments; it reconciles nothing live, and must never be
   reported as having done so.
2. **The money scales are production's, and are not uniform.** `decimal(15,2)` for the wallet and
   financial tables, `(10,2)` and `(5,2)` for `delivery_earnings`, `(20,8)` for
   `currency_rates.rate_per_usd`, `(14,4)` for `zid_orders.total`. A display that assumes two
   places everywhere will round an FX rate into nonsense.
3. **The tests run on SQLite, where type affinity means the scales are NOT verified.** That is done
   on a real server by `tests/Support/Security/bin/verify-gate-f-migrations-on-mysql.php`. A UI test
   asserting a formatted money string is asserting the driver's formatting, not the column.

### 3.2 Two tenancy findings the census surfaced, which a finance UI will meet

Both were measured while resyncing `TenantModelClassificationTest`, and both are the kind of thing
a screen gets wrong silently:

- **`zid_stores.store_id` and `zid_orders.store_id` are NOT this platform's tenant discriminator.**
  They are `varchar(255)` and `varchar(191)` holding **Zid's own** merchant identifier, which is why
  neither table has a foreign key to `stores`. A Zid screen that filters on "store_id" without
  knowing which store_id it means compares a Zid merchant string against an integer tenant id — a
  predicate that matches nothing, or matches by coincidence, while looking like isolation.
- **`delivery_boy_devices`, `delivery_boy_notifications` and `delivery_earnings` have no `store_id`
  at all.** They are reached through `delivery_boy_id` and `order_id` only, so a driver's devices,
  notifications and earnings are **not** tenant-discriminated at the row level. Their isolation
  rests entirely on the `DeliveryBoy` they hang off — which *is* enforced — and on the handler
  filtering by that driver. A driver-facing screen must carry that filter itself.

### 3.3 Nothing about Phase 02's UI scope moved

No view, component, stylesheet or design token was touched by the release-integration work.
`git diff` over `resources/views/`, `public/css/` and `docs/design-system/` between the Phase 02
head and the integration head is confined to what Phase 02 itself shipped.

---

## 4. The blockers Phase 2.5 inherits OPEN, and which of them bite a UI phase

From `D-34`'s release-blocker table as amended by `D-35`. **None is closed by the release
integration**, and `main` integration is **not** production deployment.

| # | blocker | state | does it block a UI phase? |
|---|---|---|---|
| 1 | Phase 01 outstanding release gates | **OPEN** — two items closed 2026-11-02 (the D15-gated migrations; the larabug reading for a from-source install); the rest need a live server, a provider sandbox, a business fact or an owner decision | **no**, except where a screen displays a figure the business has not reconciled |
| 2 | Security incident #120 (exposed database dump) | **OWNER-DEFERRED / OPEN** under `D-35` — not fixed, not resolved; PR #122 open and deliberately unmerged | **no** for development; **YES** before real customer data or any production claim |
| 3 | GATE F | **CLOSED 2026-11-02** | it **unblocks** financial UI work — see §3.1 |
| 4 | Paytm callback activation | **OPEN** — `PAYTM_CALLBACK_ENABLED` stays shut pending authentic provider testing | **no**, unless a screen claims a plan is active |
| 5 | Benefit and Flutterwave | **DEFERRED** under `D-33`, fail-closed, unchanged | **no** |
| 6 | Android build, signing and device verification | **OPEN** — no Android SDK in the container (installing one is a vendor-licence decision), no release keystore, no device or emulator | **YES** for any Flutter UI slice that needs on-device proof |
| 7 | any other documented production gate | **OPEN** | as applicable |

**`APP_KEY` is a separate OPEN item** that the owner's demo-data statement does not touch
(`D-35`): it is in Git history and unrotated, which is why `Crypt::encrypt()` is an encoding in this
codebase and not a control. Any screen that treats an encrypted identifier in a URL as
unforgeable is wrong.

---

## 5. What a Phase 2.5 authorisation would need to say

Written as questions rather than assumptions, because guessing them is how a phase absorbs another
phase's scope — which `CLAUDE.md` §4 forbids in both directions.

1. **Scope.** Which of §2's unbuilt Phase 02 rows are finished as **Phase 02** work and which move
   into **Phase 2.5**? The two are tracked separately and must not be merged.
2. **Does Phase 2.5 begin before or after production release?** `D-34` and `D-35` leave Phase 02
   merged-to-`main` but **not** release-ready and **not** deployed. A polish phase on an
   undeployed base is a different risk profile from one on a live product.
3. **Which blockers must clear first?** Specifically #6 (Android) if any Flutter slice is in scope,
   and #2 (#120) if any slice will be exercised against real customer data.
4. **Is `F126` (the bare `label` selector, ~60 modal forms at 2.52:1) Phase 2.5 scope or a Phase 02
   defect?** It is the single highest-leverage contrast item left and it sits exactly on the
   boundary.
5. **Are the design skills in play?** Phase 02 called none of them. If Phase 2.5 does, the egress
   note in §1 applies and the host ratchet will fire on anything new.

---

## 6. Standing rules that do not change

- **Preserve every approved change.** Do not blindly merge experimental or redundant branches.
- **Preserve Flutter and Lynomia Chat compatibility, including their API contracts**, and existing
  WorkDo functionality.
- **Green CI is necessary evidence, not proof that a production release is ready.**
- Do not edit vendored `packages/workdo/` (except first-party `LynomiaChat`) as a shortcut.
- Do not rewrite history or force-push. Never commit secrets, dumps or real customer data.
- Keep tenant isolation, permissions, payments and personal data protected.
- Escalate: production deployment, merges to `main`, persistent migrations or destructive live-data
  operations, secret/credential rotation, material changes to prices/payments/stock/business rules
  or public API compatibility, vendor licence issues, departures from the six-phase approved scope.

---

## 7. Status of this document

| | |
|---|---|
| **PHASE 2.5 PREPARED** | **YES** — this file |
| **PHASE 2.5 AUTHORISED** | **NO** |
| **PHASE 2.5 STARTED** | **NO** |
| **any Phase 2.5 code written** | **NO** |

Written 2026-11-02 on the release-integration branch. It records state and open questions only; it
grants nothing and starts nothing.

---

## 9. WHAT GATE F TAUGHT, added 2026-11-02 — the one section here that is a warning rather than an inventory

Phase 2.5 will change surfaces, not schema. Read this anyway, because the pattern below is about
**any** change that makes something exist which did not, and the next such change will not be a
migration — it will be a feature flag, a module activation, a seeded row or a config key.

### Declaring fifteen tables broke the suite in FIVE distinct shapes, found in five waves

Each wave was found by a census keyed on the **previous** wave's shape, and **every census missed
the next one.** That is the finding, more than any individual defect:

| wave | shape | how it surfaced |
|---|---|---|
| 1 | tests that **SKIPPED** on a missing table | the known-outcome gate, which declares its skip set in both directions |
| 2 | tests that asserted the **ABSENCE** of a table or column | the full Feature run, as 8 failures |
| 3 | fixtures whose **model of the schema** was wrong — `NOT NULL`, an enum rendered as a `CHECK`, a *global* unique key | the full Feature run, as 5 failures |
| 4 | **absence used as a proof technique** ("a QueryException about a missing table proves the credential gate was passed"); a census classifying on `hasColumn('store_id')`; a fixture recursion cap firing at a leaf | the full Feature run, as 4 failures and 2 undeclared skips |
| 5 | **dead fallbacks that INVENT the schema**, and application code that depended on the table being absent | nothing in the suite said so — found by a Unit ratchet whose exit condition had been written down in advance, and by independent review |

**Wave 5 is the one to learn from.** Eleven test files carried
`if (! Schema::hasTable('<money table>')) { Schema::create(...); }`. GATE F made all of them dead,
which is harmless today and a loaded gun tomorrow: those blocks declared **columns only**, with
none of production's unique keys or foreign keys, so removing one migration would have turned the
suite green against a fabricated money schema. `GateFSchemaContainmentTest` could not see them,
because a `Schema::create()` inside a test is not a discoverable migration.

### And three LIVE application defects, all the same shape

Code whose behaviour depended on a table being **absent** — the direction nobody watches:

- **`SettingController`'s currency-rate auto-seed** took down **every** settings tab once
  `currency_rates` existed, silently, through its own `catch`.
- **The Zid webhook unlinked a merchant's OAuth credentials** when any authenticated body merely
  *contained* the string `application.uninstall`. Measured: a product name did it, while the audit
  row the same handler had just written said `event = order.create`. `P1-SEC-41`.
- **A delivery commission was paid once per button click.** The cleanup was gated on a *change* of
  driver, the insert on nothing. Measured: 3 submissions → 3 rows, 6.00 owed where 2.00 was
  correct, on the web **and** in the Flutter admin API. `P1-SEC-42`.

A fourth, **not fixed and reserved to the owner**: one forged webhook, behind a secret that is a
literal in `config/services.php`, creates a **real parcel at Delivery Syria on another merchant's
account** with an attacker-chosen COD amount. `P1-SEC-43`, S1, in
`docs/security/P01-SECURITY-WORK-QUEUE.md`.

### The four rules this leaves Phase 2.5

1. **When you make something exist, search for code that assumed it did not.** `Schema::hasTable`,
   `hasColumn`, `module_is_active`, `config(...)` guards, `rescue()`, and any `try`/`catch` around a
   write. A `catch` that swallows is the one that will not tell you.
2. **Never let an absence be a proof.** A test that concludes "the gate was passed" from an
   exception proves nothing the moment the exception stops happening. Assert the positive outcome
   and the row.
3. **A fallback that builds a structure is worse than a skip.** A skip is declared and visible; a
   fallback is invisible and wrong. There is now a ratchet forbidding both for the six money tables
   — `GateFCoverageLedgerTest::test_no_test_creates_or_branches_on_a_gate_f_money_table`.
4. **Write the exit condition into the test.** Three of these waves were caught *only* because a
   docblock said, in advance, "when X happens this test must be rewritten, in the same commit, and
   do not weaken the assertion". Each of those tests then failed exactly as designed. That habit is
   the single cheapest control in this repository.
