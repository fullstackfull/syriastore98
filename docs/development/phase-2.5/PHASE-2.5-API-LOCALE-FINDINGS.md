# F154 — the admin API answers in the application's language, not the merchant's

**Found 2026-11-10, while capturing the Flutter order-detail and payment-methods screens in
Arabic. Measured, not inferred.** Recorded rather than repaired: the fix changes what a shipped
API returns, which is the "public API compatibility" class the owner reserved.

---

## 1. What was measured

```
web  group has SetLocate: YES  (25 middleware)
api  group has SetLocate: NO   (2 middleware)
```

Read back from the booted kernel's `middlewareGroups`, not from the source. `bootstrap/app.php:36`
appends `SetLocate` to the **`web`** group only, and `setlocate` appears **zero** times in
`routes/api.php` as a route alias.

`SetLocate` is what resolves the viewer's language — from the session, a cookie, or
`auth()->user()->language` (`SetLocate.php:27-47`). Without it on a request, `App::getLocale()` is
the application default.

## 2. What that means for a merchant

The admin API renders display strings through `__()` on the server:

| field | written at | the merchant sees |
|---|---|---|
| `order_status_text` | `app/Models/Order.php:417-443` — `__('Pending')`, `__('Shipped')`, … | `Pending` on an otherwise Arabic screen |
| payment method `name` | `Setting::paymentList()` — `__('Cash On Delivery')`, `__('Bank Transfer')` | `Cash On Delivery`, `Bank Transfer` |

The Flutter app's base URL is `https://storesyria.com/api/admin`, so **every** merchant using the
mobile admin reads these fields in the application default language whatever they chose in the app.
The rest of the screen is Arabic, because the app translates its own labels client-side — which is
what makes the English fields look like isolated untranslated strings rather than a setting.

## 3. Two of the symptoms are already fixed, client-side, and deliberately so

`P2.5-FLUTTER-DETAIL2` fixed the order status without touching the API: the screen now translates
the **integer** `order_status` through `StatusBadgeHelper.getOrderLabel`, which is
locale-independent by construction. That was the right fix there for a second reason — the server's
English spelling of status 4 is the typo `Comfirmed` (`Order.php:440`), pinned for the API by
`tests/Feature/ApiContract/OrderStatusTextTest`, so it cannot be corrected on one surface alone.
Translating the integer sidesteps the typo entirely.

**That approach does not generalise.** It works where the payload carries a machine-readable
discriminator beside the human string. Payment methods do carry one (`key`: `cod`,
`bank_transfer`, `stripe`), so the same trick is available — but doing it a third time would mean
three private client-side mappers for one server-side cause, and the fourth surface would get a
fourth. That is the shape of a workaround, not a fix.

## 4. Recommended fix, when it is authorised

Add `SetLocate` to the `api` middleware group, or to the `Route::prefix('admin')` group in
`routes/api.php`.

It already works for API callers without new plumbing: `app/Http/Middleware/CustomAuth.php`
resolves the Sanctum token and logs a `User` into the **`web`** guard, so `auth()->user()->language`
— the branch `SetLocate.php:35` already reads — is populated on exactly these requests.

**Why it is an owner decision and not a bug fix:**

1. **It changes what a shipped API returns.** The response *shape* is unchanged; the *language* of
   several string fields is not. Any consumer comparing those strings to English literals would
   change behaviour.
2. **There is at least one such consumer in this repository**, and it is a money path:
   `plan_orders.payment_type` is compared against `'Bank Transfer'` and `'Manually'` in
   `plans/action.blade.php:2` and `plans/receipt.blade.php:7,9` — see **F153**, which is the same
   root cause seen from the web side.
3. **Lynomia Chat compatibility** is a standing constraint and its dependence on these fields has
   not been measured here.

**A narrower first step**, if the full change is not wanted: apply `SetLocate` only to the
read-only admin endpoints the Flutter app renders directly (`/vieworder`,
`/getActivePaymentMethods`, the dashboard payloads), leaving anything whose value is stored or
compared untouched. That fixes what a merchant reads without changing what any consumer branches on.

## 5. What is NOT recommended

Translating brand names to make them look localised. `P2.5-SET-03` removed 32 of those because
`Stripe` was rendering as `الشريط` ("the ribbon") and a merchant could not find the gateway.
`Cash On Delivery` and `Bank Transfer` are generic payment *methods* and should translate;
`Stripe` and `Paypal` are brands and should not. Any fix here must keep that distinction —
`App\Payments\PaymentTypeLabel::BRANDS` is the existing record of which names are brands.
