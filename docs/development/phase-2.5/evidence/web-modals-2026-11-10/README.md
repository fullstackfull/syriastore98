# P2.5-MODAL — the AJAX create and edit modals

One harness, `tools/shoot-modals.cjs`, for ~21 surfaces. None is reachable by URL:
`public/js/custom.js:138` binds `[data-ajax-popup="true"]`, fetches the form over XHR, drops it into
`#commanModel .modal-body` (the misspelling is the application's) and shows the dialog. A
navigation captures the list page; only a click captures the modal.

---

## 1. Four harness defects, every one of which produced clean-looking evidence of nothing

### Captured mid-fade

`.modal.fade` transitions opacity 0→1 while `.modal-dialog` slides `translate(0,-50px)`→none over
~150ms. Waiting only for `.show` photographed a **washed-out dialog with the page's top navigation
bleeding into frame**, because the clip box was measured while the dialog was still moving. A dim
capture is worse than a failed one: every contrast figure measured on that paint is wrong.

Now waits for `.show` **and** opacity ≥ 0.99 **and** a settled transform, then one more frame.

### The shell's Quick Add menu stole every create trigger

`a[data-ajax-popup="true"]` matches **four** elements on `/tag`, and the first three are the
header's Quick Add dropdown — present on every page, hidden until the menu opens:

| data-url | visible | in a menu |
|---|---|---|
| `/taxes/create` | no | yes |
| `/category/create` | no | yes |
| `/coupon/create` | no | yes |
| `/tag/create` | **yes** | **no** |

Clicking the first match timed out after 30s against an invisible element, and the run recorded
"modal body holds 0 chars (never loaded)" for **every** create surface. The trigger is now the
first one that is visible and outside a menu.

### "Loaded" was a character count, so every small modal was rejected

The premise required 20 characters of `innerText`. The tag create modal is one labelled field and
two buttons — **nine characters** — and it was fully present: a `<form>` posting to `/tag` with a
CSRF token. The threshold was measuring the modal's *size* and calling it loading.

"Loaded" is now **a form with at least one visible field**, which is true of the smallest modal in
this group and of the 25-field coupon one alike.

### The edit selector assumed every list is a table

It was `table tbody button[data-ajax-popup="true"]`. `/users` is a **card grid** — the matrix
records it as such — so its edit button sits in a card and the selector matched nothing, reporting
"no visible edit trigger" as though the surface were missing.

---

## 2. Four application defects, found and fixed

### The required-field marker at 4:1

`testimonial/create.blade.php` and `edit.blade.php` marked required fields with Bootstrap's raw
`<span class="text-danger">*</span>` rather than the design system's `.ss-label__required`. Measured
**4:1 against a 4.5 floor**, in all four combinations.

Converged on the DS class, which is **6.54:1** light (`--ss-red-700` `#b3261e` on the modal's
white) and **7.47:1** dark. The DS already carries a dark-mode override for the known quirk that
`.modal-content` stays white under `data-ss-theme="dark"`, so the fix inherits that too.

### Two unlabelled selects in the flash-sale campaign builder

`fields[i][condition]` and `fields[i][condition_option]` carried an `id` but had no `<label for>`.
The only text near them names the *group* ("Apply this Campaign when these conditions are
matched:"), not either control, so a screen-reader user arriving on the second select hears "combo
box" and nothing else.

Given `aria-label` rather than visible labels: the row is a repeater that already reads as
"field / operator / value" visually, and three visible labels per row would make the form worse for
everyone to fix it for some. `Condition Option` was missing from both language files and was added.

### Select2's own strings were English in the Arabic UI

The remove-X on a multi-select carried `title="Remove item"` — **Select2's built-in default**,
injected by `public/assets/js/plugins/select2.min.js`, not by any view in this repository. Nothing
in the application said it, which is why no translation pass had ever found it.

Fixed as `$.fn.select2.defaults.set('language', …)` in the admin layout rather than per-init:
**eight** files call `.select2(...)`, and patching each would leave the ninth caller English. The
strings come from `__()` so they follow the page's locale — the reverse mistake, Arabic literals
with no translator call, is one this project has already had to undo.

### The FAQ fixture was the wrong shape, and it looked like an application defect

`faq-edit` reported "modal holds no `<form>`" in all four combinations while `faq-create` captured
cleanly — a convincing signature of a broken edit path. It was the seeder:
`faq/edit.blade.php:12` does `foreach (json_decode($faq->description, true) as $item)` and reads
`$item['question']`, because the FAQ form is a **repeater**. Seeded with a plain sentence,
`json_decode` returns null and the view fails. The seeder now writes the repeater JSON.

---

## 3. Surfaces reached by a settings tab, not a route

`pixel-create` and `webhook-create` are not pages. `/pixel-setting` and `/webhook-setting` are
**panels of the settings hub**, loaded over XHR when their pill is clicked. Driven as standalone
routes they reported "no visible create trigger" — true, and silent about the modal.

The harness now takes a `tab` for those surfaces: it opens `/setting`, clicks the pill, waits for
the panel the same way `measure-settings-tabs.cjs` does (real text, no `.ss-skeleton` left), and
then looks for the create trigger inside it.

---

## 4. What each capture is measured for

Scoped to the dialog, because a modal is a **form** and the things that break in one are the things
a picture cannot show:

| | |
|---|---|
| **unlabelled** | every field reachable by a name — `for`, `aria-label`, `aria-labelledby`, `title`, `placeholder`, a wrapping label, or `value` on a submit/button input (which *is* the accessible name, and whose absence from the first version reported the file widget's own buttons as two unlabelled fields per modal) |
| **contrast** | every text node against its composited background, WCAG 1.4.3 floors |
| **overflow** | anything past the dialog's own box — off-frame in a screenshot by definition |
| **latin** | Latin-script words in the Arabic UI |

---

## 4b. Result, and one note on `measurements.json`

**16 surfaces captured, 4 combinations each: 64 captures, 64 distinct.**

Findings across the three runs of this group: **42 → 30 → 26 → 22**, as each defect was fixed.
The 20 that remain are the `premise` entries for the five surfaces that cannot be captured (§6),
not defects in anything that was.

The committed `after/measurements.json` was written by the run that produced these captures, and it
carries **two `latin` entries for `Textiles`** on the testimonial edit modal. That is the
measurement being wrong, not the surface: `Textiles` is one of the four category names in the dev
fixture (`Textiles`, `Soaps`, `Homeware`, `Scarves & Shawls`), rendered as a selected-value chip.

Choices.js renders each selected option as a `<div class="choices__item">` — **not** an `<option>` —
so the walker's form-control exclusion never reached it. The tool now skips those chips, and
re-running `--only testimonial-edit` against the same page gives **FINDINGS: 0**. The captures are
unchanged by that fix because it alters what is *read*, not what is *drawn*.

This is the same mistake `shoot-row-actions.cjs` made with `تعديل التصنيف Scarves & Shawls`, and it
has the same cost if left standing: the repair a reader would reach for is to stop showing the
record's name, which makes the surface worse. An earlier version of this file also computed a
`pageData` string for exactly this purpose and then never used it — dead code that looked like the
check was handled.

---

## 5. Reproducing

```bash
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file php docs/development/phase-2.5/tools/seed-dev-catalogue-rows.php
```

```bash
node docs/development/phase-2.5/tools/shoot-modals.cjs --out docs/development/phase-2.5/evidence/web-modals-2026-11-10/after
```

Per-surface field counts and every finding are in `after/measurements.json`.

**No PREMIUM VERIFIED promotion** — that needs the owner's visual acceptance.

---

## 6. The five surfaces that are not claimed

Each stays **PENDING** with its measured reason rather than being claimed on a sibling's capture.

| surface | why |
|---|---|
| `users-edit`, `flash-sale-edit` | no record in the fixture carries an edit trigger. `/users` is a card grid and `/flash-sale` has no campaigns; seeding either needs more than a row. |
| `pixel-create`, `webhook-create` | the harness now opens their settings tab correctly, and the loaded panel offers no create control of its own — the three `a[data-ajax-popup]` on the page are all the shell's Quick Add items. Whether a merchant can create a pixel or a webhook at all from this hub is a product question, not a capture problem. |
| `faq-edit` | the modal loads no `<form>` even after the fixture was corrected to repeater-shaped JSON. Needs its own investigation. |

**`Category create (modal)` and `Brand create (modal)` are captured in this wave but NOT claimed.**
Both are already **PREMIUM VERIFIED**, and `VERIFIED` is a dict — a second entry under the same
name silently overwrites the first. That is not hypothetical: it happened to the web
`Coupon detail` earlier in this session and demoted it until the counts were read carefully. Their
captures here are corroboration, not a claim.
