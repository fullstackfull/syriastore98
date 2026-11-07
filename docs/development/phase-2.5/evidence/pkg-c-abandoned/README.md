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

### Defect 9 — the wishlist twins were F-SEC-09 again, and worse

Adding an `error` leg to the four recovery actions (they had none, so every failure was silent)
and then driving a missing row put this on the merchant's screen, in a toast:

> **Error** — `Attempt to read property "customer_id" on null`

A raw PHP error, verbatim, in the interface. The defect was always there; the silence hid it. That
is the argument for the error leg — and a warning, since surfacing errors surfaces the ones you
did not know about.

The wishlist send handlers are **worse than the cart twins**: `Wishlist` is absent from
`scoped_models` *entirely*, so unlike `Cart` there is no global scope behind them for anybody. An
ordinary merchant with their own store, holding a permission `Utility::addNewData()` grants on
every login, could name any wishlist id on the platform. This is F-SEC-09 — closed on
`WishlistController::show` earlier in this phase — standing unrepaired in two more methods of the
same file, which is exactly how F-SEC-09 survived F-SEC-08.

`abandonWishlistMsgSend` carried four defects in five lines: `Wishlist::find()`,
`$cart->customer_id` and `$cart->UserData` all ran **before** `isAbleTo()` (so an unpermitted
caller drove the lookup, and a missing row 500'd ahead of the refusal — the cart twin had this
corrected under P1-SEC and this one was left); no null guard; no store predicate; and it gates on
`Abandon Cart` rather than `Abandon Wishlist`. The first three are fixed. The fourth is **not**:
correcting the permission name changes who may do what, and §8 reserves that to the owner, so it
is recorded and the test asserts the permission actually checked rather than the one that ought to
be.

`abandon_wish_emailsend`'s refusal also answered **`redirect()->back()` — a 302 — to an AJAX
POST**, so jQuery followed it and got the list page's HTML; the refusal never arrived as a
refusal. It is now a 403 JSON, matching the cart twin.

### The error leg relays only a 4xx body

Its first cut relayed any `message`, which is how the raw PHP error reached the screen — Laravel's
debug JSON uses the same key. A 4xx is the class the controller authored for a person to read; a
5xx now gets a generic message. The underlying 500 is fixed, but the next one should not be a
disclosure.

Measured, driving a real 404 through the page's own button in all four combinations: the toast
appears (it did not before), body contrast 6.17–6.42, title 14.23–16.88, 0 page errors, and the
body reads `عربة التسوق غير موجودة` / `البيانات غير موجودة` on the Arabic page — `Cart not found.`
had no Arabic entry at all and rendered in English until this slice added one.

**The toast TITLE is still English** on the Arabic page, and that is not this slice's doing:
`show_toastr(title, message, type)` discards `title` and calls `notifier.show('Error', ...)` with a
hardcoded literal (`public/js/custom.js:342`), so every toast title in the admin is English. It
reaches every merchant screen, so it is recorded for its own slice rather than changed here.

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

### Defect 10 — any registered shopper could delete any wishlist row on the platform

The review's answer to "what else has this shape" found the third member of the family:

```php
public function delete_wishlist(Request $request, $slug)
{
    Wishlist::where('id', $request->id)->delete();
}
```

No customer predicate, no store predicate, no scope behind it — and on a **GET** route
(`routes/customer.php:338`), so no CSRF token is involved: an `<img src=".../delete-wishlist?id=
123">` on any page a signed-in shopper loads fires it. Driven before the fix: a shopper of one
store deleted a foreign store's row, and another shopper's row in their own store, while their own
delete still worked — so the feature was working for everybody's rows.

**Why it survived an earlier pass** is the part worth keeping. `routes/customer.php:303-306`
records this exact line being looked at and left, reasoned as *"delete.wishlist proves guests
cannot own wishlist rows at all"*, after which the route was put behind customer authentication.
That reasoning is about **guests** and is sound as far as it goes — but authenticating a handler
that trusts a caller-supplied id only narrows the attacker from "anyone on the internet" to
"anyone with an account", which on a public storefront is barely a narrowing. The verb is left
alone: `StateChangingGetInventoryTest` already carries this route as a declared state-changing GET
needing its own caller sweep.

## Mutation results, stated because "a guard is present" is not "a guard is tested"

| Mutant | Outcome |
|---|---|
| `cart/product.blade.php` reverts to `$cart->id` | **RED** — the round trip catches it |
| the probe loses its `store_id` predicate | **RED** — the storeless test catches it |
| the line query loses its `store_id` predicate | green — the probe refuses first, so it is unreachable; documented in the test rather than claimed as covered |
| either send guard alone is removed | green — the two are independently sufficient |
| **both** send guards removed | **RED** |
| `cart/show.blade.php` loses its null guard | **RED** — `Attempt to read property "name" on null` |
| either wishlist send guard alone removed | **RED** |
| `delete_wishlist` loses `customer_id` | **RED** |
| `delete_wishlist` loses `store_id` | **RED** |

### Four guards that looked tested and were not

This is the uncomfortable part, and it is the clearest thing this package learned. For **three**
handlers the `empty($storeId)` refusal could be deleted with every test still green, and for
`delete_wishlist` the `store_id` predicate could be deleted the same way. In each case the first
test I wrote measured the **predicate** while claiming to measure the **guard**:

- `where('store_id', null)` compiles to `store_id IS NULL`. No fixture row carried a null store,
  so the query missed and the handler refused — for the predicate's reason, not the guard's. Fixed
  by adding a row with a **NULL `store_id`**, which `carts.store_id` and `wishlists.store_id` both
  permit (nullable, no default), and which is the legacy/imported row `config/tenancy.php` already
  names as the operator's precondition.
- `delete_wishlist`'s cross-store tests used a victim whose customer id *differed* from the
  attacker's, so `customer_id` alone refused. Fixed by the id-collision shape: a foreign store's
  row carrying the attacker's own customer id, which is what a shared database actually produces.

Every one of those was found by deleting the guard and watching the suite stay green. None would
have been found by reading the code.

## The CI guard that caught my evidence

`check-distinct-surface-captures.py` failed the build on commit `3841696e` with four collisions
between `cell-carts-*.png` and `cell-wishlists-*.png` — **byte-identical pixels for two different
surfaces**. It was right. The dev fixture wrote the same count and the same products to both
tables, so a customer's wishlist was a verbatim copy of their cart; the cropped cell could not
evidence which screen it came from, and a wishlist that duplicates the cart is not realistic data
either. The fixture now gives the wishlist its own counts and its own product window
(cart `[1,3,5]` vs wishlist `[4,2,3]`) and **asserts in the seeder** that no customer's two counts
coincide, so a future edit fails there with the reason rather than in CI with a pixel hash. The
modal check was also changed from a hardcoded `expected 5` — which was a restatement of the
fixture and went stale the moment the fixture changed — to the invariant that the modal must list
exactly as many products as the cell's figure claims.

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
3. A comment of mine claimed the cart email handler "already answers 403". It did not — only
   `abandon_carts_messsend` did; `abandon_carts_emailsend` was still returning
   `redirect()->back()`, a 302 that jQuery follows into a 200, so its refusal arrived in the
   *success* branch as a string and produced a **blank** error toast. The new error leg could not
   reach it. Caught by the review; the cart handler is corrected in the same change, so all four
   members of the family now answer a refusal a caller can read.

### One claim of mine the review challenged and that survived

The review suggested the email path's cross-tenant read might be **latent rather than live**, on
the reading that a pre-change gate written `isAbleTo(...)` would have been an undefined *function*
and 500'd for everyone. It was conditioned on shorthand in my briefing, and the actual pre-change
line is `if (auth()->user() && auth()->user()->isAbleTo('Abandon Wishlist'))` — Laratrust's
**method** on the User object, which exists. Corroborated independently: driving that endpoint
produced `Attempt to read property "customer_id" on null`, an error from the line *after* the gate.
The gate passed, execution continued, the read was live. The claim stands unamended — recorded
here because a challenge that is checked and rejected is worth as much as one that is accepted.
