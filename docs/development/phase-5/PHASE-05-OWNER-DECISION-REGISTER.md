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

---

## Batch 2 — the 18 items the nine-row re-measurement surfaced (added 2026-11-22)

The 2026-11-22 re-measurement of the nine remaining Phase 05 rows classified every absent capability
as *buildable without a policy* or *needing one*. **33 fell on the buildable side and are being
executed now; the 18 below are the other side.** They are listed here in one place so the open set
stays readable, with the four things the owner asked each row to carry: the exact decision, the
options and what each costs, an engineering recommendation, and the split between what is safe to
build before an answer and what is genuinely blocked until one.

**Six of the eighteen are already answered, wholly or in part, by a decision on this page or by an
approved decision elsewhere.** Those are marked **ANSWERED** and are not re-asked — per the standing
rule that existing approved decisions are not put back to the owner. What remains genuinely open is
**twelve**.

**A recommendation here is engineering's reading, never a decision taken.** Where the question is
purely commercial, legal or consent-related, the row says *no recommendation* rather than inventing
one. Nothing on this page has been implemented as a default.

| # | Exact decision needed | Options and consequences | Engineering recommendation | Safe to build BEFORE the answer | Blocked until answered |
|---|---|---|---|---|---|
| **D-N13** | **ANSWERED by D-N9.** Where fee data comes from, and what the profit figure then claims. | — | — | The `MetricCaveat` **fee constant** — one constant, one message, two lang entries, one test. It states an omission; it defines nothing, invents no rate and reads no gateway. **Being built now.** | Any figure that subtracts a fee. |
| **D-N14** | **ANSWERED by D-N9 + D-N10.** The `EXCLUDES_SHIPPING_AND_TAX` wording for inclusive-tax stores. | — | — | Nothing new; the sentence is already disclosed as-is. | Re-wording it, which changes a money claim on four surfaces. |
| **D-N15** | **ANSWERED by D-N10.** A per-VARIANT cost column. | — | — | Nothing — a per-variant cost column is a persistent migration. | Per-variant costing, and whether a variant inherits the parent cost or carries its own. |
| **D-N16** | **ANSWERED by D-N3.** Which consent store is authoritative across the first-party store and Lynomia, and what a disagreement means. | — | — | Nothing further; the first-party store is complete and inert. | One consent answer across two systems. Note this **collides with D-N3**: enforcement ships `off`, so there is no live gate for a second store to disagree with yet. |
| **D-N17** | **ANSWERED by D-N11.** Whether vendored `packages/workdo/` files may be edited, here for the three countdown layouts that read no theme token. | — | — | An **override stylesheet** in first-party CSS, which needs no vendored edit. | Editing the vendored layouts themselves. |
| **D-N18** | **ANSWERED by D-N8 + `GAP-220`.** Where the conversion-overlay styling lives. **Now closed** — the wrapper is styled at `store-syria-ds.css:7542-7570` and all four card copies call the hook. | — | — | — | — |
| **D-N19** | **Who may receive a scheduled report by e-mail**, other than the employee who created it. | (a) an allowlist of the store's own users — safe, limited; (b) any address with a confirmation step — flexible, and makes the product an authenticated mail relay on a timer; (c) per-role rule — middle ground, more to maintain. | **(a).** It is the only option that cannot be turned into an open relay, and the recipient is already non-parameterised for that reason. | Everything else about scheduling is built and verified. **A paused state and a pause control** are buildable now and are in the backlog. | Sending to anyone but the creator. |
| **D-N20** | **The money-column vocabulary a custom report BUILDER may offer.** | (a) builder restricted to the four labelled figures (`D1`/`D2`); (b) arbitrary column assembly — lets a merchant construct a fifth money figure that contradicts the four and has no agreed definition. | **(a).** `D1`/`D2` exist precisely so there are four money figures and not five; a builder that can assemble a fifth undoes that decision by another route. | The builder's **mechanics** — a definitions table, a column picker, a renderer — need no ruling, and count/date columns need none either. | Any money column outside the four. |
| **D-N21** | **Period COMPARISONS on MONEY figures** anywhere (report screens, dashboard, Flutter). | (a) show them with a disclosed coverage start; (b) withhold until the ledger is backfilled; (c) backfill `store_order_financials` — a production data operation. | **(b), matching what `/analytics` already does.** A money baseline reaching before `2026-11-02` is understated by an unknown amount, and a delta built on it is a false claim dressed as a trend. | **Comparisons on COUNT figures everywhere** — `previous()` exists and the percentage/zero-baseline rules are already decided and tested (`AnalyticsComparisonTest:183,:210`). | Money comparisons. |
| **D-N22** | **The marketing-channel taxonomy**: which hosts count as organic search, social, paid or referral, and whether a no-referrer visit may be called *direct*. | (a) a curated host list — accurate at first, goes stale, needs an owner; (b) report the host verbatim and classify nothing — what ships today; (c) buy a third-party classification — recurring cost and an egress dependency. | **(b) until the owner wants otherwise.** Calling `google.com` *organic* when the visit came from an ad is a false attribution a merchant would spend money on, and the screen already refuses to call an absent referrer *direct* for the same reason. | The **host-to-channel lookup mechanism** needs no decision; so does UTM *capture* (the values are already retained verbatim and nothing parses them). | Any channel LABEL on a visit or an order. |
| **D-N23** | **A written acceptance criterion for `MKT-002`** — what *advanced when needed* means on a marketing surface. | (a) progressive disclosure at all widths; (b) phone-only, as shipped; (c) advanced-field sections on the forms rather than the filter bars. | **No recommendation — this is the owner's definition of the requirement, not a technical choice.** Engineering notes only that without it, any claim that `MKT-002` is met is unfalsifiable, and the slice just shipped is defensible both ways. | The **coupon-form advanced section** (`x-ss.advanced-fields` already exists and is proven on two product forms) is buildable under any of the three readings. | Declaring `MKT-002` met; desktop disclosure on the five filter bars. |
| **D-N24** | **A newsletter composer**, and what its Send button is allowed to do. | (a) build the composer with sending disabled until `D-N3`; (b) wait; (c) build and enable — **not available**: `MarketingSendGate` enforcement is `off` and `MKT-007` sending is held. | **(a).** It matches what `MKT-007` already does — detect, queue, hold — and keeps the consent gate the single place that decides sending. | The composer **UI and draft storage**, with no send path. | Any outbound newsletter. |
| **D-N25** | **Cadence, delay, send-cap and message template for the recovery ACTION.** | (a) merchant-configurable with no shipped default — the pattern `ANA-009` and `MKT-007` already use; (b) a platform default — invents marketing policy; (c) leave absent. | **(a).** `NudgeThrottle` was deliberately built with its number absent and `RecoveryCandidates` ships with no default age, for exactly this reason. | The **controls and persistence** for those values — the same shape as the threshold form already shipped. | A default value for any of them, and the message wording. |
| **D-N26** | **Which system owns shopper messaging once Lynomia is live**, and at what granularity (per store, per channel, per message class). | (a) first-party owns, Lynomia excluded; (b) Lynomia owns when active; (c) per-channel split. | **No recommendation — this is a business rule about who may talk to a merchant's customers.** Engineering notes that a guard keyed on *Lynomia is sending* would today guard against something that cannot happen, since Lynomia is not merchant-reachable (`D-N27`). | A **shared send-record seam** that both sides could consult, written so it binds nothing until an owner names the owner. | The runtime cross-system guard — `MKT-009`'s core. |
| **D-N27** | **May Lynomia be enabled for a real merchant** — explicitly reserved at `config/lynomia.php:21-25` pending the APP_KEY / secret-exposure prerequisite. | (a) enable after the prerequisite is cleared; (b) keep reserved. | **(b) until the prerequisite is cleared**, which is a security precondition rather than a preference. | A **dev/test fixture** — `LynomiaStagingFixtureSeeder` already exists in the package. | Any merchant-reachable Lynomia surface. |
| **D-N28** | **Which messaging/marketing modules may coexist with Lynomia**, and what happens when a merchant activates two. | (a) exclusivity enforced by the plan catalogue; (b) coexistence with a disclosure; (c) unmanaged. | **No recommendation — this is a packaging and commercial rule about `Plan::modules`.** | A **disclosure** that two are active, which states a fact and enforces nothing. | Any exclusivity rule across the nine modules. |
| **D-N29** | **Which in-store conversion tools belong in the Phase 03 theme editor as sections**, and each one's default state and placement per theme. | (a) a named catalogue; (b) none — keep them settings-only. | **No recommendation — a product/scope decision, and arguably Phase 2.5/03 territory rather than Phase 05's.** | The **plumbing** that would let a tool be a section. | The catalogue, hence `MKT-010`'s *linked to the themes* at its strong reading. |
| **D-N30** | **Which modules are in which plan** — every vendored conversion tool is unreachable as shipped, and `LotteryPrizes`' storefront slider has no consumer at all. | (a) add them to plans; (b) leave unreachable; (c) a subset. | **No recommendation — `Plan::modules` is packaging and pricing.** Engineering notes separately that the lottery slider's `@stack` position is a layout decision needing capture in four AR/EN x light/dark combinations. | Nothing that makes a tool reachable. | Every vendored conversion tool, and the dead slider. |

### What this batch does NOT block

Of the 33 items the same measurement found buildable, **none depends on any row above.** The two
sets were separated for exactly that reason: work continues on all 33 while these 18 — twelve of
them genuinely open — wait. Where a row above has a *Safe to build BEFORE the answer* cell, that
work is in the backlog and is not held.
