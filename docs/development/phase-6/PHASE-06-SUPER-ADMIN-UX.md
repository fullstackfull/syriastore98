# Phase 06 — premium Super Admin UX, slice 1

`GAP-179` / `P06-06`. Measured 2026-11-26.

> **This is engineering evidence, not a status claim and not an acceptance.** `CLAUDE.md` §4.1 names
> four states that must never be collapsed into one label. Nothing here is authorised to merge or
> release, and the requirement is **not** closed by this slice — §4 lists, by name and by count,
> what remains.

The requirement is *"premium Super Admin UX"*: the super-admin surfaces should reach the bar Phase
2.5 established for merchant surfaces, bilingual and contrast-checked. Phase 2.5 was explicitly
merchant-scoped — *"Premium Merchant Experience … premium UI/UX across merchant web and Flutter"* —
and its 295-row matrix covers merchant screens, so no operator screen has ever been measured.

This slice does two things, in this order: it **establishes the surface and measures it**, and it
**fixes the part of what it found that is this slice's**. The register row asked for the Phase 2.5
harness and its four-combination evidence rule to be reused rather than a second standard invented,
and they are: the state tool is Phase 2.5's `set-admin-preview-state.php`, and the contrast walk is
`census-low-contrast-text.cjs`'s compositing and WCAG arithmetic.

---

## 1. The surface, established by measurement

**Enumerated from the route table, not by eye.** `php artisan route:list --json` filtered on
`App\Http\Middleware\PlatformOperatorOnly` gives **37 routes**, of which **19 are GET**; removing
the export and per-record action endpoints (`platform/analytics/export`, `order_approve/{id}`,
`refund/{id}/{user_id}`, the Lynomia POST/DELETE operations, the two `zid/{storeId}` closures)
leaves the page-rendering ones. To those are added the screens production's `super admin` role is
granted — `App\Models\Utility::addNewData()`'s `$superadminNewPermission`, 29 names — because those
are the shared screens whose *content* for the operator is the platform's own data.

**24 surfaces, in three tiers:**

| tier | what it is | n |
|---|---|---|
| **A** | operator-exclusive: carries `PlatformOperatorOnly`, so no merchant can reach it and Phase 2.5 cannot have covered it | 8 |
| **B** | shared `layouts.app` chrome, operator content: every tenant's stores, the subscription plans, the platform's own settings, the Add-on Manager | 13 |
| **C** | modal fragments: `@extends`-less partials that `data-ajax-popup="true"` loads into a dialog | 3 |

Tier A: `/platform/revenue`, `/platform/resources`, `/platform/analytics`,
`/platform/documentation`, `/platform/site-content`, `/platform/site-content/{page}`,
`/plan-coupon`, `/super-admin/lynomia-integration`.

Tier B: `/dashboard` (the operator branch, `superadmin/dashboard.blade.php`), `/plan`,
`/plan/create`, `/plan-request`, `/stores`, `/stores/create`, `/users`, `/setting`, `/countries`,
`/currency`, `/modules/list`, `/modules/add`, `/profile`.

Tier C: `users/create`, `plan-coupon/create`, `store/user-create`.

### 1.1 Three things the enumeration found that a guess would not

**(a) THE OPERATOR'S SIDEBAR HAS THREE ITEMS.** Signed in as `operator@example.test` on the
untouched dev fixture, the whole navigation is Dashboard, Lynomia Chat Integration and Add-on
Manager. The sidebar is permission-driven and `CLAUDE.md` records that there is no super-admin
bypass: `isAbleTo()` alone refuses the platform operator. So the operator's reachable surface is
far wider than the operator's *navigable* surface, and crawling the chrome would have found 3
screens where the route table finds 24.

**(b) THE DEV FIXTURE'S OPERATOR HOLDS NO ROLE AT ALL, AND THAT MAKES A HARNESS LIE.**
`database/seed-p2dev.php` creates the operator with `type = 'super admin'` and no Laratrust role;
the fixture's two roles both belong to the merchant. Six operator screens then answer
`redirect()->back()` — **HTTP 200, a fully rendered page, and it is the PREVIOUS screen**:

```
/plan-coupon        -> 200, final URL /platform/site-content
/plan-coupon/create -> 200, final URL /platform/site-content
/modules/list       -> 200, final URL /super-admin/lynomia-integration
/plan               -> 200, final URL /super-admin/lynomia-integration
/plan/create        -> 200, final URL /super-admin/lynomia-integration
/plan-request       -> 200, final URL /super-admin/lynomia-integration
```

A harness that checks the status code photographs the predecessor and reports a pass — the failure
`serve-dev.sh` records for the merchant ("eleven distinct images from seventeen, every row
reporting ok"), reproduced on the operator. `tests/Support/bin/grant-dev-operator-role.php` gives
the fixture operator exactly the 29 permissions `Utility::addNewData()` grants in production,
verbatim; it weakens no gate and invents no permission name. With it, 24 of 24 surfaces are
genuinely reachable. The harness compares the final URL with the requested one and counts a
mismatch as a failure, not a pass.

**(c) THE THREE TIER-C FRAGMENTS CANNOT BE MEASURED BY NAVIGATING TO THEM.** A direct GET of
`/users/create`, `/plan-coupon/create` or `/stores/user/create` answers 200 with a form and **no
document chrome at all** — `data-ss-theme` null, body background `rgba(0,0,0,0)`,
`#main-style-link` absent, `lang` and `dir` null. The first baseline run produced twelve premise
failures for them, three surfaces × four combinations, which is the correct outcome for a
navigation and the wrong question. They are opened in their dialog on the parent screen instead,
and the dialog must really contain fields before anything is measured.

---

## 2. The instrument, and the one flag that makes it lie on purpose

`tests/Support/bin/measure-superadmin-premium-live.cjs`. Signs in as the operator, writes the
theme/direction state server-side, and for each surface × combination walks three censuses — body
text (4.5:1, 3:1 for large), control labels (4.5:1), and icon-only controls (3:1, WCAG 1.4.11
non-text) — then captures the page.

**THE ADMIN THEME IS A SETTING, NOT `prefers-color-scheme`.** It is `cust_darklayout` choosing
`style-dark.css`, so Playwright's colour-scheme emulation changes nothing and yields two identical
"light" captures — already recorded here twice. A dark pass must observe `data-ss-theme="dark"`,
the dark host sheet in `#main-style-link`, **and** a body background of exactly `rgb(34,36,44)`; a
light pass must observe none of those. A mismatch refuses to measure.

**That assertion is proved to be falsifiable, which is the point.** `--skip-state` claims the
combination it was asked for and writes nothing:

```
php docs/development/phase-2/tools/set-admin-preview-state.php 1 light ltr en operator@example.test
node tests/Support/bin/measure-superadmin-premium-live.cjs --combos dark-en --skip-state
```

```
measured (surface x combo) 0 of 24
premise failures        24
   ! dark-en /platform/revenue: data-ss-theme=null expected "dark"; body background
     rgb(249, 250, 251) expected rgb(34, 36, 44); main stylesheet .../style.css is not a dark sheet
   ... 23 more
contrast findings       0 in 0 groups
```

Without the premise, that run reports **zero contrast findings** and reads as a pass.

### 2.1 Two counting faults in the harness's own first draft, both corrected

Recorded because both inflate an inventory, which is the one number this slice owes.

- **Anything whose class contains `btn`** reported **141 failing controls** across six surfaces in
  one combination, every one a container (`div.btn-group.card-option`, `div.dataTable-botton`, the
  stores screen's `all-button-box` wrapper — `\bbtn\b` matches inside `btn-group` because the
  hyphen is a word boundary) or an icon-only control whose inherited `color` paints nothing. A
  control is now an interactive element with a button class and its own rendered text.
- **A button with a bare text label qualified for both the text and the control walk**, so the same
  pixel was two findings. The text walk now yields it.

The icon census earned its place twice over, and once by a **negative** result: the icon-only
primary buttons are painted `rgb(255,255,255)` on `rgb(51,93,168)` — 4.68:1 — because the host
overrides the glyph colour, so they are **not** part of the defect and were not "fixed" for credit.
It did find seven real icon failures on `/countries` and `/plan`.

---

## 3. The baseline, and what this slice closed

```
surfaces requested         24
combinations               4 (light-en, dark-en, light-ar, dark-ar)
measured (surface x combo) 96 of 96
premise failures           0
unreachable                0

BEFORE   contrast findings  388 in 21 groups
AFTER    contrast findings   98 in 16 groups
```

Full logs: `evidence/gap-179-superadmin-ux/measure-before.txt` and `measure-after.txt`.

### 3.1 The named defect — the host's primary button at 2.58:1

`public/assets/css/style-dark.css` paints `.btn-primary` as
`--bs-btn-color: var(--theme-color)` over `--bs-btn-bg: var(--third-color)`, which in the dark
admin resolves to **rgb(4,34,42) on rgb(51,93,168) = 2.58:1** against 4.5:1.

It is known and pre-existing: `public/css/store-syria-ds.css:5640` records the same two colours at
2.63:1 on the merchant order grid, and `resources/views/platform/content/edit.blade.php` records
2.58:1 on that screen and had already moved to `.ss-btn--primary`. **That precedent is followed,
not re-argued.** `.ss-btn--primary` reads `--ss-brand` / `--ss-on-brand`, so it is correct in both
themes by construction — measured **7.92:1** in dark (rgb(4,34,42) on rgb(42,195,229)) and
**6.42:1** in light (white on rgb(51,93,168)).

**AND THE DEFECT IS DARK-ONLY, WHICH WAS MEASURED RATHER THAN ASSUMED.** With one control put
back on the host fill and the theme set to light, the host paints it `rgb(255,255,255)` on
`rgb(51,93,168)` — **6.42:1**, which is byte-identical to what `.ss-btn--primary` paints in light,
because `--ss-brand` in a light scope resolves to that same blue. So the two buttons differ in
light not at all; the host's dark sheet swaps the INK to `--theme-color` while keeping a dark-blue
fill, and that single substitution is the whole 2.58:1. It is also why no `.btn-primary` control
failed in either light direction in the baseline, and why the evidence folder keeps dark-only
captures for the three button screens.

**Seven controls were measured failing, in each of the two dark directions — all seven now
7.92:1:**

| screen | control | label |
|---|---|---|
| `/plan/create` | `input.btn.btn-badge.btn-primary.ms-2` | Create |
| `/stores` | `a.btn.btn-sm.btn-primary.btn-icon` | Sub Domain |
| `/stores` | `a.btn.btn-sm.btn-primary.btn-badge.btn-icon` | Custom Domain |
| `/stores/create` | `input.btn.btn-primary.btn-badge` | Create |
| `/modules/list` | `a.btn.btn-primary.btn-badge` | Buy More Add-on |
| `/plan-coupon#create` | `input.btn.btn-badge.btn-primary.mx-1` | Create |
| `/stores#user-create` | `input.btn.btn-badge.btn-primary.mx-1` | Create |

**30 source conversions were made across 12 views, and only 7 of them closed a measured failure.**
The other 23 are the same control class on screens, tabs or branches this fixture does not render —
`/countries`' state and city tabs, `/modules/list`' mobile-app panel, `plans/index`'s
Subscribe / Start Free Trial (merchant-facing branches the operator does not see). They would
measure 2.58:1 identically if reached, which is why converting them is right; **this document does
not claim measured proof for them**, and that distinction is the reason the number is stated twice.

**Five of the 30 went to `.ss-btn--secondary`, not `--primary`**: the AI "Generate" buttons on
`plans/create`, `store/create` and `store/user-create`. `resources/views/category/create.blade.php`
is the precedent for exactly that control and uses `--secondary`, because the screen's real primary
action is its submit button and two primaries on one form is itself the premium-bar failure.

**Three `btn-primary` occurrences were deliberately left, each with an argument** (declared in
`SuperAdminPremiumSurfaceTest::DECLARED_NON_CONTROL_EXCEPTIONS`, all in `plans/index`):

- the active-plan ribbon `<div class="… plan-active-status btn-primary">` carries no `btn` token;
  `btn-primary` is a colour hook for a wrapper, and the chip inside it paints its own ink;
- two `<p class="… btn btn-primary w-100 text-success">Expired</p>` status notices, where
  `text-success` already overrides the ink — so they are not the 2.58:1 defect, and dressing an
  expiry notice as the screen's one primary action would be a worse answer than leaving it. They
  belong on `.ss-badge--danger`, which is a semantic change to a merchant-visible screen.

### 3.2 The Add-on Manager's status chip — 268 of 388 findings

`<span class="badge bg-danger">Disable</span>`, once per module. Measured on the rendered page:
**67 chips in the `Disable` state** and one in `Enable` (the fixture enables `BulkDelete`), so the
danger chip alone accounts for 67 findings per theme per direction:

| | |
|---|---|
| light | rgb(216,0,39) on rgb(255,222,228) — **4.26:1** × 134 (67 chips × 2 directions) |
| dark | rgb(216,0,39) on rgb(129,96,108) — **1.03:1** × 134 |

The host paints `.badge.bg-danger` as a soft tint; on the dark card that tint composites to a muddy
mauve the red ink disappears into. **One span is 69% of every contrast failure on the entire
operator surface.** Now `.ss-badge .ss-badge--danger` / `--success`: measured **9.06:1** in dark
(rgb(243,169,164) on rgb(44,20,19)) and the red-800 / red-50 pair in light. Counted on the
rendered page afterwards: 67 `.ss-badge--danger`, 1 `.ss-badge--success`, **0** `.badge.bg-danger`
or `.badge.bg-success` left.

**`bg-danger` and `bg-success` are REMOVED, not joined** — the one place this project's "add a DS
class, never substitute" rule does not apply. Those utilities carry `!important`, so an
`.ss-badge--danger` beside one changes nothing and the 1.03:1 would survive a change that looked
complete. A mutation pins exactly that: *"module status chip keeps bg-success BESIDE the DS class"*
is CAUGHT.

### 3.3 `/platform/resources` — the storage paths

A bare `<code>`, painted by Bootstrap at `#e83e8c`: **3.65:1** light, **4.05:1** dark. Both fail,
and the dark figure failing by *less* is why it survived — it reads as merely dim, and the light
page was never the one anybody looked at. There is no merchant counterpart to this screen, so
Phase 2.5 could not have found it. Now `.ss-code` (`--ss-fg` over `--ss-surface-sunken`, both
re-theming): grey-900 on grey-100 in light, `#e8eaed` on `#1f232a` in dark. The rule is refused a
hex literal by test, because a literal is precisely what the rule it replaces does wrong.

### 3.4 Per-surface, before → after

`t` = body text, `c` = control label, `i` = icon. Four combinations, left to right:
light-en, dark-en, light-ar, dark-ar.

| surface | before | after |
|---|---|---|
| A `/platform/revenue` | 0/0/0 · 0/0/0 · 0/0/0 · 0/0/0 | unchanged, already clean |
| A `/platform/resources` | 2/0/0 · 2/0/0 · 2/0/0 · 2/0/0 | **0/0/0 in all four** |
| A `/platform/analytics` | 0/0/0 × 4 | unchanged, already clean |
| A `/platform/documentation` | 0/0/0 × 4 | unchanged, already clean |
| A `/platform/site-content` | 0/0/0 × 4 | unchanged, already clean |
| A `/platform/site-content/home` | 0/0/0 × 4 | unchanged, already clean |
| A `/plan-coupon` | 0/0/0 · 1/0/0 · 0/0/0 · 1/0/0 | unchanged (the 1 is the shared sidebar) |
| A `/super-admin/lynomia-integration` | 1/0/0 · 2/0/0 · 1/0/0 · 2/0/0 | unchanged — vendored |
| B `/dashboard` | 0/0/0 · 1/0/0 · 0/0/0 · 1/0/0 | unchanged (shared sidebar) |
| B `/plan` | 6/0/0 · 14/0/1 · 6/0/0 · 14/0/1 | unchanged — §4 |
| B `/plan/create` | 4/0/0 · 5/**1**/0 · 4/0/0 · 5/**1**/0 | 4/0/0 · 5/**0**/0 · 4/0/0 · 5/**0**/0 |
| B `/plan-request` | 0/0/0 · 1/0/0 · 0/0/0 · 1/0/0 | unchanged (shared sidebar) |
| B `/stores` | 0/0/0 · 1/**2**/0 · 0/0/0 · 1/**2**/0 | 0/0/0 · 1/**0**/0 · 0/0/0 · 1/**0**/0 |
| B `/stores/create` | 0/0/0 · 0/**1**/0 · 0/0/0 · 0/**1**/0 | **0/0/0 in all four** |
| B `/users` | 0/0/0 × 4 | unchanged, already clean |
| B `/setting` | 0/0/0 · 1/0/0 · 0/0/0 · 1/0/0 | unchanged — see §3.5 |
| B `/countries` | 0/0/**3** · 1/0/**3** · 0/0/**3** · 1/0/**3** | unchanged — §4 |
| B `/currency` | 0/0/0 · 1/0/0 · 0/0/0 · 1/0/0 | unchanged (shared sidebar) |
| B `/modules/list` | **67**/0/0 · **68**/**1**/0 · **67**/0/0 · **68**/**1**/0 | **0**/0/0 · **1**/**0**/0 · **0**/0/0 · **1**/**0**/0 — the dark 68th, and the 1 that remains, is the shared sidebar |
| B `/modules/add` | 0/0/0 × 4 | unchanged, already clean |
| B `/profile` | 0/0/0 · 1/0/0 · 0/0/0 · 1/0/0 | unchanged (shared sidebar) |
| C `/users#create` | 0/0/0 × 4 | unchanged, already clean |
| C `/plan-coupon#create` | 0/0/0 · 0/**2**/0 · 0/0/0 · 0/**2**/0 | 0/0/0 · 0/**1**/0 · 0/0/0 · 0/**1**/0 |
| C `/stores#user-create` | 0/0/0 · 0/**1**/0 · 0/0/0 · 0/**1**/0 | **0/0/0 in all four** |

### 3.5 A conversion with no measured effect, found by identical captures

`setting/index.blade.php:283` carried `class="btn btn-primary"` and was converted with the rest.
The before and after full-page captures of `/setting` then came back **byte-identical** —
sha256 `5c348c85daf08704` on both sides, dark-en and dark-ar. The reason is in the markup: that
button sits inside `<div id="delivery-syria-content" style="display: none;">`, so it has never been
rendered. It measured 0×0 in the browser, it was never among the failing controls, and the
conversion changed not one pixel. The visible "Save Changes" on that screen is fetched into the tab
panel by AJAX from a different file and was already on `.ss-btn--primary` from Phase 02.

**Those two identical captures are kept in the evidence rather than quietly dropped, because the
identical hash is the finding.** It is also why `tests/Feature/Platform/SuperAdminPrimaryActionRendersTest.php`
exists beside the source census: a class on markup nobody sees satisfies a source census and
changes nothing on screen.

---

## 4. What this slice did NOT fix — 98 findings, every one named

Nothing here is deferred by an owner decision; all of it is open.

| n | surfaces | finding | why not in this slice |
|---|---|---|---|
| **22** | 11 (`/plan-coupon`, `/super-admin/lynomia-integration`, `/dashboard`, `/plan`, `/plan-request`, `/stores`, `/setting`, `/countries`, `/currency`, `/modules/list`, `/profile`) | **dark 2.58:1**, rgb(4,34,42) on rgb(51,93,168) — `span.dash-mtext` (the ACTIVE SIDEBAR ITEM) and `a.list-group-item…active` (the settings tab rail). The same colour pair as the named defect: the host uses `--third-color`/`--theme-color` for active states too | **This is `D-25`.** Both live in `layouts/app.blade.php` and `partision/sidebar.blade.php`, which render on every merchant screen and across all 70 vendored modules. The owner's standing instruction reserves the general theme change; a Phase 06 slice must not take it |
| **40** | `/plan`, `/plan/create` | `.text-danger` at pure `rgb(255,0,0)` — **4:1 light, 3.56:1 dark**. Fails in BOTH themes | Merchant-visible plan screens; small and safe, but it is a second colour decision and belongs with the rest of the plan card below rather than split across two slices |
| **12** | `/plan` | rgb(128,129,145) on rgb(41,42,51) — **3.71:1** dark, on the plan card's figures ("Unlimited", "Products", "Store", "Users"). The dark theme's own muted grey, the same colour Phase 2.5 recorded | same — the plan card wants one pass, not four |
| **12** | `/countries` | the DataTables tool buttons: `btn-light-danger` **2.66:1 / 1.30:1**, `btn-light-info` **1.73:1 / 1.15:1**, `btn-light-warning` **1.74:1 / 1.16:1** (light / dark, icon floor 3:1) | **The repair already exists and is reuse, not new work:** `.ss-dt-card-body` fixes exactly these on merchant grids. `country/index.blade.php` carries ~100 lines of its own inline `<style>` for this table, so wrapping it interacts with all of that and needs its own capture round |
| **4** | `/super-admin/lynomia-integration` | `span.badge.bg-warning` **1.68:1** light; `a.nav-link.active` **2.41:1** dark | `packages/workdo/LynomiaChat` — first-party per `CLAUDE.md`, but another workstream's file |
| **2** | `/plan` | `li.d-flex` rgb(51,93,168) on rgb(41,42,51) — **2.22:1** dark ("-1MB Storage") | with the plan card. Note the string itself is also wrong: a plan with unlimited storage prints `-1MB` |
| **2** | `/plan/create` | `span.input-group-text.bg-transparent` **1.1:1** dark (the "MB" suffix) — the body's default ink on a dark card | with the plan card |
| **2** | `/plan` | `a.btn.btn-sm.btn-info` glyph: white on rgb(232,248,255) — **1.09:1** dark, against the 3:1 icon floor | the host's soft `btn-info` in dark, the same family as the `/countries` tools |
| **2** | `/plan-coupon#create` | `button.btn.btn-outline-primary` "Generate" — **3.07:1** dark, rgb(120,148,198) on the dialog's white sheet | `.ss-btn--secondary` is the repair; the dialog stays white in dark by the Phase 2.5 A-01/A-02 decision, so this needs that decision re-read rather than assumed |

**Also measured and recorded rather than fixed, outside the 98:**

- **`resources/views/layouts/app.blade.php:894`** builds a product-attribute control by string
  concatenation inside a `<script>`:
  `'<a href="#" class="btn btn-sm btn-primary add_attribute btn-badge" …'`. It is a `btn-primary`
  on a control a **merchant** uses, in the one file that reaches all 70 vendored modules — `D-25`
  again. It is in the HTML of every admin page as JavaScript source and paints nothing on an
  operator screen (`document.querySelectorAll('.btn-primary').length` really is 0 on
  `/platform/resources`), which is why the Feature test strips `<script>` blocks before asserting.
- **`/currency-rates` answers HTTP 500** on the dev fixture (79,883-byte page for `/currency`,
  512,126-byte error page for `/currency-rates`). It is a GATE F table and not a UX finding;
  recorded so the next reader does not take it for one.
- **The body's own default foreground in dark is `rgb(41,50,64)` on `rgb(34,36,44)` — 1.09:1.**
  Every legible figure on a dark admin page is legible because something overrode it. Any element
  that forgets to is invisible, which is the mechanism behind two of the rows above.

---

## 5. Evidence

| | |
|---|---|
| the instrument | `tests/Support/bin/measure-superadmin-premium-live.cjs` |
| the fixture's operator role | `tests/Support/bin/grant-dev-operator-role.php` |
| source census + arithmetic | `tests/Unit/Ui/SuperAdminPremiumSurfaceTest.php` — 14 tests, 103 assertions |
| rendered-page contract | `tests/Feature/Platform/SuperAdminPrimaryActionRendersTest.php` — 4 tests, 26 assertions |
| before / after logs | `evidence/gap-179-superadmin-ux/measure-{before,after}.txt` |
| captures | `evidence/gap-179-superadmin-ux/{before,after}/` — see that folder's `README.md` for which combinations are kept and why |

### 5.1 Failing first, then reverted

`SuperAdminPremiumSurfaceTest` on the unchanged tree: **8 premises pass, 5 ratchets fail, 1
arithmetic test passes.** The premises passing first is what makes the ratchets worth anything —
the defect's mechanism and the fix's basis are both measured before either is touched.

Then every half of the fix was reverted through `tests/Support/bin/mutate.py` (which restores mtime
and runs `view:clear`, because a stale compiled Blade reverses a mutation result):

```
CAUGHT   store/index "Custom Domain" back on the host primary fill
CAUGHT   setting/index "Save Changes" back on the host primary fill
CAUGHT   plan-coupon/create submit back on the host primary fill
CAUGHT   module/index "Buy Now" back on the host primary fill
CAUGHT   currency/index primary action deleted rather than converted
CAUGHT   module status chip back on the host bg-danger utility
CAUGHT   module status chip keeps bg-success BESIDE the DS class (the !important trap)
CAUGHT   platform/resources path back to a bare <code>
CAUGHT   .ss-code declares a literal hex instead of a token
CAUGHT   the DS primary button stops reading the brand token
ALL CAUGHT

CAUGHT   platform/resources path back to a bare <code> (rendered, not source)
CAUGHT   the content editor regresses to the host primary fill
CAUGHT   the content editor emits a btn-primary somewhere on the page
CAUGHT   the page-shell premise is fed a screen that is not the admin shell
ALL CAUGHT
```

**The last mutation of the first set SURVIVED on its first run, and that is the entry worth
reading.** `test_premise_the_design_systems_primary_button_reads_the_brand_tokens` asserted only
that `var(--ss-brand)` appeared somewhere in the block — and `.ss-btn--primary` declares that token
twice, on `background` and on `border-color`. Replacing the **fill** with a literal `#335da8`,
which is exactly the host colour this slice exists to get away from, left the border's token behind
and the test passed. A ratchet that cannot tell a fill from a border would have let the defect back
in under its own fix. The assertion now matches by property and refuses a hex in the block at all.

### 5.2 The live harness, reverted too

One control put back on the host fill, `view:clear`, re-measured:

```
before revert  /stores dark-en  control-fail=0/5  .btn-primary=0  .ss-btn--primary=5   findings 1
after  revert  /stores dark-en  control-fail=1/5  .btn-primary=1  .ss-btn--primary=4   findings 2
               CTRL 2.58:1 (needs 4.5) rgb(4,34,42) on rgb(51,93,168)
                    a.btn.btn-sm.btn-primary.btn-badge.btn-icon  "Custom Domain"
restored       /stores dark-en  control-fail=0/5  .btn-primary=0  .ss-btn--primary=5   findings 1
```

The remaining 1 is the shared sidebar's active item, §4's first row.

### 5.3 Suites run

| | |
|---|---|
| `tests/Unit/Ui` (1,563 tests) | **pass**, 10,827 assertions |
| `tests/Feature/Platform` (289) | **pass** |
| `tests/Feature/Ui` + the 5 affected `Feature/Merchant` files (87) | **pass** |
| `tests/Feature/Security` (5 affected files) + `Feature/Tenancy/BulkDeleteAuthorizationTest` (100) | **pass** |
| `tests/Unit/Saas` + `tests/Unit/Dashboard` (23) | **pass** |
| `tests/Unit/Merchant/ArabicUiCoverageTest` (3) | **pass** |

The full Unit, Contract and Feature suites were **not** run here; they are the integration gate,
not a slice's.

---

## 6. Bilingual

Every combination was measured in **ar/rtl** as well as **en/ltr**, and the Arabic captures are
part of the evidence. **This slice adds no new user-facing string**: every label it touched was
already `__()`-wrapped, and the changes are class attributes and one CSS rule. `ArabicUiCoverageTest`
passes unchanged.

The converted controls were read back in Arabic — "إضافة كوبون", "إضافة عملة", "النطاق الفرعي",
"نطاق مخصص", "عرض القائمة", "إنشاء مستخدم جديد", "إنشاء خطة جديدة", "احفظ التغييرات",
"إنشاء بلد", "أوقف" — at the same 7.92:1 / 6.42:1 as the English ones, which is expected and was
checked rather than assumed.

`.ss-code` sets `unicode-bidi: isolate` and **no `dir`**: a filesystem path is Latin inside an
Arabic sentence, and isolation is what stops a trailing slash jumping to the wrong end of the line
in the RTL build.
