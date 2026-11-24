# MKT-006 — rewards are never reversed; and the eleven copies of one money expression

**MKT-006 (owner-approved, recovered plan):** *"عكس المكافآت عند الإلغاء والاسترداد"* —
**reverse rewards on cancellation and refund.**

**MKT-006 status: NOT IMPLEMENTED.** Measured, not inferred.

While measuring it, the eleven first-party payment paths that *spend* reward points were found to
share one copy-pasted expression that was fatal under a reachable configuration. That is fixed,
because it changes no price. Four further findings are **reported and not acted on**, each because
acting would change a customer-visible amount or require editing a vendored module.

---

## 1. MKT-006 itself — points are added and never taken away

| | |
|---|---|
| listeners in `packages/workdo/RewardClubPoint/` | **one** — `AddRewardClubPointLis`, on `App\Events\AddRewardClubPoint` |
| cancel / refund / reversal handling in the module | **none.** `grep -rliE "cancel\|refund\|revers" src/app/` returns nothing (exit 1) |
| writes to `customers.total_club_point` in `app/` | **zero.** All 11 references in `app/` are reads |
| the only writer | `AddRewardClubPointLis.php:46`, inside the vendored module |

A shopper orders, earns points, has the order **cancelled or refunded**, and **keeps the points**.
They are then spendable: the eleven paths in §2 subtract a discount computed from the balance. The
leak converts to money on the next order.

Same shape as P4-F124 and P4-F127 on the money side — an entitlement that survives a reversal —
except nothing here was ever built to reverse it.

---

## 2. FIXED — one expression, defined once (`App\Support\Money\RewardPointDiscount`)

All eleven first-party sites carried this, character for character:

```php
$saveRewardPrice = ($customerDetail->total_club_point * $rewardSetting['reward_point_price'] ?? 0)
                 / ($rewardSetting['reward_points'] ?? 0);
```

**Two defects in every copy, both measured by execution:**

| input | shipped expression | now |
|---|---|---|
| both settings present (100 pts, 0.5/pt, 10 pts per unit) | `5.0` | **`5.0` — identical** |
| `reward_points` absent | **`DivisionByZeroError`** | `0.0` |
| `reward_points` = `0` (passes Laravel `required`) | **`DivisionByZeroError`** | `0.0` |
| settings row entirely absent | **`DivisionByZeroError`** | `0.0` |
| `reward_point_price` absent | **`ErrorException` — an HTTP 500** | `0.0` |
| `reward_point_price` = `'free'` | **`TypeError`** (`100.0 * 'free'`) | `0.0` |
| `reward_point_price` = `'2xyz'` | `20.0` — a discount computed from junk | `0.0` |
| `reward_point_price` = `-0.5` | `-5.0` — a NEGATIVE discount, which **adds** to the bill | `0.0` |

**The absent-price row was wrong in the first draft of this document and a review caught it.** I
first measured it in a standalone script, where PHP merely warns and yields `0.0`. Inside this app
it is fatal: `HandleExceptions::bootstrap()` sets `error_reporting(-1)` and `handleError()` throws,
so an absent `reward_point_price` was `ErrorException: Undefined array key "reward_point_price"` —
an HTTP 500 mid-checkout. Re-measured in the booted app. **The fix is better justified than I
originally claimed**: the old expression was fatal in four families of input, not one.

1. **`/ (… ?? 0)` defaults the divisor to zero**, so a store with the module enabled and no
   `reward_points` saved throws **during payment confirmation**. The `?? 0` reads as a safe
   default and is its exact opposite.
2. **`($a * $b ?? 0)` is mis-bound** — `*` binds tighter than `??`, so it parses as `($a * $b) ?? 0`,
   which can never be null and therefore never defaults anything.

**Why this is not a pricing change.** `RewardPointDiscountTest` reproduces the shipped expression in
`self::shipped()` and asserts the helper returns `assertSame()` on every configured input. **No
ordinary store's discount moves by one cent.**

**One deliberate divergence, which the first draft of this document wrongly denied.** It claimed the
arithmetic was unchanged "wherever it produced a number at all". That is false for a **negative**
configured rate: the old expression produced `-5.0`, and because a discount is *subtracted*, a
negative one **added** to the bill — `final_price + tax - (-5.0)` charged 5.00 **more** than the
basket. The helper clamps it to no discount. This is reachable, not perverse-only-in-theory: the
module's settings validator is `'required'` with **no `numeric` and no `min:0`**, so a merchant can
save `reward_point_price = -0.5` through the ordinary form. The behaviour is right and is pinned by
`it_never_returns_a_negative_discount`; only my sentence was wrong.

**A missing rate is not a rate of one.** The module's own `RewardClubPointController::applyPoints()`
guards the same division with `$rewardPoints = … != 0 ? … : 1` — a divisor of **one**, which turns a
misconfiguration into the largest discount the balance can buy. Measured on that fallback: a
100-point balance at 0.5 took **50.00 off a 20.00 basket**. The helper deliberately diverges: no
configured rate means no discount, the only reading that cannot invent money from an empty settings
row.

**Evidence.** 22 tests, `tests/Unit/Loyalty/RewardPointDiscountTest.php`, including a premise test
asserting the shipped expression *does* throw, so the guard cannot later be mistaken for decoration.
A seven-mutation battery reddens on every surviving line. Three guards in the first draft were
**proved dead** by mutation and removed — explicit `$balance <= 0.0` and `$price <= 0.0` returns and
the final clamp were mutually covering, so any one deleted invisibly. Two checks remain, each pinned
alone. One mutation then found a real hole the tests had missed: **`is_numeric()` is not
interchangeable with casting** — `(float) '5abc'` is `5.0` while `is_numeric('5abc')` is false, so a
cast-only guard divided by 5 and granted a **20.00 discount computed from `reward_point_price =
'2xyz'`**. Test case added; guard reinstated.

**THE HONEST LIMIT OF THAT EVIDENCE.** `the_shipped_expression_is_fatal…` asserts about
`self::shipped()`, a copy of the old expression inside the test file — it exercises **no production
code** and must not be counted as covering the eleven call sites. **No test in the repository drives
any of the eleven**, and that is structural rather than lazy: `module_is_active('RewardClubPoint')`
gates every one of those branches and, per `CLAUDE.md`, no WorkDo module loads in a test run without
an `add_on_managers` row. So nothing pins that a site passes the *authenticated* customer's balance
and *this* store's settings; a review traced all eleven by hand instead and found every
`$customer_id` server-derived, with one latent exception in `PayUPaymentController::getProductStatus`
that **no route declares** (dead code, unchanged by this work).

Suites after the change: Unit **1902 passed / 1 skipped** (now 1906 with the loyalty tests),
Contract **45 passed**, Feature `Payment|Reward|Checkout|ClubPoint|Order` **2281 passed**,
`StorefrontCustomerReadAuthorizationTest` **34 passed**.

---

## 3. REPORTED, NOT ACTED ON — four findings

### 3a. A large balance against a small basket writes a NEGATIVE order total

The eleven callers compute `final_price = $total_sub_price - $saveRewardPrice` with **no
`max(0, …)`**. Measured: a 20.00 basket less a 50.00 discount stored as **`-30.00`**. The module's
own `applyPoints()` clamps with `max(0, …)` and comments *"Ensure it doesn't go negative"*; no
first-party caller does.

*Consequence of fixing:* clamping changes what a customer pays in a case that currently **succeeds**
(a negative total is written, not an error), so it is a monetary change, not a crash fix. Not taken.

### 3b. The redemption thresholds are enforced in Blade only

`checkout_points` and `checkout_price` are tested in
`resources/views/theme/clubPointApply.blade.php` and `clubPointPriceShow.blade.php`. The
**server-side** guard is:

```php
module_is_active('RewardClubPoint') && isset($requests_data['club_point_is_active'])
    && $requests_data['club_point_is_active'] == 'on'
```

— entirely caller-supplied. A client posting `club_point_is_active=on` redeems below the
configured minimum points and minimum order value. The amount is still bounded by the customer's
**own** balance, read server-side from `Customer::find($customer_id)`, so this is a business-rule
bypass, not an unbounded discount.

*Consequence of fixing:* enforcing a threshold **denies** redemptions that currently succeed. Not
taken.

### 3c. Three defects inside the vendored listener

`packages/workdo/RewardClubPoint/src/app/Listeners/AddRewardClubPointLis.php`. `CLAUDE.md` forbids
editing vendored modules, so these are reported only.

- **A dead validation check.** The guard reads
  `$requests_data['club_point_is_active'] ?? '' == 'on' && $requests_data['final_amount_point'] == $order['final_price']`.
  `==` and `&&` bind tighter than `??`, so PHP parses it as
  `$requests_data['club_point_is_active'] ?? ('' == 'on' && …)` — **the left operand whenever it is
  set.** Executed with `final_amount_point = 999.0` against `final_price = 100.0`, values that
  disagree: *as written* `'on'` (branch taken); *as intended* `false` (branch refused). **The check
  comparing the client-posted amount against the server's own total never runs.**
- **Enabling club points without a point price is a hard 500.** `$orderClubPoint = (… ) / ($setting['order_point_price'] ?? 0)`
  — measured **`DivisionByZeroError`** with the setting absent. Every order creation throws for a
  merchant who enables the feature without setting that one field. The numerator's `??` is
  mis-bound too, so an absent `order_points` is a warning, not a 0.
- **Redemption replaces the balance rather than debiting it.** `$totalClubPoint = $orderClubPoint`
  discards the prior balance. Self-consistent only because the discount always redeems the *entire*
  balance; a partial redemption added later would silently delete the remainder.

### 3e. Two more, found by review of the change itself

- **A Khalti redemption never spends the points.** `KhaltiPaymnetController` is the only one of the
  nine writers with no `event(new AddRewardClubPoint(...))`, and that listener is the only thing
  that consumes the balance. So a shopper paying by Khalti takes the discount and **keeps** their
  points — the same leak as MKT-006, by a different route. Not fixed: firing the event changes a
  customer-visible balance.
- **Display can now show a saving the server will not grant.** The vendored
  `clubPointApply.blade.php` still carries the old expression and `applyPoints()` still falls back
  to a divisor of 1, so with a non-numeric configured price the page offers 20.00 off while the
  order is now written at full price with no message. Before this change the server answered 500
  instead, which was at least not silent. Mechanism verified by reading; reachability is
  speculative — it needs a store that saved a non-numeric price.

### 3f. A separate finding on the loyalty READ path — now P1-SEC-63

Measuring MKT-006 surfaced a cross-tenant exposure in `ApiController::loyality_program_json()` and
a `point` figure that always reports `0.00`. Both are recorded in
`docs/security/P01-SECURITY-WORK-QUEUE.md` under **P1-SEC-63**; the authorization half is fixed and
demonstrated, the display half is an owner decision because fixing it produces an accurate but
**mislabelled** figure (lifetime points earned, not the spendable balance).

### 3d. MKT-006 remedy options

| option | consequence |
|---|---|
| **A. A first-party reversal listener** in `app/`, on the existing cancellation and refund paths, writing a compensating `RewardClubPoint` row and debiting the balance | Honours MKT-006 without editing the module. Needs a decision on **floor behaviour** — a customer who already spent the points goes negative unless the debit is clamped, and clamping silently forgives the leak — and must be idempotent, since P4-F124 showed how often a reversal path is replayed. |
| **B. Treat it as a vendor defect** | No first-party code to maintain, no control of timing, leak continues. |
| **C. Disable the module** until reversal exists | Stops new leakage immediately; removes a feature merchants may use; points already awarded remain. |
| **D. Accept and document** | Small per order, unbounded in aggregate. 3c's first two items should be fixed by whoever owns the module regardless: one is a dead check on a money comparison, the other a 500. |

**Historical balances are NOT proposed.** Whatever is decided for new cancellations, points already
awarded against cancelled orders sit in `customers.total_club_point` today. Recomputing them is an
irreversible change to live customer balances and is out of scope.

---

## 4. Phase 05 traceability

- **MKT-005** (loyalty points, VIP, referrals, transaction log) — **PARTIAL.** Points and the
  `RewardClubPoint` log exist. VIP tiers and referrals were not found in this pass and are not
  assessed here; they remain open, not closed.
- **MKT-006** — **NOT MET.** Recorded as not met, not deferred: per `CLAUDE.md` §4.1 an engineering
  report deferring something is not an owner decision.
