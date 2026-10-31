# F234 — corrections after verifying the reader's findings MYSELF

I checked the three "new findings" I had recorded from the reader rather than
propagating them. **One was wrong.**

## 1. WRONG AS I RECORDED IT — PayFast "burns the coupon with NO limit check at all"

**FALSE.** `PayFastController::index` does check and deactivate; the block sits
immediately after the usage write:

```php
$userCoupon->save();
$usedCoupun = $coupons->used_coupon();
if ($coupons->limit <= $usedCoupun) {
    $coupons->is_active = 0;
    $coupons->save();
}
```

Do not carry that claim forward.

**Still true and confirmed for PayFast:**

- `$plan_amount = $request->coupon_amount;` takes the plan amount from **request
  input**. That is an amount-substitution shape and belongs with the decision-free
  rule F230 and D-31 both rest on: never read the payable amount from the caller.
- it gates on `$request->coupon_code != null` while querying with `$request->coupon`
  — two different request keys, so supplying one and not the other behaves
  inconsistently.

## 2. SHARPENED — the five "initiators with no deactivation"

Each of the five files contains exactly one `is_active = 0`, so a **file-level** grep
says "has deactivation" and would have closed this wrongly. It is in a **different
method**. Measured:

| provider | initiator | writes usage | deactivates? | return/callback | writes | deactivates |
|---|---|---|---|---|---|---|
| Aamarpay | `paywithaamarpay:20` | `:63` | **no** | `aamarpaysuccess:185` | `:231` | `:238` |
| Benefit | `initiatePayment:22` | `:59` | **no** | `call_back:143` | `:224` | `:231` |
| Cashfree | `cashfreePayment:59` | `:96` | **no** | `cashfreePaymentSuccess:185` | `:279` | `:286` |
| Paytr | `PlanpayWithPaytr:19` | `:58` | **no** | `paytrsuccessCallback:205` | `:258` | `:265` |
| Yookassa | `paywithyookassa:65` | `:109` | **no** | `planGetYooKassaStatus:222` | `:278` | `:285` |

So the limit **is** enforced on the paid path (the callback). What is unenforced is the
initiator's own write, which the reader placed in the free-plan branch — i.e. a free
activation records a coupon use and never tests the limit, so a limited coupon can be
over-consumed through free activations.

**This is the same lesson as F227's guard claim**: a file-scoped question gives the
wrong answer, and the question has to be asked of the enclosing method.

## 3. UNVERIFIED BY ME, still the reader's reading

`BankTransferController:77` dereferences `$coupons->discount` before the
`!empty($coupons)` test at `:79`. Not independently checked yet — treat as a lead.

## Consequence for the F234 slice

None of this changes the slice's shape: CoinGate is the defect, Mollie is the
measured sibling (its `setApiKey('')` throw is executed, not assumed), Midtrans is the
partial case. The five-provider item is a **separate, smaller finding** about free-plan
coupon accounting and should get its own row rather than being folded into F234.
