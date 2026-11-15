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

## 5. The packages

Ordered by measured dependency, not by the template. Each states what "done" means as something that
can fail.

### P4-1 — Storefront commerce correctness *(starts immediately)*
The locale defect in §3; the variant-control question in §4; cart edge behaviour (quantity bounds,
removal, persistence across guest→customer transition). Acceptance: a test that fails today against
the Arabic-on-English message and passes after the fix; a storefront harness that drives cart and
checkout in **both** languages and asserts the message language matches the page.

### P4-2 — Checkout and order-write integrity
Server-side totals, price snapshots, duplicate-order prevention, transaction boundaries, and the
`process-order` vs `place-order` divergence. Acceptance: a forged price or quantity in the POST
cannot change what is stored; a double submit creates one order.

### P4-3 — Payment and callback integrity census
The ~30 gateway controllers, as a table: which verify server-side, which check amount, which have
replay/idempotency guards, which are covered by the existing `Gateway*` tests. Acceptance: the census
is complete and every gateway is classified; no gateway is marked verified without evidence. Gateways
needing external credentials are marked **externally blocked**, not verified.

### P4-4 — Inventory, totals, shipping and tax
Seed shipping methods and tax into the fixture, then exercise them. Resolve `"CURRENCY": null`.
Acceptance: totals reconcile against `OrderTotalReconciler` with shipping and tax present.

### P4-5 — Refunds, returns and cancellations
Authorization, ownership, valid transitions, amount limits, duplicate protection, inventory effect.
Acceptance: negative tests for each. **No real financial transaction is initiated**; any path needing
a live gateway is documented as externally blocked.

### P4-6 — Merchant web and Flutter contract
The commerce contract between `routes/api.php` (`api/admin/*`) and `/home/user/StoreSyria_app98`.
Acceptance: each commerce endpoint the app calls is named with its Dart consumer, and contract
regression tests cover them. **No new 401 on a path the app calls** — the shipped app force-logs-out
on 401.

### P4-7 — Security, tenant isolation and concurrency
The negative matrix from the directive §11, driven at HTTP level, plus MySQL concurrency beyond the
existing oversell harness. Acceptance: every claim has a test that fails against the corresponding
defect.

### P4-8 — End-to-end journeys A–F
Built on the measured journey in §2.1. Acceptance: each journey asserts on state (rows written,
amounts, ownership), never on HTTP 200 alone — §2 shows why: a refusal here returns 200 with
`status: 0`.

### P4-9 — Integrated verification and closure
Full gates, CI on the Draft PR, final report.

---

## 6. Standing constraints for this phase

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
