# P2.5-FRAG — the panel-injected fragments

3 surfaces, 4 combinations each. **12 captures, 12 distinct. Findings 46 → 2.**

---

## 1. Why these needed their own harness

`/order-preview/{id}`, `/product-preview/{id}` and `/orders/order_view/{id}` each answer **HTTP
200** with a body that has **no `<html dir>` and no theme stylesheet**. They are not pages: the
parent page's JavaScript fetches them into a side panel (`data-ss-panel-url`,
`store-syria-ds.js:273`) or a modal (`data-ajax-popup`, `custom.js:138`).

Driven as routes they were not merely unphotographable — they were **unmeasurable**. A fragment
with no stylesheet has no colours to measure. Every finding below was invisible until the fragment
was driven in the container it really lives in.

`tools/shoot-fragments.cjs` takes the container as configuration, so the DS panel and the Bootstrap
modal are the same job.

---

## 2. Four defects found and fixed

### Fourteen address labels at 2.3:1, in dark mode

`order/order_show.blade.php` is **both** the order detail page and the body of the order-view
modal. A previous dark-mode fix (`custom.css:5222`) gave its labels the dark palette's light grey —
correct on the page, wrong in the modal, because **`.modal-content` stays white under
`data-ss-theme="dark"`** (a quirk recorded at the top of `store-syria-ds.css`). Light ink on white.

Fixed with a `.modal-content`-scoped override naming light-theme values explicitly: `--ss-grey-600`
at 6.42:1 for labels, `--ss-grey-700` at 9.35:1 for values. The labels were also moved off
Bootstrap's `.h6 .text-sm` onto a DS class in the same change.

### The `Payment Type` label at 3.84:1

`<b class="shipping-text">`, outside the address block, so the first selector did not reach it.
Named explicitly rather than widening to a bare `b`, which would recolour every bold word in every
modal.

### Two WhatsApp links that were empty anchors

With no phone on the order they rendered `<a href=".../send?phone=&text=Hi"></a>` — no accessible
name, no visible text, no destination. A guest order is exactly the kind that has no phone. Now
guarded, with an em-dash placeholder that is itself legible.

### `Edit Order` read `ترتيب التحرير`

"The arrangement of editing": the machine read `Order` as *sequence* and reversed the words. Now
`تعديل الطلب`.

The preview panels' definition terms were separately raised from `--ss-fg-subtle` to
`--ss-fg-muted` (4.39:1 → 5.88:1 on the sunken surface).

---

## 3. One finding remains, and the attempt to fix it is the point

`.btn-success` measures **2.5:1** in dark. Giving it a green fill and white text made it **worse —
1:1, green on green.**

`store-syria-ds.css:1904` already records why: the host sets `--bs-btn-bg: var(--third-color)`,
which resolves **transparent**. `.btn-success` is not a filled button; it is coloured text that
happens to be clickable, and the design system deliberately moved only its text colour and tracks
the missing fill as a separate item. Adding a background under text that was recoloured to sit on
the *page* puts that text on its own fill.

Restoring a real fill means choosing text and background together for every `.btn-success` in the
application. That is a design-system change with a blast radius, so it stays where the DS already
tracks it. The failed attempt is recorded rather than left as a silent partial fix.

---

## 4. `custom.css` and `rtl-custom.css` are parallel copies

Not a base and a supplement — **the same rules with the same selectors**, and the admin loads one
or the other by direction. Measured: after the modal fix landed in `custom.css` alone, the labels
were clean in `en-ltr-dark` and still **2.3:1** in `ar-rtl-dark`.

**A contrast fix applied to one of those files is a fix that works in one language.**

---

## 5. What each capture is measured for

Scoped to the container, because the shell around it is verified elsewhere and its findings would
drown the fragment's own:

| | |
|---|---|
| **contrast** | every text node against its composited background, WCAG 1.4.3 floors |
| **overflow** | anything past the container's own box |
| **latin** | Latin-script words in the Arabic UI, minus merchant data already on the parent page |
| **nameless** | controls with no accessible name — which is how the empty WhatsApp links surfaced |

Premises: the landed path is the requested **parent** path; a visible trigger exists outside the
shell's Quick Add menu; and the container is visible with real text and no loading placeholder
left.

---

## 6. Reproducing

```bash
node docs/development/phase-2.5/tools/shoot-fragments.cjs --out docs/development/phase-2.5/evidence/web-fragments-2026-11-10/after
```

Every finding is in `after/measurements.json`. **No PREMIUM VERIFIED promotion** — that needs the
owner's visual acceptance.
