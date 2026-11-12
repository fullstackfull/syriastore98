# P2.5-PKG-J — the confirmation dialog's two buttons, in the dark admin

The owner authorised a repair of "the confirmed 1.04:1 contrast problem in the default modal
pathway … approximately 120 trigger locations", with real screenshots and computed contrast in
Arabic/English, light/dark and desktop/mobile, and with destructive, non-destructive and
payment-adjacent dialogs exercised.

Everything below was produced by
`docs/development/phase-2.5/tools/audit-dialog-contrast.cjs` against the running merchant admin.
It opens the REAL dialog on the REAL screen, reads each element's computed colour, composites every
translucent layer down the ancestor chain, and refuses to keep an image whose premises fail.

## 1. What the dialog pathways actually are

Found by grepping every `Swal.` call site under `public/js`, `resources/views` and
`packages/workdo`. All five live pathways share one pairing.

| | pathway | class | buttons |
|---|---|---|---|
| A | `public/js/custom.js` `.show_confirm` — **124 triggers across 107 views** (55 first-party + 52 vendored) | destructive | `btn btn-success` / `btn btn-danger` |
| A2 | the same handler with `data-confirm-tone="danger"` (2 triggers, added in PKG-I) | destructive | `ss-btn--danger` / `ss-btn--secondary` |
| B | `.order_replace`, `resources/views/order/view.blade.php:812` | cancel + reissue | `btn btn-success` / `btn btn-danger` |
| C | cancel refund, `resources/views/order/refund_details.blade.php:354` | **payment-adjacent, terminal** | `btn btn-success` / `btn btn-danger` |
| D | refund amount, `resources/views/order/refund_details.blade.php:508` | **payment-adjacent, terminal** | `btn btn-success` / `btn btn-danger` |
| E | four vendored modules incl. BulkDelete | destructive | `btn btn-success` / `btn btn-danger` |
| — | `resources/views/layouts/includes/alerts.blade.php:43` declares a `sweetAlert` mixin with the same pairing that **is never fired**. Dead code; it also carries a debug string, `"23sfdcvdcg…"`. Reported, not changed. | | |

So one rule scoped to `.swal2-popup` reaches A, B, C, D and E **without editing anything under
`packages/workdo/`**.

## 2. The defect, measured

The dialog sheet is `#ffffff` in BOTH themes — the same decision P2.5-A-01/A-02 made for the
Bootstrap modal. `data-ss-theme` sits on `<html>`, so the dark-page rules reach inside it.

| element | dark | why |
|---|---|---|
| cancel `btn btn-danger` | **1.04:1** | `[data-ss-theme="dark"] .btn-danger { color: var(--ss-fg) }` puts `#e8eaed` on the 41% pink tint, which over the white sheet composites to `rgb(255,222,228)` |
| confirm `btn btn-success` | **2.50:1** | `--bs-btn-color: #5eb839` with `--bs-btn-bg: var(--third-color)`, and `--third-color` **resolves to the empty string** here, so the fill falls back to transparent |

**It is not only the cancel button.** The 1.04:1 figure recorded in `custom.js` named the cancel
button because that is where a capture had been looked at. Measuring every part of the open dialog
found the confirm button failing too — the merchant could read **neither** answer.

Light mode is unaffected: 7.82:1 and 7.30:1, because the light sheet's rules never fire in dark.

## 3. The repair

```css
[data-ss-theme="dark"] .swal2-popup .btn-success { color: var(--ss-green-800) !important; }
[data-ss-theme="dark"] .swal2-popup .btn-danger  { color: var(--ss-red-800)   !important; }
```

The host paints both fills identically in the light and dark sheets, so the light theme's own inks
are already measured correct against exactly these grounds. The repair makes the dark dialog
**identical to the light dialog** rather than giving it a fourth appearance — 7.82:1 and 7.30:1,
the figures light mode already produced, which is a prediction the live re-measurement reproduced.

`--ss-green-800` / `--ss-red-800` are Layer-1 primitives and never re-theme. The semantic
`--ss-success-fg` / `--ss-danger-fg` **would**, and were tried: see §5.

## 4. Coverage of this evidence

36 rows, **0 premise failures**: `A`, `A2` and `C` × {en, ar} × {light, dark} × {1440, 390, 320}.
Every title, body, confirm and cancel button passes in all 36.

* `before/` — 12 images, pathway A, the defect.
* `after/` — 36 images, pathways A, A2 and C.
* `*/measurements.json` — the computed colours, composited grounds and ratios behind every image.

**Not every affected surface is verified by this.** 124 triggers share the CSS; three pathways were
driven. The shared fix is proved on the pathways listed, and the completeness ratchet
(`ConfirmDialogButtonContrastTest::test_no_confirmation_pathway_uses_a_button_variant_the_repair_does_not_cover`)
fails if a pathway ever starts using a variant these two rules do not cover.

### A VENDORED pathway, driven for real (added after the first push)

The claim above said the fix reaches `packages/workdo/` "by code identity". It does not have to be
taken on trust: **BulkDelete is enabled in the dev fixture** (`add_on_managers`, `is_enable = 1`),
so its own `Swal.mixin` — declared in
`packages/workdo/BulkDelete/src/resources/views/pages/script.blade.php:49`, a file this slice does
not edit — was fired on `/customer` in dark mode with a row selected:

```
confirm  "Yes, delete it!"  [swal2-confirm btn btn-success]  rgb(22,94,52)  on rgb(255,255,255)  -> 7.82:1
cancel   "No, cancel!"      [swal2-cancel btn btn-danger]    rgb(140,29,23) on rgb(255,222,228)  -> 7.30:1
```

The same two figures as the first-party pathways, on a dialog belonging to a vendored module.

## 5. Mutations — both applied to the sheet and re-measured live

| mutation | result |
|---|---|
| remove both rules | 2.50:1 and 1.04:1 return. The rules are what carries the change, not a cache. |
| use `--ss-success-fg` / `--ss-danger-fg` | **1.75:1** and **1.53:1**. The obvious repair leaves cancel unreadable and makes confirm *worse* than the defect. |
| widen the danger rule off `.swal2-popup` | arithmetic: 1.58:1 on the dark page, 1.66:1 on the card — it would trade one failure for two. |

## 6. Found alongside, and reported rather than swept in

* **The warning icon is 2.01:1 in BOTH themes** — `#ffa21d` ring and `!` glyph on the white sheet,
  against the 3:1 that WCAG 1.4.11 asks of a graphic. It fails in light mode too, so it is a
  different defect from the one authorised, and recolouring it changes every dialog in both themes.
  **Not fixed. Open.**
* **`.swal2-close` measures 1.61:1 and is 0×0 px** — `showCloseButton` is never set on any pathway,
  so it does not render. **Not a defect**; an earlier draft of the audit reported it as one, which
  is why the tool now refuses to measure an unrendered element.
* **The Arabic `Yes` and `No` shipped as `نعم ..` and `لا ..`** — a trailing bare double dot on two
  keys used at ~120 first-party call sites, so every Arabic confirmation button read "Yes ..".
  **Fixed** (`resources/lang/ar.json`), with a ratchet in `TranslationFileIntegrityTest`.

## 7. Not captured, and why

* **Pathway D (refund amount)** — `#refund-amount` is `display: none` until the refund request has
  been accepted. Reaching it requires performing a **terminal payment action**, which the owner's
  instruction excludes ("without altering their business behavior"). It fires the identical
  `Swal.mixin({ customClass: { confirmButton: 'btn btn-success', cancelButton: 'btn btn-danger' } })`
  as pathway C, 154 lines above it in the same file, so the CSS reach follows from code identity —
  but **it is not measured in situ and is not claimed as verified.**
* **Pathway B (`.order_replace`)** — state-gated on `Gate::allows('updateStatus')` **and**
  `OrderReplacement::eligibility() === REPLACED`. No order in the dev fixture satisfies both, across
  the first 12 in the list. **Not measured. Not claimed.**
* **A genuinely non-destructive `.show_confirm`** — there is none. Every one of the 124 triggers,
  first-party and vendored, sits in a view containing a DELETE form. The non-destructive dialog
  class exists only as SweetAlert's own `buttonsStyling: true` alert (BulkDelete's "No rows
  selected"), which does not use the `.btn` pairing and is therefore untouched by this repair.

## 8. Reproducing it

```
docs/development/phase-2.5/tools/serve-dev.sh            # 127.0.0.1:8899
node docs/development/phase-2.5/tools/audit-dialog-contrast.cjs \
     --out /tmp/pkg-j --label after
```

The dev fixture merchant needs `Manage Order Refund Request` for pathway C:

```
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file \
  php docs/development/phase-2.5/tools/grant-dev-screen-permissions.php \
      merchant@example.test "Manage Order Refund Request"
```
