# Phase 01 — Acceptance Criteria

What must be true before Phase 01 can be reported ready for owner review. **Only the owner may
approve closing the phase**, and Phase 02 does not start without that approval.

These criteria are deliberately written so that each one can be **shown**, not asserted.

---

## A. Task completion

| # | criterion | how it is shown |
|---|---|---|
| A1 | Every task in the ledger is `DONE`, or `BLOCKED` with a named owner decision | `phase-01-tasks.json` + the GitHub milestone agree, with no task left `IN_PROGRESS` |
| A2 | No task is `DONE` on a commit alone | each `DONE` row carries acceptance evidence: commit sha, test names, measured numbers |
| A3 | Every corrective task is linked to the work it corrects | ledger `queue_refs` and the issue cross-links |

## B. Security and identity

| # | criterion | how it is shown |
|---|---|---|
| B1 | No storefront or admin handler derives its subject, store, price or quantity from caller input unchecked | the security suites, each control removal-checked |
| B2 | Cross-tenant reads and writes are refused on every reachable path found in Phase 01 | `tests/Feature/Tenancy` + `tests/Feature/Security`, and the S-row table in the queue |
| B3 | Merchant, customer and driver token boundaries hold under the `custom.auth` guard-class trap | a driven test per boundary, with a merchant and a driver token — not only a customer token |
| B4 | Role and permission escalation is refused, including the finance-permission denylist gap | `P1-SEC-02`'s negative tests |
| B5 | Session hygiene: logout symmetry, regeneration, `same_site`, CSRF path-matching, MFA ordering | `P1-SEC-01`'s tests in both locales |
| B6 | Every remaining security row is either closed with evidence or recorded as an owner decision | `docs/security/P01-SECURITY-WORK-QUEUE.md`, S1…S37 |

## C. Orders, checkout and monetary integrity

| # | criterion | how it is shown |
|---|---|---|
| C1 | One server-authoritative total per order writer; no posted money field reaches a persisted total | the survey test pattern, failing when a writer bypasses the seam |
| C2 | A coupon that is printed is a coupon that is charged | `P1-ORD-01` positive tests per coupon type |
| C3 | Invalid quantities are refused at both ends — non-positive **and** above the sanity ceiling | the three cash-on-delivery writers plus the gateway returns |
| C4 | No partial write: a refused basket leaves no order, no stock movement and no orphan decrement | the orphan-decrement test, with fixture order stated |
| C5 | Returns and refunds restore exactly what the order took, and only for the owning store | `P1-INV-02`, `P1-INV-05` |

## D. Payments and financial integrity

| # | criterion | how it is shown |
|---|---|---|
| D1 | Payment state transitions are derived, never asserted from a browser return | `PaymentVerificationService` is the only writer of `Paid`, and each unverified write is logged |
| D2 | Replay of a gateway return cannot produce a second order or a second charge | `P1-PAY-02` |
| D3 | COD settlement timing is explicit and owner-approved | `P1-PAY-04` |
| D4 | Gateway callback authenticity is enforced for every gateway the owner elects to enforce | `P1-PAY-03`, per-gateway fixtures |
| D5 | GATE F readiness: the money tables are either released with a recovery plan, or contained with the containment proven | `guard-gate-f-containment.sh`, and `P1-MIG-01` |

## E. Inventory and stock

| # | criterion | how it is shown |
|---|---|---|
| E1 | Product and variant ownership is checked on every reachable mutation — "scoping the product does not scope the variant" | the S36/S37 suites |
| E2 | `stock_management` ON enforces, OFF does not deduct, on every writer | `StockManagementSettingTest` (40 cases over four settings states) |
| E3 | Concurrent checkout of the last unit cannot oversell | `P1-INV-05`, on MySQL (SQLite cannot show it) |
| E4 | Cancellation and return restore stock exactly | `P1-INV-05` positive test |

## F. Platform foundation

| # | criterion | how it is shown |
|---|---|---|
| F1 | CI gates run on both event classes, or are explicitly report-only | the three gate-wiring tests |
| F2 | The known-outcome gate exits 0 with the declared set unchanged, and the manifest unmodified | `assert-known-test-outcomes.php` + `git diff` on the manifest |
| F3 | Unit, Feature and Contract suites all run, with the Contract suite pinning the Flutter and Lynomia contracts | the three suite runs |
| F4 | Schema-sensitive evidence is verified on **real MySQL**, not SQLite | `P1-PLT-01`'s harness, with at least one SQLite-masked finding re-verified |
| F5 | Migration readiness: every parked draft is reviewed, and nothing runnable exists that the owner has not released | `guard-gate-f-containment.sh`, `_gate_f_pending/`, `_sec13_pending/` |
| F6 | Design System foundation intact and contrast-gated | `check-contrast.py` in CI |
| F7 | Observability: the security-relevant log lines are enumerated and reach a channel an operator reads | `P1-PLT-05` |

## G. Phase-wide verification (the directive's §9)

Run as one exercise, not as a sum of task tests:

1. End-to-end **merchant** journey: create product with variants → receive order → fulfil → return.
2. End-to-end **customer** journey: browse → cart → checkout (COD and one online gateway) → order
   history → return request.
3. Cross-tenant attempt at **every** step of both journeys, with a merchant, a customer and a
   driver token.
4. Checkout and payment status consistency across the nine order writers.
5. Refund and inventory restoration.
6. Financial integrity with GATE F in whichever state the owner has approved.
7. Flutter admin and Lynomia Chat contracts (`tests/Contract`).
8. Storefront and theme compatibility, Arabic (RTL) and English (LTR), contrast-checked.
9. MySQL-specific validation for anything schema-sensitive.
10. CI and the security attestation on the final head.

## H. Honest limits

Phase 01 cannot be reported complete while any of these is unrecorded:

- controls that are **subsumed** rather than independently proven (the ledger and the queue name them);
- controls that **cannot be proven in this environment** — no WorkDo module loads in a test run, the
  Authorize.Net SDK and the Stripe keys are unreachable, and `dev.azure.com` is blocked;
- findings recorded but **not verified by execution** (`P1-SEC-03`, `P1-INV-04`);
- owner decisions outstanding, each with what it blocks.

A phase report that omits these is not an acceptance report.
