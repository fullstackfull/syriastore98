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
