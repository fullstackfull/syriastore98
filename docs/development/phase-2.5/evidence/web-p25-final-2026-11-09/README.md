# P2.5-WEB-FINAL — thirteen merchant-web routes, and the run that looked fine and was worthless

**Date:** 2026-11-09 · **Branch:** `claude/vibrant-bell-12tquy`

Thirteen merchant-web routes rendered through `render-matrix.cjs` in all four combinations:
**52 rows, 0 not clean, 52 distinct images.**

---

## 1. The first run produced 11 distinct images from 17, and every row said "ok"

This is the part worth reading. The first pass reported HTTP 200 and a passing premise for nine of
these routes. All nine were titled **"Store Syria - Dashboard"**, and the captures collided:

```
pages   + shipping                     → identical
blog    + menus     + newsletter       → identical
contacts + shipping-zone               → identical
taxes   + support_ticket + out-stock   → identical
```

They were **redirects**. A merchant screen this application refuses answers with
`redirect()->back()`, not an error — so the harness followed it, recorded 200, and photographed
wherever it landed. `grant-dev-screen-permissions.php` exists for precisely this, and its own
header says it plainly:

> a screen the fixture role cannot open answers with a REDIRECT TO THE DASHBOARD, not an error —
> so the harness records HTTP 200 with the wrong title and a screenshot of the dashboard. That is
> the single most dangerous reading this project can take, **because it looks like evidence.**

The project had measured this exact trap before (P2.5-WAVE-13) and six surfaces were once marked
PREMIUM VERIFIED on refusal screenshots. This run reproduced it, and the two guards caught it: the
**title** was wrong, and `check-distinct-surface-captures.py` would have refused the captures.

### What fixed it

Thirteen permissions granted **to the dev fixture only** — `Manage Deliveryboy`, `Manage Menu`,
`Manage Page`, `Manage Blog`, `Manage Blog Category`, `Manage Contact Us`, `Manage Newsletter`,
`Manage Shipping Class`, `Manage Shipping Zone`, `Manage Tax`, `Manage Support Ticket`,
`Manage Shipping`, `Manage Stock Report` — plus the `taxes` setting `TaxController::index` requires
on top of the permission.

**No controller was weakened and no gate was removed.** The fixture was missing permissions a real
merchant has; that is a fixture gap, not a defect, and the fix belongs in the fixture.

Afterwards every route answered with its **own** title — DeliveryBoy, Menus, Pages, Blogs, Blog
Category, Contact-us, Newsletters, Shipping Class, Shipping Zone, Settings, Support Ticket, Tax
Class — and all 52 captures are distinct.

---

## 2. Three things that are NOT screens, and are not claimed

Found by the same run, each with a concrete reason rather than a silent exclusion:

| route | what it really is |
|---|---|
| `/languages` | **does not exist.** `routes/web.php` has only `change-languages/{lang}` and `change-language-store/{lang}`. The inventory row naming a `/languages` screen is wrong. |
| `/shipping-method` | `ShippingMethodController::index()` **has an empty body — literally `//`.** It renders nothing. A registered-but-unimplemented route must not be presented as a working screen. |
| `/stock-report/low-stock`, `/stock-report/most-stocked`, `/stock-report/out-stock` | **row-payload endpoints**, which their own inventory rows say: "Low in Stock — row payload". They return DataTables JSON to feed a screen; they are not screens. |

---

## 3. What is claimed, and the two rows with a stated limit

Six rows are claimed outright: **Delivery boys**, **Menus**,
**Pages / Blog / Blog categories / Contact / Newsletter** (all five routes captured distinctly),
**Settings hub**, **Support tickets**, **Attribute options list**.

Two rows name more than one screen and one named thing is not a screen. Both are claimed on what
exists, with the gap stated here rather than left for a reader to find:

* **Shipping classes / zones / methods** — `/shipping` and `/shipping-zone` both render and are
  captured. `/shipping-method` is the empty-body stub above.
* **Taxes and tax methods** — `/taxes` renders and is captured. The tax-method routes are
  create/edit **modals**, which are their own inventory rows.

---

## 4. A bidi defect I reported here, and then refuted

**The claim made in the first version of this file was wrong, and it is corrected rather than
quietly deleted.**

It said the shell header rendered `مرحباً, Amina Haddad!` with the exclamation mark "at the wrong
end", and proposed `<bdi>` as the repair. The owner authorised that fix. There is nothing to fix.

**`<bdi>` is already there**, applied by P2.5-A-03, whose own comment in
`resources/views/partision/header.blade.php:62-75` records the pre-fix symptom precisely:

> Measured in the running Arabic admin: this rendered `Amina Haddad!مرحبًا` — the exclamation mark
> is a bidi-NEUTRAL character, so in an RTL paragraph it resolves against the paragraph direction
> rather than against the Latin run it belongs to, and lands on the wrong side of the name.

`tests/Unit/Ui/HeaderGreetingBidiTest.php` has four passing tests asserting the name is isolated
and the `!` is outside the isolate. Those read the blade source, so the rendered order was measured
separately, in the running Arabic admin, at `dir: rtl`:

| run | painted left | painted right |
|---|---:|---:|
| `مرحباً،` | 1088 | 1124 |
| `Amina Haddad` | 994 | 1088 |
| `!` | 989 | 994 |

Read right-to-left, as an RTL paragraph is read: greeting, then name, then `!`. The mark is at the
**end of the phrase**, which in RTL is the left edge of the line. That is correct, and it is what I
mistook for a defect by reading the screenshot left-to-right.

**No change was made.** The authorisation to fix it does not make a fix correct, and editing this
would break a working implementation that already carries its own regression tests.

## 5. Captures

`after/` holds **52 PNGs** at 1440×900 plus `render-matrix.json`, all distinct: thirteen routes ×
four combinations (en-ltr and ar-rtl × light and dark), each with the document direction and the
design-system theme attribute asserted against what was asked for.

Reproduce:

```bash
docs/development/phase-2.5/tools/serve-dev.sh &
node docs/development/phase-2.5/tools/render-matrix.cjs --base http://127.0.0.1:8899 --out <dir> --paths /taxes,/menus
```
