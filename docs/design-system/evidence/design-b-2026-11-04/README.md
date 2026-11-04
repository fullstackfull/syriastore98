# P2.5-DESIGN-B — the merchant shell: brand, login, and the board that pushed the page sideways

The second Premium Design work package, after P2.5-TYPE (typography). Owner instruction of
2026-11-04: *"continue the actual visual improvements across the full merchant experience"*, with
*"real BEFORE/AFTER screenshots for every significantly changed screen"* and *"do not count contrast
fixes alone as comprehensive premium redesign."*

---

## 1. What was wrong, measured before anything was changed

### The brand block was empty on every screen

The sidebar's `<img>` resolved to `/storage/uploads/logo/logo-dark.png`, which answered **404**. The
element reported `naturalWidth 0`, `naturalHeight 0`, a `0×0` box — on all four screens probed, in
both themes. So the first ~75px of the sidebar was nothing at all. This is the **normal state of a
new tenant**: `Utility::GetLogo()` reads the merchant's `logo_dark`/`logo_light` *setting*, and a
store that has never uploaded one is pointed at a file that was never shipped.

Three defects sat in that one line of markup:

| | |
|---|---|
| the fallback file does not exist | a 404 on every page, in a `<img>` with no dimensions |
| `?timestamp=' . time()` | a fresh query string per request, so the one image on every screen was the one guaranteed never to be cached |
| `alt=""` on the only content of a link | an image-only link with an empty alt has **no accessible name**, so the first control in the tab order announced as nothing |

### `! empty($company_logo)` is not "the merchant uploaded a logo"

The first draft of the repair tested exactly that and **still rendered the 404**. `get_file()`
returns `url($path)` for local storage with no existence check, so a setting naming a file that was
never uploaded yields a perfectly well-formed URL to nothing. The probe caught it: all four
combinations still reported `naturalWidth 0`. The local file is stat-ed now; remote storage
(s3/wasabi) cannot be stat-ed from a view and is trusted.

### The login screen was the worst screen in the product

Measured before the change:

```
body font-family : "Open Sans", sans-serif   <- and Open Sans is NO LONGER DELIVERED, because
                    P2.5-TYPE removed the runtime Google @import from assets/css/style.css.
                    The login page rendered in whatever generic sans the device had, with no
                    Arabic typeface at all.
store-syria-ds   : NOT LOADED.  None of the design system, none of the typography.
brand image      : naturalWidth 0, in a 49x26 box -- a broken image
external request : fonts.bunny.net (a Google Fonts mirror), on every load
console          : 404 on the logo
<html dir>       : "" in LTR, so [dir="ltr"] rules never matched
<html theme>     : no data-ss-theme at all, so every design-system component rendered LIGHT
<title>          : config('app.name', 'Laravel')  -- the word "Laravel" in the browser tab
```

**A CORRECTION TO MY OWN TYPOGRAPHY REPORT.** P2.5-TYPE reported *"external Google requests:
NONE"*. That was measured on the merchant **admin** screens and is true there, but I did not state
the scope, and it is not true of the product. Nine more views still link an external font host at
runtime: `layouts/guest.blade.php` (bunny.net — fixed here), `order/shippinglabel.blade.php`,
`plans/request`, `plans/cybersourcerequest`, `plans/Braintreepayment`, `payment/authorizenet`,
`midtras/payment`, `front_end/layouts/app` and `marketplace/marketplace`. The last two are Phase 03
and 06 surfaces; the rest are recorded as open.

### The Arabic order board pushed the whole page sideways

First seen as a screenshot in which the Arabic kanban sat **scrolled right**, with the sidebar
off-screen and every heading clipped. It is **not an RTL defect** — measured identically in both
directions at a 1440px viewport:

```
document scrollWidth 1735 against a 1440 viewport      -- +295px
.ss-board        rect 1145, clientWidth 1145, scrollWidth 2008, overflow-x: auto
                 -> the board scrolls correctly and clips its own columns
.dash-container  clientWidth 1185, scrollWidth 1480     -- the overflow starts HERE
hiding .ss-board              -> 1480 drops to 1185
hiding .ss-board__card-move   -> 1480 drops to 1185
hiding .ss-select inside it   -> no change
```

**My first attribution was wrong and the measurement refuted it.** I wrote that "the kanban board
overflows"; the board is well-behaved. The escapee is the move control's **visually hidden label**.
`.ss-visually-hidden` is `position: absolute` with `auto` offsets, so it sits at its static position
relative to the nearest *positioned* ancestor — and the board, its columns and its cards were all
`position: static`, which made each label's `offsetParent` `.dash-container`, **outside the scroll
container**. An absolutely positioned box is not clipped by an ancestor's `overflow` unless that
ancestor is its containing block. 57 such labels were on the page; the furthest had a right edge at
**1735px**, which *is* the document width.

---

## 2. What changed

| file | change |
|---|---|
| `public/assets/images/brand/` | the real Store Syria lockup (light + dark), the bag/cart mark, a 64px favicon, a 180px touch icon, a multi-size `.ico` — all derived from `docs/design-system/brand/store-syria-logo-reference.png` |
| `resources/views/partision/sidebar.blade.php` | the brand block: existence-checked merchant upload, Store Syria fallback, mtime cache key, named link, mark for the collapsed rail |
| `resources/views/layouts/guest.blade.php` | the design system reaches the login page; bunny.net removed; favicon and brand fixed; real `dir` and `data-ss-theme`; `platform.brand_name` replaces the `Laravel` / `E-CommerceGo` literals |
| `public/css/store-syria-ds.css` | `.ss-brandmark` component; `.ss-board__col { position: relative }`; the hazard recorded where `.ss-visually-hidden` is defined |

**The brand artwork is the real logo, not an interpretation.** It is cropped from the reference and
quantised (mean channel delta **0.38**, imperceptible; ~10 KB per lockup, 2 KB for the favicon). The
dark variant recolours **20,700** neutral wordmark pixels to white and lightens **5,048** blue
tagline pixels by 45%, keeping alpha so the antialiased edges still blend. The mark's blue-to-cyan
gradient is untouched in both: it is the identity, and it reads on both surfaces.

**What was deliberately NOT changed.** The login button is green (`#10B77F`), not the brand blue —
and that is `var(--theme-color)`, a **merchant-configurable** WorkDo setting whose default is
`theme-3`. Overriding it would change every merchant's chosen colour, which is WorkDo business
behaviour and outside this authorisation. P2-A-05 already fixed the *text* contrast on that fill.

---

## 3. Verification

### The login page, driven through its own controls

`layouts/guest.blade.php` reads `getSuperAdminAllSetting()` and `app()->getLocale()` — **not** the
merchant's settings. **So my first login capture varied nothing**: it used
`set-admin-preview-state.php`, which writes the merchant's row, and produced the same English
light page four times while reporting four combinations. Corrected: language comes from
`/change-languages/<code>` and RTL follows it; dark follows the super admin's `cust_darklayout`.

```
en/ltr/light  html-lang=en theme=-    font="IBM Plex Sans Arabic"  lockup--light 116x38  "Log in to your account"
ar/rtl/light  html-lang=ar theme=-    font="IBM Plex Sans Arabic"  lockup--light 116x38  "تسجيل الدخول إلى حسابك"
en/ltr/dark   html-lang=en theme=dark font="IBM Plex Sans Arabic"  lockup--dark  116x38  "Log in to your account"
ar/rtl/dark   html-lang=ar theme=dark font="IBM Plex Sans Arabic"  lockup--dark  116x38  "تسجيل الدخول إلى حسابك"

external requests: NONE (all four)        4xx/5xx: none (all four)
```

### The admin, 8 screens × 4 combinations

32 pairs, **32 changed**, mean **3.31%**, max **32.94%** — table in `pixel-diff.md`. The two Arabic
board rows at 21% and 33% are the overflow repair; the rest is the brand block appearing.

After the fix, `/order-kanban`, `/dashboard`, `/product-list` and `/order-grid` all report
`scrollWidth == viewport` in both directions. Before it, the board overflowed by **+295px** in both.

### Bites

| what was reverted | what went red |
|---|---|
| `.ss-board__col { position: relative }` | the ratchet, **and** the live page: +295px returns in both directions |
| the brand existence check | all four combinations back to `naturalWidth 0` |

### Not verified

* **Headless Chromium on one machine at 1440×900.** No device matrix, no real handset.
* **No Arabic native-reader review.** RTL correctness here is structural — direction, mirroring,
  clipping, font delivery — not editorial.
* **`/order-reports` still answers 500 on its chart partial** (`/order-reports-chart`), before and
  after. Pre-existing and separately tracked.
* The collapsed-rail mark is styled but **not driven** in these captures: the rail is a stored
  preference and the matrix does not toggle it.

---

## 4. Design skills applied

| skill | what it actually changed here |
|---|---|
| `ui-ux-pro-max` | its priority order chose the work. #1 Accessibility produced the named brand link and the focus ring the host sheet never gave the first control in the tab order; #3 Performance produced the mtime cache key in place of `time()`; #5 Layout & Responsive — *"no horizontal scroll; ensure content fits viewport width"* and *"avoid nested scroll regions that interfere with the main scroll"* — is what made the board overflow a defect rather than a quirk |
| `frontend-design` | *"spend your boldness in one place"*: the brand lockup is the one bold element in the shell and everything around it stays quiet. It is also what rejected a hand-drawn SVG mark in favour of the real artwork — a redrawn logo is an interpretation, and the brief says *the actual* identity |
| `design-system` | the three-layer discipline: `.ss-brandmark` is a component with its own tokens, the theme swap keys on the same `data-ss-theme` every other component uses, and the `!important` is documented against the measured host rule it beats rather than applied defensively |
| `ui-styling` | theming and dark-mode discipline transferred; its component library (shadcn + Tailwind) does not apply to Blade + vendored Bootstrap and was not used |

**Specificity over force, wherever force is avoidable.** Two host rules had to be beaten:
`custom.css:629` `.brand_icon { width: 100% !important }` — only `!important` outranks `!important`,
whatever the specificity, so `inline-size` carries one; and `custom.css:722`
`.navbar-brand img { height: 20px }` at (0,1,1) — beaten by making the rule (0,2,0) with a parent
class, no `!important` needed.
