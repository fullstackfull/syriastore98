# P2.5-ROW — the DataTable row-action cells

11 surfaces, 4 combinations each. **44 captures, 44 distinct. 0 findings.**

One harness, `tools/shoot-row-actions.cjs`, for the whole family — adding a twelfth surface is one
line in its `SURFACES` table.

---

## 1. Why the camera is the weaker half of this tool

These cells are the densest concentration of icon-only controls in the application: a row of ghost
buttons whose entire accessible name is an `aria-label`, in a pinned column, at the end of a dense
table. That is precisely the shape that photographs perfectly and fails in use.

So each cell is measured as well as captured:

| | result |
|---|---|
| **nameless** — a control identified only by its icon | **0** — every control carries a name in both languages |
| **target** — WCAG 2.5.8 Target Size (Minimum), 24×24 CSS px | **0** below the floor |
| **contrast** — the glyph against the row it sits on, WCAG 1.4.11 non-text 3:1 | **0** below the floor |
| **latin** — untranslated strings in the Arabic accessible name | **0** |

That is a real result rather than an absent one. The partials build names as
`__('Edit :thing', ['thing' => $record->name])`, and this proves they render — in Arabic too.

---

## 2. The premise that mattered: an empty cell photographs as a tidy cell

Every button in these partials is wrapped in `@permission`:

```php
@permission('Edit Coupon')
<button aria-label="{{ __('Edit Coupon') }}" …>
```

A fixture holding `Manage Coupon` but **not** `Edit Coupon` renders
`<span class="d-flex gap-1 justify-content-end"> </span>` — an empty cell, HTTP 200, no error, and
a perfectly clean screenshot of nothing. Measured exactly that on `/coupon`, `/faqs` and `/tag`
before the 36 action permissions were granted.

The harness refuses a cell with no controls rather than capturing it, and refuses a page it did not
land on — see §4.

---

## 3. Three harness corrections, each of which was producing a false reading

### Cropped to the cell, four surfaces were byte-identical

Brand, category, product-label and tag each render two anonymous ghost icons — same size, same
colour, no text — so one image stood for all four in all four combinations: **44 captures, 30
distinct**. `check-distinct-surface-captures.py` would have refused them and would have been right:
a picture of two pencil-and-bin icons is not evidence about *Tag row actions*, it is evidence that
*some* row has two buttons.

**The capture is now the whole row**, which carries the record that identifies the surface. 44
distinct. It remains distinct from the list page's own capture, which is a full-viewport screenshot
of a different thing.

### The Latin check reported the accessible-name feature as a defect

It flagged `تعديل التصنيف Scarves & Shawls` and `حذف الزبون Walk-in Guest`. Including the record's
own name is the **point** — it is what tells a screen-reader user which row's Edit button they are
on. Left unfixed, the obvious "repair" would have been to strip the record name out, making the
surface worse.

The check now ignores Latin words that appear in the row's own cells: merchant data, not an
untranslated string. A Latin word in the label that appears **nowhere** in the row is still
reported, which is the case worth finding.

### …case-insensitively

The customer fixture stores `Walk-In Guest` in the cell and `Walk-in Guest` in the label. A
case-sensitive compare called that three defects. Same words; one capital letter.

---

## 4. The refusal shape that defeats a title check

A WorkDo controller refuses with `redirect()->back()`, landing on the **referrer** — so the capture
carries the previous page's entirely legitimate title and every ordinary premise passes. Measured
driving `/coupon`, `/tag`, `/faqs` in order: three pages all titled `Store Syria - Coupon`.

Both this harness and `render-matrix.cjs` now assert **the landed path equals the requested path**.
Full detail in `PHASE-2.5-DEV-FIXTURE-PERMISSIONS.md`.

---

## 5. Fixtures

`tools/seed-dev-catalogue-rows.php` — dev fixture only, idempotent, **reversible** (`--remove`,
exercised both ways). Every row is tagged `P25PROBE`, which is what makes it findable and
removable. It refuses to add to a table holding rows it did not create, and it resolves foreign
keys against the fixture rather than assuming id 1 exists — `testimonials.category_id` defaults to
`'0'` while carrying an FK to `categories(id)`, so the column's own default violates its own
constraint, which is how the first insert failed.

Coupons, FAQs, testimonials and product questions were empty; tags, product labels, brands and
categories already had rows.

Thirty-six action permissions were granted to the dev fixture, enumerated from the
`@permission(…)` directives in the 37 `action.blade.php` partials rather than guessed.

---

## 6. What is not claimed

**`Attribute row actions` stays PENDING.** `/product-attribute` renders zero rows in this fixture,
so there is no cell to capture. Seeding one needs an attribute *and* its parent product's variant
wiring — a fixture job of its own, not a line in the catalogue seeder. It is not claimed on a
sibling's capture.

**No PREMIUM VERIFIED promotion.** That needs the owner's visual acceptance.

---

## 7. Reproducing

```bash
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file php docs/development/phase-2.5/tools/seed-dev-catalogue-rows.php
```

```bash
node docs/development/phase-2.5/tools/shoot-row-actions.cjs --out docs/development/phase-2.5/evidence/web-row-actions-2026-11-10/after
```

Raw per-control measurements, including every accessible name and its rendered size, are in
`after/measurements.json`.
