# Phase 06 — `Popular Coupon`: a figure the owner reads was redefined, and nothing approved the new meaning

**Status:** reconciliation complete. **One owner decision drafted, not landed** — it belongs in
`docs/development/OWNER-DECISION-PACKAGE.md`, which this workstream may not edit, so §7 below is
written in that file's own shape for the integrator to move across under a `J`-number.
**Five tests are left RED on purpose; see §6.** No application predicate was changed.

**Owner instruction this answers (2026-11-27):** *"Reconcile the Popular Coupon metric against its
approved definition. If its business meaning has changed without owner approval, surface that
decision instead of silently rewriting the tests."*

**Verdict: there is no approved definition of this metric.** So the change `GAP-231` shipped is not
a defect fix and is not a contradiction of an approved text — it is an unapproved *definition*, and
it is the owner's to confirm or reverse. Everything below is the evidence for that sentence and the
numbers the decision needs.

---

## 1. What changed, and when

| | |
|---|---|
| the card | `resources/views/superadmin/dashboard.blade.php`, `<x-dashboard-card :count="$couponName" :label="__('Popular Coupon')">`, linking to `plan-coupon.index` |
| the figure | `$couponName` — the NAME of the plan coupon with the most redemptions, computed in `HomeController::handleSuperAdmin()` |
| until 2026-11-26 | every `plan_user_coupons` row counted. `GROUP BY coupon_id ORDER BY COUNT(*) DESC, coupon_id`, `LIMIT 1` |
| since `8cb288150` (`GAP-231`) | only a redemption whose `order` string matches a `plan_orders.order_id` whose `payment_status` is **settled**. `whereExists` + `App\Support\Operator\SettledPlanOrders::redeemedAgainstSettledOrder()` |
| the same commit | applied the same rule to the **`Popular Plan`** card — `Plan::most_purchese_plan()` now carries `whereIn('payment_status', SettledPlanOrders::spellingsInUse())`. **One ruling should cover both cards**; this document measured the coupon one |
| the same commit | deliberately did **not** filter `Total Plan Orders`, and gave the three cards a shared disclosure sentence instead |

The argument in the commit message is sound on its face: *a coupon redeemed three times against
orders that were never paid should not outrank one redeemed against an order that was.* Nothing
below disputes that as a reading. What is at issue is whether it is **the** reading, and who gets
to pick.

---

## 2. Every source searched for an approved definition, and what each said

Searched in the order the owner's instruction implies: the recovered plan first, then the trackers,
then the decision package, then the code's own history.

| source | what it says about this metric |
|---|---|
| `docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-BASE.md` + `-WITH-AMENDMENTS.md` | **Nothing.** A case-insensitive search for `coupon` across the whole `master-plan/` directory returns **one** hit, `MKT-003`, whose whole text is *«كوبونات وخصومات تلقائية وBOGO»* — "coupons, automatic discounts and BOGO" — a **marketing** decision about the coupons shoppers use at a merchant's checkout, not the SaaS plan coupons this card ranks. Different table (`user_coupons` / `order_coupon_details`, not `plan_user_coupons`), different payer, different phase. |
| `DECISION-ID-REGISTER.md` → `ADM-001` | The Phase 06 decision this card's screen answers to. Its **entire recovered text is one line**: *«لوحة قيادة شاملة للمنصة»* — "a comprehensive platform command dashboard". No metric, no figure, no inclusion rule. The register says so itself: *"Whether that constitutes «لوحة قيادة شاملة» … is a product judgement and not a measurement, and the recovered text is one line."* |
| `DECISION-ID-REGISTER.md` → `ANA-005`…`ANA-008` | The nearest decisions in **spirit**, and all four are Phase **05**: `ANA-005` *separate sales, collection, settlements and profitability*; `ANA-006` do not claim precise profitability on missing data; `ANA-007` *show data quality, estimates and omissions*; `ANA-008` *document metric definitions and their sources*. None names this card. Note what `ANA-005` actually asks for: **separate** them — not replace one with the other. The shipped change does not separate attempted from settled redemptions on this card; it replaces one with the other, and leaves the attempted basis live and unlabelled elsewhere — see §4, where the exhaustion check and the detail grid still count every row, so the two readings now coexist with no sign that they differ. |
| `DECISION-ID-REGISTER.md` → `SAAS-007` | *«فصل إيرادات المنصة MRR/ARR عن GMV ومبيعات التجار»*. This is the decision `App\Services\SaasRevenueFigures` answers, and it is where the word "settled" is defined for platform money. It defines it **for MRR and ARR**. It does not name a ranking. |
| `PHASE-05-METRIC-DEFINITIONS.md` — **which is the `ANA-008` document**, by its own §0a | **Zero mentions of plan coupons, of `Popular Coupon`, or of either operator ranking.** Its 16 sections are merchant/shopper figures; §9 *Coupon redemption* is `order_coupon_details` joined to a customer order, a different table and a different payer. **So this metric has no documented definition at all, which is itself an open `ANA-008` gap.** What the document's §11 universal rules do say points at disclosure, not at a predicate: rule 1 *"Name the record"*; rule 5 *"A metric with a known gap displays the gap."* |
| `PHASE-05-METRIC-DEFINITIONS.md` §1, the nearest **standing rule** | Not about this card, and worth quoting anyway, because it is this project's written answer to this exact class of question. On `GrossMerchandiseValue`: *"**Includes.** Every order regardless of `payment_status`. **Excludes.** Orders with `delivered_status = 2` (cancelled) — **stated as an option, not a default**: GMV conventionally includes cancellations, and this platform has no prior convention. The report must label which it used. **Never silently pick one.**"* `GAP-231` silently picked one. |
| `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md`, `ADM-001` row | Records the finding, not a definition. Its own *Next action* reads: *"give `PlanOrder::total_orders()` and `Plan::most_purchese_plan()` a `payment_status` predicate **or a stated basis**"* — two remedies, and the choice between them is left open. |
| `MASTER-PLAN-GAP-REGISTER.md`, `GAP-231` (amended twice) | This is the strongest text **for** the shipped change, and it is an engineering row, not an owner decision. It asserts: *"the two RANKINGS are not defensible … a ranking led by rows representing no money is a defect, not a definitional choice"* and *"This is NOT a J35-shaped owner question … so it is decidable from the repository."* §3 explains why that reasoning does not hold. |
| `OWNER-DECISION-PACKAGE.md` → `E4` | `GAP-231`/`GAP-232` scope: *"Does `ADM-001` describe what exists, a superset, or a replacement?"* Not this question. |
| `OWNER-DECISION-PACKAGE.md` → `J35` | *"Must the platform dashboard carry operator DECISION QUEUES?"* Not this question. |
| `OWNER-DECISION-PACKAGE.md` → `J42` | `OPS-003` alerting. Unrelated. |
| **the whole package** | No entry governs what the operator's rankings count. |

**Conclusion of §2: no owner-approved text names this metric, in either direction.** Not case 1
(approved and matched) and not case 2 (approved and contradicted) — case 3.

**And the one comparable figure the owner HAS ruled on points the other way.** `F257`, decided
2026-11-01, is the merchant dashboard's *Order value*: the owner excluded **cancelled** orders and
the figure still counts every order *regardless of `payment_status`* — the Arabic acceptance report
states it as *«الرقم يستثني الملغاة فقط»*, the number excludes only the cancelled, and the commit adds
*"RETURNS ARE STILL INCLUDED, AND THAT IS PINNED AS A NON-DECISION."* So the single approved
exclusion for a displayed figure in this product is narrow and deliberate, and **rule B is strictly
broader than it**: it drops `pending`, `failed` and `rejected` too. That does not make rule B wrong.
It makes it a bigger step than the only step the owner has actually taken.

---

## 3. Why "decidable from the repository" does not survive contact with the repository

`GAP-231` declined to escalate on the ground that the question was answerable from committed
material. That conflates **two different questions**, and the repository answers only the first.

1. **Which stored `payment_status` spellings mean the platform was paid?** Genuinely decidable.
   `SaasRevenueFigures::SETTLED_PLAN_STATUSES` declares the set and
   `PlanOrderSettledStatusCensusTest` classifies every spelling the application writes. `GAP-231`'s
   engineering on this question is good and §5 confirms it by measurement.
2. **Should the `Popular Coupon` ranking count only settled redemptions?** Not decidable from the
   repository, because nothing in the repository states what that card is for. Answering (1) and
   then applying it to a figure nobody defined is the move this project has a name for.

**And it has a name because the project already refused it, on the adjacent card of the same
screen.** `D-N31` in `docs/development/phase-5/PHASE-05-OWNER-DECISION-REGISTER.md`:

> **Does the operator's store ranking count cancelled orders?** The platform dashboard's *Top
> Selling Store* panel sums every order a store has taken, cancelled and unpaid included. Owner
> decision **F257** excludes cancellations from the MERCHANT dashboard's order value; it names that
> figure and not this one. … **(a).** One name should carry one meaning, and F257 already settled
> what that meaning is for a merchant. **Recommending it is not the same as doing it: extending a
> decision to a figure it does not name is exactly how a rule gets invented.**

That is this same question about a panel further down the same screen, and it is **still open**: measured in this container,
`PlatformAnalyticsFigures::topStoresByOrderValue()` constructs `new GrossMerchandiseValue()` with
the default `includeCancelled: true`, so the ranking still counts cancelled orders, says so on the
panel, and waits for the owner. `GAP-231` did for `SETTLED_PLAN_STATUSES` precisely what `D-N31`
refused to do for `F257`.

### 3.1 Four precedents, and `GAP-231` is the only one that moved a figure

| | what was wrong | what the project did |
|---|---|---|
| **F257** (Phase 02, `PHASE-02-TASK-LEDGER.md`) | the merchant dashboard's *Order value* included cancelled orders, because an `orWhere` admitted them | **Escalated.** The ledger: *"NOT FIXED, BECAUSE IT IS NOT AN ENGINEERING CALL. Two defensible readings disagree about a number the merchant reads."* The owner-facing report put it as *«رقمٌ مُبلَّغ، وتغييره قرار عمل»* — a reported figure, and changing it is a business decision — under the heading **«قرارك»**, *yours*. Pinned by nine tests and **decided by the owner on 2026-11-01** (`bbc712a6d`), whose first line is the governing sentence: *"Owner decision, 2026-11-01. **This changes a DISPLAYED business metric only** — no settlement, no ledger, no transaction status, no merchant balance."* A change that touches nothing but a displayed figure was still the owner's. |
| **`e967e3788`** (`P06-DASH-CARDS`, this very screen) | two cards named the wrong thing; one printed a merchant count under "Total Store" | **Relabelled. "No figure on the screen changed."** Explicitly: *"Changing the figure means changing that test in the same breath, which is a product decision about what an operator's dashboard should state; changing the label makes the existing figure honest and decides nothing."* |
| **`9b54687ef`** (`P5-R7` item 3) | **four** merchant Top-5 rankings counted cancelled *and* returned orders with no predicate anywhere | **One translated disclosure sentence on all four. "Neither needs an owner decision and neither changes a number."** And the pin works both ways: *"If someone later adds a status predicate to `top_product()`, the disclosure becomes FALSE — so the premise case fails."* |
| **`D-N31`** (`P05-14`) | the operator's *Top Selling Store* ranking counts cancelled and unpaid orders | **Escalated and held.** Left as shipped, stated on the panel, pinned by a test *"so that changing it is a deliberate act."* Engineering recommended the narrower rule and did not take it. |
| **`8cb288150`** (`GAP-231`) | the operator's two rankings counted unsettled purchases | **Predicate added. The figure changed. Not escalated.** |

Four of the five share one property, and it is the only one that matters here: **none of them moved
a number without the owner.** Two were escalated — `F257`, which the owner then decided, and
`D-N31`, which is still held. The other two needed no escalation *precisely because* they did not
move a number: one relabelled, one disclosed. `GAP-231`'s own commit message states that rule in
as many words for the third card — *"a predicate there would silently redefine a figure the owner
reads to answer a question nobody asked"* — and `OperatorDashboardAttemptCountDisclosureTest`'s
failure message spells it out: *"If it should be filtered, that is an owner decision, not a defect
fix."* **That sentence is correct. It was applied to the card that counts attempts and not to the
two beside it that rank them.** The asymmetry is the whole question.

---

## 4. What ships today

- `HomeController::handleSuperAdmin()` ranks on settled, matched redemptions only (**rule B**
  below). Two commits merged; this is live on `int/checkpoint`.
- `Plan::most_purchese_plan()` does the same for `Popular Plan`.
- `Total Plan Orders` is still `PlanOrder::count()` — every attempt.
- The screen carries a standalone `ss-stat__hint` paragraph **which already states the new
  definition in both languages**: *"Total Plan Orders counts every purchase attempt, whether or not
  it was ever paid. Popular Plan and Popular Coupon count only purchases the platform was actually
  paid for, and Popular Coupon counts only redemptions whose order can be found."* So the "a figure
  should say what it counts" requirement is **met for the rule that ships** — and the sentence
  becomes **false** the moment the rule is reversed. §6.3 carries the replacement wording for that
  case, in both languages, so a reversal is one catalogue edit rather than a drafting exercise.
- **The rest of the product still counts every redemption, so "used" now means two things.**
  Measured: `PlanCoupon::used_coupon()` is `hasMany(PlanUserCoupon::class, 'coupon_id', 'id')
  ->count()` — every row, no predicate — and it is the exhaustion check every payment path runs
  (about 120 call sites; `PHASE-06-DASHBOARD-CARD-LABELS.md` §2 is why it was left untouched). And
  `/plan-coupon/{id}` — one click from `plan-coupon.index`, which is where the card links — lists
  every redemption row unfiltered:
  `PlanCouponDetailDataTable::query()` is `->where('coupon_id', $this->planCouponId)` and nothing
  else. So the fixture's `LAUNCH20` is **exhausted at 12 of 12** for every gateway, shows **12**
  rows on its own detail screen, and is ranked **third on 3** by the card itself. That is not
  an argument against rule B — it is the thing a ruling has to be aware of, and it is why the
  attempted number is *not* lost by rule B as an earlier draft of this document claimed.
- Two things the ranking does **not** state, both pre-existing and neither decided here:
  - **no period.** The query carries no date predicate, so the card is all-time while every other
    figure this project ships states its window. `PHASE-06-DASHBOARD-CARD-LABELS.md` §9's successor
    rule ("every figure carrying its period") has never been applied to this screen.
  - **`plan_coupons.is_active` is not filtered**, so a deactivated coupon can still be the
    `Popular Coupon`. Recorded as pre-existing by `PHASE-06-DASHBOARD-CARD-LABELS.md` §10 and still
    true.

---

## 5. The measurement — what each rule actually yields

**One fixture, three rules, both engines.** Run by
`tests/Support/Security/bin/measure-popular-coupon-ranking-rules.php`: **16 checks, 0 failed**, on
MariaDB **10.11.14** in a throwaway database created with `utf8mb4_unicode_ci` (the collation
`config/database.php` declares for production, asserted before anything is measured) and on SQLite
`:memory:` (the suite's engine). Rule B is not a copy of the shipped query — the script passes
`SettledPlanOrders::redeemedAgainstSettledOrder()` itself, so a change to the predicate changes the
measurement.

**The fixture.** Five plan coupons, **30 redemptions**, one plan. Spellings are the ones the
application really writes, capitalisations included.

| coupon | redemptions | against a settled order | `payment_status` as stored | against an unsettled order | naming no order at all |
|---|---|---|---|---|---|
| `LAUNCH20` | 12 | 3 | `success` | 9 (`pending` 5, `failed` 4) | 0 |
| `LOYAL10` | 5 | 5 | `succeeded` | 0 | 0 |
| `BANKWIRE` | 4 | 4 | `Approved` | 0 | 0 |
| `MIXEDCASE` | 2 | 2 | `Succeeded` | 0 | 0 |
| `GHOST5` | 7 | 0 | — | 0 | 7 |

### 5.1 The result

| rule | ranking, highest first | the card prints |
|---|---|---|
| **A — as shipped before `GAP-231`** (every redemption) | `LAUNCH20`=12, `GHOST5`=7, `LOYAL10`=5, `BANKWIRE`=4, `MIXEDCASE`=2 | **`LAUNCH20`** |
| **B — as shipped today** (settled, matched redemptions) | `LOYAL10`=5, `BANKWIRE`=4, `LAUNCH20`=3, `MIXEDCASE`=2 | **`LOYAL10`** |

**The size of the difference, in numbers:**

- The card **names a different coupon**: `LAUNCH20` → `LOYAL10`. The card's entire value is that
  one name, so nothing it said before is preserved.
- **The leader's own count falls from 12 to 5.** Under rule A the winner's 12 redemptions include 9
  the platform was never paid for.
- `GHOST5` **leaves the ranking entirely**: 7 redemptions, 0 matchable orders. Under rule A it is
  **second on the platform**; under rule B it does not exist. Its rows are not "unpaid", they are
  *unmatchable* — `plan_user_coupons.order` is a string with no foreign key — so rule B's second
  claim ("only redemptions whose order can be found") is doing separate and larger work than its
  first, and nothing on the screen distinguishes the two exclusions.
- **All five coupons change position**, and four of the five change by more than one place. What
  survives is narrower than it looks: the three coupons with no unsettled and no dangling rows
  (`LOYAL10` > `BANKWIRE` > `MIXEDCASE`) keep their order *among themselves*; every pairwise
  comparison involving `LAUNCH20` or `GHOST5` flips.
- Both rules give **identical numbers on both engines** (asserted).

### 5.2 The rule nobody shipped, and the collation claim it rests on — confirmed

`GAP-231` rejected the obvious predicate `whereIn('payment_status', SETTLED_PLAN_STATUSES)` because
that constant is lower-cased while the application stores capitalised spellings, and the two engines
compare differently. **Measured, and it is true:**

| rule C, `IN ('success','succeeded','approved')` | ranking |
|---|---|
| MariaDB, `utf8mb4_unicode_ci` | `LOYAL10`=5, `BANKWIRE`=4, `LAUNCH20`=3, `MIXEDCASE`=2 — identical to rule B |
| SQLite `:memory:` | `LOYAL10`=5, `LAUNCH20`=3 — **`BANKWIRE` and `MIXEDCASE` vanish** |

So on production's engine the naive predicate happens to agree with the shipped one on this fixture,
and on the suite's engine it silently drops **6 paid redemptions across two coupons**, one of them
the operator's own bank-transfer approval. A predicate that is green in the suite and differently
correct in production is exactly the trap `CLAUDE.md` names. The shipped derivation avoids it.

### 5.3 A documented claim about the fix, refuted by the same run

`SettledPlanOrders::spellingsInUse()`'s docblock says the derivation *"cannot be short by a casing
nobody imagined, because it reads the column"* and *"behaves identically on both engines, because
the comparison that decides anything happens in PHP."* **Measured: it returns four spellings on
SQLite and three on MariaDB.**

| engine | `spellingsInUse()` |
|---|---|
| SQLite `:memory:` | `Approved`, `Succeeded`, `succeeded`, `success` |
| MariaDB, `utf8mb4_unicode_ci` | `Approved`, `succeeded`, `success` — **`Succeeded` missing** |

`DISTINCT` is **collation-aware**. Under a case-insensitive collation `'succeeded'` and
`'Succeeded'` are one value and the server returns whichever casing it saw first. Isolated outside
the script to be sure it is the collation and not the fixture: `SELECT DISTINCT s` over
`('succeeded','Succeeded','Approved','success')` in a `utf8mb4_unicode_ci` column returns **3** rows
and `COUNT(DISTINCT s)` is **3**.

**No ranking is wrong because of it**, which is why this is recorded and not fixed: the `IN()` the
shorter list builds is folded by the *same* collation, so it still matches the casing the list
omitted — `MIXEDCASE`, stored `Succeeded`, scores 2 on both engines. But the safety on MariaDB comes
from the engine's case-insensitivity, which is the exact behaviour the derivation was written not to
depend on, and two of the docblock's three clauses are false on the engine that ships. The remedy is
a sentence in that docblock, not a code change; it is left for whoever lands §7, because touching
the file would mean touching the predicate under decision.

---

## 6. What was changed, and what was deliberately left red

### 6.1 Left red — five tests, on purpose

`php artisan test tests/Feature/Platform/OperatorDashboardCardLabelsTest.php tests/Feature/Platform/PlanCouponRankingFreshnessTest.php`
→ **5 failed, 12 passed (69 assertions)**, measured at `b2e823561`.

| test | asserted | why it fails | is its subject the contested definition? |
|---|---|---|---|
| `OperatorDashboardCardLabelsTest::test_the_coupon_card_still_names_the_most_used_coupon` | `BUSYCOUPON` (2 redemptions) beats `QUIETCOUPON` (1) | got `''` | **YES.** "most-used", unqualified, *is* rule A |
| `OperatorDashboardCardLabelsTest::test_a_tie_is_broken_the_way_the_original_broke_it` | a tie on 3 each goes to the lower id | got `''` | no — tie-break |
| `PlanCouponRankingFreshnessTest::test_a_new_redemption_moves_the_named_coupon_on_the_next_request` | premise: the only redeemed coupon is named | got `''` | no — model-cache freshness |
| `PlanCouponRankingFreshnessTest::test_removing_redemptions_moves_the_named_coupon_back` | premise: `BETA` leads 2–1 | got `''` | no — cache invalidation |
| `PlanCouponRankingFreshnessTest::test_deleting_every_redemption_empties_the_card` | premise: `ALPHA` is named | got `''` | no — cache invalidation |

**The mechanism is confirmed and is the one reported:** both files' `redeem()` helpers write
`plan_user_coupons` rows with a synthetic `order` string and create **no `plan_orders` row at all**,
so rule B's `EXISTS` is false for every fixture row and the card renders empty. The four
non-definitional tests are collateral: their subjects (a tie-break, and the model-cache staleness
that `PHASE-06-DASHBOARD-CARD-LABELS.md` §5 was written about) are orthogonal to which redemptions
count, and they fail only because their fixtures predate the predicate.

**They are not repaired, and that is the deliverable, not an omission.** The owner's instruction was
to surface the decision rather than rewrite the tests to match the new rule, and adding settled plan
orders to those fixtures — even though a fixture whose every redemption is settled would pass under
**both** rules and so decide nothing — is making the new rule's precondition true in order to go
green. That is the move the instruction forbids. Nothing was added to
`.github/known-test-outcomes.json`; no assertion, fixture or helper in the five was altered. Each of
the two files has gained a dated note in its class docblock pointing at this document, so the next
reader meets the reason where the failure is.

**If this is mistaken for unfinished work, it is not.** The repair is one short step either way and
§6.3 names it.

**THE PRICE, SAID PLAINLY, BECAUSE IT IS NOT FREE.** `.github/known-test-outcomes.json` declares
**0** accepted failures, and `.github/scripts/assert-known-test-outcomes.php` asserts the outcome
set in both directions. **So the Feature gate fails on these five until §7 is answered**, and it
will name them. That is deliberate: the alternative was to declare them — which `CLAUDE.md` says an
empty set is not a licence for — or to repair the fixtures, which the owner's instruction forbids.
The second cost is in `PlanCouponRankingFreshnessTest`: it is the only thing in the suite that
catches the `withCount` cache-staleness its own docblock documents, and while it is red that guard
is **unenforced**. Both costs end the moment §7 is answered, either way.

### 6.2 Changed — one bilingual defect in the disclosure, which decides nothing

The disclosure sentence `GAP-231` added is correct and complete in English and **named none of its
three cards in Arabic.** Measured straight out of the two catalogues:

| the card's own label, `ar.json` | the name the Arabic disclosure used |
|---|---|
| `إجمالي طلبات الاشتراك` (`Total Plan Orders`) | `إجمالي طلبات الباقات` |
| `الخطة الشعبية` (`Popular Plan`) | `الباقة الأكثر طلباً` |
| `الكوبون الشعبي` (`Popular Coupon`) | `القسيمة الأكثر استخداماً` |

All three. An Arabic-reading operator was handed a careful sentence about three figures that are not
on the screen, while the English sentence named all three exactly. One of the three was a
known-wrong word being reintroduced: `PHASE-06-DASHBOARD-CARD-LABELS.md` §7 records that the retired
`Total Coupon` label used `قسيمة`, that this *"disagreed with"* `ar.json`'s own `كوبون` for `Coupon`,
and that the card was translated `الكوبون الشعبي` for that reason — and the disclosure then wrote
`قسيمة` again.

Nothing saw it. `OperatorDashboardAttemptCountDisclosureTest::test_the_disclosure_renders_in_arabic`
asserts the Arabic renders *at all* (because `__()` returns its key, an undeclared sentence appears
in English on the Arabic page), which is a different question.

**Fixed:** one `ar.json` value, naming each card as the card prints it and keeping the phrase that
test keys on.

> يَعُدّ إجمالي طلبات الاشتراك كل محاولة شراء، سواء دُفعت أم لا. أما الخطة الشعبية والكوبون الشعبي فتَعُدّان المشتريات التي قُبض ثمنها فعلاً فقط، والكوبون الشعبي يَعُدّ الاستخدامات التي يمكن العثور على طلبها فقط.

**Pinned**, in both directions and in both languages, by
`tests/Unit/Localisation/OperatorDashboardDisclosureNamesItsCardsTest` — 4 cases, 28 assertions,
including a premise case so a renamed or deleted sentence cannot make the rest pass vacuously.
Reverting the `ar.json` value fails it with the old Arabic quoted in the message (executed, not
assumed). **This pins the NAMING and never the claim**, so it survives either answer to §7: a
changed claim keeps the three names, a changed name keeps the claim.

### 6.3 Whichever way §7 is answered, the landing step

| if the answer is | then |
|---|---|
| **keep rule B** (settled only) | the five tests' fixtures each gain a settled `plan_orders` row per redemption. That makes them rule-agnostic rather than rule-B-specific — a coupon with 2 settled redemptions beats one with 1 under **both** rules — so their real subjects (tie-break, cache freshness) are asserted again and the files stop being hostage to the definition. Nothing else changes; the disclosure is already true. |
| **restore rule A** (every redemption) | revert the `whereExists` in `HomeController::handleSuperAdmin()` and the `whereIn` in `Plan::most_purchese_plan()`; the five tests go green with no edit. Then **three** things must move together or the screen starts lying: `OperatorDashboardRankingsCountSettledOnlyTest`, whose 8 cases state the settled rule as the contract for both cards; `OperatorDashboardAttemptCountDisclosureTest::CLAIMS` keys on the words *"actually paid for"* and *"whose order can be found"*; and the disclosure sentence itself, in **both** catalogues. The English replacement: *"Total Plan Orders, Popular Plan and Popular Coupon count every purchase attempt, whether or not it was ever paid."* The Arabic, naming the cards as they print: *«يَعُدّ إجمالي طلبات الاشتراك والخطة الشعبية والكوبون الشعبي كل محاولة شراء، سواء دُفعت أم لا.»* |
| **a third rule** (e.g. *rank on settled, but show the attempted count beside it* — the reading `ANA-005` most nearly asks for) | new work, not a revert. Both numbers exist in the fixture above (`LAUNCH20` 3 of 12) and `<x-dashboard-card>` has no second slot, so it needs a shape decision first. |

---

## 7. The owner decision, drafted for `OWNER-DECISION-PACKAGE.md`

> ### J«n» — `ADM-001` / `GAP-231`: what do the operator's two ranking cards count — purchases, or paid purchases?
>
> `Popular Coupon` and `Popular Plan` on `/dashboard` were changed on 2026-11-26 to rank on
> **settled** purchases only. Before that they ranked on every row. **No approved text defines
> either metric** — `ADM-001`'s whole recovered text is *«لوحة قيادة شاملة للمنصة»*, the `ANA-008`
> metric-definitions document has zero mentions of plan coupons, and no entry in this package
> governs it — so the new meaning shipped on engineering's reading of an argument, not on a
> decision. Reported here rather than left, because it is a figure you read.
>
> **Measured, one fixture of 5 coupons and 30 redemptions, on MariaDB 10.11 at the production
> collation and on the suite's SQLite, 16 checks 0 failed**
> (`tests/Support/Security/bin/measure-popular-coupon-ranking-rules.php`):
>
> | | the card prints | the leader's count |
> |---|---|---|
> | **(a) every redemption** — as shipped until 2026-11-26 | `LAUNCH20` | **12**, of which 9 were never paid |
> | **(b) settled, matchable redemptions** — as shipped today | `LOYAL10` | **5**, all paid |
>
> A different coupon, and **all five change position**. One coupon with **7** redemptions
> disappears entirely under (b) — its rows name orders that do not exist, which is a second and
> larger exclusion than "unpaid", and nothing on the screen tells the two exclusions apart.
>
> | | |
> |---|---|
> | **(a) keep every redemption** | The card ranks on *usage*: which coupon code merchants reach for. It is then led, as measured, by a coupon whose redemptions were three-quarters unpaid, and by rows that match no order at all. It is also the basis the REST of the product already uses for a plan coupon — see the row below. Four earlier slices met this shape of question and **not one of them narrowed a figure on engineering's authority**: `F257` was escalated (you then narrowed it), `e967e3788` relabelled, `P5-R7` item 3 disclosed, `D-N31` is held. |
> | **(b) settled only** | The card ranks on *revenue influence*: which coupon code the platform was actually paid against. Already shipped, already disclosed in both languages, and `OperatorDashboardRankingsCountSettledOnlyTest`'s 8 cases state it as the contract. |
> | **What (b) already costs, measured** | **The product now holds two meanings of "used" for one plan coupon, and they can disagree about the same coupon.** The exhaustion limit every payment path enforces is `PlanCoupon::used_coupon()` — `hasMany(PlanUserCoupon::class)->count()`, **every** row, called from about 120 sites — and `/plan-coupon/{id}`, one click from the screen this card links to, lists every redemption row unfiltered (`PlanCouponDetailDataTable::query()` filters on `coupon_id` alone). So `LAUNCH20` can be **exhausted at 12 of 12**, show 12 rows on its detail screen, and be ranked third on 3 by this card, with nothing anywhere explaining the gap. `D-N31`'s own words: *"One name should carry one meaning."* |
> | **(c) rank on settled, state the attempted count beside it** | The reading `ANA-005` (*separate sales, collection and settlements*) most nearly asks for. Not a revert — `<x-dashboard-card>` has one value slot, so it needs a shape decision. |
> | **The standing rule nearest to this** | The `ANA-008` metric-definitions document's own words, about the sibling demand measure: *"**stated as an option, not a default** … The report must label which it used. **Never silently pick one.**"* And your one ruling on a comparable figure — `F257`, the merchant *Order value* — excluded **cancelled** orders while keeping every order *regardless of payment status*. Rule (b) is broader than that: it also drops `pending`, `failed` and `rejected`. |
> | **What the sibling card's precedent suggests** | **(a) plus disclosure.** `e967e3788` relabelled `Total Orders` → `Total Plan Orders` on this exact screen *"rather than changing its figure"*, writing down that *"changing the number … is a product decision"*. `GAP-231` kept `Total Plan Orders` unfiltered for the same reason and said so: *"a predicate there would silently redefine a figure the owner reads"*. **That argument applies word for word to the two cards beside it, and was not applied to them.** `D-N31` is the same question about the *Top Selling Store* panel on the same screen, is still open, and its own reasoning is the answer to this one: *"extending a decision to a figure it does not name is exactly how a rule gets invented."* |
> | **Engineering has not chosen** | Both rules are measured, neither is pinned as the contract by anything this workstream wrote, and the five tests that encode the old rule are **left red with the reason recorded** rather than rewritten. The one change made is bilingual, not definitional: the Arabic disclosure named none of its three cards and now names all three. |
> | **What an answer releases** | (a): a two-line revert, plus one disclosure sentence in two catalogues and two test files, all named in §6.3 of `PHASE-06-POPULAR-COUPON-METRIC-RECONCILIATION.md`. (b): five test fixtures gain a settled plan order each, which makes them rule-agnostic. (c): a card shape. |
> | **Also yours, and smaller** | The ranking states **no period** (it is all-time, while every other figure this product ships states its window) and does not filter `plan_coupons.is_active`, so a switched-off coupon can be the `Popular Coupon`. Both pre-existing, both recorded in `PHASE-06-DASHBOARD-CARD-LABELS.md`, neither touched. |
> | **Full analysis** | `docs/development/phase-6/PHASE-06-POPULAR-COUPON-METRIC-RECONCILIATION.md` |

**One ruling should cover `Popular Plan` too.** The same commit applied the same predicate to
`Plan::most_purchese_plan()` on the same argument. This document measured the coupon card; the plan
card's behaviour under rule B is covered by
`OperatorDashboardRankingsCountSettledOnlyTest::test_a_plan_whose_purchases_all_failed_does_not_win_popular_plan`
and was not independently measured here. Splitting the ruling would leave two cards side by side
counting differently.

---

## 8. Evidence

| | |
|---|---|
| the measurement | `tests/Support/Security/bin/measure-popular-coupon-ranking-rules.php` — **16 checks, 0 failed.** Both engines, one fixture, three rules; calls `SettledPlanOrders::redeemedAgainstSettledOrder()` rather than copying it. States its own preconditions and creates and drops its own throwaway database. |
| why it is a script and not a test | two independent reasons, both in its docblock: rule C's whole point is that it answers differently on the two engines and the suite has one of them; and a test asserting what any one rule yields would pin the definition under decision. |
| the bilingual fix | `resources/lang/ar.json`, one value. Pinned by `tests/Unit/Localisation/OperatorDashboardDisclosureNamesItsCardsTest` — **4 passed, 28 assertions**; reverting the value fails it (executed). |
| the five red tests | `5 failed, 12 passed (69 assertions)`, unchanged by this workstream except for a dated note in each file's class docblock. |
| suites run | `tests/Unit/Localisation/` **59 passed**. The four related dashboard files together: **5 failed, 24 passed (94 assertions)** — the same five, and the two `GAP-231` files still green, so the `ar.json` change moved nothing. The blocking **Unit** suite: see §8.1. |
| not changed | every predicate in `HomeController::handleSuperAdmin()`, `Plan::most_purchese_plan()`, `App\Support\Operator\SettledPlanOrders` and the English disclosure. `.github/known-test-outcomes.json` untouched. |

### 8.1 Suites run on the final tree

| suite | result |
|---|---|
| **Unit** — the blocking CI gate | **7,183 passed, 1 skipped, 70,620 assertions**, exit 0. (`CLAUDE.md` §3 still says 6,762 + 1 skip; the branch has grown since, and the four new localisation cases are inside this figure.) |
| `tests/Unit/Localisation/` | 59 passed |
| `tests/Feature/Platform` + `tests/Feature/Saas` + the three raw-query censuses | **8 failed, 513 passed (3,795 assertions)** |
| the same two directories on a **PRISTINE** tree — every change here reverted, both new files moved aside | **8 failed, 498 passed**, the **same eight names**. The +15 passed is the three census files the first run added. **So this workstream introduces no failure**; measured rather than argued, because three of the eight are not mine. |

**THE THREE THAT ARE NOT MINE, reported and not touched.** `expected_failures` in
`.github/known-test-outcomes.json` is `{}`, so these are undeclared failures already on
`int/checkpoint`: `StorelessMerchantDashboardTest::test_the_refus…` and two cases of
`OperatorPlatformNavigationTest` (the sidebar's `Across the platform` group does not contain
`platform/verification`; its own message names `ADM-001`). They are outside this question and belong
to whoever owns that navigation group — but the Feature gate will name all eight, so the integrator
should not read the three as collateral of this one.
| the four dashboard files together | 5 failed, 24 passed (94 assertions) — the same five |

---

## 9. Two figures found stale on the way, neither corrected here

- **`CLAUDE.md` §4 says "14 documents under `docs/development/phase-6/`".** Measured: **24** `.md`
  files in that directory before this one, 25 after. `CLAUDE.md` is outside this workstream's
  permitted edits and no test asserts the number, so it is reported rather than fixed — and the
  absence of a test is why it drifted by ten.
- **`SettledPlanOrders::spellingsInUse()`'s docblock** makes two claims that are false on
  production's engine (§5.3). Left for whoever lands §7, because the file is the one under decision.
