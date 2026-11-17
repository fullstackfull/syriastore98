# Vendor defects — `workdo/RewardClubPoint`

**Owner decision D7 (2026-11-17):** *"Use safe first-party protections for the identified vendor
defects and report vendor issues upstream. Do not directly modify protected WorkDo vendor packages.
If a defect cannot be safely intercepted, record the unresolved risk and propose containment instead
of claiming it fixed."*

This file is the upstream report and the containment record. **No file under `packages/workdo/` is
modified by any of it.** Three defects, all in one file, all in the same family — `??` used as
though it bound tighter than it does.

Everything below was measured by executing the expression, not by reading it. Where a result
differs inside the booted application and outside it, the **inside** figure is the one quoted:
`HandleExceptions::bootstrap()` sets `error_reporting(-1)` and `handleError()` throws, so an
undefined array key is an `ErrorException` and not a warning. Verified: `error_reporting()` returns
`-1` in this application.

---

## V1 — the redemption guard reads any present flag as "redeem", including `'off'`

**File:** `packages/workdo/RewardClubPoint/src/app/Listeners/AddRewardClubPointLis.php:38`

```php
if ($requests_data['club_point_is_active'] ?? '' == 'on' && $requests_data['final_amount_point'] == $order['final_price']) {
```

`==` and `&&` both bind tighter than `??`, so PHP parses this as

```php
$requests_data['club_point_is_active'] ?? (('' == 'on') && ($requests_data['final_amount_point'] == $order['final_price']))
//                                        └──────────── always false, and never evaluated ────────────┘
```

which is `$flag ?? false` — the **truthiness of the posted flag**.

### Measured truth table

| `requests_data` | branch taken |
|---|---|
| field absent | accrue (correct) |
| `'off'` — **the value the shipped form posts** | **redeem** |
| `'on'` | redeem (correct) |
| `'on'`, `final_amount_point` deliberately mismatched | **redeem** — the check never runs |
| `'off'`, `final_amount_point` mismatched | **redeem** |
| `''` / `'0'` / `null` | accrue (correct) |

### Consequence

The redemption branch is:

```php
$totalClubPoint = $orderClubPoint;              // :39  assignment, NOT +=
$customerTotalPoints = $customer['total_club_point'];
$rewardPrice = (…);                             // :41
…
$customer['total_club_point'] = $totalClubPoint;
$customer->save();                              // :46-47
```

So on an order that redeemed nothing, **`customers.total_club_point` is replaced by just that
order's earned points** — the accumulated balance is gone — and a `RewardClubPoint` row is written
with `used_points` set to the entire prior balance (`:55`), as though it had been spent. The
accrual branch at `:43`, which does `($customer['total_club_point'] ?? 0) + $orderClubPoint`, is
the branch that does not run.

### Which customers this reaches — CORRECTED after independent review

**An earlier version of this section was wrong, and the test that "pinned" it could not fail.** It
traced the flag through `clubPointFormField.blade.php` → `checkout_hide_field.blade.php` →
`common/checkout/checkout_form.blade.php:125` and concluded "every checkout". **Nothing renders
that last file.** Its only reference anywhere in the repository is LynomiaChat's ViewComposer
naming it as a composer target — composing a view that is never resolved. The live checkout
includes a *different* file of nearly the same name, `front_end/hooks/checkout_form.blade.php`,
which holds one unrelated stack. So `clubPointFormField` never reaches a browser, and neither does
its `final_amount_point`.

**The input that actually posts is the other one**, in `theme/clubPointApply.blade.php:25`:

```blade
<input type="hidden" class="club_point_is_active" name="club_point_is_active" value="off">
```

(the `class` matters: it is the handle the module's JS uses at `clubPointApply.blade.php:58,80`),
and it is **inside an eligibility gate** at `clubPointApply.blade.php:1-9`:

```blade
@if (isset($setting['order_point_price']) && … all six reward settings …)
    @if ($customer->total_club_point >= $setting['checkout_points']
         && $response->data->final_price >= $setting['checkout_price'])
```

The chain that puts it in the browser, each link verified:

| | |
|---|---|
| `app/Providers/ViewComposer.php:27-32` | pushes `clubPointApply` when the view has both `store` and `response` |
| `app/Http/Controllers/CartController.php:114` | renders `front_end.pages.checkout-cart-list` with exactly those two keys, into `checkout_html_2` |
| `front_end/pages/checkout-cart-list.blade.php:97` | `@include('front_end.hooks.checkout_list')` |
| `front_end/hooks/checkout_list.blade.php:2` | `@stack('clubPointApply')` |
| `public/assets/js/front-theme.js:224` | `$('.checkout_page_cart').html(response.checkout_html_2)` |
| `front_end/pages/checkout.blade.php:25 / :256 / :263` | `.checkout_page_cart` sits **between** `Form::open` and `Form::close`, so the injected input is submitted |

**Corrected severity.** The destructive path fires for a logged-in customer **who already qualifies
to redeem** — all six settings present, balance ≥ `checkout_points`, cart total ≥ `checkout_price`
— and who did *not* press "Apply Points". That is narrower than "every checkout", and two things
keep it serious:

- it is precisely the population **whose balance is worth destroying**; and
- with `checkout_points` and `checkout_price` configured at `0` it degenerates to *every* logged-in
  checkout. Those values are savable: `RewardClubPointController:26-33` validates them with a bare
  `'required'`, which passes `0`.

Separately, a hand-crafted POST — or any storefront-API caller reaching `PaymentService.php:3435` —
can set the flag to any value regardless of what the form renders. So the containment is justified
independently of which view ships it.

### Second consequence: the anti-tamper check has never executed

`$requests_data['final_amount_point'] == $order['final_price']` is plainly meant to refuse a forged
total. It sits in the dead right operand. Note that a naive upstream fix — adding parentheses
without also guarding the key — would turn it into an `ErrorException: Undefined array key
"final_amount_point"` for any request that does not post that field.

### Containment — IMPLEMENTED, first-party

`App\Listeners\NormaliseRewardRedemptionFlag` reduces the posted flag to `'on'`-or-absent before
the vendored listener reads it, and touches nothing else. It is a **shape normalisation, not a
second decision**: it reads no balance, consults no threshold and computes no money, so it cannot
drift from the ELEVEN first-party sites that apply the discount (eleven apply it; only NINE dispatch the event -- `KhaltiPaymnetController:972` applies and dispatches nothing, a pre-existing leak recorded at `PHASE-05-OWNER-DECISION-PACKAGE.md:145` and assigned to D4). No input moves from accrue to
redeem; only `'off'` moves, and it moves to the branch the vendor's own `else` was written for.

Ordering is the load-bearing claim and it is measured: module providers arrive from
`app->booted()`, strictly after this listener is registered. A probe listener registered that way
observed the flag as `ABSENT`.

Pinned by `tests/Unit/Loyalty/RewardRedemptionFlagTest` — 18 tests, 57 assertions, 8 mutations run
and **8 reddened**. Its premise tests assert the vendored expression is still the mis-bound one, so
an upstream fix makes CI say so rather than letting this containment silently become redundant.

### NOT contained

**Balances already overwritten are not repaired.** D4 forbids retrospectively altering balances
without separate approval, and the history needed to reconstruct them may not exist: the
`reward_club_points` rows record `used_points` (the prior balance at the time) but the `'off'` path
wrote those rows too, so a row does not distinguish a real redemption from this defect.
**Whether any production balance has been affected depends on whether the module is enabled there,
which is D3 and is not answerable from this container** — `add_on_managers` has zero rows here and
no module loads at all.

---

## V2 — the earned-points expression divides by a defaulted zero, and mis-binds its multiplication

**File:** `AddRewardClubPointLis.php:37`

```php
$orderClubPoint = ($order['product_price'] * $setting['order_points'] ?? 0) / ($setting['order_point_price'] ?? 0);
```

The identical pair of defects this repository already fixed on the first-party side in
`App\Support\Money\RewardPointDiscount`:

- `($a * $b ?? 0)` parses as `($a * $b) ?? 0`, which can never be null, so the `?? 0` never
  defaults and an absent key reaches the multiplication.
- `/ ($setting['order_point_price'] ?? 0)` **defaults the divisor to zero**.

### Measured, inside the booted app

| settings | result |
|---|---|
| fully configured | `10.00` |
| `order_point_price` missing | **`DivisionByZeroError`** |
| `order_point_price` = `0` | **`DivisionByZeroError`** |
| `order_points` missing | **`ErrorException: Undefined array key "order_points"`** |
| whole settings row absent | **`DivisionByZeroError`** |

`RewardClubPointController`'s validator (`:26-33`) is `'required'` with no `numeric` and no `min:0`,
so `order_point_price = 0` is savable through the ordinary settings form.

### Containment — NOT POSSIBLE first-party. Risk recorded.

`:37` runs **before** anything a listener could normalise could matter: it is the first statement
inside the `club_point_enabled` block, it reads `$setting` from `getAdminAllSetting()` and not from
the event, and it throws before any later line executes. Nothing in the event payload influences
it. The only interception points would be `getAdminAllSetting()` — a global helper on the read path
of every setting in the product, far too wide a blast radius for this — or editing the vendored
file, which is forbidden.

**Unresolved risk:** with `RewardClubPoint` enabled and `order_point_price` unset or `0`, order
confirmation throws. Proposed containment, requiring an owner decision rather than taken here:
refuse to save `order_point_price = 0` (a first-party validation layer over the module's settings
POST), which converts a fatal during checkout into a refusal at configuration time. That is a
change to a merchant-facing validation rule and so is **not** covered by D7.

---

## V3 — the ledger's `reward_price` recomputes the uncapped discount, with the same two defects

**File:** `AddRewardClubPointLis.php:41`, stored at `:56` as `'reward_price' => $rewardPrice`

```php
$rewardPrice = ($customer['total_club_point'] * $setting['reward_point_price'] ?? 0) / ($setting['reward_points'] ?? 0);
```

### Measured, inside the booted app

| settings | result |
|---|---|
| fully configured | `50.00` |
| `reward_points` = `0` | **`DivisionByZeroError`** |
| `reward_point_price` missing | **`ErrorException: Undefined array key "reward_point_price"`** |
| whole settings row absent | **`DivisionByZeroError`** |

### Consequence for D5

This is the reason **D5 bounds `orders.final_price` and does not fix reward accounting.** The order
writer now records a capped discount via `RewardPointDiscount::applyTo()`; this line recomputes the
**uncapped** figure from the raw expression and stores it beside `used_points` = the whole prior
balance. On the over-discount path the order reads `0.00` while the ledger row reads the full
reward. Before D5 both were wrong and consistent; after it they are inconsistent by the capped
amount. That trade was taken deliberately and is recorded in `RewardPointDiscount`'s own docblock.

### Containment — NOT POSSIBLE first-party. Risk recorded.

Same reason as V2: `$setting` comes from `getAdminAllSetting()`, not from the event, and the
computed value is written straight into the module's own model. Reconciling order and ledger needs
either **D4's first-party obligation record** (owner-authorised, not yet built) or a vendored edit.

---

## Upstream report

These three are a single review finding against `workdo/RewardClubPoint`: **`??` is used in three
places as though it bound tighter than `*`, `==` and `&&`.** The fix upstream is parentheses plus a
guarded divisor, and the one at `:38` additionally needs `final_amount_point` defaulted, or
re-parenthesising it turns a dead check into a fatal.

Minimal upstream diff, for the report — **not applied here, and must not be applied here**:

```php
// :37
$orderPoints     = is_numeric($setting['order_points'] ?? null) ? (float) $setting['order_points'] : 0.0;
$orderPointPrice = is_numeric($setting['order_point_price'] ?? null) ? (float) $setting['order_point_price'] : 0.0;
$orderClubPoint  = $orderPointPrice > 0.0 ? ($order['product_price'] * $orderPoints) / $orderPointPrice : 0.0;

// :38
if ((($requests_data['club_point_is_active'] ?? '') == 'on')
    && (($requests_data['final_amount_point'] ?? null) == $order['final_price'])) {

// :41  — same shape as :37, over reward_point_price / reward_points
```

**Status:** this repository has no channel to WorkDo. Filing it is an owner action. Everything
needed for the report is above: file, line, parse, measured truth table, and the minimal diff.

---

## Summary

| | defect | contained? | by what |
|---|---|---|---|
| **V1** | `'off'` reads as "redeem"; balance replaced instead of accrued; anti-tamper check dead | **YES** | `App\Listeners\NormaliseRewardRedemptionFlag`, 18 tests, 8/8 mutations redden |
| **V2** | earned points: mis-bound `*`, divisor defaults to `0` → fatal at checkout | **NO** | risk recorded; containment proposed, needs an owner decision |
| **V3** | ledger `reward_price`: same two defects, and stores the UNCAPPED reward | **NO** | needs D4's obligation record or a vendored edit |

Severity of all three is **conditional on `RewardClubPoint` being enabled in production**, which is
**D3 and unresolved**. This container cannot answer it: `add_on_managers` has zero rows and no
WorkDo module loads here at all. So this file reports what the code does and whom it can reach, and
neither asserts nor denies that it is firing in production.
