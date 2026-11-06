# Package C — the abandoned carts and wishlists family

Six merchant surfaces: the two list pages, their product cells, and the two "Show Products"
modals. **Before this slice none of them had ever been seen with data** — the dev fixture that
puts carts and wishlists on the screen was added here, and it is what made every finding below
visible. A surface with no rows renders, returns 200 and passes a contrast sweep while telling
you nothing.

## What was wrong, and how each was found

| # | Defect | Found by |
|---|---|---|
| 1 | The cart page was titled **"Abandon Cart"** — an imperative, and broken English — in both the page title and the breadcrumb. Its Arabic, `تخلي عن عربة`, read as the instruction *"abandon a cart"*. | Reading the rendered page with data on it |
| 2 | The wishlist page was titled just **"Wishlist"**, so nothing distinguished it from a shopper's own list. | The same capture |
| 3 | The count sat in a `bg-light-success` pill. Green is the SUCCESS tone everywhere in this admin; the number counts products a customer **walked away from**. | Reading the rendered cell |
| 4 | The count carried **no noun**, under a column headed `Product` (singular), so `5` could have been products, units or carts. | The same |
| 5 | **The cart "Show Products" modal had never worked.** The button sent `MIN(carts.id)`; the handler keys on `customer_id`. The lookup missed, `redirect()->back()` fired, and jQuery injected the whole list page — sidebar included — into the modal. | **Clicking it.** Nothing else could see this: the route answers 200 |
| 6 | Two JS console errors (`Identifier 'slideUp' has already been declared`) — the layout's scripts re-running inside the modal, a symptom of #5. | The same click |
| 7 | **A product deleted while sitting in an abandoned cart 500'd the whole modal.** `$product->product_data->name` had no null check and `Product` has no SoftDeletes, so one catalogue tidy-up hid every other line in the cart too. | Asking what the relation returns for a removed row, then driving it |
| 8 | **Both "nudge the shopper" actions read ANY store's cart for a storeless caller** — and the SMS one reached the gateway. | The tenant-authz review of defect 5's fix |

### Defect 8 in full, because it is the serious one

`abandon_carts_emailsend` and `abandon_carts_messsend` keyed on a caller-supplied `cart_id` with
no store predicate anywhere, relying entirely on `Cart`'s global scope. `null_tenant => 'open'`
turns that scope off for a caller who resolves no tenant — and `getCurrentStore()` is NULL both
for a merchant who has created no store and for **any staff member with an unset
`current_store`**, because the fallback keys on `created_by = $user->id` and staff never created
the store they work in. Measured, for such a caller posting another tenant's `cart_id`:

```
Cart::find(<victim's cart id>)   -> FOUND       the scope is inert
POST /abandon-carts-emailsend    -> 500         died downstream on a null store
POST /abandon-carts-messagesend  -> 200, is_success false,
                                    "Invalid Auth access token - Cannot parse access token"
```

The last line is the one that matters. The SMS path did not die — it reached
`Utility::SendMsgs()` and the gateway refused it **only because no Twilio credentials exist in
this environment**. On a configured store that message goes out: to another tenant's customer,
naming another tenant's products. A read is bad; an outbound message to someone else's customer
cannot be recalled.

Both handlers now refuse when no store resolves and name `store_id` on the lookup and on the
follow-up `customer_id` query — the follow-up separately, because customer ids are reused across
tenants. `AbandonedCartSendStoreScopeTest` drives all four cases.

Defect 5 is the one worth remembering. The page renders, the route returns 200, the cell is
present and the permission is correct. It is only wrong **after a click**, which is exactly what
§6 of the directive means by not marking a screen premium because it rendered.

## Measured after the repair

Eight renderings per table below = 2 pages × {en LTR, ar RTL} × {light, dark}.

**List pages and the product cell** — `v2-page-*.png`, `cell-*.png`, `v2-320-*.png`

| Property | Light | Dark |
|---|---|---|
| figure contrast | 12.37 | 6.14 |
| button text contrast | 16.88 | 11.81 |
| button **border** contrast (SC 1.4.11) | 3.38 | 3.68 |
| button box | 115×32 (en) / 102×32 (ar) | same |
| `.ss-num` treatment | `tabular-nums`, `unicode-bidi: isolate` | same |
| horizontal overflow at 1440 / 320 | 0 / 0 | 0 / 0 |

The column heading is `Products` / `المنتجات`, and `store-syria-ds.js:1806` copies it onto the
cell as `data-ss-label`; the 320px captures confirm the `::before` content resolves to it, so the
figure is labelled in both layouts from one string rather than two.

**Modals** — `modal-*.png`: 5 product rows in all eight renderings, product-name contrast 15.43,
no overflow, modal body free of page layout, and **0 page errors across the whole run** (2 before).

### The white modal in dark mode is not a finding

`.modal-content` measures `rgb(255, 255, 255)` with `data-ss-theme="dark"` on `<html>`. That is
the **P2.5-A-01/A-02 decision**, recorded at `public/css/store-syria-ds.css:3611` and `:5786`: the
host keeps the modal a white sheet and the design system repaired the INK on it rather than
darkening the sheet. The 15.43 above is that ink working, not a light surface that escaped the
theme. Nothing in `cart/show.blade.php` or `wishlist/show.blade.php` uses `.ss-*` classes, so no
design-system token is resolving its dark value against this white surface — the defect class
described at `:344`. Any later change that puts a design-system component inside these modals
must carry `data-ss-theme="light"` on the container.

## Not fixed here — for the owner

**An abandoned cart shows its item count but not its value.** A merchant cannot tell a large
abandoned basket from a small one, which is the first thing they would want to sort by. The data
exists (`carts.qty`, `carts.price`), so this is a new aggregate rather than new data — but it is a
new **money figure on a merchant screen**, and §4-D/§8 of the directive reserve financial changes.
Recorded rather than built.

**The per-row quantity has since been added** to the cart modal (it was listed here as the next
step and is now done): `carts.qty` was already fetched and discarded, and a count is not money.
The wishlist modal deliberately does not match — `wishlists` has no `qty` column, so the two
differ because the data differs.

## Mutation results, stated because "a guard is present" is not "a guard is tested"

| Mutant | Outcome |
|---|---|
| `cart/product.blade.php` reverts to `$cart->id` | **RED** — the round trip catches it |
| the probe loses its `store_id` predicate | **RED** — the storeless test catches it |
| the line query loses its `store_id` predicate | green — the probe refuses first, so it is unreachable; documented in the test rather than claimed as covered |
| either send guard alone is removed | green — the two are independently sufficient |
| **both** send guards removed | **RED** |
| `cart/show.blade.php` loses its null guard | **RED** — `Attempt to read property "name" on null` |

## Two claims of mine that were wrong, and what corrected them

1. I wrote that `where('store_id', null)` "matches nothing". It does not — it compiles to
   `store_id IS NULL`, which this codebase already records at `ShippingMethodController:138`. The
   predicate refused only because no `carts` row happens to carry a NULL `store_id`, and that
   column is nullable with no default. The handlers now refuse a storeless caller **explicitly**,
   which does not depend on the table's contents. Caught by the tenant-authz review.
2. I wrote that a deleted product was *more* likely to strand a wishlist row than a cart line. The
   opposite: `wishlists.product_id` carries `onDelete('cascade')` and `carts` has none, so with
   foreign keys enforced the delete left both cart lines and removed the wishlist row. Caught by
   measuring it instead of reasoning about it.
