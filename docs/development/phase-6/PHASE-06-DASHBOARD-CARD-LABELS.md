# Phase 06 — the operator dashboard: two cards that named the wrong thing, and two figures counted outside the database

**Status:** engineering complete, local. Not pushed at the time of writing; not released.
**Scope:** `resources/views/superadmin/dashboard.blade.php`, `app/Http/Controllers/HomeController.php`
(`handleSuperAdmin()`), `app/Models/Plan.php`, both language files. **`app/Models/PlanCoupon.php` is
untouched** — see §2 and §5.
**Task:** #211 `P06-DASH-CARDS`. Found by reading the operator dashboard while closing #208.

---

## 1. What was wrong

Four defects on one screen. All four measured by driving `GET /dashboard` as a platform operator.

### 1.1 "Total Store" was the MERCHANT count

`<x-dashboard-card :count="$platformTotals['stores']" :label="__('Total Store')">` where
`$platformTotals['stores']` is `User::countCompany()`:

```php
User::where('type', 'admin')->where('created_by', $this->creatorId())->count()
```

That is a count of type-`admin` **user** rows. `stores` is a separate table and `User::stores()` is a
`hasMany`, so one merchant owns N stores and the two numbers differ **by construction**, not
occasionally. The fixture in `OperatorDashboardCardLabelsTest` makes them 2 and 4.

**The label was the defect, not the figure — and that is a measurement, not a preference.** Three
independent facts all say the card is about merchants:

| | |
|---|---|
| the number | `countCompany()`, a count of type-`admin` USERS |
| the destination | the card links to `stores.index`, whose handler is `User::select(['users.*'])->join('stores', ...)->where('users.type', 'admin')->groupBy('users.id')` — it lists USERS |
| that screen's own heading | `store/index.blade.php`'s breadcrumb already reads `__('Users')` |

Only the word "Store" on the card disagreed. Changing the **figure** to `Store::count()` would have
made the card contradict the screen it opens, and would have removed the merchant count, which
nothing else on the dashboard states. `OperatorDashboardWithoutAStoreTest` is also the recorded
contract for that figure — it builds its expectation by *calling* `countCompany()` — so changing the
number means changing that test in the same breath, which is a product decision. Changing the label
makes the existing figure honest and decides nothing.

Relabelled to **`Total Merchants`**.

### 1.2 "Total Coupon" printed a coupon NAME

`:count="$couponName" :label="__('Total Coupon')"`, where `$couponName` is the name of the most-used
plan coupon. Every other "Total X" card on the screen is a count. The screen already had the right
idiom for a card whose value is a name, immediately below: `Popular Plan`, showing
`Plan::most_purchese_plan()`.

Relabelled to **`Popular Coupon`**, matching that sibling, rather than replacing the value.

### 1.3 The coupon figure was an N+1, unbounded by coupon count

```php
$coupons = PlanCoupon::get();
foreach ($coupons as $coupon) {
    $max = $coupon->used_coupon();       // hasMany(...)->count() -- its OWN query, per coupon
    ...
}
```

`used_coupon()` returns an `int`, not a relation, so it cannot be eager-loaded. **Measured: exactly
12 queries against `plan_user_coupons` for 12 coupons**, on every load of the operator's landing
page, growing with the number of coupons the platform has ever created.

### 1.4 The plan figure counted every row in PHP

```php
$plan_order = PlanOrder::pluck('plan_id','id')->toarray();
$order = array_count_values($plan_order);
$max_value = max($order);
```

Every `plan_orders` row on the platform pulled into memory and counted there — no predicate, no
bound. This is the **same class** the controller a few lines below already records as fixed twice:
`popularStores` was three lines of exactly this shape over the visit log, and `topStoresByOrderValue`
likewise. Found only because the coupon card beside it was being fixed.

---

## 2. What was NOT changed, and why

**`app/Models/PlanCoupon.php` is untouched, `used_coupon()` included.** It has **~120 callers** —
every payment controller in `app/Http/Controllers/` calls it to decide whether a plan coupon is
exhausted — and two pieces of evidence pin its exact meaning:
`PlanCouponRefusalPersistsNothingTest` and
`tests/Support/Security/bin/verify-plan-coupon-refusal-ordering-on-mysql.php`, both of which turn on
the fact that it counts **across users**, so the limit is platform-wide. Changing its shape would
change a limit check on every gateway.

An earlier draft of this slice *did* add a relation to that file, for `withCount()`. That approach
was **withdrawn as a defect** (§5), and reverting it left the model byte-identical, which is the
smaller and safer diff.

**No figure on the screen changed.** Both relabelled cards print exactly the value they printed
before; `test_the_merchant_card_names_merchants_and_keeps_its_figure` asserts the number against
`countCompany()` so a figure change cannot ride along with the label change.

**The per-merchant tiles keep the words "Total Store".** Lower down the same view,
`{{ __('Total Store') }} : {{ $topAdmin->stores_count ?? 0 }}` labels a real count of `stores` rows
for one merchant. Those words are **correct** there. See §4.

---

## 3. Semantic equivalence of the two rewritten rankings

Both rewrites preserve two properties of the original, and both properties have their own test.

| property | how the original produced it | how the rewrite produces it | test |
|---|---|---|---|
| a coupon nobody redeemed never wins | `$maxValue = 0` and `if ($max > $maxValue)`, so a zero never set a name | a coupon with no rows is not in the `GROUP BY` at all — the same outcome by construction | `test_an_unused_coupon_is_not_named` |
| a coupon tie goes to the lowest id | `>` never displaces an equal count, and `get()` returns primary-key order | `->orderBy('coupon_id')` after the count | `test_a_tie_is_broken_the_way_the_original_broke_it` |
| no plan orders means no name | `if (!empty($order))` | `if ($ranked === null) return ''` | covered by the fixture-free default |
| a plan tie goes to the earliest order | `array_count_values()` keys follow first appearance and `array_search()` returns the first match, so the winner was the plan whose earliest `plan_orders` row came first | `MIN(id) as first_order_id` then `->orderBy('first_order_id')` | `test_a_plan_tie_is_broken_by_the_earliest_order` |
| a renamed plan shows its current name | `Plan::find($plan_id)->name`, not the denormalised `plan_orders.plan_name` | unchanged — still `Plan::find()` | — |

Without the `MIN(id)` tie-break, two equally popular plans could swap between requests on unchanged
data. That tie-break is the **original's**, recovered by reading it, not a new choice.

---

## 4. The assertion that could only have passed by breaking correct code

The first version of the test asserted over the whole rendered page:

```php
$this->assertStringNotContainsString('Total Store', $body);
```

**That assertion was unsatisfiable without a regression.** The same view prints `Total Store` a
second time, on each top-merchant tile, where it labels `$topAdmin->stores_count` — a real count of
`stores` rows, for which those are the right words. A whole-body search answers "does this string
appear anywhere on the page", so the only way to go green was to relabel a card that was already
correct.

Five of the six cases failed on the first run and **one of them could never have passed for the right
reason**. This is CLAUDE.md's recorded census trap in its second form — *"scope the search to the
block too"* — and it cost a round here.

The fix was to the test. Each `<x-dashboard-card>` renders
`<div class="details-card">` containing `<a href="{url}" aria-label="{label}">` and
`<h2>{label}</h2><h3>{count}</h3>`, so the cards are **parsed out of the response by identity** and
every assertion names a card by the route it links to. Two premises make that honest:

- `test_the_six_cards_are_found` — without it, a parse that matched nothing would make every "the
  label is not X" case pass **vacuously**.
- `test_the_parse_does_not_reach_the_per_merchant_tile` — three merchant tiles are added and the
  number of parsed cards must not move. Asserting "no parsed card says Total Store" instead would
  have been useless: before the fix the card itself said that, so the assertion fails identically
  whether or not the tiles are being parsed. It cannot tell the two apart; the count can.
- `test_the_per_merchant_store_badge_still_says_total_store` — the guard for the correct label the
  first version would have destroyed.

One further helper limit, found by execution: `cardLinkingTo('plan.index')` **refused to answer**,
because THREE of the six cards link to `plan.index` (Total Plan Orders, Total Plans, Popular Plan).
The `Popular Plan` sibling is therefore located by label among the parsed cards, not by route.

---

## 5. My first fix for the N+1 was itself a defect, and the MySQL probe is what found it

The obvious repair for §1.3 was one query:

```php
PlanCoupon::query()->withCount('planUserCoupons')
    ->orderByDesc('plan_user_coupons_count')->orderBy('id')->first();
```

It is one query, it reads correctly in every test that does not write a redemption mid-request, and
**it is stale.**

- 79 of the 95 models in `app/Models/` use `Cachable`, `PlanCoupon` and `PlanUserCoupon` among them.
  laravel-model-caching keys a result under **the model being queried**.
- `withCount` is a correlated subquery inside a **`PlanCoupon`** query, so the count caches under
  `plan_coupons`.
- A redemption inserts into **`plan_user_coupons`**. That flushes `PlanUserCoupon`'s cache. It does
  **not** flush `PlanCoupon`'s.
- So the card kept naming the previous winner, at its previous count, after the lead had changed.
- **The original loop was accidentally immune.** `used_coupon()` is
  `hasMany(PlanUserCoupon::class, ...)->count()` — a query on `PlanUserCoupon`, whose cache the
  redemption write *does* invalidate. The N+1 was slow but fresh; my repair was fast but wrong.

Measured on MariaDB with the array store:

```
1. after 1 redemption on ALPHA        : winner=ALPHA count=1
2. after 2 MORE on BETA (Eloquent)    : winner=ALPHA count=1   <- STALE
3. used_coupon() (the OLD path)       : ALPHA=1 BETA=2         <- FRESH
4. grouped on PlanUserCoupon+toBase() : coupon_id=2 count=2    <- FRESH
```

**How it was found is worth recording, because it was not found by looking for it.** The MySQL probe
(§7) reported two failures — a tie-break defect and a zero-use defect — that **did not exist**. Its
own fixtures used `DB::table()->delete()`, a raw write that flushes no model cache, so the re-read
served pre-delete rows. That is CLAUDE.md's recorded trap verbatim. Diagnosing *my harness's* false
positive is what exposed the real caching asymmetry in the code under test; a probe that had been
written correctly the first time would have passed and shipped the stale ranking.

The shipped ranking is therefore shape 4: grouped on `PlanUserCoupon`, the table the writes go to.
`PlanCouponRankingFreshnessTest` pins it, and fails all three of its cases against the withdrawn
implementation.

**`toBase()` is defensive, not load-bearing, and that is measured too**: a mutation removing it
**SURVIVED**. Freshness comes from grouping on the right model, not from bypassing the cache. It is
kept because it avoids hydrating an Eloquent `PlanUserCoupon` from a row holding only `coupon_id` and
a count, and because it matches `PlatformAnalyticsFigures::topStoresByPageViews()`. Nothing pins it;
removing it would not be a defect. Said so at the call site rather than implying coverage that does
not exist.

---

## 6. A false citation of my own, and the case that makes it true

`Plan::most_purchese_plan()`'s docblock was written saying *"`OperatorDashboardPopularPlanTest` pins
it"*. **There is no such class.** Worse, nothing pinned the plan tie-break at all — the coupon tie
test covers the coupon ranking only. Caught by checking the citation rather than trusting it.

Corrected two ways: the docblock now names
`OperatorDashboardCardLabelsTest::test_a_plan_tie_is_broken_by_the_earliest_order`, and that method
was written, so the citation is true.

---

## 7. The translation ratchet runs in one direction only

Adding `Total Merchants` and `Popular Coupon` surfaced this, measured rather than assumed:

`TranslationFileIntegrityTest::test_arabic_declares_no_key_english_has_dropped` fails an **ar.json**
key with no en.json sibling (exactly 31 orphans are pinned). **There is no case asserting the
reverse.** An English key with no Arabic one is silently legal, so both new labels could have
rendered in English on the Arabic operator dashboard with the entire suite green.

**Measured: 35 en.json keys have no Arabic sibling.** The sampled ones are payment-gateway brand
names — Aamarpay, Benefit, Braintree, Cashfree, Coingate — which must **not** be translated;
`PaymentBrandNameTranslationTest` is a whole file asserting exactly that, because they once were. So
a reverse ratchet needs a proper-noun policy and is **its own piece of work**, recorded here and not
closed in this slice.

What this slice does instead is pin its own two keys, three ways:

- `OperatorDashboardCardLabelTranslationTest` — both resolve under the `ar` locale, both are declared
  in en.json, and neither retired label is still passed as a card's `:label`.
- `OperatorDashboardCardLabelsTest::test_both_relabelled_cards_read_in_arabic` — the **rendered**
  card, through the real `SetLocate` middleware (an operator whose `language` is `ar`;
  `app()->setLocale()` would be discarded by an HTTP request).

Vocabulary is ar.json's own, not invented: `تاجر` is how it already renders merchant
(`Merchant ID` → `هوية التاجر`), and `الكوبون الشعبي` follows `Popular Plan` → `الخطة الشعبية`, the
sibling whose idiom the coupon label was matched to. `كوبون` is also ar.json's existing word for
`Coupon` — the retired `Total Coupon` used `قسيمة`, which disagreed with it.

---

## 8. Evidence

- `tests/Feature/Platform/OperatorDashboardCardLabelsTest.php` — 14 tests. Failing-first baseline
  measured: **6 failed, 6 passed**, with the six being the four label targets and the two unbounded
  figures, and the six passing being the parse premises and the three semantics guards.
- `tests/Feature/Platform/PlanCouponRankingFreshnessTest.php` — 3 tests. **All three fail against
  the withdrawn `withCount` implementation** and pass against the shipped one (§5).
- `tests/Unit/Localisation/OperatorDashboardCardLabelTranslationTest.php` — 3 tests.
- `tests/Support/Security/bin/verify-dashboard-rankings-on-mysql.php` — **13 checks, 0 failed**, on
  a real MariaDB 10.11 with `ONLY_FULL_GROUP_BY` set **explicitly on the session**. That matters:
  MySQL 8 enables it by default, MariaDB does not, so a probe on a MariaDB default would pass
  without exercising the restriction and would be worthless as evidence about production. It cannot
  be a test because the suite runs on SQLite, which has no such mode and accepts a `GROUP BY` that
  selects a non-aggregated column. It creates and drops its own throwaway database and reads no
  existing schema.
- **Mutations: 15 caught, 1 declared survivor.** Each label put back; each label changed to a third
  word; the per-merchant tile label removed; both tie-breaks reversed; both rankings inverted; the
  per-coupon COUNT loop restored; the PHP `array_count_values` count restored; the ranking moved
  back onto a `PlanCoupon` query; the Arabic removed from ar.json (two forms); the Arabic silently
  anglicised; the English sibling dropped. The declared survivor is `toBase()` (§5).
- `tests/Feature/Platform/` + `tests/Unit/Localisation/` — **293 passed**; with
  `tests/Unit/Ui/` and two security files, **1,468 passed**.
- Blocking suites on the final tree: **Unit 6,202 passed + 1 declared skip; Contract 45 passed.**

`OperatorDashboardWithoutAStoreTest` needed one line changed: its label/figure pairing now reads
`__('Total Merchants')`. **Its strength is unchanged** — it still requires the label and the number to
be inside the same card, and still builds the expected number by calling `countCompany()`.

---

## 9. Recorded, not fixed

| | |
|---|---|
| **`Store` → `مخزن` is the wrong Arabic word.** ar.json renders `Store` as `مخزن`, `Stores` as `المخازن` and `Total Store` as `إجمالي المخزن`. `مخزن` is a *warehouse/storeroom*, not a shop — and `Most Popular Store` in the same file correctly uses `متاجر`. The per-merchant tile this slice deliberately left alone therefore reads "total warehouse" in Arabic. Fixing it touches every surface using those three keys and needs its own measurement. | its own slice |
| **The translation ratchet's missing direction** (§6): 35 en-only keys, mostly brand names that must stay untranslated, so it needs a proper-noun allowlist. | its own slice |
| **`SetLocate` does unrelated writes on a GET.** Reading the locale resolution for §6 showed `SetLocate::handle()` calling `$user->assignPlan()` on an expired plan or trial, i.e. a plan mutation during locale resolution on every request. Not touched here; not measured. | lead, unverified |
