# P2.5-PKG-I — the customer-management area, before

84 captures: **7 surfaces × 4 combinations × 3 widths**, every one taken by driving the real
merchant admin, signing in, opening the real parent screen and — for the destructive surfaces —
**clicking the real control** so the dialog is photographed as a merchant meets it.

Widths are 1440 desktop plus the **390** and **320** phone widths the owner named. Combinations are
English LTR and Arabic RTL, each light and dark.

**All 84 rows passed their premise block**: signed in; the page title proves the right screen;
the workspace chip proves the right store; the control being photographed existed *before* the
click; direction and theme are what was asked for; and for the four interactive scenarios the
dialog really opened. A row failing any premise has its image deleted rather than kept.

## A distinct NAME was not enough, and a CI guard said so

The first version of this set gave `customer-row-actions` and `customer-status-toggle` different
filenames and **byte-identical contents**: both photographed the whole of `/customer` with no
interaction to tell them apart. `check-distinct-surface-captures.py` failed CI on exactly that, and
it was right to — that guard exists because three surfaces were once marked PREMIUM VERIFIED on
twelve screenshots that were all the dashboard. Two names over one image is the same error, smaller.

So a scenario that photographs a **cell** now photographs that element rather than the viewport.
That is also the more honest picture: the surface under review is the action cell, not the page it
sits on.

Both guards are now run locally before any push, not discovered in CI:

    check-distinct-surface-captures.py   -> no two captures claiming different surfaces share content
    detect-refusal-toast-captures.py     -> no capture shows a permission-refusal toast

Neither proves a capture shows the *right* screen — the harness's own page-title premise does that,
and the two guards are complementary to it rather than a substitute.

## One scenario = one surface = its own image

| scenario | surface | parent screen | interaction |
|---|---|---|---|
| `customer-row-actions` | Customer row actions | `/customer` | none — the cell as it sits |
| `customer-archive-confirm` | Archive customer | `/customer` | click the trash → confirm dialog |
| `customer-status-toggle` | Customer status toggle | `/customer` | none — the status cell |
| `abandoned-cart-row-actions` | Abandoned-cart row actions | `/abandon-carts-handled` | none |
| `abandoned-cart-delete-confirm` | Abandoned-cart delete | `/abandon-carts-handled` | click → dialog |
| `abandoned-wishlist-row-actions` | Abandoned-wishlist row actions | `/wishlist` | none |
| `abandoned-wishlist-delete-confirm` | Abandoned-wishlist delete | `/wishlist` | click → dialog |

## Two things the capture found that no analysis did

**1. The Action column was a header over three empty cells.** On the first run the cart and
wishlist scenarios found no controls at all. `cart/action.blade.php` gates its three controls on
`@permission('Abandon Cart')` twice and `@permission('Delete Cart')`; `wishlist/action.blade.php`
does the same with `Abandon Wishlist` / `Delete Wishlist`. The dev fixture role held none of them,
so the table rendered a column titled «العمل» over nothing:

    HEADERS: ["", "العميل", "البريد الإلكتروني", "المنتجات", "العمل"]
    ROW 0:   ["", "Nour Saleh",   "nour.saleh@example.test",   "5", ""]

The missing permissions are a **fixture gap**, granted with `grant-dev-screen-permissions.php`.
The **header over nothing is a product defect**, and it is the same one repaired in
`customer/status.blade.php` during PKG-G: `@permission` compiles to Laratrust's `hasPermission()`,
which has no operator bypass, so the platform operator sees the empty column too.

A static review had called this surface *"REACHABLE — all three controls have a registered route,
a real handler and a rendered partial."* True of the source, false of the running screen.

**2. A refusal that redirects BACK, not to the dashboard.** Before the permissions were granted,
`/abandon-carts-handled` and `/wishlist` both came back titled "Customer":
`CartController::abandon_carts_handled` and `WishlistController::index` answer a refusal with
`redirect()->back()`, which returned to the customer list the harness had just been on. Without the
page-title assertion this would have photographed the **customer table** and filed it as evidence
for **three** independent surfaces. It is a nastier variant of the dashboard-redirect trap this
project already records: a dashboard screenshot looks obviously wrong, a plausible customer table
does not.

## What the before images show, by eye

* **The destructive dialog says «نعم ..» and «لا ..»** — a two-dot artefact rendered verbatim at
  the moment of deletion (`ar.json` "Yes" → `نعم ..`, "No" → `لا ..`).
* **The dialog names nothing.** "هل أنت متأكد؟ هذا الإجراء لا يمكن إلغائه" — no customer, no cart,
  no count. The merchant cannot tell what is about to go.
* **The red fill is on «لا» (No).** The eye is drawn to Cancel while the confirming action is a
  plain text link, so the colour semantics are inverted.
* **At 320px the e-mail is clipped and reordered**: `nour.saleh@example.test` renders as
  `:saleh@example.test` — the start cut off and a colon migrating to the front, a Latin run in an
  RTL card with no bidi isolation.

These are the "before" state. Nothing in this directory is a claim that any surface is finished.
