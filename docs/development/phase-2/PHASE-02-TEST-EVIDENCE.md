# Phase 02 — test evidence

Every claim below names the command, the engine it ran on, and the output. A claim with no command
under it is not in this file. Where something could **not** be run, that is stated instead of being
worked around.

---

## 0. What has NOT been run, and why it matters most

**The PHPUnit suites have not run in this container at all.** Not the Unit suite, not Feature, not
Contract, and not `.github/scripts/assert-known-test-outcomes.php`.

### 0a. The Composer blocker, investigated to the bottom

The locked coordinates, read from `composer.lock` rather than inferred:

| package | version | source | dist |
|---|---|---|---|
| `yoomoney/yookassa-sdk-php` | 3.7.1 | `https://git.yoomoney.ru/scm/sdk/yookassa-sdk-php.git` | `https://git.yoomoney.ru/rest/api/latest/.../archive?at=refs%2Ftags%2F3.7.1&format=zip` |
| `yoomoney/yookassa-sdk-validator` | 1.0.2 | `https://git.yoomoney.ru/scm/sdk/yookassa-sdk-validator-php.git` | `https://git.yoomoney.ru/rest/api/latest/.../archive?at=refs%2Ftags%2F1.0.2&format=zip` |

**Both routes for both packages resolve to the same host**, and that host is denied:

```
$ curl ...  "https://git.yoomoney.ru/rest/api/latest/projects/SDK/repos/yookassa-sdk-validator-php/archive?..."
curl: (56) CONNECT tunnel failed, response 403
$ curl ...  "https://git.yoomoney.ru/"
curl: (56) CONNECT tunnel failed, response 403
$ curl ...  "https://repo.packagist.org/packages.json"        # control
HTTP=200
```

The proxy records it as a policy denial by name:

```
connect_rejected | git.yoomoney.ru:443 | gateway answered 403 to CONNECT (policy denial or upstream failure)
```

Four things were checked before concluding, because "blocked" is a claim that should cost
something to make:

1. **`composer.json` defines no custom VCS repository** for these packages — only the
   `packages/workdo/*` path repo. The coordinates come from packagist, which merely *indexes*
   metadata; the artefacts live on yoomoney's own server.
2. **No other version avoids the host.** All **94** versions packagist indexes for
   `yoomoney/yookassa-sdk-php` carry a `git.yoomoney.ru` dist and source. Pinning a different
   version is not an escape.
3. **`--prefer-source` was tried, and it is the right thing to try** — it uses the lock's own
   recorded source URLs and references and modifies nothing. It got through **every other
   package** and then failed on yookassa by source *and* by dist:
   ```
   fatal: unable to access 'https://git.yoomoney.ru/scm/sdk/yookassa-sdk-php.git/': CONNECT tunnel failed, response 403
       Now trying to download from dist
   curl error 56 while downloading https://git.yoomoney.ru/rest/.../archive?...: CONNECT tunnel failed, response 403
   ```
4. **`dev.azure.com` was a false alarm.** Three other packages were also absent —
   `myfatoorah/laravel-package` and `myfatoorah/library` (Azure DevOps, **source only, no dist at
   all**) and `symfony/polyfill-php56`. `git ls-remote` against `dev.azure.com` **succeeds**, so
   those were simply not reached before Composer aborted. **`git.yoomoney.ru` is the single blocked
   host.**

### 0b. Why there is no partial-install workaround either

`vendor/` contains 232 of 237 locked package *directories* — and **every one of them is empty**.
Composer downloads to its cache first and installs at the end, so aborting during download leaves
the directory tree and nothing in it. Reconstructing `vendor/composer/installed.json` from the lock
and running `composer dump-autoload` does produce an autoloader, but it autoloads only the
application's own 457 classes, and `php artisan --version` still dies with
`Class "Illuminate\Foundation\Application" not found`. Those diagnostic artefacts were removed
again; `composer.json` and `composer.lock` are untouched.

The cache does hold **233 VCS mirrors (7.2 GB)** from the attempts, so the install would complete
in minutes once the host is reachable.

### 0c. The exact allowlist requirement

> **Allow `git.yoomoney.ru` (443).** Environment settings → Network access → Allowed domains, with
> "Allow package managers" left ticked. Steps:
> <https://code.claude.com/docs/en/cloud-environments#network-access>

Nothing else is needed: every other host in the dependency graph already resolves.

**Re-confirmed 2026-10-25**, unchanged: `curl https://git.yoomoney.ru/` → `CONNECT tunnel failed,
response 403`; the control `curl https://repo.packagist.org/packages.json` → `200`. The proxy's own
`/__agentproxy/status` still lists `connect_rejected … gateway answered 403 to CONNECT … host:
git.yoomoney.ru:443`.

**What was deliberately NOT done:** no mirror substituted, no `composer.lock` edit, no version
change, no removal of YooKassa, no payment-SDK replacement. Phase 01 retired the YooKassa
*provider* and deliberately kept the *package*; dropping the dependency is an owner decision with a
release consequence, not a way around an allowlist.

### 0d. What this means for everything below

The evidence in this file is a contrast checker, a real browser, `php -l`, `node --check` and the
Flutter toolchain. That is genuinely strong for presentation, RTL, theming and accessibility — a
browser rendering the real stylesheets — and it says **nothing** about routing, authentication,
tenancy enforcement at runtime, the yajra DataTables stack, or the controller queries.

Those remain unverified here.

---

## 1. Design-system contrast — 58 pairs, light and dark

```
$ python3 docs/design-system/tools/check-contrast.py public/css/store-syria-ds.css
...
=== AUTO SCOPE vs DARK SCOPE ===
  identical (35 tokens)

ALL 58 PAIRS PASS (light + dark)
```

Was 40 pairs before Phase 02; 9 pairs were added for the new components (29 pairs × 2 themes = 58).

**It found two real failures on the first run, and both were fixed rather than excused:**

| Failure | Measured | Cause | Fix |
|---|---|---|---|
| `stat period on page background` | **4.40:1** < 4.5 | The stat grid sits on `--ss-bg` (`#f6f8f8`), not on the white card. `--ss-fg-subtle` is the lightest neutral clearing 4.5:1 **on white**. | The period uses `--ss-fg-muted`; the hierarchy is carried by size and weight instead of colour. |
| `workspace accent bar on surface` | **2.70:1** < 3.0 | `--ss-brand-accent` was `--ss-teal-600`. The token had existed since Sprint 01 but **no component consumed it**, so its value had never been through the checker. | Changed to `--ss-teal-700` (**3.91:1**). An accent bar carries meaning, so WCAG 1.4.11 puts it on the 3:1 non-text threshold. |

## 2. Pre-existing design-system suite — no regression

```
$ php -S 127.0.0.1:8099 -t public docs/design-system/tools/preview-server.php &
$ node docs/design-system/tools/browser-checks.cjs
================  66 PASSED, 0 FAILED  ================
```

The Sprint 01 suite, unchanged, re-run against the Phase 02 stylesheet and the extended harness.

## 3. Preview-harness change is backwards compatible — proved, not assumed

The harness gained Blade-component support and per-screen fixtures. The pilot screen must render
exactly as before:

```
$ git stash -q -u   # old harness + old css
$ php .../render-preview.php resources/views/attribute_option/index.blade.php en full pilot-OLD.html
$ git stash pop -q  # new
$ php .../render-preview.php resources/views/attribute_option/index.blade.php en full pilot-NEW.html
$ diff pilot-OLD.html pilot-NEW.html
11c11,12
< <link rel="stylesheet" href="/css/store-syria-ds.css?v=p01b-3">
---
> <link rel="stylesheet" href="/css/store-syria-ds.css?v=p02-1">
> <script defer src="/js/store-syria-ds.js?v=p02-1"></script>
88c89,90   (whitespace only)
```

Whitespace-normalised, ignoring the two asset lines: **IDENTICAL**, in English and in Arabic.

## 4. Phase 02 browser checks — 70, on real Chromium

```
$ node docs/development/phase-2/tools/browser-checks-p02.cjs
Chromium 153.0.8010.12
...
79 PASSED, 0 FAILED
```

What they actually drive:

| Group | Checks | What is asserted |
|---|---|---|
| Horizontal overflow | 48 | 6 variants × 8 widths (360…1440). Narrow widths go through an iframe so the media queries evaluate at the true width. |
| Data honesty | 5 | every stat states a period; the body contains no "revenue" or "profit" claim; the order-value figure is labelled "all time, gross" and carries its disclaimer. |
| Empty + permissions | 9 | the attention block is **absent** when nothing needs attention; a new store still sees its zero totals; a staff member with no order permission gets **no "Add product" control, no order-value figure, no money figure at all, and no link the controller would refuse**, their status chips are static `<span>`s rather than dead buttons, and the counts that were visible *before* Phase 02 still are; a merchant **with** `Manage Order` does see the figure. |
| RTL | 3 | `dir="rtl"`; the accent bar's `border-inline-start` resolves to the **right** edge (`right=3 left=1`); the first stat cell starts at the right edge. |
| Money | 4 | the currency figure stays on one line at 360px and 390px, in English and Arabic (`h=35 lineHeight=35`). |
| Keyboard + a11y | 4 | every design-system control shows a focus ring (13 controls); tab order reaches the attention actions before the status chips; no control has an empty accessible name; the attention block is a labelled `<section>` landmark. |
| Dark layout | 2 | the page follows the host's dark layout (`data-ss-theme="dark"`); the dark card sits above the dark page (`rgb(38,51,49)` vs `rgb(34,36,44)`). |

### 4a. The money check bites — and the first thing I thought was the fix was not

The project rule is that a regression test must fail when its fix is removed. Both candidate fixes
were reverted in turn:

```
revert  overflow-wrap: break-word -> anywhere      -> 70 PASSED, 0 FAILED   (does NOT bite)
revert  phone grid 1 column -> 2 columns           -> 66 PASSED, 4 FAILED   (BITES)
         FAIL  money figure stays on one line @360px -- h=105 lineHeight=35 "SYP 1,284,500.00"
```

So the operative fix is the **one-column phone layout**, not the `overflow-wrap` value. `break-word`
is kept as defence in depth for a longer currency string, and this file says plainly that the check
does not prove it. Had the bite test not been run, `break-word` would have been reported as the fix
and it would have been wrong.

### 4a-bis. The permission gate bites too

```
remove the @if ($ssMayOrders) around the order-value stat  -> 73 PASSED, 2 FAILED
  FAIL  ungated staff do NOT see the order-value figure  -- Order value
  FAIL  ungated staff do NOT see a money figure at all   -- SYP 1,284,500.00
```

The failure output is the disclosure itself, which is the useful property: the
check does not merely notice a missing element, it prints the number that
leaked.

### 4a-ter. The reconciliation check bites, and its failure output IS the defect

```
revert the breakdown to the six statuses it used to show  -> 76 PASSED, 3 FAILED
  FAIL  the status breakdown sums to the order total
        -- breakdown=1272 total=1284
  FAIL  the rendered chips themselves sum to the total
        -- chips=[12,48,21,1164,3,24] sum=1272 total=1284
```

**Twelve orders invisible**, on a screen that shows a total of 1,284 beside six counts a merchant
would reasonably read as a partition of it. The check reads the chips and the total **off the
rendered page**, not from the fixture, so it measures what a merchant would see.

### 4b. One check was corrected rather than left green

The focus-ring check first failed on `"Home"` — a link in the **harness's stand-in chrome**, outside
`.ss-scope`. The design system never styles a bare element selector, so an opt-in stylesheet cannot
give that control a ring. The assertion was scoped to `.ss-scope`, and the host-chrome controls are
printed as a `NOTE` instead of being silently dropped. The finding is recorded in the ledger as F3.

## 4c. Independent tenant/authz review — and the defect it found in my own work

The `tenant-authz-reviewer` subagent reviewed the slice before it was committed, as CLAUDE.md
requires for any change touching a query, tenancy or a permission. It came back **clean on
cross-tenant access, caller-supplied identifiers, permission bypass and output escaping**:

- the new `$out_of_stock_products` count keeps `store_id = getCurrentStore()` as a **conjunct** —
  both `orWhere` calls are inside closures, so neither makes the store predicate optional, which
  was the specific failure mode to check;
- `getCurrentStore()` derives from the authenticated user, never from request input, and no new
  view reads `$request`, `request()`, `->query()` or `old()`;
- `@permission` is a real Laratrust directive resolving through `hasPermission()`, **not** through
  Laravel's Gate, so it is unaffected by `permissions_as_gates => false` (unlike `@can`, which
  would be silently false);
- no `{!! !!}` was introduced in any of the 16 new Blade files.

**But it found a real defect in my change, and it was mine, not pre-existing.** Verified
independently before acting:

```
$ git show HEAD:resources/views/dashboard.blade.php | grep -c total_revenues
0
$ sed -n '400,402p' routes/web.php
  Route::middleware(['auth', 'xss', 'setlocate', 'verified'])->group(function () {
    Route::get('/dashboard', ...)->name('dashboard');
```

`$total_revenues` sat in `HomeController`'s `compact()` with **no consumer** — the old dashboard
never rendered it. `/dashboard` carries no permission middleware. So putting the figure on this
screen newly showed **store-wide gross order value to every authenticated member of the tenant**,
including a staff member whose role grants nothing about orders. A within-tenant disclosure
widening, introduced by Phase 02.

Fixed by gating that one stat on `Manage Order` — the same permission name
`OrderController::mayUseOrderScreen()` checks, so the UI gate and the server gate agree about who
may see order money. The figure is **omitted entirely** rather than shown as zero or "unavailable":
the data exists and the viewer may not see it, and those are different statements.

The reviewer's second finding (links rendered to principals the controller would refuse) was also
acted on: stat links, attention actions and status chips now drop their `href` when ungated, and a
chip with no destination renders as a static `<span>` rather than a button that does nothing.

## 4bis. How to reproduce every browser run in this file

Recorded because re-running these from a clean session was **not** trivial, and two of the three
difficulties produced a misleading result rather than an error:

1. **`file://` silently loads no stylesheets.** A probe pointed at a rendered preview on disk
   reported the browser's default link blue on bare white and *passed* every contrast variant at
   9.40:1. `/assets/css/style.css` resolves at the filesystem root there. Always serve through
   `preview-server.php`, and assert the sheet count: the probe prints `5 stylesheets` per page.
2. **`browser-checks.cjs`'s `orig-*` baseline is NOT `git show 5c63bcea:…index.blade.php`.** That
   path renders 19,872 bytes containing **zero** `list-group-item`, and the suite then dies in
   `getComputedStyle` on a null element. The baseline is the pre-design-system pilot view, kept
   outside the repo; rendered from it, the same files produce 56,931 bytes and 25 rows.
3. **A missing preview looks like a failing check.** Without `new-en-noperm.html` the suite reports
   `FAIL records still listed without write permissions — 0`, which reads as a permission-gating
   regression and is actually an absent fixture. Render all four names it references by literal
   (`new-ar-full`, `new-en-full`, `new-en-noperm`, `orig-en-full-dark`) plus its `VARIANTS` array —
   grepping only for quoted names without `.html` misses `new-en-noperm`.

```bash
SS_PREVIEW_DIR=<dir> php -S 127.0.0.1:8099 -t public \
    docs/design-system/tools/preview-server.php &

# Phase 02 suite: dashboard-after x {en,ar} x {full,empty,noperm} x {light,dark}
#                 -> after-<locale>-<state>-<theme>.html
#                 components -> comp-en-light, comp-ar-light, comp-en-errors
#                 sidebar-nav -> nav2-<locale>-<theme>-<full|bgoff|noperm>.html
php docs/design-system/tools/render-preview.php <view> <en|ar> <state> <out.html> <light|dark>
```

Counts, all executed on Chromium 153 at the head this file documents:

| suite | result |
|---|---|
| `docs/development/phase-2/tools/browser-checks-p02.cjs` | **110 PASSED, 0 FAILED** (was 100; +10 sidebar legibility) |
| `docs/design-system/tools/browser-checks.cjs` (pre-existing) | **66 PASSED, 0 FAILED** — unchanged by the CSS fix |
| `docs/design-system/tools/check-contrast.py` | **58 pairs pass**, light and dark |
| `tests/Support/Security/bin/verify-menu-render-parity.php` | **17 checks**, all match |
| `tests/Support/Security/bin/verify-order-chart-operator-branch.php` | **9 PASSED, 0 FAILED** |

## 4d. Second independent review — three findings, two settled, one closed with a new guard

The `tenant-authz-reviewer` ran again over P2-01-10 and P2-02-12 before commit. It called (a) the
menu refactor **clean** — `menuTree()` does no authorization and no tenant read, the gates stay in
`Menu::add()` (`app/Classes/Menu.php:18-22`), `generateMenu()` has no caller outside `helper.php`,
and the only two other writers of the registry both end in the untouched `generateSideMenu()`. It
also confirmed the **latency claim in both directions**: `$chartData` reaches only
`superadmin/dashboard.blade.php` and `theme_analytic.blade.php`, the latter is fed by
`ThemeAnalyticController::getStoreOrderChart` and not this method, and `getOrderChart` is **not
routed** — so there was no JSON surface either.

**Its one precision correction is accepted and recorded.** My monotonicity claim holds for
`PlanOrder` — new set `{isOperator}` ⊂ old set `{type !== 'admin'}`, so no principal can newly
receive platform subscription data. But staff now *newly execute* the two `Customer` queries at
`HomeController.php:539-549`: their own store's registered and guest counts, same-tenant, still
discarded by the view, at a cost of **+14 queries per staff dashboard load**. "No principal newly
receives data" would have been an overclaim in the opposite direction, so it is not made.

**Finding 2 — the parity script's "verbatim" copy — was unverifiable for the reviewer (no Bash) and
is now settled.** It named the exact way it could be wrong: the copy emits the icon bare while
`generateSideMenu()` wraps it in `<span class="dash-micon">`, so if the real pre-change
`generateMenu()` had wrapped it too, the new partial would have dropped a styling hook and the
script would stay green *because both sides were wrong*. Checked:

```
$ git show 5c63bcea:app/Helper/helper.php | sed -n '/^    function generateMenu(/,/^    }$/p'
        $html .= '<i class="ti ti-' . $item['icon'] . '"></i>
        <span class="dash-mtext">';
```

No wrapper — it belongs to `generateSideMenu()` alone. A body diff after normalising indentation
found **three differences, all deliberate and necessary**: `__()` → `__t()` (the stub; `__()`
cannot be redeclared), `generateMenu()` → `generateMenu_old()` (so the copy recurses into itself),
and a trailing newline. The HTML string literals are byte-identical. That check is now written into
the script beside the claim. The probing also surfaced one real markup difference worth naming: the
old builder opened `<span class="dash-mtext">` only for top-level items but appended `</span>` for
**every** item, so each submenu link carried an unmatched closing tag. Both libxml and the browser
discard it, so the DOM trees are equal across that difference rather than in spite of it — now
recorded as the partial's third deliberate change.

**Finding 3 — `getOrderChart` had no regression guard at all — is closed.** The reviewer
established that reverting the fix would fail *nothing*: no test references `getOrderChart`, and
`OperatorCheckCensusTest`'s construct assertion covers three **named** files (HomeController is not
one) with regexes keyed on `'super admin'`/`PLATFORM_ADMIN` — while the removed line compared
against `'admin'`, so even adding HomeController to that list would not have caught it. A PHPUnit
test is the natural home but cannot be **executed** here (§0), and an unrun test is a claim, not
evidence. So the guard went where CLAUDE.md §3 says such evidence goes:

```
$ php tests/Support/Security/bin/verify-order-chart-operator-branch.php
9 PASSED, 0 FAILED
```

It asserts the **branch structure**, not a mention — "does the file say `isOperator`" is the
assertion that already let a drift hide once (`OperatorCheckCensusTest.php:120-128`). **Bitten three
ways:**

| mutation | result |
|---|---|
| the real pre-fix `HomeController` from `5c63bcea` | **3 FAILED**, exit 1 |
| discriminator inverted, arms left alone (semantically wrong) | **1 FAILED**, diagnostic reads `non-operator arm: Order=no PlanOrder=yes` |
| `if ($isOperator)` with the arms swapped too (semantically **equivalent**) | **9 PASSED** — no false alarm |

That third row is the reason the check accepts both spellings. A first version keyed only on
`if (! $isOperator)` and would have failed a guard on correct code, with a message
("no `if (! $isOperator)` with an else was found") that sent the reader hunting for a missing else.

**Recorded, not acted on**, per the reviewer's own judgement: `HomeController::index():139` still
dispatches on the literal `$user->type == 'super admin'` while `:530` now normalises, so for a
hand-edited `'Super Admin'` row the two disagree — doubly latent (the merchant view discards
`chartData`, and `RolePolicy::RESERVED_NAMES` blocks the role path), but one more instance of the
pattern P1-SEC-11 exists to end, in a file the census does not cover. Ledger **F10**.

**The standing gap it is right to repeat:** no Blade has been *executed*. `MenuTreeParityTest` is
unrun, and both green artefacts use stand-in Blade compilers, so view-finder resolution, real
`@include` recursion and compiled-view caching have **zero** execution behind them. The Unit suite
has no known-outcome allowance (`phpunit.xml:8-10` gates Feature only), so a first-run error there
is a hard CI break. **Run `php artisan test --testsuite=Unit` before this slice is relied on.**

## 5. Visual evidence — 33 screenshots, reviewed

16 in `p2-02-dashboard/`, 10 in `p2-01-components/`, 7 in `p2-01-sidebar-nav/` (§5a).

`docs/development/phase-2/screenshots/p2-02-dashboard/`, real Chromium 153 at
`deviceScaleFactor: 2`, full-page at desktop widths and true-viewport at 390px.

Desktop 1440 (before/after × en/ar × light/dark), the empty state, a view-only employee, mobile 390
(before/after × en/ar, plus Arabic dark) and tablet 834.

**Reviewing them caught three defects that reading the code did not:**

1. **Slot and attribute HTML rendered as escaped text.** `{{ $slot }}` and
   `{{ $attributes->merge(...) }}` were being escaped, so every nested component appeared as visible
   markup (`class=&quot;ss-stat&quot;`). Laravel does not escape `Htmlable` values; the harness had
   no such concept. Fixed with a `SafeHtml` marker that `e_()` honours.
2. **The money figure broke across three lines** in Arabic at 390px. Fixed (§4a).
3. **The attention row actions wrapped inconsistently** — inline on short rows, stacked on long
   ones, which reads as a rendering fault. The action is now always full-width on its own line at
   phone width.

A fourth problem was in the evidence itself: the first fixture rendered the Arabic figure in
**Arabic-Indic digits**, which the real `currency_format_with_sym()` never produces — it formats
with `number_format` separators and a configurable symbol. That screenshot would have shown a
product that does not exist, so the fixture was corrected to Latin digits with a post-positioned
`ل.س`. That case also exposed the genuine bidi hazard it models, and `unicode-bidi: isolate` (the
CSS equivalent of `<bdi>`) was added to `.ss-num`, `.ss-stat__value` and `.ss-attention__count`.

### 5a. The sidebar nav — and the screenshot that was wrong in my own favour

`docs/development/phase-2/screenshots/p2-01-sidebar-nav/`, 7 shots: desktop 1440 en/ar x
light/dark, a narrowly-scoped employee, mobile 390 Arabic, and one that exists only to record a
defect (`07-…-custthemebg-off.png`).

Reviewing the first Arabic shot showed navigation labels that were almost invisible. A Chromium
probe put a number on it: **1.25:1**, `rgb(51,51,51)` on `rgb(28,35,47)`. Before reporting that as
a defect I checked whether my own harness was telling the truth, and it was not — twice over.

**First false reading.** The probe was re-pointed at `file://` for convenience. Every label came
back `rgb(0,0,238)` on `rgb(255,255,255)` and the worst ratio in the run was a comfortable
**9.40:1** — *all six variants passed*. That is the browser's default unvisited-link blue on a bare
white page: `file://` resolves `/assets/css/style.css` against the filesystem root, so **no
stylesheet had loaded at all**. A run that reported six passes had measured nothing. Re-run through
`preview-server.php`, which serves `public/`, the probe reports `5 stylesheets` per page — and the
numbers change completely.

**Second false reading, and the real one.** `partision/sidebar.blade.php:15-19` renders one of
*two* navs depending on `$setting['cust_theme_bg']`, and that setting defaults to **`'on'`** in
four places (`Utility.php:44`, `helper.php:1106` and `:1181`, `DefaultSetting.php:32`, plus
`app.blade.php:17` when the stored value is empty). `'on'` adds `transprent-bg`, which sets
`background: transparent`. My preview had hard-coded the `else` branch, so it was showing a
combination a merchant does not get by default. Measured over the preview server:

| `cust_theme_bg` | mode | label on background | ratio | |
|---|---|---|---|---|
| `on` (**default**) | light | `#333333` on `#f8f9fd` | **12.01:1** (en) / **12.63:1** (ar) | passes |
| `on` (**default**) | dark | `#808191` on `#22242c` | **4.03:1** | **fails** AA |
| `off` | light | `#333333` on `#1c232f` | **1.25:1** | **fails** badly |
| `off` | dark | `#808191` on `#292a33` | **3.71:1** | **fails** AA |

`.dash-link` is `15px/500`, so the 4.5:1 threshold applies, not the large-text 3:1.

So the 1.25:1 was my harness picking the wrong branch — **not a defect this slice introduced**; the
preview now mirrors the real conditional and the screenshots are re-taken on the default branch.
But three combinations genuinely fail, the dark default among them. They are **pre-existing host
chrome** — P2-01-10 changed markup and not one colour — and they are **not fixed here**, for a
reason worth stating: the design-system stylesheet cannot reach them.
`layouts/includes/store-syria-ds.blade.php` is included **per page**, while the sidebar comes from
`layouts/app.blade.php` on *every* page, so a DS rule would have made these screenshots look fixed
while production stayed broken. A real fix edits `public/css/custom.css` and
`public/assets/css/style-dark.css`, where the dark value is `color: #808191 !important` on a
selector list **shared with `p` and `.dataTable-info`** — admin-wide across all 70 modules, needing
`!important` and explicit exclusion of `.active`/`:hover`/`:focus` (which set `#fff`) in four theme
branches plus the `minimenu` and `collapse-sidebar` variants. **With Composer blocked (§0) the
Feature suite cannot run**, so that change currently has no regression evidence available. Recorded
as ledger finding **F8** and proposed as its own slice.

## 6. Translations

```
renderer output, all 12 dashboard variants (en/ar × light/dark × full/empty/noperm):
  "all strings translated"
```

`resources/lang/ar.json` and `en.json` each gained **22 keys**, verified **append-only**:

```
resources/lang/ar.json: changed=0 removed=0 added=22
resources/lang/en.json: changed=0 removed=0 added=22
```

That check exists because the first attempt re-serialised the files and **silently changed an
existing entry** — `"Symbol"` went from `"إشارة العملة"` to `"الرمز"` — because `ar.json` contains
17 duplicated keys and re-serialising drops the earlier one. It was reverted and redone as a text
append. The duplicate-key finding is recorded in the ledger as F2.

## 7. Flutter — measured, with a real SDK

Phase 0 recorded "whether Repo B compiles at all" as a blocked check. **That check is now closed.**

```
$ flutter --version
Flutter 3.41.2 • channel stable • Dart 3.11.0

$ flutter analyze --no-fatal-infos
248 issues found. (ran in 70.0s)      exit 0
```

**248 info · 0 warning · 0 error.** Top rules: `prefer_const_constructors` 93, `unnecessary_this`
91, `deprecated_member_use` 23, `use_super_parameters` 12.

### 7a. Re-run at the Phase 02 Flutter head (`99d85f4`)

```
$ flutter analyze
246 issues found. (ran in 32.4s)
$ flutter analyze | grep -cE '^\s+(error|warning) .'
0

$ flutter test
00:23 +1189: All tests passed!
```

**246 info · 0 warning · 0 error · 1189 tests pass.** The three Phase 02 Flutter commits
(`flutter_localizations` wiring, the demo-server avatar fetch, the icon-button accessible names)
are in this run. The count moved 248 → 246 because the avatar change removed a `DefaultImages.`
reference and with it an `unused_import`.

Worth recording about that a11y commit: a **first version of the sweep matched the project's own
`CommonIconButton(` and `SmallCommonIconButton(`** — their names end in `IconButton(` — and tried
to pass them a `tooltip` they do not accept: **36 analyzer errors**. The real count of icon-only
controls was **14, not 30**. The guard is now `(?<![A-Za-z])IconButton\(`, and
`test/a11y/icon_button_names_test.dart` includes an anti-vacuity case that fails if the regex ever
stops matching a real `IconButton` or starts matching `CommonIconButton`.

Caveat, stated rather than hidden: `flutter pub get --enforce-lockfile` **fails** on this SDK
(`Unable to satisfy pubspec.yaml using pubspec.lock`); a plain `pub get` resolves by moving five
SDK-pinned packages. That is an artefact of the SDK version available here, and the resulting
`pubspec.lock` change is **not committed**.

## 8. Syntax

```
$ php -l   on every changed PHP and Blade file            -> No syntax errors detected
$ node --check public/js/store-syria-ds.js                -> clean
$ python3 -c "import json; json.load(...)"  on both lang files -> valid
```
