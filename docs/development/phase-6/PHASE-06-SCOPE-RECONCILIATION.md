# Phase 06 — the recovered scope against the tracked scope

**Created 2026-11-24.** `P06-08`'s own next action is to state the Phase 06 numbering hazard wherever
Phase 06 is discussed, and `D-P6-46` asks the owner which of two scopes Phase 06 actually is. This
page is the measurement that question needs: what the recovered plan asks for, what the traceability
matrix tracks, and where the two do not meet.

## The two lists are different sizes and different shapes

**The recovered plan** (`docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-BASE.md:379-449`) gives
Phase 06 **eight sections** and **33 decision ids**:

| § | Section | Decision ids | Count |
|---|---|---|---|
| 06-A | Super Admin و Store 360° | `ADM-001`–`ADM-002` | 2 |
| 06-B | الاشتراكات وإدارة SaaS — subscriptions and SaaS administration | `SAAS-001`–`SAAS-007` | 7 |
| 06-C | التوثيق والامتثال — verification and compliance | `KYC-001`–`KYC-003` | 3 |
| 06-D | التشغيل والمراقبة — operations and monitoring | `OPS-001`–`OPS-003` | 3 |
| 06-E | Marketplace | `MARKET-001`–`MARKET-005` | 5 |
| 06-F | الدعم والمساعدة — support and help | `SUPPORT-001`–`SUPPORT-005` | 5 |
| 06-G | الهوية والصلاحيات المؤسسية — enterprise identity and permissions | `IAM-001`–`IAM-004` | 4 |
| 06-H | التحكم بالمنصة — platform control | `CTRL-001`–`CTRL-004` | 4 |
| | | | **33** |

**The traceability matrix** carries **eight `P06-*` rows**, and only six of them are scope:

| row | what it is |
|---|---|
| `P06-01` | **source** — records that no Phase 06 source document exists. NOT IMPLEMENTED. |
| `P06-02` | scope *(derived)* — SaaS subscriptions |
| `P06-03` | scope *(derived)* — support |
| `P06-04` | scope *(derived)* — controls and operations |
| `P06-05` | scope *(derived)* — platform admin |
| `P06-06` | scope *(derived)* — premium Super Admin UX |
| `P06-07` | **carried-in item** — Flutter payment-gateway configuration |
| `P06-08` | **numbering hazard** — the three-way "Phase 6" collision |

**The word *derived* in those five rows is load-bearing.** `P06-01` records that their requirement
text comes from one-line summaries in `CLAUDE.md` §4 and `PROJECT-STATE.md` §2 — not from the owner's
plan. So the tracked scope was reconstructed from a summary of a plan rather than from the plan.

## Where they do not meet

| recovered § | nearest tracked row | state |
|---|---|---|
| 06-A `ADM-001` platform command dashboard | none — overlaps `P06-05`/`P06-06` by subject only | **was tracked NOWHERE until `GAP-231` (2026-11-24)** |
| 06-A `ADM-002` Store 360° | none | **was tracked NOWHERE until `GAP-232` (2026-11-24)** |
| 06-B `SAAS-001`…`006` | `P06-02` | tracked, as one derived row covering seven ids |
| 06-B `SAAS-007` MRR/ARR vs GMV | `P06-02` covers the subscription half only | **the platform-revenue half is untracked** |
| 06-C `KYC-001`…`003` | none | **untracked, whole family** |
| 06-D `OPS-001`, `OPS-003` | `P06-04` by subject | tracked loosely |
| 06-D `OPS-002` real resource measurement | none | **untracked** |
| 06-E `MARKET-001`…`005` | none | **untracked, whole family** |
| 06-F `SUPPORT-001`…`003`, `005` | `P06-03` | tracked, as one derived row |
| 06-F `SUPPORT-004` temporary scoped support access | none | **untracked** |
| 06-G `IAM-001`, `002`, `004` | none clearly | partly reachable through Phase 01 security rows |
| 06-G `IAM-003` two-person approval for dangerous actions | none | **untracked** |
| 06-H `CTRL-001`…`004` | none | **untracked, whole family** |

That column is not this page's own census: it is `DECISION-ID-REGISTER.md` §4.3, which names the nine
Phase 06 entries it found untracked — `KYC`, `OPS-002`, `MARKET`, `SUPPORT-004`, `IAM-003`, `CTRL`,
`SAAS-007`'s MRR/ARR half, and (added 2026-11-24) `ADM-001` and `ADM-002`.

## What this means for the decision the owner is being asked for

`D-P6-46` asks whether Phase 06's scope is the eight recovered sections or the eight derived rows.
The honest framing, which engineering must not settle:

- **The derived rows are smaller than the plan.** Three whole families (`KYC`, `MARKET`, `CTRL`) and
  four individual requirements have no tracked row at all. Building only the derived set would
  deliver something the plan does not ask for, and the standing rule forbids silently reducing
  approved scope.
- **The recovered sections are 33 one-line requirements.** `KYC-001` is "verification of individuals
  and companies" and nothing else. `MARKET-003` defers its own prices-and-revenue content to a
  commercial model that the plan itself says is not yet adopted. These cannot be built from the
  sentences; most of area C, E and F of the decision package exists because of it.
- **Neither list is wrong.** The derived rows are an honest reconstruction made when the plan was
  missing; the plan is the authority now that it has been recovered. Reconciling them is a scope
  decision with a cost either way, which is why it is `D-P6-46` and not an engineering default.

## The three-way "Phase 6" collision, stated here as `P06-08` requires

"Phase 6" names three different things in this repository's records:

1. **Phase 06 of the owner's six-phase Master Product Plan** — SaaS & Super Admin. This document.
2. **A "Phase 6" in the Lynomia Chat delivery records**, which belongs to a different project with
   its own numbering.
3. **A "Phase 6" in the Phase 0 discovery roadmap** (`docs/discovery/`), an older, differently
   numbered plan that CLAUDE.md §4 says does not override the approved one.

`P06-08`'s next action is annotation, not renaming: renaming the Lynomia documents or the discovery
roadmap would touch delivered records of other work, which is `D-P6-47` and not engineering's call.

## What has NOT been done

No Phase 06 requirement is implemented, and nothing in this document is an implementation plan. Of
the six mapped areas, five cannot proceed without an owner decision; the sixth — platform admin and
its premium UX — can, and its engineering default (the Phase 2.5 harness and its four-combination
AR/EN × light/dark evidence rule) needs no owner input. See
`PHASE-06-OWNER-DECISION-PACKAGE.md` and `PHASE-06-INVENTORY-2026-11-24.md`.
