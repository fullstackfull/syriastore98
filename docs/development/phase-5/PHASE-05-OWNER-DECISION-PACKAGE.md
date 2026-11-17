# Phase 05 — one consolidated owner decision package

**Prepared 2026-11-17 at the owner's instruction** ("Prepare one concise consolidated decision
package rather than repeatedly interrupting engineering"). It covers the three families that are
blocking Phase 05 engineering: **analytics figure labels**, **loyalty**, and **marketing consent**.

Nothing in this document has been acted on. Every item here is either a monetary change, a
customer-visible change, a persistent migration, or a vendored-module change — the four categories
the standing restrictions reserve to you.

> **STATUS OF §3 (CONSENT):** the sender census is still running at the time of writing. That
> section is marked PENDING and will be completed in this same document rather than sent
> separately.

---

## 0. The decisions, on one page

| # | decision | recommendation | why it cannot wait on engineering |
|---|---|---|---|
| **D1** | Historical coverage for the two ledger-based figures | **Option A** — show them with the start date stated and refuse earlier windows | Gates P5-4, P5-7 and P5-8 entirely. No merchant analytics dashboard can be built until the answer is known |
| **D2** | The four figure labels and their inclusion rules | Adopt as proposed | Changes established product semantics on screens merchants already read |
| **D3** | Is `RewardClubPoint` active in production? | **Establish this first.** It is NOT active in this container | Decides whether MKT-006 is a live leak or a pre-activation defect. Everything in §2 hangs on it |
| **D4** | Reward reversal: how an already-spent point is treated | **Separate obligation from spendable balance** (D4-c below) | Both obvious answers are wrong: clamping silently forgives, going negative is customer-visible nonsense |
| **D5** | The negative order total (`20.00 − 50.00 = −30.00`) | Clamp with `max(0, …)` | It is a monetary change in a case that currently *succeeds*, so it is yours, not mine |
| **D6** | Redemption thresholds enforced in Blade only | Enforce server-side | Fixing it **denies** redemptions that currently succeed |
| **D7** | The three vendored-listener defects | Report upstream + first-party guard | Cannot be fixed without editing `packages/workdo/` |
| **D8** | Consent and unsubscribe | **PENDING §3** | Release-critical by your own classification |

---

## 1. Analytics figure labels

The full proposal with per-figure inclusion rules is
[`PHASE-05-FOUR-FIGURE-LABELLING-PROPOSAL.md`](PHASE-05-FOUR-FIGURE-LABELLING-PROPOSAL.md). This is
the decision, not a restatement of it.

Your stated direction matches what that document already recommends:

> Order value is not collected payments · Collected payments are not merchant wallet balance ·
> Wallet balance is not profit · Profit must disclose missing cost and fee data

**D1 — historical coverage. Recommendation: Option A.**

`store_order_financials` has one migration and **no backfill**; the entitlement record begins
**2026-11-02**. So *collected revenue* and *profit* return zero for every order before that date —
correctly, because the ledger genuinely has no rows, and uselessly, because a merchant reading it
concludes their business stopped.

| option | consequence |
|---|---|
| **A. State the start date and refuse earlier windows** — "Payments received (from 2 Nov 2026)" | Honest and available now. A merchant asking for last year gets a refusal with a reason instead of a wrong figure. **Recommended, and consistent with your instruction not to invent pre-ledger history.** |
| B. Show unbounded with a caveat | Produces a flat zero line under a real line — the wrong-number-that-looks-right |
| C. Hold both figures until the ledger has history | Nothing wrong is shown; the separation you asked for stays unbuilt for months |

**Backfilling is not proposed and is not requested.** It is an irreversible live-data operation.

**Already delivered under this heading, needing no decision:** *profit must disclose missing cost
and fee data* is now true on `/finance`, the only surface in either repository that renders profit.
Both figures there carry a measured caveat naming how many rows have no recorded cost and the exact
amount exposed (P5-10). And as of P5-11 those caveats are actually **visible** — they had been
rendering into a 1×1 clipped span on every report screen.

---

## 2. Loyalty — MKT-005 and MKT-006

### 2.1 What is actually there, measured

| | finding |
|---|---|
| Earning and spending | `RewardClubPoint`, vendored. One listener (`AddRewardClubPointLis`) is the only writer. `app/` holds **zero** writes to `customers.total_club_point`; all 11 first-party references are reads that **spend** it |
| Reversal | **None.** No cancel, refund or reversal handling anywhere in the module |
| Transaction log | `reward_club_points`, vendored, with FKs to `customers` and `orders`. The balance column `customers.total_club_point` is `float(15,2) DEFAULT 0`, also added by a **vendored** migration |
| VIP tiers | **Absent.** `VIP` and `vip_tier` return **0 files** across `app/` and all ~70 modules |
| Referrals | **NOT absent, and `GAP-191` was wrong to say no referral surface was located.** Vendored `ProductAffiliate` adds `customers.affiliate_code`, `used_affiliate_code` and `commission_amount` plus three tables — customer-refers-customer with a reward, in all but name |

### 2.2 D3 — the question that changes everything, and I cannot answer it

**`add_on_managers` has ZERO rows in this container**, so no WorkDo module is loaded here.
Measured consequences, both verified by `route:list`:

- `ProductAffiliate` registers **0 routes**. Its schema and controllers exist on disk and are
  unreachable.
- `RewardClubPoint` also registers none. The two reward routes that *do* resolve
  (`api/{slug}/loyality-reward`, `{storeSlug?}/reward-list`) are **first-party**, in `routes/api.php:151`
  and `routes/customer.php:340`.

If the module is not loaded, its listener is not registered, so `event(new AddRewardClubPoint(...))`
fires into nothing and **no points are earned or spent at all**.

**So the severity of MKT-006 is conditional on production activation, which is not visible from
this container.** The code defect is real and was established by reading the module. Whether any
customer currently holds a point is a question about your production database.

**Do this before anything else in §2:** check whether `add_on_managers` carries an enabled
`RewardClubPoint` row in production, and whether `customers.total_club_point` holds any non-zero
value. If the answer is no, every item below becomes a pre-activation fix with no live exposure,
and the "disable the module" option is moot.

### 2.3 D4 — how an already-spent point is treated

This is the question the earlier analysis could not resolve, and your framing dissolves it.

A customer earns 100 points on an order, spends them, then the order is cancelled. The points are
owed back to the merchant but the balance is already 0.

| option | consequence |
|---|---|
| **a. Clamp the debit at zero** | The balance never goes negative, and the merchant silently eats the loss. This is forgiveness disguised as arithmetic — your instruction explicitly forbids it |
| **b. Allow the balance to go negative** | Honest, and customer-visible nonsense: a shopper opens the app and sees **−100 points**. It also puts an unbounded negative into a column eleven spend paths read |
| **c. Separate the obligation from the spendable balance** — `total_club_point` stays ≥ 0 and spendable; a distinct obligation record holds what is owed back; future earnings net against the obligation before crediting the balance | **Recommended.** Nothing is forgiven, nothing customer-visible goes negative, and the obligation is auditable. It is the ordinary accounting treatment and it matches what P4-F128 already did for settlement vs entitlement |

**Cost of (c):** it needs a new first-party table, which is a **persistent migration** and therefore
your authorisation. It does *not* require editing the vendored module: the obligation record is
first-party, and the netting happens in a first-party listener on the existing cancellation and
refund paths.

### 2.4 D4b — consistency of balances and history

Whatever is decided, two rules should hold, and neither is currently enforced:

1. **A reversal writes a compensating row; it never edits history.** `reward_club_points` is the
   transaction log, and the audit trail must show the award and the reversal, not an award that
   quietly disappeared. This is the same discipline P4-F124 applied to the financial ledger.
2. **The displayed balance must be reconcilable against the log.** Today
   `customers.total_club_point` is a denormalised column that the listener *replaces* rather than
   debits (see D7), so it can already drift from its own log.

A related display defect is open and is **P1-SEC-63b**: the loyalty figure the storefront API
returns always reads `0.00` where the true sum is 40.00. Fixing it produces an accurate but
**mislabelled** figure — it is lifetime points earned, not the spendable balance — so it is held
behind a labelling decision and pinned by a tripwire test so it cannot be "fixed" silently.

### 2.5 D5, D6, D7 — the recorded defects

Full evidence for each is in
[`PHASE-05-MKT-006-REWARD-REVERSAL.md`](PHASE-05-MKT-006-REWARD-REVERSAL.md) §3.

| id | defect | why it is yours | recommendation |
|---|---|---|---|
| **D5** | **Negative order total.** The eleven callers compute `final_price = subtotal − rewardDiscount` with no `max(0, …)`. Measured: a 20.00 basket less a 50.00 discount stored as **−30.00**. The module's own `applyPoints()` clamps and comments *"Ensure it doesn't go negative"*; no first-party caller does | Clamping changes what a customer pays in a case that currently **succeeds** — a negative total is written, not an error. That is a monetary change | **Clamp.** A stored negative order total is worse than the change required to stop it |
| **D6** | **Thresholds enforced in Blade only.** The server guard is `… isset($requests_data['club_point_is_active']) && … == 'on'` — entirely caller-supplied. A client posting `club_point_is_active=on` redeems below the configured minimum. Bounded by the customer's own balance, so it is a business-rule bypass, not an unbounded discount | Enforcing the threshold **denies** redemptions that currently succeed | **Enforce server-side**, after D3 establishes whether anyone is redeeming at all |
| **D7** | **Three defects inside the vendored listener.** (i) A validation check that **never runs**: `$x['club_point_is_active'] ?? '' == 'on' && …` parses as `$x[...] ?? ('' == 'on' && …)` because `==` and `&&` bind tighter than `??`, so the comparison of the client-posted amount against the server's own total is dead. (ii) Enabling club points without a point price is a hard **500** (`DivisionByZeroError`). (iii) Redemption **replaces** the balance rather than debiting it — self-consistent only because the discount always redeems the entire balance; a partial redemption added later would silently delete the remainder | `CLAUDE.md` forbids editing `packages/workdo/` | **Report upstream**, and add a first-party guard before the event for (i) and (ii). (iii) is latent until partial redemption exists |
| **—** | **A Khalti redemption never spends the points.** `KhaltiPaymnetController` is the only one of nine writers with no `AddRewardClubPoint` event, and that listener is the only consumer of the balance. A shopper paying by Khalti takes the discount and **keeps** their points | Firing the event changes a customer-visible balance | Fix with D4, as the same family of leak |

### 2.6 MKT-005 — what remains open

- **VIP tiers: genuinely absent.** Not started, nowhere.
- **Referrals: present but unreachable.** `ProductAffiliate` implements them and is not routed here.
  Per your instruction to use existing WorkDo modules and not build a second engine, the question is
  whether to **activate and verify** that module rather than build anything. That is the same
  activation question as D3.
- **`GAP-191` is corrected by this document** on the referral half.

---

## 3. Marketing consent and unsubscribe — **PENDING**

> The sender census is still running. This section will carry: every message sender with its
> category (transactional vs promotional, decided from content and trigger rather than module name),
> its recipient source, whether any consent is consulted at the **sending boundary**, and whether a
> **first-party interception seam exists that needs no vendored edit**.
>
> What is already established and does not depend on the census:
>
> - `customers` has **no consent or eligibility column at all** — all 25 columns of the create
>   migration were read.
> - The one real consent mechanism, `lynomia_consent_states`, is enforced **upstream, outside this
>   repository**; nothing in `app/` or `packages/workdo/` reads it to gate a send.
> - There is **no unsubscribe path anywhere**.
> - At least four senders carry no consent predicate: `CouponEmail` on registration, on first order
>   and on an order-value threshold; `ReviewReminder`; the newsletter list; the back-in-stock push.
>   The census has already found more than these four.
>
> **A boundary I will not blur:** I can establish what the code does and whom it can reach. I cannot
> establish whether promotional sending is *currently firing in production* — that is a question
> about the live host, which I have no access to. The same activation question as D3 applies, since
> several senders live in modules that are not loaded in this container.

---

## 4. What I am doing while these are open

Nothing in this document is being implemented. Engineering continues on the Phase 05 work that
needs no decision from you — the metric layer's remaining consumers, and the reporting surfaces
that do not depend on the two ledger-based figures.

Per your instruction, engineering completion, original-plan coverage and production readiness are
reported separately and are not treated as the same thing. **No phase gap from Phases 01, 02, 2.5,
03, 04 or 06 is closed by any Phase 05 progress**, and none has been.
