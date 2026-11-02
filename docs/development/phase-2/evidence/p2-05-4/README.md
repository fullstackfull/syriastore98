# P2-05-4 — categories and attributes on the design system

Sixteen screenshots, taken against the running application by
`docs/development/phase-2/tools/shoot-categories-attributes-live.cjs`: the category list, the
attribute list, the create-category modal and the attribute-option **pilot** screen, each in all
four configurations (English LTR and Arabic RTL × light and dark). Invented fixture data only
(`database/seed-p2dev.php`) — no real customer, order or credential appears in any of them.

The harness is not a camera. It asserts **74 cases** and fails the run if any of them is unmet;
these images are what it photographed on a clean pass.

## What to look for

**THE ROW ACTIONS, on both lists.** Both controls on both screens were **unnamed at runtime** —
they carried `title`, which Bootstrap's tooltip plugin moves to `data-bs-original-title` and
blanks on initialisation, so after page load each was a control wrapping an unlabelled `<i>`.
They now carry an `aria-label` naming the **record** as well as the verb (`Edit category
Scarves & Shawls`, `حذف الخاصية Size`), with `aria-hidden` on the icon, and they are the design
system's quiet icon buttons rather than the vendored `btn-info` / `btn-danger` — the family
P2-04-4 measured below AA in 44 of 56 readings, as low as 1.03:1 in dark mode.

**AND THEY ARE NOW PERMISSION-GATED**, which they were not. `CategoryController::update`/
`::destroy` and `ProductAttributeController::update`/`::destroy` all gate on `Edit`/`Delete`
permissions; the markup showed both controls to anyone who could open the list. The pilot screen
has always gated its own row actions, so this brings the parent lists into line with the
reference. **No functionality is removed** — the handler already refused those users — and that
is asserted in both directions by `CategoryAttributeRowActionsTest`.

**THE STATUS BADGE** on the category list was the vendored `bg-success` / `bg-danger`. It is now
`DsBadge::render()`, the same helper the order and product lists use, so it carries the
`ss-badge__dot` that keeps the state from being signalled by colour alone (WCAG 1.4.1). Built by
hand first; the live check failed in all four configurations on the missing dot, which is how it
came to use the shared helper.

**THE CREATE-CATEGORY MODAL is where the least visible fix lives.** Every label on that form was
`Form::label('', …)` — `<label for="">`, associated with nothing, so clicking it did not focus
the field and a screen reader announced each input unlabelled. Five fields. The two switches were
worse: their own visible label was **empty**, so the checkbox had no accessible name from any
source. A picture cannot show this; the harness resolves each `for` to an element and fails if
one does not exist.

**THE DARK MODAL IS THE FINDING WORTH READING THE PICTURES FOR.** Compare
`03-category-create-en-dark.png` against what it replaced: the host's Bootstrap modal keeps a
**white** `.modal-content` in dark mode — measured `rgb(255, 255, 255)` with
`data-ss-theme="dark"` on `<html>` — so text tuned for a dark surface was being drawn on white.
Two separate causes, both measured:

- `custom.css` carries `html[data-ss-theme="dark"] body label { color: #9DA3B4 !important }` — a
  **bare element selector** added for the sidebar and DataTables chrome, which catches every
  label in the application. On the white modal that is **2.52:1**. This **predates** the
  design-system conversion: the previous markup used `<label class="form-label">`, still a
  `label`, still matched, six of them on this one form.
- the design system's own light palette lived on `:root` alone, so a subtree inside a dark page
  could never be returned to light — the documented "on `<html>` or on any container" scoping
  worked in one direction only. The hint text measured **2.18:1** that way.

Both are fixed for a container that declares its surface with `data-ss-theme="light"`. Every
piece of text in the modal now clears AA in all four configurations, measured over the
**effective** background with alpha compositing.

**WHAT IS NOT FIXED, and is a ledger finding rather than a silent pass:** every *other* modal in
the admin still renders its labels at 2.52:1 in dark mode, because that bare `body label` rule is
still there for them. Roughly sixty forms across the host and seventy vendored modules. Narrowing
the rule means re-measuring the sidebar and tables it was added to fix, which needs its own pass.

**THE PILOT IS UNCHANGED AND THAT IS THE POINT.** `04-attribute-options-pilot-*.png` is
`attribute_option/index.blade.php`, the reference screen for this area, photographed to show the
converted lists now match it rather than a second convention being invented beside it. The
harness asserts its sortable list still renders.

## The numbers

| | |
|---|---|
| `shoot-categories-attributes-live.cjs` | **74 cases, 74 passed, 0 failed** |
| `check-row-actions-live.cjs` (five screens × four configurations) | **260 cases, 260 passed, 0 failed** |
| `CategoryAttributeRowActionsTest` | 7 tests |
| `CategoryTreeEscapingTest` | 4 tests |
| `CategoryParentValidationTest` + `MerchantCategoryOwnershipTest` + `AttributeOptionAndCustomerBoundaryTest` | 45 tests, unchanged |

## What the harness caught that a screenshot would not have

Three runs were green **about the wrong thing** before this one, and each is now a premise:

1. `/product-attributes` refuses a user without `Manage Attributes` with `redirect()->back()`,
   which landed on the previous screen in the harness's own loop. The run measured the **category
   list** while labelling every line `attributes`, and reported contrast failures naming
   `#ORD-2047` — an order reference — on a screen with no orders on it. Both harnesses now assert
   the URL they landed on before anything else.
2. The create-modal probe scoped itself as `document.querySelector('.modal.show') || document`.
   When the click failed, it measured the **list page**: "every label resolves to a control (5)"
   passed against the page chrome while the form it names was never on screen. The modal is now a
   hard premise.
3. The run reported **66/66** while the two switch captions were visibly dimmer than the fields
   above them. It was checking that labels *resolve*, never that they can be *read*. Contrast and
   dialog-overflow measurement were added, and immediately found the 2.18:1 and 2.52:1 above.
