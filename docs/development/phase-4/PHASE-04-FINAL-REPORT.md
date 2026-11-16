# Phase 04 — Commerce: engineering report

**Branch** `feature/phase-04-commerce` · **Draft PR #139** · **Not merged, not released, not deployed.**

This report states what was changed, what was measured, what was deliberately not changed, and what
the owner still has to decide. Every figure in it names the command that produced it. Nothing here
claims a production release is ready, and nothing here was deployed, migrated or merged.

---

## 1. The one-line answer

**Sixteen of the eighteen HIGH confirmed findings in the Phase 04 register are now closed.**

This stretch fixed **twenty-one distinct defects** across the order write, returns, authorization,
tax and gateway-callback surfaces — seventeen in the first pass, then two more live payment legs
(PayTR, Xendit) and two unjustified exemptions, all four found by reviewing the corrections
themselves. Among them:
a coupon that could be made to **pay out twice**, a repeated checkout post that wrote **a second
order and took the stock twice**, a second return that drove an order's `final_price` **negative**,
three order-mutating admin API routes with **no permission check at all**, a jurisdiction-restricted
tax **charged to shoppers outside it**, and a cancelled gateway payment that wrote a **phantom order
and took the goods**.

**Nineteen of those defects were in work this phase had already called verified.** Six were found by
independent review of the fixes; eight by the known-outcome gate on the fixes for those, including a
**PHP fatal in a fix whose purpose was to make a failure survivable**; one by an adversarial sweep
over a fix *and its test*; and four more by a second sweep over the *corrected* fix and its
*rewritten* test. Section 3 is about that pattern, and it is the part of this report worth reading
twice.

**Nothing in this phase is release-ready.** Six questions remain the owner's, not engineering's —
five about what a money column means (P4-F124, P4-F126, P4-F127, P4-F128, P4-F032) and one about
where a live payment callback goes (P4-F046). Section 4 states each one and why it is not an
engineering call.

---

## 2. What was fixed, and how each was falsified

Every fix below was **removed again and the test re-run**, because a green test that cannot fail is
the failure mode this codebase has repeatedly paid for. The falsification column says what went red.

| id | defect | falsification |
|---|---|---|
| **P4-F026** | the two storefront API order writers saved the order and four child rows with **no transaction**; a caller-chosen `coupon_id` broke the write between `order_coupon_details` and `user_coupons`, and a `coupon_limit = 1` coupon **paid out twice** | `StorefrontOrderWriteAtomicityTest` 6 cases |
| **P4-F130** | a bare `DB::rollBack()` in the new boundary would unwind a **caller's** transaction after our own commit succeeded | found by building a probe, not by a test; the probe is `ApiOrderWriterStockCompensationTest`'s post-commit case |
| **P4-F131** | the D-13 stock gate asked `$order->exists`, which a rollback never clears, so it skipped the give-back on **every failure inside the boundary** | the old spelling re-reddens the new rollback-window case on both writers |
| **P4-F132** | `OrderController::store()` accepted **any store's** `customer_id` | removing the predicate reddens 3; the rejected `getCurrentStore()` draft reddens exactly the 2 fail-closed cases |
| **P4-F133** | a repeated POST to `{slug}/process-order` wrote a **second order** and took the stock twice | removing the claim reddens the duplicate-post case |
| **P4-F136** | the new web claim read cart ids from a payload where they are **a loop index** on the guest branch — so guests had no protection at all, or were refused for 120 s over an order that never existed | the payload-derived ids re-redden the guest duplicate case |
| **P4-F137** | two payment arms end in `exit;` / `die()`, so the claim was stranded — a **transient network error** to PayTR locked that shopper out of checkout for two minutes | structural; the release is now a `register_shutdown_function` |
| **P4-F082** | a second return re-subtracted the first line: a 147.50 order went **−72.50** where 17.50 is owed | reverting one line reddens 3 of 4 |
| **P4-F107** | `DELETE /api/admin/deleteorder` hard-deleted an order and its tax, coupon and billing rows with **no permission check** | no gate reddens the permissionless case; `isAbleTo()` reddens the operator case |
| **P4-F037** | `status-change` and `order-cancel` had no permission check, and cancelling is **money-bearing** | returning `true` from the predicate reddens both |
| **P4-F070** | a tax restricted to one city was charged to shoppers elsewhere: **8.00 where 5.00 is owed**, and **8.00 where 0.00 is owed** before an address is entered | reverting `Cart.php` reddens 5 of 8 |
| **P4-F044** | pressing **Cancel** at the gateway wrote a phantom order, took the stock with no compensation, emptied the cart and consumed the 48-hour return claim | removing the reader reddens both cancel cases |
| **P4-F096** | an incomplete guest billing form answered **HTTP 500** instead of the shopper's own validation message | removing the guard re-reddens it with the measured 500 |
| **P4-F046** (part) | a dead `webhook_backup()` POSTed the whole order model and the raw request to a **public bin**; the Benefit charge sent the first name as the surname | ratcheted by `GatewayCallbackDestinationTest` |

Two further fixes hardened the new code against failure modes no test could reach: a cache read that
could **cancel the stock compensation** (P4-F139) and a failed claim write that could **skip a
committed order's webhook** (P4-F140).

---

## 3. What independent review found in Phase 04's own work

This is the part worth reading twice. Across the phase, **five of my own fixes needed their own
review**, and each was invisible to the tests written specifically to falsify it:

- a first-come coupon allocation that moved the tax base by 20.00 with row order;
- a pro-rata allocation that then lost its bound — **933.33 discount on a 750.00 basket**;
- `SetNumber()` truncating 99.99 to 99;
- a process-static memo that broke test isolation under `RefreshDatabase`;
- an uncaptured closure variable that turned a webhook defect into an **HTTP 500 and a full
  rollback**, wider than the defect it replaced.

The P4-3b review round found six more in one pass, two of them live on the storefront checkout.

**Then the Feature known-outcome gate found eight more, in the fixes for those.** Five in one run
and three in the next: a `register_shutdown_function` that called a facade after the container was
torn down and so raised a **PHP fatal** — from code written to make a failure survivable; a
permission gate that refused the store's own owner, which two older tests had been written
specifically to catch, one of them saying in its own message *"The predicate is too tight — that is
a defect, not a fix"*; an inverted release condition; and five ratchets that needed honest
re-classification rather than widening.

**Then a second sweep, over those corrections, found two more LIVE payment defects** — PayTR's
`merchant_fail_url`, byte-identical to its own success URL, and Xendit's `failure_redirect_url` —
sitting behind a ratchet I had rewritten specifically to be exhaustive and whose docblock claimed it
was. Measured: that rewrite enumerated **10 of the file's 47** return-URL sites. It also removed two
exemptions I had written without checking, one of them asserting that an encrypted payload was "its
own marker" when `Crypt::decrypt` appears **zero** times in the handler.

The pattern is consistent and worth stating plainly: **a test written to catch a defect does not
catch the defect introduced by its fix, and a test written to catch THAT does not either.** The
sequence here ran four deep — fix, review, gate, sweep, sweep. Each layer found real defects the
previous one had certified. Any unreviewed fix in these files should be treated as provisional, and
a ratchet that enumerates by name rather than by destination should be assumed incomplete until
someone counts what it actually covers.

---

## 4. Outstanding financial decisions — the owner's, not engineering's

| id | question | why it is not an engineering call |
|---|---|---|
| **P4-F124** | what cancelling an order should do to `payment_status` for prepaid, COD, wallet-credited, refunded, uncollected and duplicate-cancelled orders | changing it misrepresents actual payment settlement; the full measured inventory and four read-only production queries are in `docs/finance/P4-F124-LEDGER-SEMANTICS.md`, status **UNRESOLVED** |
| **P4-F126** | a merchant may assert `Paid` on a COD order at creation, which credits the wallet before the parcel leaves — the literal condition owner decision D-06 forbids | coherent for a counter sale, incoherent for a COD parcel |
| **P4-F127** | a fully refunded order keeps `payment_status = 'Paid'`, keeps its `store_order_financials` row at `paid` and keeps the merchant's wallet credit | a refund that moves no money record is a policy gap, not a bug to patch silently |
| **P4-F128** | the wallet can be moved without the order, in both directions, with no reconciliation anywhere | same |
| **P4-F046** (rest) | the live storefront Benefit callback points at a **public `webhook.site` bin**, and the storefront return performs **no capture verification for `benefit` at all** | changing a payment callback destination is the open P1-SEC-55 escalation; three more copies are in vendored code that must not be edited |
| **P4-F032** | an order in a store with no `store_wallets` row writes **no financial record** and loses that credit permanently — `firstOrFail()` throws, `OrderObserver` swallows it, the order commits | creating the missing wallet would start crediting orders that credit nothing today, changing a merchant's balance semantics mid-stream and doing nothing for the credits already lost. **The silence is fixed here** — the loss is now a `critical` line naming the order, the store and the amount, inventoried — **the policy is not** |

**One behaviour change in this phase deserves the owner's eye even though it is a defect fix:**
P4-F070 reduces what shoppers are charged on stores that have saved tax options, toward what the
merchant's own configuration says. Stores that have never opened the tax settings form are
unaffected in either direction, and that is pinned in both directions by a test.

---

## 5. Security blockers

- **P1-SEC-40 / issue #120** — the database-dump exposure incident is **open and untouched by this
  phase**. No commerce change remediates it.
- **The `Security-sensitive diff: human checklist` CI job is red by design** on PR #139 and is the
  owner's to clear. **It was not falsified, worked around, or claimed complete.** No automation in
  this phase wrote to it.
- **P4-F046's live half** is an open owner escalation, above.

---

## 6. Recorded, not fixed

| id | why |
|---|---|
| **P4-F134** | `ordersave` may record **no coupon redemption at all**, so coupon caps never see those orders. Needs a driven test before anything changes, because the fix direction changes what shoppers may spend. |
| **P4-F135** | a **refused** coupon still writes `order_coupon_details` and `user_coupons`, burning the shopper's allowance. Queued with P4-F134; both want the same change. |
| **P4-F138** | `cart_id` means a row id on one cart branch and a loop index on the other. Correcting it is a storefront contract change: four views post it back to the cart remove and quantity endpoints. |
| two boundary decisions | `store_wallets` is now locked for the whole order write (one row per store, so concurrent checkouts in a store serialise, and it is a new deadlock surface), and `BillingGeography` can insert into the platform-global `cities` table inside it. Both recorded rather than changed on a reviewer's say-so. |

---

## 7. Evidence

Every figure below was measured on **one clean tree** — `feature/phase-04-commerce` at `ee3c68ca`,
`git status` empty — and each names the command that produced it. The Feature suite subsumes
`tests/Feature/Commerce`, `tests/Feature/Security` and `tests/Feature/Tenancy`, so the whole surface
is covered by a single run on a single tree. Figures from earlier trees in this
phase are not carried forward, because three suite runs during this work were invalidated by editing
files while they ran, and an unattributable number is worse than no number.

| gate | command | result |
|---|---|---|
| Unit | `php artisan test --testsuite=Unit` | **1845 passed, 1 skipped, 0 failed** |
| Contract (the Flutter API contract) | `php artisan test --testsuite=Contract` | **45 passed** |
| Commerce + Security + Tenancy | `php artisan test tests/Feature/Commerce tests/Feature/Security tests/Feature/Tenancy` | **6648 passed, 1 skipped, 0 failed** |
| Feature | `php artisan test --testsuite=Feature --log-junit … --log-teamcity …` | 8977 testcases, 47262 assertions, **2 failures, 0 errors, 1 skip** |
| Known-outcome gate | `php .github/scripts/assert-known-test-outcomes.php …` | **OK — the observed set matches the manifest exactly** |

**The gate's own words, kept rather than paraphrased:** *"This is NOT a statement that the suite is
green. It is a statement that the suite is failing and skipping exactly the tests the project has
recorded, for the reasons it has recorded."* The two failures are the two the manifest already
declared — `Auth\RegistrationTest::test_new_users_can_register` and
`ProfileTest::test_profile_page_is_displayed` — both pre-existing, neither touched by Phase 04.
**Nothing was added to `.github/known-test-outcomes.json`.**

**GitHub CI: the merge-base regression gate passes; the required Tests jobs have never completed.**
At `2e0a996c`, CI reported **success** for `Merge-base regression gate (new failures only)`,
`Static checks`, `Secret scan` and the PayHere report — the merge-base gate being one of the two
this phase was asked to satisfy, and independent evidence that this work introduces no new failures
against the base. Both required Tests jobs were **cancelled** at six successive SHAs, each push
superseding the previous run; they have not failed either. The one genuine CI failure in this
stretch — at `14a46da6`, before this work began — was a stale observability inventory, since
resynced. `Security-sensitive diff: human checklist` is red by design
and is the owner's to clear; no automation in this phase wrote to it or claimed it complete.

**New tests added by this phase:** 8 files, 52 test methods.

### What the gates do not prove

- They run on **SQLite `:memory:`** with `CACHE_STORE=array`. Production is MySQL with
  `CACHE_STORE=database`, and SQLite's type affinity hides real MySQL defects — that is how
  P01-SEC-13 survived. No change in this phase added or altered a migration, so no new schema risk
  was introduced, but the money arithmetic in P4-F070 and P4-F082 is verified on SQLite decimals
  only.
- `register_shutdown_function` behaves differently under a long-lived worker (queue, Octane) than
  under the per-request lifecycle these tests exercise. The P4-F137 release is written to be total
  and to fail safe, but that specific environment is not covered here.
- No journey was driven against a real gateway. Every gateway assertion is either a stored-state
  drive or a source-structural one, and each test says which it is.

---

## 8. The exact Phase 05 entry point

**Phase 05 is Marketing & Analytics** in the owner-approved six-phase plan: campaigns, loyalty,
reports and trustworthy analytics.

**Do not start it until these are answered**, because each one determines what an analytics figure
means:

1. **P4-F124, P4-F126, P4-F127 and P4-F128** all concern what `payment_status`, `store_wallets` and
   `store_order_financials` mean. Revenue reporting reads exactly those columns. Building analytics
   on a ledger whose semantics are unresolved produces numbers nobody can defend — which is the
   "trustworthy analytics" the phase is named for.
2. **P4-F129** records that the repository's own vocabulary documentation is wrong about where
   `'succeeded'` is written. One read-only production query settles it:
   `SELECT payment_status, COUNT(*) FROM orders GROUP BY 1`.
3. **P4-F134 / P4-F135** decide what `user_coupons` counts, which is what any campaign-redemption
   report would sum.

**The first executable Phase 05 action**, once those are answered: inventory every reporting surface
that reads a money column, the way P4-0 inventoried the commerce surface — a register of claims,
each confirmed or refuted by independent adversarial review before any of it is built on.

**Phase 04's own remaining work**, if the owner wants it closed rather than carried: **two** of the
eighteen HIGH confirmed findings are still open, and both are owner decisions rather than engineering
— **P4-F032** (a wallet-less store loses its credit permanently; the loss is now audible but the
wallet is deliberately not created) and **P4-F046's live half** (the Benefit callback destination and
the absent Benefit capture verification). Then the P4-F134 / P4-F135 coupon-recording pair, and
P4-F138's `cart_id` contract.
