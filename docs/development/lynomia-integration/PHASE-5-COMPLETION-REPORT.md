# Store Syria × Lynomia — Phase 5 completion report

Native campaigns, audiences, messaging templates, marketing analytics and advanced commercial
entitlements, plus the partner consent subsystem P5E was authorised to build.

**What this report is.** One consolidated account of what was built, what was found broken
while building it, what was tested and what is still owed. The per-increment detail lives in
`PHASE-5-COMPLETION-MATRIX.md`, `PHASE-5E-CLOSURE.md` and
`../../../../lynomiachat/docs/partner-consent/THROTTLE-SAFETY.md`; this does not restate them.

**Assessment: CONDITIONAL GO.** Every approved capability is implemented and tested offline,
and almost all of it is browser-verified. The condition is blocker **A1**, unchanged and
unclosable from inside these two repositories: nothing here has ever run against a deployed
Lynomia. Phase 5 narrowed A1 — the two sides' agreement about the consent wire format is now
proven against one real artifact rather than two mocks — but narrowing is not closing.

---

## 1. Feature matrix

Full detail, per capability, in `PHASE-5-COMPLETION-MATRIX.md`. Summary:

| Increment | State |
|---|---|
| P5A — capability mapping and contracts | VERIFIED |
| P5B — audiences and store-scoped segmentation | VERIFIED |
| P5C — message templates and approval visibility | VERIFIED |
| P5D — campaign management and lifecycle | VERIFIED |
| P5E — partner consent, eligibility, bidirectional sync, throttling | VERIFIED |
| P5F — campaign analytics and delivery outcomes | VERIFIED, with one documented gap |
| P5G — commercial entitlements, trials, add-ons, quotas | IMPLEMENTED, with one deliberate omission |

Two things are **not** built, both stated rather than hidden:

- **Conversion attribution** (part of P5F). Neither platform records a conversion event tied
  to a campaign message, so attribution would mean inventing the data. A chart with no source
  would be worse than its absence.
- **A merchant-facing purchase flow for add-ons** (part of P5G). Every payment gateway in
  this installation is switched off and activating one is outside this phase's authorization.
  Add-ons are operator-granted; `price_cents` is recorded, never charged.

## 2. Defects found and fixed

Eleven, all reproduced before being fixed. Seven were in code written earlier in this same
phase, which is the argument for verifying rather than reviewing.

| # | Defect | Would have caused | Fixed in |
|---|---|---|---|
| 1 | The send guard ran **before** the throttle's sleep | a withdrawal, kill switch or revocation landing during the wait was read and ignored — and the wait is where a long campaign spends most of its time | Lynomia `2b768b11` |
| 2 | `account.settings`, `store.status`, `contact.blocked` read off objects loaded before the run | the per-account kill switch, a revoked installation and an agent blocking a shopper mid-run all did nothing until the next job | Lynomia `2b768b11` |
| 3 | The degraded-throttle operations signal used values outside the allow-lists | an operator would never have learned the throttle had failed | Lynomia `2b768b11` |
| 4 | TOCTOU read-then-write in the throttle counter | concurrent workers overshoot the ceiling by exactly their number | Lynomia `122012e2` |
| 5 | Native and partner consent states shared one row, ordered by incommensurable revisions | a partner grant overwrote a native withdrawal on the **merchant's screen** | Store Syria `79dcdd68` |
| 6 | `external_customer_id` selected through a SQL join returned ciphertext | reconciliation would have matched nothing, silently | Lynomia `eb202ac3` |
| 7 | Commerce aggregates summed across every store | a store-scoped campaign's preview counted another store's customers | Lynomia `e11d941c` |
| 8 | `spec/models/campaign_audience_spec` had 7 failures at HEAD | an earlier P5E change broke a spec file outside the regression set I was running | Lynomia `e11d941c` |
| 9 | Extending `LIMIT_KEYS` broke `current_count` | every usage display would `KeyError` | Lynomia (P5G) |
| 10 | Two test-harness stubs placed above existing ones for the same URL | shadowed them, silently breaking the seat and Studio tests | Store Syria `628d5003` |
| 11 | A fixture generator committed rows to the test database | eleven request specs failed on a count, passing alone and failing in a suite | Lynomia `122012e2` |

Two promises made in `THROTTLE-SAFETY.md` and since **closed rather than quietly dropped**:
campaign cancellation (§3, delivered in P5D) and the commercial quota living outside the
throttle module and failing closed (§1, delivered in P5G).

## 3. Security and isolation

Every new merchant surface carries the same five layers, asserted **from the booted router**
rather than read off the routes file: `web/auth/xss/setlocate/verified`,
`PlanModuleCheck:LynomiaChat`, a per-person permission, `EnsureStoreOwnsLink:existing`, and no
route parameter naming a tenant.

Three new permissions, each a decision a merchant can reasonably make separately:
`Manage Lynomia Audiences` (who gets messaged), `Manage Lynomia Templates` (the words Meta
approves), `Manage Lynomia Campaigns` (the act of sending, which cannot be taken back). Each
is tested as a refusal for staff who hold the others. The Phase 4 permission tripwire caught
all three additions and was updated, never relaxed.

Tenant isolation is tested positively on every surface: another tenant's audiences, consent
decisions and customer names do not appear, and a store id naming another account is refused
with a 422 rather than silently narrowing a count to zero — because zero reads as "nobody
qualifies", which is the wrong thing to tell somebody whose real mistake was naming a store
that is not theirs.

The send boundary now has ten ordered refusals, every one of them re-read live per recipient:
kill switch, campaign cancelled, installation revoked, contact blocked, native suppression,
native withdrawal, partner suppression, partner withdrawal, missing promotional grant, and
the commercial quota.

## 4. Tests actually executed

| Suite | Result |
|---|---|
| Store Syria — full suite against the known-outcome gate | 2,020 tests, 8 declared failures, 17 declared skips, **nothing undeclared** |
| Store Syria — `--filter Lynomia` | 422 tests, 4,947 assertions, 0 failures |
| Store Syria — `Contract` suite (real producer artifact, real ingress) | 9 tests, 32 assertions, 0 failures |
| Store Syria — browser, 7 suites | 188 checks, 0 failures |
| Lynomia — campaigns, partners, consents, contacts, contracts, platform, senders, campaign models | 0 failures (see the matrix for counts per run) |
| Lynomia — concurrency, real threads, non-transactional | 3 examples, 0 failures |

The eight Store Syria failures and seventeen skips are the pre-existing declared set; the gate
asserts the **set by name**, so a ninth failure or a fixed-but-still-declared one both break
it.

## 5. What is still open

| Id | What | Who can close it |
|---|---|---|
| **A1** | No run against a deployed Lynomia has ever happened | the owner, with a staging deployment of both sides |

Everything in both repositories is switched **off** as deployed —
`LYNOMIA_INTEGRATION_ENABLED`, `STORESYRIA_ENABLED`, `whatsapp_campaign`, `lynomia_commerce`
and every payment gateway. No Lynomia-facing path has executed in this environment, and no
production credential was created, rotated or activated.

Full register: `PRODUCTION-READINESS-BLOCKERS.md`.
