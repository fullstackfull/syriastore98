# Phase 04 — Commerce: execution plan

**Branch:** `feature/phase-04-commerce`, based on the Phase 03 engineering head `f4315390`.
**Depends on:** PR #138 (Phase 03), which is Draft and unmerged. This branch carries Phase 03's
commits and **must not be merged ahead of it**.
**Status of this document:** P4-0 deliverable. Written from measurement, not from reading alone.

---

## 1. The headline, before the detail

**Commerce on this platform already works.** A guest can buy something today. That is measured, not
assumed: on 2026-11-15, against the `p2dev` fixture, a browser session added a product to a cart and
completed checkout, producing **order `#020261115232155`**, COD, `Pending`, `final_price` 65,
`is_guest=1`, and a redirect to `/order-complete`. The `orders` table went from 9 rows to 10.

So Phase 04 is **not** "build commerce". It is: close the edges, prove the negatives, and finish the
flows that stop short. The plan below is shaped by that.

### What the baseline measures

| | |
|---|---|
| Commerce + Payments + Contract + ApiContract suites | **969 passed, 0 failed** (3,787 assertions, 146 s) |
| `tests/Feature/Commerce/` | **59 test files** |
| Commerce-shaped registered routes | **488 of 1,476** |
| Real-MySQL oversell concurrency harness | **14 checks, 0 failures** (MariaDB 10.11.14, this container) |
| Existing `verify-*-on-mysql.php` harnesses touching commerce | **21** |

A phase that began by writing a cart would be duplicating all of that. The WorkDo engine stays.

---

## 2. What was measured, end to end

Everything in this section was executed against a running server, not inferred.

### 2.1 The purchase journey completes

`product-list` → product page → `POST /product_cart` (`{"status":1,"message":"successfull"}`) →
`/cart` showing `Hand-loomed Table Runner $65.00`, subtotal `$65.00` → `/checkout` → address and
billing → COD → `POST /process-order` (302) → `/order-complete` with the order reference.

**The form posts to `process-order`, not to the `place-order` route** declared at
`routes/customer.php:161`. Both exist; the storefront uses the former. Any harness that drives
`place-order` is not driving what the storefront drives.

### 2.2 Cross-customer order access is refused — a candidate finding, refuted by testing

CLAUDE.md warns that `APP_KEY` is in git history, so `Crypt::encrypt()` is an encoding rather than a
control and an encrypted id in a URL is forgeable. The order-complete page hands the customer exactly
such a link (`/{storeSlug}/order/{encrypted-id}`).

**So it was forged.** A token for order 9 — a different customer's order — was minted locally with
the real `APP_KEY` and fetched in a fresh anonymous session: **HTTP 403**. Ownership is enforced
beyond the encryption. Recorded as REFUTED rather than carried into the plan as a gap.

### 2.3 Concurrent-checkout overselling is already proven safe

`tests/Support/Security/bin/verify-stock-oversell-concurrency-on-mysql.php` runs here and passes
**14/14** on real MariaDB, including the control that the unguarded read-modify-write it replaced
*does* oversell, and that a concurrent restock loses a unit without the atomic increment.

**Journey C in the directive is therefore already covered at the engine level.** P4-7 extends it to
the paths that harness does not drive, rather than rebuilding it.

---

## 3. Confirmed defect: storefront commerce messages ignore the storefront locale

**This is the first real Phase 04 finding and it is cross-tenant.**

### What happens

On an English storefront (`<html lang="en" dir="ltr">`, English chrome, English product and cart
labels), every commerce AJAX message comes back **in Arabic**:

| call | response |
|---|---|
| `POST /product_cart` (no variant) | `من فضلك اختر نوعاً من المنتج` |
| `POST /product_cart` (success) | `تم إضافة المنتج بنجاح` |
| `POST /wish-list-count` | `قائمة أمنيات العد` |
| `POST /states-list` | `حدد الدولة` |

The rendered checkout page shows it too: `State حدد ا…` beside English labels.

### Why

`app/Http/Middleware/ThemeLanguage.php:23-37`:

```php
$currantLang = Cookie::get('LANGUAGE');          // null for a fresh visitor
if ($currantLang == null) {
    $user = \Auth::user();                        // null for an anonymous shopper
    if ($user) { $currantLang = $user->language; }
    if ($currantLang == null) {
        $user = Cache::remember('admin_details', 3600, function () {
            return User::where('type','admin')->first();   // <-- the defect
        });
        $currantLang = $user->language ?? 'en';
    }
}
App::setLocale($currantLang);
```

The final fallback is **the first `type = 'admin'` user in the entire shared database**, cached
globally as `admin_details`. In a multi-tenant system that is *some other merchant*. The store being
visited is never consulted — and `stores.default_language` exists and says `en` for both fixture
stores.

Measured: fixture user 2 is `type=admin, language=ar`, so **every storefront of every tenant** serves
Arabic commerce messages to anonymous visitors, regardless of that store's own language.

### Why the page still looked English

The theme layout computes its own `$currantLang` for `lang`/`dir`, so the chrome and `__()` disagree.
That divergence is what makes this invisible: the page looks correct and only the messages are wrong.

### Not previously recorded

`admin_details` appears in the docs only as a cache-invalidation hazard. Nothing records the locale
fallback, and no test pins it. **Owned by P4-1.**

---

## 4. Gaps in the measurement environment, recorded as fixture work

These blocked parts of the journey and are **fixture gaps, not product defects**. The directive
requires the distinction and it is made here explicitly.

| | measured | consequence |
|---|---|---|
| No shipping method configured | `POST /shipping-method` → `shipping_method: ""`, `shipping_final_price: "$0.00"` | shipping selection, zones and costs cannot be exercised |
| No tax configured | `POST /get-tax-data` → `tax_price: "0.00"`, `tax_id_value: null` | tax derivation cannot be exercised end to end |
| `"CURRENCY": null` in both payloads | measured | may be a real defect or a fixture gap — **P4-4 must determine which** |
| Only **1 of 32** store-1 products is `variant_product=1`, and its page renders a `variant_form` with **no variant controls** | measured | variant selection cannot be exercised; whether the missing control is the theme or the data is **P4-1's** first question |

Two harness errors are recorded for the same reason — each looked like a product defect:

- **Clicking the last visible submit button submits the footer newsletter** (`302 /newsletter`), not
  the order. The real control is `button#payfast_form` inside `form#formdata`.
- **Filling the billing address before the country/state/city selects loses it**, because the address
  block is injected afterwards by `POST /checkout/address/form`. The first attempt failed validation
  with "Please enter your billing address" and looked like a broken checkout.

---

## 5. The findings register, and what it changed about this plan

P4-0 closed with a second, much larger measurement than §2: a parallel inventory of **12 commerce
domains**, every claimed defect then handed to an **independent reviewer told to refute it**. 129
agents, 5,734 tool calls, 22.2M tokens.

| | count |
|---|---|
| claims inventoried | 117 |
| **confirmed by independent review** | **94** |
| — high | 18 |
| — medium | 42 |
| — low / informational | 34 |
| **refuted** | **17** |
| already tracked elsewhere | 6 |

The register is `PHASE-04-FINDINGS-REGISTER.md`, with per-domain detail in `findings/<domain>.md`.
Each confirmed row carries the reviewer's **corrected statement** of the defect — which supersedes
the original claim, and in several rows *widened* it — and the failing test the reviewer asks for.

**The 17 refutations are the most valuable part.** They are why this is a queue of 94 and not 117,
and they are recorded by name so the next reader does not spend the effort again. Three examples of
what adversarial review killed: a claim that `carts.price` is a disagreeing money column (it is
written by five paths and **read by none**); a claim that `get_tax_data()` leaks another tenant's
`sale_price` through an unscoped `Product::find()`; and a claim that `canManageRefunds()`'s
byte-exact operator comparison is a defect (it is a pinned owner decision, and the proposed fix
would have broken it).

### What the register does not do

It does not close anything. **A register row is a claim about the code, not a measurement of
runtime, and not work done.** Every row stays open until a test fails against the defect and passes
against the fix. Where a row and a measurement disagree, the measurement wins: §2.2 records a
candidate finding that testing **refuted**, and P4-F096's "every guest submission from the shipped
form" has to be reconciled against the guest order this phase actually placed in §2.1.

### The three families worth naming

Most of the 94 are individually small. Three have a shared root cause, so they are one fix each
rather than nine:

1. **`Cache::remember('admin_details')` → `User::where('type','admin')->first()`** — "the first
   merchant in the shared database, chosen by having the lowest id" — decides money, not just
   presentation. P4-F014, P4-F023 and P4-F024 are the shipping-charge arm. §3's locale defect, fixed
   in this branch, was the same line in a different file; that is why it is a family and not a
   coincidence.
2. **A flat coupon applied once per cart LINE** rather than once per basket. P4-F001 and P4-F063.
   `getCouponTotalAmount()` floors each line at zero, so the multiplication is Σ min(line, D) — and
   a basket of five 40.00 lines against a flat 50.00 coupon floors **every** line to zero. The
   basket can be driven free, and that figure reaches `orders.final_price` for COD.
3. **Cancel and return doors that write a status without crediting stock back.** P4-F034, P4-F056,
   P4-F104 and P4-F105. Eight merchant-side doors, and the Flutter and webhook arms stamp
   `return_status = 2` — the exact value the web uses as its restock idempotency marker — which
   *disarms* the one door that does restock.

---

## 6. The packages

Ordered by measured dependency and money impact, not by the template. Each states what "done" means
as something that can fail. Finding ids are from the register.

### P4-1 — Storefront commerce correctness *(in progress)*
§3's locale defect **(done, `073de166`)**; the variant-control question in §4; cart edge behaviour
(quantity bounds, removal, guest→customer persistence); `P4-F002` `P4-F004`. Acceptance: a test that
fails against the Arabic-on-English message and passes after the fix (done); a storefront harness
driving cart and checkout in **both** languages, asserting the message language matches the page.

### P4-2 — Pricing arithmetic *(family 2, highest money impact)* — **DONE**
`P4-F001` `P4-F063` `P4-F062` — the per-line flat coupon, and the cart's coupon arithmetic having no
`minimum_spend` / `maximum_spend` gate although `apply_coupon` does. Acceptance: a **multi-line**
basket fixture — the one thing every existing coupon test lacks — in which a flat coupon reduces the
basket by its own amount exactly once, and a basket under the spend threshold is not discounted at
all, asserted on `orders.final_price` and not on the AJAX figure alone.

### P4-3 — Order-write integrity *(family 1)* — **in progress: the plan family and the ordersave
### arm are done; the transaction boundary, the unscoped `customer_id` write and double-submit are not**
`P4-F014` `P4-F023` `P4-F024` `P4-F025` `P4-F026` `P4-F018`: the foreign-plan shipping arm, the
missing transaction around the Order row and its four child tables (reachable as coupon-limit
evasion, not merely an audit gap), the unscoped `orders.customer_id` write on the web form, and
double-submit. Acceptance: a store's shipping charge is decided by **that store's** plan, proved by
a second tenant whose plan disagrees; a forced child-table failure leaves no order row; a double
submit creates one order.

### P4-4 — Stock integrity across every door *(family 3)*
`P4-F034` `P4-F056` `P4-F104` `P4-F105`, and the `OrderReplacement` path that deliberately does
**not** restock and must be preserved. Acceptance: for each cancel and return door, a test asserting
the stock delta, including the idempotency marker — one restock, never two, and never zero.

### P4-5 — Payment and callback integrity census
`P4-F044` `P4-F046` plus the ~30 gateway controllers as a table: which verify server-side, which
check amount, which have replay guards, which are covered by existing `Gateway*` tests. `P4-F046`'s
hardcoded public `webhook.site` callback is a **data-egress** item, fixed in code; the capture
verification it also lacks is a separate fix. Acceptance: every gateway classified; none marked
verified without evidence; gateways needing external credentials marked **externally blocked**.

### P4-6 — Refunds, returns and cancellations
`P4-F082` (a second distinct line re-subtracts the first, driving `final_price` negative) and the
refunds-returns rows. Acceptance: negative tests for authorization, ownership, transitions, amount
limits and duplicates. **No real financial transaction is initiated.**

### P4-7 — Authorization on the commerce API
`P4-F037` `P4-F107` and the permission rows. Acceptance: operator-first checks that fail against the
defect. `P4-F107` gating may refuse a live merchant, so it is an **owner decision** of the same shape
as S2, not a free tightening.

### P4-8 — Inventory, totals, shipping and tax
`P4-F070` and the fixture work in §4: seed shipping methods and tax, resolve `"CURRENCY": null`.
Acceptance: totals reconcile against `OrderTotalReconciler` with shipping and tax present.

### P4-9 — End-to-end journeys A–F, integrated verification, closure
Built on the measured journey in §2.1; every journey asserts on state, never on HTTP 200 alone.
Then full gates, CI on the Draft PR, and `PHASE-04-FINAL-REPORT.md`.

---

## 7. What is closed so far, and by what evidence

Every fix in this list was **falsified** — removed again, with the test re-run — because a green test
that cannot fail is the failure mode this codebase has paid for repeatedly.

| package | fix | falsification |
|---|---|---|
| P4-1 | a storefront's language is its own, not the first merchant's (`ThemeLanguage`) | restoring the admin fallback reddens 4 of 5 |
| P4-1 | **P4-F119** editing a variant product emptied its storefront selectors, so it stopped selling | reverting the partial reddens 5 of 6; the one that stays green is the create-shape guard |
| P4-2 | **P4-F001 / P4-F063** a flat coupon was one discount PER LINE; a 120.00 basket wrote a COD order of 0.00 | reverting both readers reddens 7 of 10 |
| P4-2 | **P4-F062** the cart readers had no `minimum_spend` / `maximum_spend` gate although `apply_coupon` does | in the same 7 |
| P4-2 | the first version of that fix allocated first-come, so row order moved the tax base by 20.00 — found by **review of the fix** | restoring first-come reddens the order-independence test with both measured figures |
| P4-2 | a spend-refused coupon was still echoed as applied in `coupon_info` | the refusal test plus a positive control that an accepted coupon is still echoed |
| P4-3 | **P4-F014 / P4-F024** twelve order writers priced shipping from `User::where('type','admin')->first()` — the first merchant in the shared database | reverting the plan read reddens the shipping-on test: a real 25.00 delivery charge is dropped |
| P4-3 | **P4-F023** `ordersave`'s shipping-on arm omitted the product subtotal entirely | with both defects restored the order total is 0.00 on a 100.00 basket |

**Not closed, and not claimed:** every other register row. In particular P4-F118 and P4-F120 are
recorded as owner decisions rather than fixed, because each changes what a merchant's existing
coupons do or what an existing money column means.

---

## 8. Standing constraints for this phase

- **No second commerce engine.** The WorkDo modules and the existing services are the source of truth.
- **No duplicate source of truth** for orders, payments, products, customers or inventory.
- **HTTP 200 is not evidence.** `ApiResponser::error()` and `Utility::error()` both return 200 with
  `status: 0`. Every assertion must read the body or the database.
- **Separate product defects from fixture and harness failures**, in writing, every time. §4 is the
  template.
- **Do not invent tax rates, shipping policies or refund eligibility.** Where a policy is missing,
  record it and implement the existing safe behaviour.
- **No real financial transactions. No production anything.**
- The Feature known-outcome manifest holds **2 declared failures and 1 declared skip**. It is not to
  be edited to make a gate pass.
