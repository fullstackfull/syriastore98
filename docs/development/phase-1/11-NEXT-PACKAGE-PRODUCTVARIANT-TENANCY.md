# P01-COMMERCE-SEC-03 — `ProductVariant` ownership and checkout tenant isolation

**Status: prepared, not started.** Scoped deliberately small, and written so it can be rejected
without blocking anything else. It is the follow-up P01-COMMERCE-SEC-02's adversarial review
identified; §7 item 1 of [`S33`](../../security/P01-SECURITY-WORK-QUEUE.md) is the one-paragraph
version.

---

## 1. A correction to this project's own record, first

P01-COMMERCE-SEC-02's report said *"NMI is now the only one of the nine handlers with a
product-level store predicate"*. **That is wrong**, and the inventory below is what corrected it.
Three `ApiController` writers have had one all along:

| handler | product lookup | store-scoped? |
|---|---|---|
| `ApiController::place_order` | `:3251` | **yes** |
| `ApiController::ordersave` | `:8032` | **yes** |
| `ApiController::variant_list` | `:8527` | **yes** |
| `NMIPayController::getNMIProductStatus` | `:574`, plus the pre-charge `basketHasForeignProduct()` | **yes** |

So the product-level gap is in the **web storefront** handlers, not platform-wide. Getting this
wrong in a report is cheap; getting it wrong in a fix means scoping something twice and missing the
thing that was actually open.

## 2. The real gap: `ProductVariant` is scoped by nothing, anywhere

`app/Models/ProductVariant.php` registers **no** `StoreScope` and the model is **not** in
`config/tenancy.php`'s `scoped_models`. Every stock loop in the platform resolves its variant row
as

```php
ProductVariant::where('id', $variant_id)->where('product_id', $product_id)->first()
```

— a predicate built **entirely from the caller's own two values**. It consults neither the store nor
the `$Product` the handler just looked up, so **scoping the product does not scope the variant**.
That is why the three `ApiController` writers above are still exposed on their variant branch, and
why P01-COMMERCE-SEC-02's first attempt at NMI closed one of two branches.

`product_variants.store_id` **exists** — `unsignedBigInteger`, nullable, indexed
(`2024_01_08_*_create_product_variants_table.php:30`) — so a predicate is available, and the
nullable-tolerant rule the project already uses applies: refuse a **provable** mismatch, accept
`NULL`.

### Every site, by file

**Variant lookups with no store predicate — 12 sites, all of them:**

| file | line | enclosing method | reaches a stock write? |
|---|---|---|---|
| `Api/ApiController.php` | `:3256` | `place_order` | yes |
| `Api/ApiController.php` | `:6527` | `confirm_order` | yes |
| `Api/ApiController.php` | `:8036` | `ordersave` | yes |
| `PaymentController.php` | `:2127` | `getProductStatus` | yes |
| `PaymentController.php` | `:2869` | `getWhatsappUrl` | no — availability check only |
| `PaymentController.php` | `:3408` | `whatsapp` | yes |
| `NMIPayController.php` | `:607` | `getNMIProductStatus` | yes — **mitigated** by the pre-charge guard |
| `KhaltiPaymnetController.php` | `:431` | `getProductStatus` | yes |
| `EasebuzzController.php` | `:471` | `getProductStatus` | yes |
| `PaiementProPaymentController.php` | `:472` | `getProductStatus` | yes |
| `CinetPayController.php` | `:577` | `getProductStatus` | yes |
| `AuthorizeNetPaymnetController.php` | `:534` | `getOrderPaymentStatus` | unreachable here — dies before the loop |
| `PayUPaymentController.php` | `:407` | `getProductStatus` | **dead code** — no route declares it |

**Product lookups with no store predicate — 9 sites:**

`AuthorizeNetPaymnetController:529/:530`, `PayUPaymentController:402/:403` (dead),
`PaymentController:2123/:2124`, `:2867`, `:3404/:3405`, `KhaltiPaymnetController:426/:427`,
`EasebuzzController:466/:467`, `PaiementProPaymentController:467/:468`,
`CinetPayController:573/:574`, `ApiController:6534` (`confirm_order`).

Note the recurring **`$datas = Product::find($product_id)`** beside each one: a *second, unscoped*
instance of the same row, which is what let P01-COMMERCE-SEC-02's variant branch dereference a null
after the `$Product` lookup started returning one. Any fix must handle both names.

## 3. The shared helper already exists, and that is the point

`App\Http\Controllers\Concerns\GuardsGatewayReturn::basketHasForeignProduct()` was written for NMI
and is **already shared** — seven handlers use that trait today. It takes the basket and the store
and refuses a provable mismatch **before** any money moves, which is the placement that matters: on
a card charge, refusing inside the loop means a captured payment with no order.

So the work is wiring, not invention — the same argument that made P01-COMMERCE-SEC-01 tractable:

1. **Extend the helper to the variant**, as one change in one place: for each line, require the
   variant (when one is named) to belong to a product this store owns. One predicate, not twelve.
2. **Call it at each handler's pre-flight**, before the stock loop and before any capture. Five
   gateway returns, `getProductStatus`, `whatsapp`, and the three `ApiController` writers.
3. **Leave the in-loop lookups alone.** They become defence in depth, which is exactly what
   P01-COMMERCE-SEC-02 recorded its own in-loop predicate as once the pre-flight subsumed it —
   kept, labelled unproven, and not claimed.

## 4. Reachability, stated honestly

**THIS PARAGRAPH'S FIRST VERSION WAS WRONG, and the correction raises the severity rather than
lowering it.** It said: *"through the host app a cart row cannot name a foreign product"*, citing
`CartController::product_cartlist:264` and `ApiController::addtocart:960`. The project's own
`tenant-authz-reviewer` refuted it, and the refutation is traced below. That sentence is exactly the
one that would have made someone deprioritise this package, so it is corrected in place.

`product_cartlist:264` **does** hold. `addtocart` **does not**, on its default path:

```
POST /api/{slugA}/addtocart     (custom.auth, a real store-A customer's own token)
  product_id = <store B's variant product>, attribute_id = <store B's variant>, variant_id = same

  :960   $product = Product::where('id', …)->where('store_id', $store->id)->first()   -> NULL, scoped
  :1450  the top-level else (neither WholesaleProduct nor SizeGuideline active)
  :1451  if (! empty($request->attribute_id) || …)        <-- never consults $product
  :1452  ProductVariant::where('id', $request->attribute_id)
             ->where('product_id', $request->product_id)   <-- the caller's own two values
  :1518  $variant_id = isset($product_stock_datas->id) ? … : $request->variant_id
             ($product_stock_datas is never assigned on this branch -> the POSTED id)
  :1557  $cart->product_id = $request->product_id          <-- foreign
  :1558  $cart->variant_id = $variant_id                   <-- foreign
  :1569  $cart->save()                                     <-- ROW PERSISTS
```

The null dereference that follows raises **after** the insert, and there is no transaction around
it, so the row survives behind a 500. A later `place-order` or `order-save` on a store with
`stock_management = 'on'` then resolves that foreign variant at `:3256` / `:8036` and decrements
**merchant B's** stock. `cart_list` cannot stop it — the row genuinely is store A's — and `Cart`'s
global scope adds nothing on API routes, which carry no `storeSlug` and therefore resolve no tenant
(`null_tenant => 'open'`).

So the honest severity is: **a live cross-tenant stock write, reachable by an ordinary customer of
one store with their own token, through two host-app requests.** Not module-gated, not latent.

The vendored writers remain a second path — `packages/workdo/BoostSales/.../BoostSalesController.php:181-186`
writes `product_id` straight from the request with no product or store check, and
`FrequentlyProduct` is the same shape. Both are already named in S30/S32 for `qty`; this records
that they are the same hole for `product_id` and `variant_id`. No module loads in any test run, so
testing that path needs the production loader plus a premise assertion (`CLAUDE.md` §1) — but the
`addtocart` path above needs none of that and should be the package's first unit.

**A note on the fix shape, from the same review.** Prefer requiring the already-store-scoped
`$Product` to be non-null before entering the variant branch, over adding
`->where('store_id', …)` to the variant query: `product_variants.store_id` is **nullable**
(`create_product_variants_table.php:30`), so a hard predicate would drop legitimate legacy rows.
The same caution applies to the `Product` lookups themselves — `ApiController`'s are HARD
`store_id` matches, unlike the nullable-tolerant rule `basketHasForeignProduct()` and `0ad3f7e0`
use, and that inconsistency is its own small decision for this package to make explicitly.

## 4b. Two further findings from the same review, for this package to absorb

1. **`ApiController::place_order` has no ownership refusal for an unmanaged store.** Its product
   lookup is store-scoped (`:3251`) but the refusal that acts on a null result lives inside the
   `stock_management` arm (`:3380-3382`), and that handler's expression was always correct — so a
   store with the setting off has **never** had that refusal. The order is written recording a
   foreign product, priced from the other merchant's catalogue, with no stock movement: a
   cross-tenant **reference**, not a write. S34 hoisted the equivalent refusal in `ordersave`
   because the `stock_management` fix had removed it there; `place_order` was deliberately left,
   because hoisting it would be a NEW refusal over a HARD `store_id` predicate and would start
   rejecting legitimate legacy NULL-store products. **That is this package's decision to make.**
2. **`ApiController::cart_qty` (`:1648`, route `api/{slug}/cart-qty`) is a second `if`/`else`
   stock-management site**, with the same strict-versus-loose arms as `Cart::cart_qty_cookie`. S34
   corrected its expression and no test drives it; neither arm does ownership work, so it is a
   coverage gap rather than a hole. Worth one test here.
3. **`Cart::cart_qty_cookie` dereferences `$cart_array->store_id` at `Cart.php:717` BEFORE the
   `empty($cart_array)` check at `:718`**, so that guard is dead and a non-matching cart cookie
   500s. Pre-existing, unrelated to tenancy, cheap to fix while in the file.

## 5. Scope boundary — what this package must NOT become

- **Not** adding `ProductVariant` (or `Product`) to `config/tenancy.php`'s `scoped_models`. That is
  a platform-wide change to every read of those models, with `null_tenant => 'open'` underneath it,
  and it belongs to its own package with its own owner decision. What shipped before, and what
  should ship here, is **containment at the caller**.
- **Not** restructuring the five gateway controllers' stock loops. S32 item 8 (no atomic decrement,
  no give-back) is still open for them and is a different package; the Concern they would use now
  exists.
- **Not** touching `PayUPaymentController`. Its handler is unrouted dead code; guarding it would be
  unverifiable by construction. If it is ever routed it must be guarded first.

## 6. Tests the package owes

- One data-provider test per reachable handler, driving a **variant line** whose variant belongs to
  another store, asserting: nothing moves on either store, no order is written, and the refusal is
  the guard's own message. `UnguardedOrderWriterTrustBoundaryTest`'s
  `test_a_foreign_product_in_the_state_is_refused_before_the_charge` is the shape, including its
  `variant_id` data set — written precisely because its absence hid this gap.
- A per-handler **ON control**, because four of the nine handlers do not reach their stock loop in
  this environment and an "it did not move" assertion is vacuous there. The
  `stock_management` package learned this the hard way: Easebuzz silently moved no stock at all
  until its own session key was seeded.
- A premise test that the module which can write a foreign cart row **is actually loaded**, or the
  reachability half is untested and must say so.

## 7. Why it is worth doing at all

Every guard P01-COMMERCE-SEC-01 and -02 added protects the *amount*, the *owner* and the *store of
the order*. This is the one identifier left that is the caller's and reaches another tenant's
**inventory** — the same family as S19's cross-tenant stock drain, which a `customer_id` sweep
could not see because it was looking at the wrong identifier. The variant is now the wrong
identifier nobody has swept.
