# P2.5-PARAM — the parameterised web routes

Eighteen matrix rows carry an `{id}`. They are not one group: **two are pages**, five are **HTML
fragments**, and the rest are **POST-only actions that are not screens at all**. Only the first
kind can be claimed from a page capture, and that distinction is the whole result here.

---

## 1. Claimed — two full pages, four combinations each

| row | route | |
|---|---|---|
| Product detail (read-only) | `GET /product/{product}` | 4 captures |
| Product editor — edit | `GET /product/{product}/edit` | 4 captures |

8 captures, 8 distinct, every premise clean: landed on the requested path, correct direction,
correct theme sheet, and the page's own title in both languages (`Store Syria - Product` /
`Store Syria - المنتج`) rather than the dashboard's.

---

## 2. Not claimed — five HTML fragments

`/user-info/{id}`, `/order-preview/{id}`, `/orders/order_view/{id}`, `/order-receipt/{id}`,
`/product-preview/{id}`.

Each answers **HTTP 200** with a body that has **no `<html dir>` and no theme stylesheet**, because
it is injected into a panel or a modal by the parent page's JavaScript. `render-matrix.cjs`
correctly fails its direction and theme premises on them — they genuinely have neither.

This is a real distinction, not a harness limitation to work around: a fragment captured on its own
is a screenshot of unstyled markup on a white page, which is not what any merchant sees. They need
the parent page driven and the fragment triggered, the way `shoot-modals.cjs` drives a modal. That
is the next batch for this group.

---

## 3. `/user-info/{id}` is **not** a permission gap

It looks exactly like one — the merchant is refused and lands on the dashboard — so it is worth
recording why it is not.

`UserController::userInfo` refuses unless the actor is the platform operator **or is the target
themselves**:

```php
if (! \App\Policies\UserPolicy::isOperator($actor) && (int) $actor->id !== (int) $user->id) {
    return redirect()->back()->with('error', __('Permission denied.'));
}
```

Its docblock records that an earlier version admitted anyone whose `creatorId()` matched the
target's, and that this was **deliberately tightened**. Measured against the fixture:

| requested | actor | result | |
|---|---|---|---|
| `/user-info/1` | merchant (id 2) | refused | id 1 is the **super admin** — correct |
| `/user-info/3` | merchant (id 2) | refused | id 3 is their own staff — **still correct**, by the rule above |
| `/user-info/2` | merchant (id 2) | **200** | their own record |

The fixture was **not** given a permission to "fix" this, and the refusal captures were deleted
rather than kept: a screenshot of a dashboard redirect is evidence of the dashboard.

---

## 4. Not claimed — POST-only actions

Mark read / mark all read, archive customer, withdrawal approve / pay / reject, inline
payment-status update. These mutate and redirect; they have no rendered surface of their own. The
confirmation and feedback they produce belong to the page that invokes them, which is captured
elsewhere.

---

## 5. Reproducing

```bash
node docs/development/phase-2.5/tools/render-matrix.cjs --paths /product/1,/product/1/edit --out docs/development/phase-2.5/evidence/web-param-routes-2026-11-10/after
```

Ids come from the dev fixture (`products` 1–3, `orders` 1–3, `users` 1–5, where 1 is the operator,
2 the merchant and 3–5 their staff). **No PREMIUM VERIFIED promotion** — that needs the owner's
visual acceptance.
