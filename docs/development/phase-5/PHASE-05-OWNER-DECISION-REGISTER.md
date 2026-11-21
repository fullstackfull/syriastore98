# Owner decision register — the questions engineering cannot answer

**Created 2026-11-21.** One page, deliberately. Every row is a question whose answer is a business,
financial, legal or design policy rather than a correctness question, so engineering must not pick
it. The full analysis for each lives in the document named in the last column; this page exists so
the open set can be read in one sitting instead of reconstructed from eleven files.

## How to read this, and what it is NOT

**These are not stopping points.** Every row's independent supporting code is already built,
tested and merged into the Phase 05 branch. Nothing here is waiting on a decision before work can
continue, and nothing here has been left half-done pending an answer. Each row names what is
already in place, so a decision changes a configuration value or releases a held change rather than
starting a project.

**Engineering has deliberately not chosen a default in any of these rows.** A quiet default is the
decision taken by another route, and in this project it has twice been the wrong one. Where a
capability is built but inert, that is stated as inert.

**Two security items are tracked privately and are not described here.** This repository is public.
Their existence is recorded in `docs/security/P01-SECURITY-WORK-QUEUE.md` by id only; the analysis,
the held fix and the production-remediation handoff were delivered to the owner outside the
repository and must not be summarised into it.

## The register

| # | The question | What is already built | What an answer releases | Full analysis |
|---|---|---|---|---|
| **D-N1** | How often may one shopper be nudged about an abandoned cart or wishlist? | A first-party nudge ledger with a tenant-safe, idempotent duplicate guard, ON: a double-click can no longer send two billable messages. The cadence limb is built, tested and **inert** — `config('marketing.nudge_min_interval_seconds')` is null, so no shopper is refused for recency. | Setting one config value. No code change. | `config/marketing.php`, `GAP-219` |
| **D-N2** | At a gateway return, when a shopper starts checkout as one customer and comes back as another: whose discount applies and whose points are spent? | D6 stage 1 — redemption is decided once at the quote, the WhatsApp/Telegram writer re-checks, and a refusal reaches the event so points are not spent for a discount that was not given. The **gateway-return handlers are not covered.** | Options (a)/(b)/(c) in the analysis; each is a bounded change to named handlers. | `GAP-214`, `PHASE-05-D6-WIRING-MEASUREMENT.md` |
| **D-N3** | What makes a marketing-consent record count — and may enforcement be switched on? | The whole first-party consent store: events, projection, ledger, send gate, unsubscribe tokens, a bilingual opt-in surface and two wired seams (email, WhatsApp nudge). `MARKETING_CONSENT_ENFORCEMENT` is **`off`**, so it reports and holds nothing. | Switching enforcement to `report`, then `enforce`. The address-verification question (`GAP-215`) is separate and narrower. | `GAP-200`, `GAP-215`, `PHASE-05-MKT-008-ENFORCEMENT-DESIGN.md` |
| **D-N4** | Do loyalty points reverse when an order is cancelled or refunded, and what happens when the shopper has already spent them? | The measurement: the vendored module contains **no** cancel, refund or reversal handling at all, and one listener is its only writer. Four remedy options are costed. **None taken** — every one changes customer-visible balances. | One of the four options, prospectively. A backfill of historical balances is explicitly out of scope. | `MKT-006`, `GAP-202`, `PHASE-05-MKT-006-REWARD-REVERSAL.md` |
| **D-N5** | Should BOGO apply to variant products? | The measurement: both call sites that invoke the rule engine gate on `empty($cart_value->variant_id) && $cart_value->variant_id == 0`, so BOGO does not apply to variants **at all** today. | A pricing-rule change with a stated expectation for multi-variant baskets. | `MKT-003` |
| **D-N6** | Do the five remaining `$plan` handlers get the subscription guard? | The guard is landed on five handlers. The other five are **held** by the owner's own Phase 02 ruling D-13/D-18 — and the assumption that ruling rested on was made false by P4-F014. | Releasing or re-affirming the hold. | `GAP-210` |
| **D-N7** | Should the platform operator and the tenant owner pass the five Marketing screens' permission gates? | Measured: all five gate on `isAbleTo()` alone, so they refuse the operator and refuse an owner whose role lacks the name. **Fail-closed**, so it is a consistency gap, not an escalation. Not fixed, because changing owner-pass semantics on five live gates already broke two tests that encoded the old behaviour as the contract (P4-9b). | Routing all five through one predicate in `app/Services/`, as `ReportAccess` and `StoreSettingsAccess` already are. | `GAP-218` |
| **D-N8** | Where does the conversion-action overlay's styling live — one design-system rule, or one per theme? | Measured: `supdiv` occurs in **exactly one file in the repository** — the hook that emits it. Nothing positions it in any theme, so its inline `top: 40px` is inert. Adding greentic's missing call would give it the same unstyled overlay the other two have. | The styling home. Then greentic's one-line call, then capture in all four AR/EN × light/dark combinations. | `GAP-220`, `MKT-010` |
| **D-N9** | How should profit be stated when fee data does not exist, and for stores whose prices include tax? | Both profit figures disclose what they omit, with a measured count of rows whose cost was never recorded. **Fee data is absent entirely** — `store_order_financials` has no fee column — and `EXCLUDES_SHIPPING_AND_TAX` is half untrue for inclusive-tax stores. | Either a fee column (a persistent migration, so also D-N10) or an agreed wording that stops short of claiming profit. | `ANA-006`, `GAP-213(d)` |
| **D-N10** | May the prepared migrations be applied to production, and may `shetabit_visits` get a `(store_id, created_at)` index? | Four tables are prepared and **approved for development and isolated testing only**: three marketing-consent tables and the nudge ledger. GATE F's 15 tables and 3 columns have measured migrations proved on a real server (29 checks, 0 failed). The visits index is not written. | A production migration window. Nothing here is applied, and applying it is explicitly outside engineering's authorisation. | `docs/GATE-F-VERIFIED-SCHEMA.md`, the four migration files' own docblocks |
| **D-N11** | The vendored `Helpdesk` module's notification emails never send, and its guest reply page shows the visitor an internal reason. Fix in place, or replace? | Measured and recorded. Not fixed: `packages/workdo/` is vendored and must not be edited as a shortcut. | A direction: patch the vendored module, wrap it, or replace the surface. | `GAP-212` |
| **D-N12** | Should Sanctum tokens expire, and should the OTP length change? | Both measured and written up. Tokens currently **never** expire, every one carries `['*']`, and there is no refresh path for any client; the shipped Flutter app force-logs-out on any 401, so a new 401 is a user-visible regression. | A lifetime and a client migration plan; an OTP length with its compatibility consequences. | `docs/security/P01-TOKEN-EXPIRY-PROPOSAL.md`, `P01-OTP-LENGTH-COMPATIBILITY.md` |

## What is deliberately absent from this page

- **Anything already decided.** The owner-approved decisions in the Phase 02 Decision Register and
  the Master Plan's 181 decision ids are not re-asked here. `D1`/`D2`, the four-figure labelling and
  the ledger-coverage start are settled and implemented.
- **Engineering judgements dressed as decisions.** A question with one correct answer is not on this
  page; it is in the backlog. The nudge duplicate guard is the example: the frequency is here, the
  double-click is not.
- **Deferrals engineering invented.** Per `CLAUDE.md` §4.1, an engineering report deferring
  something is not an owner decision. Every row here is a question that was always the owner's.
