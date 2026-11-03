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
