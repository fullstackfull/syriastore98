# Phase 02 — test evidence

Every claim below names the command, the engine it ran on, and the output. A claim with no command
under it is not in this file. Where something could **not** be run, that is stated instead of being
worked around.

---

## 0. SECTION 0 IS OBSOLETE — the suites run now

> **This section is kept, not deleted.** It is the record of what was true before the owner opened
> `git.yoomoney.ru`, and the reasoning in it is still the reasoning that identified the blocker.
> Everything it says is unavailable is now available, and §9 below carries the measured output.

**WHAT CHANGED.** `composer install --no-interaction --prefer-dist --no-scripts` completes from the
**existing lockfile**, exit 0, with YooKassa installed and `composer.lock` untouched. GitHub
*archive* downloads still answer 403 at the proxy, so Composer falls back to `git clone --mirror`
for most packages — about twenty minutes and ~7 GB of cache, and lock-faithful, which is what
matters. Nothing was removed, no payment SDK was swapped, and the lockfile was not edited to make
anything install.

So the sentence this section opened with — *"the PHPUnit suites have not run in this container at
all"* — no longer holds, and neither does anything downstream of it.

---

<details>
<summary><strong>The original §0, as written under the blocker</strong></summary>

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


</details>

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
| `docs/development/phase-2/tools/browser-checks-p02.cjs` | **159 PASSED, 0 FAILED** (100 → 110 legibility → 137 zones/chip/rail → 148 orders chart → 159 keyboard, rail and the two dashboard lists) |
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

## 5. Visual evidence — 47 screenshots, reviewed

23 in `p2-02-dashboard/`, 10 in `p2-01-components/`, 14 in `p2-01-sidebar-nav/` (§5a, §5b).

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

### 5b. P2-01-11 — four defects that only a screenshot found

The sidebar zones, workspace chip and collapsed rail passed `php -l`, rendered without error, and
measured correctly in a DOM probe while being visibly wrong. Each of these was caught by opening the
PNG:

1. **The rail toggle was an empty box.** `ti-layout-sidebar-left-collapse` is not in this build of
   the icon font — `public/assets/fonts/tabler-icons.min.css` has no such rule — so the button
   rendered at the right size, in the right place, containing nothing. A probe reported it
   `display: block, visibility: visible`. Changed to `ti-layout-sidebar`, which exists.
2. **The toggle's icon was pinned on top of the search magnifier.** The host positions the
   magnifier with a *descendant* selector, `.sidebar-input i { position: absolute; left: 10px }`,
   which also captured the `<i>` inside the new button. Measured: both icons at `x=46`, the button
   itself at `x=285`.
3. **The toggle was clipped outside the sidebar.** `.sidebar-input .form-control` is `width: 100%`,
   so in a flex row it took everything and pushed the 30px button past the 280px edge — row
   `x=36 w=279`, button `x=285`, sidebar ending at 316.
4. **The chip was drawn inside a white box with a teal edge, on a dark sidebar.** A class-name
   collision: `store-syria-ds.css:1539` *already defined* `.ss-workspace`, built in P2-01-1 for
   exactly this chip, and the new CSS in `custom.css` collided with it.

The fourth is the one worth keeping. **The component already existed and was rebuilt by accident** —
accent, ellipsis, print rule and all. The fix was not to rename the duplicate but to delete it and
use the real one, which then exposed the actual blocker: `store-syria-ds.css` shipped through a
**per-screen** include while the sidebar is rendered by the layout on **every** screen. So the
stylesheet is now loaded from `layouts/app.blade.php`.

That is a change to all 70 WorkDo modules, so it was not made on an assumption. Every selector in
the file was enumerated: all of them carry an `.ss-` class except `:root`, `*`, keyframes, and two
`[data-ss-theme=…]` blocks — and those two contain **35 declarations each, zero of them anything but
a custom property**. No markup in the application uses an `.ss-` class. The pre-existing DS suite
re-ran at **66 PASSED, 0 FAILED** afterwards.

Using the real component also produced a correctness fix the duplicate had got wrong: `.ss-navgroup`
carries a comment refusing `text-transform: uppercase` because *capitals are meaningless in Arabic*
and an ALL-CAPS eyebrow label is a template tell. The duplicate had used uppercase.

**A measured AA failure came out of the same move.** `.ss-navgroup` used `--ss-fg-subtle`
(grey-500). On the design system's own `--ss-surface` (`#ffffff`) that is 4.69:1 and passes, which
is why the 58-pair contrast checker never flagged it — **the checker verifies tokens against DS
surfaces**. The sidebar is a *host* surface, `#f8f9fd`, where the same token measures **4.46:1** —
under AA by 0.04. Changed to `--ss-fg-muted` (grey-600): 5.65:1. The new browser checks measure the
rendered pixels on the real surface, which is how it surfaced at all.

### 5c. The sidebar checks, and what they are really protecting

`browser-checks-p02.cjs` went from 100 to **137 checks, 0 failed**. Most of the new ones are not
about appearance. The sidebar search at `layouts/app.blade.php:426` selects
`.dash-navbar > .dash-item` with a **direct-child** combinator, so the obvious way to group items —
wrapping each zone in a container — breaks search *with no error*. The checks therefore assert the
DOM contract and then **drive the search's own algorithm** over the rendered page rather than
reasoning about it.

Bitten three ways, each the mistake it exists to catch:

| mutation | result |
|---|---|
| wrap each zone's items in a `<div>` | **3 FAILED** — "every navbar child is an `<li>`", "nav entries are still DIRECT children", "a search query still matches real items" |
| drop `dash-item` from the zone heading | **1 FAILED** — headings would stop hiding during a search |
| revert `.ss-navgroup` to the 4.46:1 token | **1 FAILED** — at 1440px light only, naming the measured pair |

That last row matters: the same check passes at 390px and in dark mode, so a single-viewport or
single-theme run would have reported it clean.

### 5d. Third independent review — seven defects, and one the review got wrong

The `tenant-authz-reviewer` ran over P2-01-11 before commit. It confirmed the parts I was least sure
of — `menuZones()` is a pure regrouping that cannot surface a gated item; `switchableStores()` could
never be broader than `changeStore()` for any shape of `users.type`; the design system's global load
cannot restyle host markup — and then found **seven defects, four of them functional**. All are
fixed; the fixes are below because several are instructive.

**1. The store switcher's keyboard path was dead and threw.** The JS queried
`.ss-workspace__option` — a class that exists nowhere. It was left behind when the chip was
refactored onto the design system's `.ss-menu__item`. `options()` returned `[]`, so ArrowDown opened
the list and focused nothing, and arrowing inside it evaluated `opts[(i+1) % 0]` → `opts[NaN]` →
`TypeError`. Mouse users saw a working menu. **Three browser checks now drive the keyboard.**

**2. A shipped Unit test's premise had moved.** `MenuTreeParityTest:78` asserts exactly 2 direct
`.dash-item` children; a zone heading is itself a `.dash-item` (by design — that is what makes the
search hide it), so the count became 3. The counts were **not** loosened to `>=`: that assertion is
what stands between a future wrapper element and a silently dead sidebar search. The flat case now
pins `zones_enabled => false`, and a second case covers the zoned rendering with items and headings
counted separately. Its expected numbers were verified by driving the real `menuZones()` against the
real config — 2 items, 1 "More" heading — since PHPUnit cannot run here.

**3. The operator's sidebar was being zoned with a merchant-shaped map.** `getMenu()` passes
`$parent = null` for everyone, so `SuperAdminMenuListener`'s entries were zoned too — and only
`plan` and `settings` are in the map, while the operator's landing entry is `dashboard`, not
`admin_dashboard`. Result: "Administration" drawn first and the operator's own Dashboard, Users,
Plan Request, Country and Currency under a heading called **"More"**, on every page. Zoning is now
skipped for the operator.

**4. The new literal broke the project's own ratchet.** Mirroring `changeStore()`'s
`type !== 'super admin'` matched `OperatorCheckCensusTest`'s `strict against the literal` pattern,
whose `CEILING` may only fall. The resolution was not to reword it — dodging the regex is what the
ratchet exists to prevent — but to **stop asking the type question at all**: `switchableStores()`
now scopes by `created_by = creatorId()` for everyone. For an operator `creatorId()` returns their
own id, so they see the stores they created, which is **strictly narrower** than `changeStore()`
accepts. That also removed an unbounded `Store::where('is_active',1)->get()` that hydrated every
store on the platform on every operator page render.

**5. The chip rendered dead on two real surfaces.** `store-syria-nav.js` was loaded only in
`footerlink.blade.php`'s `@else` arm, so a tenant with `SidebarCustomization` active got a "Switch
store" button with nothing to open it. And `layouts/main.blade.php` renders the sidebar but takes
its head from `partision/head.blade.php`, which loaded neither the script nor the design system —
and an undefined `.ss-visually-hidden` does not fail quietly: **"Current store:" and "Collapse menu"
become ordinary visible text**, on nine live super-admin screens. Both files now load both assets.

**6. `?v=` was not bumped** after `store-syria-ds.css` changed, so any browser holding the file from
an earlier Phase 02 slice would have served the chip and the rail unstyled. All six references are
now `p02-2`.

**7. Three claims in my own comments were false** and are corrected in place, because each was the
justification on record for something: that nothing linked to `change.store` (a header switcher has
shipped all along — ledger F11); that "every selector in the design system carries an `.ss-` class"
(`.ss-sort-list .ui-sortable-placeholder` does not, though it is still descendant-scoped and
unreachable outside the DS); and the stale `app.blade.php:358-398` pointer to the sidebar search,
which is at **`:421-461`** — swept across all eleven copies in code, tests and docs.

**The one the review got wrong, and it is worth recording.** It reported the collapsed rail as a
one-way trip: the toggle hid itself under `.minimenu` while the state persisted to localStorage, so
one click would strand the merchant permanently. The reasoning was sound from the CSS alone, and I
fixed it. But the browser disagreed — a bite that re-added the hide rule still **passed**. The
measurement says why: the host sets `#vertical-nav-toggle { display: block !important }` in
`style.css`, at top level *and* inside `@media (min-width: 1025px)`. An ID selector carrying
`!important` beats anything a class rule can say, so the trap was never reachable — **and my own
`.ss-rail-toggle { display: grid; place-items: center }` was equally inert**, which no one had
noticed because the icon happened to look centred for an unrelated reason. The component is now
centred as the block box the host forces (`line-height` + `text-align`, measured: 6px either side),
and the check is bitten with a rule that *can* win rather than one that cannot.

That is the second time in this phase a static reading and a measurement disagreed, and the second
time the measurement was right.

### 5e. P2-06 onboarding — built, measured, and deliberately not shipped

The getting-started checklist is complete and correct in LTR. Under `dir="rtl"` the preview paints
each step's mark **twice** — once in its own box and once at the far inline-end of the row. It is
recorded here rather than quietly fixed or quietly shipped, because what makes it interesting is
how much evidence says the markup is right:

| question | answer |
|---|---|
| marks in the DOM | exactly **one** per row (3 marks, 3 rows) |
| `getClientRects()` | **one** rect, at the correct position |
| box-shadow / transform / filter / reflection | none |
| pseudo-elements on the row, link, mark or label | none |
| rules matching the mark | its own, plus two `box-sizing` rules |
| hiding the mark | removes **both** circles |
| LTR | renders **one** |

And it is unchanged by every structural variant tried: icon font → CSS `border-radius` → inline
SVG; `position: static` vs `relative`; `display: flex` vs `grid`; `overflow: hidden` on the row;
removing the label's `flex`.

That exhausts what this harness can answer. The remaining candidate is **the harness itself** — it
loads `style-rtl.css` and `rtl-custom.css` into a stand-in layout rather than the real one — and
separating a harness artefact from a product defect needs the real application, which §0's Composer
blocker prevents. This session has already produced two cases where a static reading and a
measurement disagreed and the measurement won; this is the first where the measurement is
self-contradictory, and the honest answer is that I do not know which side is wrong.

**So it is not rendered.** The wiring is written and commented out in `dashboard/overview.blade.php`
with the full trail, so shipping it is a one-block change the moment it can be seen in the real
app. The alternative — shipping it because the DOM looks right — would put a visibly duplicated
mark on the first screen a new Arabic-speaking merchant sees, and "the DOM is correct" is not a
defence a merchant can read.

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

---

# 9. THE RUNTIME CYCLE — everything §0 said could not be run

Laravel head `d1bb94ee`, Flutter head `2774a76d`. PHP 8.3.6, Laravel 11.47.0, PHPUnit on SQLite
`:memory:` with `CACHE_STORE=array` (`phpunit.xml`); the browser runs drive a real `artisan serve`
on a seeded SQLite file. Flutter 3.47.8 / Dart 3.13.7.

## 9a. The PHPUnit suites, first execution in this phase

```
$ composer install --no-interaction --prefer-dist --no-scripts
  ... exit 0.  vendor/yoomoney/{yookassa-sdk-php,yookassa-sdk-validator} present.
  git diff composer.lock -> empty

$ php artisan test --testsuite=Unit
  Tests:  363 passed (2012 assertions)

$ php artisan test --testsuite=Contract
  Tests:   26 passed (83 assertions)

$ php artisan test --testsuite=Feature \
      --log-junit storage/logs/feature-junit.xml \
      --log-teamcity storage/logs/feature-teamcity.txt
  Tests:  5798 passed, 3 failed, 17 skipped (29134 assertions)   [FIRST run]

$ php .github/scripts/assert-known-test-outcomes.php \
      storage/logs/feature-junit.xml .github/known-test-outcomes.json \
      storage/logs/feature-teamcity.txt
  failures observed : 3  (manifest declares 2)
  ::error::NEW FAILURE, not in the manifest:
      SecurityLogInventoryTest::test_the_observability_inventory_matches_the_source
```

**FINAL run, at head `b314cca1`, after the inventory was re-pointed and every slice in this
cycle was in the tree:**

```
$ php artisan test --testsuite=Feature \
      --log-junit storage/logs/feature-junit.xml \
      --log-teamcity storage/logs/feature-teamcity.txt
  Tests:  5814 passed, 2 failed, 17 skipped (29174 assertions)
  Duration: 1101.50s

$ php .github/scripts/assert-known-test-outcomes.php \
      storage/logs/feature-junit.xml .github/known-test-outcomes.json \
      storage/logs/feature-teamcity.txt

  testcases observed   : 5833  (manifest floor 1000)
  failures observed    : 2  (manifest declares 2)
  errors observed      : 0  (always expected: 0)
  skips observed       : 17  (manifest declares 17)

  OK: the observed outcome set matches the manifest exactly -- 2 declared failures,
      17 declared skips, 0 errors, and nothing undeclared.
```

The two failures are the manifest's own — `RegistrationTest::test_new_users_can_register` and
`ProfileTest::test_profile_page_is_displayed`. **The gate's own closing words are worth repeating
rather than paraphrasing:** *"This is NOT a statement that the suite is green. It is a statement
that the suite is failing and skipping exactly the tests the project has recorded, for the reasons
it has recorded."*

**The extra failure was mine, and it was the inventory doing its job.** Three security log lines in
`OrderController.php` moved when `order_preview()` was inserted above them, and
`P01-OBSERVABILITY-INVENTORY.md` is keyed on `file:line`. Re-pointed with the repository's own tool
rather than by hand:

```
$ python3 docs/development/phase-1/tools/resync-observability-inventory.py --write
  rows re-pointed : 9      rows unchanged : 120      documented : 129

$ php artisan test tests/Feature/Security/SecurityLogInventoryTest.php \
      tests/Feature/Tenancy/ResidualTenancyExposureTest.php \
      tests/Feature/Tenancy/EloquentScopeBypassInventoryTest.php \
      tests/Feature/Security/StoredFilePathSafetyTest.php
  Tests: 106 passed
```

**The 17 skips matched the manifest exactly on the first run**, in both directions.

## 9b. The suites the brief named individually

```
$ php artisan test --filter OperatorCheckCensusTest
  Tests: 19 passed (33 assertions)

$ php artisan test tests/Unit/Navigation/MenuTreeParityTest.php     # the REAL Blade engine
  Tests:  8 passed (28 assertions)

$ php artisan test tests/Feature/Security/OrderDocumentAccessTest.php
  Tests: 49 passed (77 assertions)

$ php artisan test tests/Feature/Security/ReportAuthorizationTest.php
  Tests: 25 passed      # 24 report routes refused without the permission, then admitted with it
```

`OrderDocumentAccessTest` had never executed. It is the suite that proves the order-document
guard — encrypted and raw ids, merchant and shopper, a doubled principal, and the guard's presence
in each target's resolved middleware stack.

## 9c. Real DataTables behaviour — both legs

`tests/Feature/Merchant/DataTableRuntimeTest.php`, 6 cases, 33 assertions.

yajra chooses its leg on `$this->request()->ajax() && $this->request()->wantsJson()`
(`laravel-datatables-buttons/src/Services/DataTable.php::render`), so a drive setting only one of
the two headers silently takes the HTML branch and returns 200 with no rows — the shape of a test
that passes while measuring nothing. Both headers are set and **every case asserts the DataTables
envelope is present as its premise** before claiming anything about the rows.

| case | result |
|---|---|
| the product list renders its DataTable scaffold | pass (404 and 500 excluded explicitly) |
| the AJAX leg returns the caller's own rows | pass, `recordsTotal = 1` |
| the AJAX leg does not serve another tenant's catalogue | pass — foreign row asserted **still present** via `DB::table()`, so "excluded" is distinguished from "absent" |
| the order DataTable's AJAX leg is tenant-scoped | pass |
| staff without `Manage Products` get no envelope and no product data | pass (302, not JSON) |
| the **same** staff member gets both once granted | pass |

`Product` is not in `scoped_models` and registers no effective scope, so
`ProductDataTable::query()`'s own `where('store_id', getCurrentStore())` is the **only** thing
keeping one merchant's catalogue out of another's list. That is what these measure.

## 9d. Security and tenancy regressions added this cycle

| file | cases | what it pins |
|---|---|---|
| `CustomSidebarAuthorizationTest` | 19 | F4's gate at its seams, F5's escaping, the route-existence drop, the iframe branch, the arrow-after-drop |
| `CustomSidebarRenderPathTest` | 3 | **`getSideMenu()` itself** — the gate's production call site |
| `OrderPreviewAuthorizationTest` | 9 | the new preview endpoint, with its two controls measured **separately** |
| `MerchantSearchAuthorizationTest` | 11 | the global search: cross-tenant, per-section permission, unresolved tenant, LIKE wildcards, a non-string `q` |
| `TenantSwitchTest` | 12 | F12 — a GET answers 405 having moved nothing; CSRF asserted structurally |
| `ProductConcurrentEditTest` | 5 | a stale save is refused and A's work survives — asserted on the RESPONSE BODY, because a session assertion passed against a refusal shape the page could not read |
| `ProductPreviewAuthorizationTest` | 8 | the new product preview endpoint: `Product` carries no effective scope, so the handler's predicate is the only control |
| `OrderStatusDateTest` | 4 | F19 — the order list does not render the Unix epoch as a date, and keeps the status label when the date is missing |
| `TablePreferencesTest` | 8 | P2-06-4's wiring on the shared helper that builds all 68 DataTables, that yajra emits the hooks as real JS, the absent-script guard, and three real lists still rendering |
| `MerchantNotificationAuthorizationTest` | 25 | P2-06-3: four handlers, two of them writes; cross-tenant on all four; per-TYPE permission; the operator admitted while holding no Laratrust permission; an unregistered type refused; route ACTIONS asserted; the marks refuse GET |
| `TranslationFileIntegrityTest` | 5 | both lang files are valid JSON, carry no duplicate keys, and gain no new Arabic-only orphan (F2, resolved as a side effect and now pinned) |
| `BrandLabelStatusAuthorizationTest` | 15 | P2-05-1: three toggles that were unauthorised cross-tenant writes on a GET — cross-tenant, per-permission, operator, the verb, a non-integer id, a NULL-store row, and that the switch is DISABLED for a caller the handler would refuse |
| `StateChangingGetInventoryTest` | 4 | the set of host GET routes whose handler writes cannot grow, and cannot carry a stale row |
| `ProductListFilterTest` | 12 | P2-03-2: each filter narrows to what it names, the three stock filters PARTITION the tracked products on boundary values, and no filter value — including SQL fragments — reaches another tenant or returns more rows than no filter |
| `OrderListFilterTest` | 14 | P2-04-8: status (all NINE values, including the two that are not fulfilment states), payment as an exact match, and a date range whose end is inclusive of the whole day |
| `order_status_label_test.dart` (Flutter) | 27 | P2-07-6: all nine statuses in both locales, that no label still looks like a translation key, that an Arabic locale yields Arabic characters, that each label resolves the KEY it should, and that every badge colour pair clears AA |
| `DashboardPermissionNamesTest` | 1 | every `isAbleTo()` name in the merchant views can actually be granted |
| `AdminLayoutAssetsTest` | 2 | every `asset()` path in the admin layout resolves to a real file |

### Bite tests — every control reverted, and what failed

This project's rule is stricter than TDD's: after the fix, revert it and confirm the test fails
again. Recorded in full because **five of these revealed tests that passed for the wrong reason**
(the `getSideMenu()` call site and the iframe ordering, both 0-failed on the first attempt), and
because **one bite test's own written conclusion was wrong**: the search row below used to say no
endpoint case fails on a single control being removed. Re-measuring each of the three predicates
separately refuted it — products and orders each fail a case; only customers does not. The
corrected rows are below, and the overstated note has been replaced in the test file too. Reverting
a control is evidence; what somebody writes about the revert is not, until it is re-run.

**AND A BITE TEST THAT FINDS NOTHING IS A RESULT, NOT A PASS.** Four of the rows below read
"first attempt: 0 failed". Each time the honest reading was the same: the control was real and the
TEST was not measuring it. In three of those cases a new case was written that does
(`getSideMenu()`'s call site, the iframe ordering, the unregistered notification type, the
operator limb, the unresolved-tenant guard); in one the measurement refuted a written claim
instead (the search's redundancy note). None of them was resolved by deciding the control was
redundant, which is the tempting reading and was wrong every time it was tried.

| control reverted | result |
|---|---|
| `Menu::add()` stops recording denials | 1 failed — the right one |
| F5's escaping + the route-existence drop | 4 failed, one of them `RouteNotFoundException` — the availability defect, reproduced |
| `getSideMenu()`'s gate call site | **first attempt: 0 failed.** Every assertion drove the helpers directly. `CustomSidebarRenderPathTest` was written to drive the function; now 1 fails |
| the iframe ordering fix | **first attempt: 0 failed** — `sidemenubuilder.iframe` does not resolve in a test run, so the row was dropped for the wrong reason. A stand-in wrapper route is registered and asserted; now 2 fail |
| the order preview's **handler** check | 1 failed — exactly the direct-call case |
| the order preview's **guard** entry | 1 failed — exactly the registration case |
| the concurrent-edit check | 1 failed — exactly the stale-token case |
| the product preview's store predicate | 2 failed — exactly the two cross-tenant cases |
| `OrderDataTable::hasDate()` short-circuited to true | 2 failed — exactly the two epoch cases; the label and real-date cases stayed green |
| the `stateSave` wiring removed from `buildDataTable()` | 6 of 8 failed |
| `sanitizeTableState` made a no-op | 8 of 16 node cases failed, and the suite case that drives the script failed with it |
| `sanitizeTableState` made to clean a COPY instead of mutating | **exactly 1 of 16** — the mutate-in-place case, which exists because `stateSaveParams` is called for its side effect and discards the return value, so a function returning a cleaned copy would pass everything else and change nothing on the page |
| `sanitizeTableState` made a no-op, against the LIVE browser check | **first attempt: 0 of 14 failed.** That version cleared the search and returned to page one before reloading, so the saved state held nothing to drop. Rewritten to leave all three set, the fixture seeded to 32 products so page two exists, and the reloaded row count asserted to be the UNFILTERED total; now **5 of 21 fail** — the search restored, the box pre-filled, page two restored, the filtered count, and the term still in localStorage |
| the notification **store predicate** | 5 failed — both reads, both writes, and the unread count |
| the notification **type filter** (`whereIn('type', $types)`) | **first attempt: 0 failed.** Both current types name the SAME permission, so a staff member reads both or neither and `context()`'s empty-types short-circuit already served them nothing — the `whereIn` was redundant with it and nothing measured it. A case was added driving an UNREGISTERED type against a merchant holding every permission; now 1 fails |
| the scoped find in `markRead` replaced by `StoreNotification::find($id)` | 1 failed — exactly the cross-tenant mark |
| the notification **operator limb** (operator checked after `isAbleTo`) | **first attempt: 0 failed** — no case drove an operator, and `isAbleTo()` alone refuses them, so the bell would have been empty for the one principal whose contract is to read across stores. A case was added; now 1 fails |
| a type dropped from `TYPE_PERMISSIONS` | 1 failed — the registry ratchet |
| the notification **mass-write store limb** | 2 failed — the operator marking another tenant's rows, and the operator with no current store mass-marking store 1 |
| the bell `<li>` put back inside the header's `type == 'admin'` block | 1 failed — the staff reachability case |
| the `Show Order` gate removed from the "View order" button | 1 failed |
| the notification **unresolved-tenant guard** | **first attempt: 0 failed.** `where('store_id', null)` becomes `store_id IS NULL` and the column is NOT NULL, so the row assertion was free. Rewritten to count queries against `store_notifications` (0 with the guard, 2 without), with a companion case proving a resolved principal DOES reach the table; now 1 fails |
| the bell badge's colour pair reverted to `--ss-danger-fg` + white | 2 of 60 contrast readings fail at **1.91:1** in dark. `--ss-danger-fg` is a FOREGROUND token (#f3a9a4 in dark); the pair `.ss-btn--danger` already uses (`--ss-danger` / `--ss-on-danger`) inverts correctly in both themes |
| the feed's three text classes removed from the host-override block | 10 of 60 fail at 3.42–3.85:1. `style-dark.css` sets `p { color: #808191 !important }` on a selector list shared with the sidebar and `.dataTable-info`, so a `<p>` in the feed is forced grey — the same rule F8 fought |
| the feed's timestamp colour reverted to `--ss-fg-subtle` | 2 of 64 failed — the unread row in both directions, light mode |
| the feed's `orderByDesc('created_at')` reverted to `orderByDesc('id')` | 1 failed — the ordering case, whose fixture makes id order and time order disagree |
| the product list's store predicate | 1 failed — the cross-tenant case, which drives 14 filter values |
| the stock filter's `track_stock` guard | 1 failed — an untracked product reported as out of stock |
| the low-stock boundary made exclusive (`<` for `<=`) | 1 failed — the partition case, on a stock exactly equal to the threshold |
| the filter reads' `is_string` guards (all four) | **0 failed**, and the comment claiming they prevented a 500 was wrong: strict `in_array` and `filter_var` both decline an array on their own. Kept, with the reason corrected — the next filter added there may compare directly, which is where `?ss_x[]=` becomes a 500 |
| the order range's `endOfDay()` | 1 failed — an order at 23:45 on the END date vanished from its own range |
| the payment filter matched with `LIKE` | 1 failed — "Partially Paid" answered a request for "Paid" |
| the order statuses limited to 0–6 | 1 failed — a Pre Order became unfindable from the list |
| the strict date parse replaced by `Carbon::parse` | 1 failed — `'0'` and `'now'` became real bounds |
| the order list's store predicate | 1 failed — the cross-tenant case, which drives 11 filter values |
| a duplicate key and an Arabic-only key reintroduced into `ar.json` | 2 of 5 failed |
| the brand **store predicate** | 3 failed — both cross-tenant cases and the NULL-store case |
| the brand **permission check** | 2 failed |
| the brand **operator limb** | 1 failed |
| the label **store predicate** | 1 failed |
| the brand table's **disabled-switch gate** | 1 failed |
| a NEW state-changing GET route added | 1 failed, naming the uri and the action |
| one of the four converted routes reverted to GET | 2 failed |
| a stale row added to the declared set | 1 failed |
| one of the four live callers reverted to GET | 4 of 28 browser cases failed, including the values reappearing in the query string |
| `CustomerController::customerStatus`'s ownership block | **first attempt: 0 failed**, even with all five drives on POST — `Customer` IS in `scoped_models`, so the global scope already excludes a foreign row for a principal who resolves a tenant, and an endpoint test cannot tell the handler's `where` from the scope. A case was added for a principal who resolves NONE, where `null_tenant => 'open'` means the scope adds nothing; now 1 fails |
| the Flutter badge's statuses 7 and 8 reverted | 4 failed |
| the three Flutter badge colours reverted | 1 failed, naming all three ratios (2.81, 4.06, 1.85:1) |
| one Arabic description literal restored | 1 failed |
| the Flutter `'cancelled'` key reverted to `'cancel'` | **first attempt: 0 failed** — both keys exist and both resolve to a real word, so "does it still look like a key" cannot tell them apart. Two cases were added comparing each label to the value of the key it should use; now 2 fail |
| the search's `is_string($q)` guard | 1 failed, with "Array to string conversion" raised at the `assertOk()` line — `?q[]=a` was a server error on a route every merchant can reach |
| both contrast stylesheets | 14 of 32 readings below AA, from 0 |
| the singular permission name restored | 1 failed, with the plural named in the message |
| search: the **products** store predicate | 1 failed — `⨯ a merchant does not find another tenants products` (1 failed, 10 passed) |
| search: the **orders** store predicate | 1 failed — `⨯ a merchant does not find another tenants orders` (1 failed, 10 passed) |
| search: the **customers** store predicate | **0 failed (11 passed)** — the one section where a single removal is not caught, because `Customer` IS in `scoped_models` and its global scope supplies the same predicate for a principal who resolves a tenant. The predicate stays on the project's rule that the scope must never be the only tenant check, and the hazard is pinned by its own test, which measures that `Customer::query()` for a **tenant-less** principal compiles with NO store predicate and returns both tenants' rows |
| search: the unresolved-tenant refusal | 0 failed — with it gone the queries run `where('store_id', null)`, which compiles to `IS NULL` and matches nothing, so products and orders come back empty either way. Its value is in the Customer query under `null_tenant => 'open'`, and both controls would have to go for rows to escape |

## 9d-ter. What the full Feature suite caught that the targeted suites did not

The targeted suites were green through all of P2-05-1. The full `--testsuite=Feature` run found
**three failures the targeted runs could not see**, and all three were mine:

| failure | what it was |
|---|---|
| `RouteShadowingTest` | `routes/web.php` gained `Route::get('notifications', ...)` while `routes/api.php:347` already declared the same verb and uri for the delivery boy. No runtime collision — the api one is inside `Route::prefix('admin')` — but the guard compares DECLARED strings and is right to. Renamed to `merchant-notifications` (F35). |
| `CustomerAdminAuthorizationTest` × 2 | the two cases asserting a 200 and a write, broken by `customer-status` becoming a POST. **The other three in that file kept passing, vacuously** — a 405 satisfies "the row did not change" as well as a guard does. All five drives moved (F34). |

That is the argument for running the whole suite at an integration milestone rather than trusting
the slice's own tests: none of these three is reachable from the files the slice touched.

## 9d-bis. The four toggles, driven in a real browser (P2-05-1)

`docs/development/phase-2/tools/check-toggle-posts-live.cjs` — a real login, a real list, a real
click. The suite drives the ROUTE; this drives the PAGE, which is where the GET→POST change to
four `$.ajax` calls could silently break a toggle a merchant then discovers.

```
33 cases passed
```

Per toggle: the switch is on the page (asserted, not assumed), the click issues a **POST**, no
values remain in the query string, the response is 200, the handler reports success, and **the row
changed in the database** — read back after a reload rather than from the DOM the click left
behind.

**And one case the file was missing until it was written down.** The three views originally set an
explicit `X-CSRF-TOKEN` header; removing it changed nothing here, because
`public/js/custom.js` sets `$.ajaxSetup({headers: …})` for every jQuery call in the application.
So the protection was claimed and never measured. The redundant headers were deleted and a case
added that POSTs with `fetch` — which bypasses `$.ajaxSetup` — from the page's own origin and
session: **419 required on all four**. That is the shape a cross-site POST takes once it reaches
the handler, and without it these routes would have been POST in name only.

## 9e. Contrast, measured in the running application

`docs/development/phase-2/tools/measure-contrast-live.cjs` — a real login, a real session, and the
host's own server-side theme/direction switch, reading composited colours off the painted page.

```
before:  32 measured, 14 below AA     (F8/F9, the sidebar and the active item)
after:   32 measured,  0 below AA
```

**Extended to the notification feed (P2-06-3).** The same tool now visits `/notifications` as
well as `/order` and measures seven more elements — the feed's title, message and timestamp on
BOTH the read and the unread surface (different backgrounds: a token pair that is AA on one is
not AA on the other by implication, which is what F8 was), the unread badge, and the header
bell's count. It also exits non-zero on a failure now; it used to always exit 0, so its number
had to be read by a human to mean anything.

```
first run:  60 measured, 12 below AA
after:      60 measured,  0 below AA
then:       64 measured,  0 below AA   (two more readings; see below)
```

**And the first clean run was clean by luck.** The probe measured `.ss-feed__when time` once,
which samples whichever row is first — and that fixture's first row happened to be the READ one,
on the plain surface. A later fixture change put an UNREAD row first and the timestamp measured
**4.13:1**: it sits on `--ss-surface-sunken` there, where `--ss-fg-subtle` does not clear AA
(4.69:1 on the plain surface, 4.13 on the sunken one). Fixed with `--ss-fg-muted` (5.94 / 5.23),
and the probe now measures the timestamp on BOTH rows, as it already did for the title and the
message. Third instance of the same lesson: **AA on one surface is not AA on another, and a probe
that samples one row is a probe that samples one surface.**

Both of those twelve were real and neither was visible by looking:

| failure | reading | cause |
|---|---|---|
| bell unread count | **1.91:1** white on #f3a9a4 | the rule set `background: var(--ss-danger-fg)` — a FOREGROUND token, fine by accident in light, a pale red in dark |
| feed title / message / timestamp, read and unread rows | **3.42–3.85:1** | `style-dark.css` forces `p { color: #808191 !important }`; the feed's text sits on `<p>` |

| element | ltr/light | ltr/dark | rtl/light | rtl/dark |
|---|---|---|---|---|
| sidebar label (inactive) | 12.63 | 3.71 → **5.66** | 12.63 | 3.71 → **5.66** |
| **sidebar label (active)** | 2.59 → **6.92** | 2.59 → **6.92** | 2.59 → **6.92** | 2.59 → **6.92** |
| sidebar search field | 12.93 | 3.71 → **5.66** | 12.93 | 3.71 → **5.66** |
| table cell | 12.93 | 3.71 → **5.66** | 12.93 | 3.71 → **5.66** |
| breadcrumb link | 2.48 → **5.00+** | 4.03 → **6.14** | 2.48 → **5.00+** | 4.03 → **6.14** |

F9's fix changes **no brand colour**: across the ten presets, white wins on six and near-black on
four, and all ten reach AA — worst case 4.90:1.

## 9f. The browser suites, after every change

```
$ bash docs/development/phase-2/tools/render-all-fixtures.sh /tmp/p2fixtures   # 41 fixtures
$ SS_PREVIEW_DIR=/tmp/p2fixtures php -S 127.0.0.1:8099 -t public \
      docs/design-system/tools/preview-server.php &

$ node docs/development/phase-2/tools/browser-checks-p02.cjs      159 PASSED, 0 FAILED
$ node docs/design-system/tools/browser-checks.cjs                 66 PASSED, 0 FAILED
$ python3 docs/design-system/tools/check-contrast.py               ALL 58 PAIRS PASS
$ php tests/Support/Security/bin/verify-menu-render-parity.php     17 checks, all match
$ php tests/Support/Security/bin/verify-order-chart-operator-branch.php   9 PASSED
```

**Neither browser suite's fixture set was committed or fully documented** — the evidence file named
`nav2-*` where the code reads `nav3-*`, and said nothing about the `orig-*` baselines, which must
come from git because the pre-redesign markup no longer exists in the tree. A missing fixture does
not report as missing; it reports as a FAILING CHECK or a harness `TypeError`, which reads like a
regression in the thing being tested. `render-all-fixtures.sh` renders all 41.

## 9g. Screenshots of the running application

| harness | what it drives | combinations |
|---|---|---|
| `shoot-orders-live.cjs` | the order list and the quick-preview panel, fetched | 8 — desktop/mobile x en/ar x light/dark |
| `shoot-dashboard-live.cjs` | the dashboard and the onboarding checklist | 8 |
| `shoot-search-live.cjs` | Ctrl+K, a real term, real results, arrow-key selection | 8 |

Each run asserts, per combination: which stylesheet the **server** actually sent, the resolved
`dir`, that the fetch produced real content, and that no 5xx occurred — with
`/top-brand-category-chart` excluded **by name** (blocker B5), so a 500 anywhere else still fails.

**The dark-mode shots were wrong at first and the harness said so.** It set `data-theme` from the
browser while the layout picks its stylesheet server-side, so four of eight were of the light
stylesheet. `set-preview-state.php` writes the store's real `cust_darklayout` / `SITE_RTL`, and
every run now asserts the result.

## 9h. P2-06-1 — the RTL hold, settled

The onboarding checklist was held because the preview harness painted each step's mark **twice**
under `dir="rtl"`. Driven in the running application, at desktop and mobile in light and dark,
reading **painted geometry** rather than the DOM — because counting elements is exactly what could
not see the defect, the DOM always had one:

```
desktop-ar-light   rows=4 domMarks=4 paintedRects=[1,1,1,1] OK
desktop-ar-dark    rows=4 domMarks=4 paintedRects=[1,1,1,1] OK
mobile-ar-light    rows=4 domMarks=4 paintedRects=[1,1,1,1] OK
mobile-ar-dark     rows=4 domMarks=4 paintedRects=[1,1,1,1] OK
```

**The duplication was the harness.** The component ships.

## 9i. Flutter

```
$ flutter --version          3.47.8 stable / Dart 3.13.7
$ flutter pub get --enforce-lockfile      exit 0   # B2 CLEARED; pubspec.lock unchanged
$ flutter analyze            246 issues, 0 warnings, 0 errors   # unchanged by this cycle
$ flutter test               1195 passed            # was 1189; +6 RTL mirroring
$ flutter build web --release             FAILS — see B4
```

`flutter build web` fails inside `firebase_messaging_web` 3.5.18 under Dart 3.13.7, with **zero
files under `lib/` implicated**. That blocks Flutter *screenshots*; it does not block RTL *layout*,
which `test/localization/rtl_mirroring_test.dart` measures through the real RenderObject tree.

**`analysis_options.yaml` must be reverted after every `pub get`.** The SDK rewrites it to exclude
the platform directories, which silently narrows what `analyze` sees and would make the 246 figure
incomparable to the recorded baseline.

## 9j. What is still NOT verified, stated plainly

- **No Flutter screen has been seen.** B4. Layout is measured; pixels are not.
- **No MySQL run.** Everything PHPUnit here is SQLite, whose type affinity hides real MySQL
  defects — that is how P01-SEC-13 survived. Nothing about payment or schema behaviour in
  production is established by any figure in this file.
- **`/top-brand-category-chart` is unexercised** (B5), so the dashboard's brand/category chart has
  no runtime coverage in any environment available here.
- **The ~31 remaining `asset('public/...')` files** (F16) are not fixed and not measured.
- **P2-03's list redesign and full-page editor, P2-04's list redesign, detail page and Kanban,
  P2-05-2's design-system pass** are not built, so there is nothing to verify. P2-05-1, P2-06 in
  full, P2-03-2 and P2-04-8 were built and verified after this bullet was first written; the parts
  still listed are the parts still owed.

## 9k. P2-02-9 — the dashboard lists, with rows in them

The two cards were finished code that had **never been seen holding a row**. `handleRegularUser()`
already passed `$new_orders` and `$topSellingProducts`; the view rendered, gated and empty-stated
both. What was wrong was the development fixture: `database/seed-p2dev.php` left
`orders.product_id` at its default `'0'`, and `HomeController` plucks *that* column, not
`product_json`. So `topSellingProductIds` was `[0]`, nothing matched, and the card showed
`No sales yet` — which is exactly what a healthy card with nothing to show looks like.

```
$ php artisan test tests/Feature/Merchant/DashboardBestSellersTest.php
  ✓ the block lists the products the orders actually contain
  ✓ a foreign product sharing an id cannot appear
  ✓ a staff member without the product permission does not get the block
  ✓ with no orders the block renders its empty state
  Tests: 4 passed (16 assertions)

$ node docs/development/phase-2/tools/shoot-dashboard-cards-live.cjs
  desktop-en-light: best=5 rows "Best selling products", orders=4 rows "Latest orders"  ok
  desktop-ar-light: best=5 rows "المنتجات الأكثر مبيعاً", orders=4 rows "أحدث الطلبات"  ok
  ... 8 configurations, 0 problems
```

**Bitten four ways.**

| reverted | result |
|---|---|
| `(clone $productQuery)->whereIn(...)` → `Product::whereIn(...)` | store B's product appears on store A's dashboard — 1 failure |
| the `Manage Products` gate in the view | staff holding only `Manage Order` are shown the table — 1 failure |
| `pluck('product_id')` → `pluck('product_json')` | the card empties — 2 failures |
| `orders.product_id` blanked back to `'0'` in the dev database | the browser run reports 32 problems across 8 configurations, which is the original defect exactly |

**Two things the first attempt got wrong, both caught by premise assertions.**

1. **The test granted `Manage Product`, singular.** That name exists in no seeder, so
   `grantPermission()` created a fresh row nothing reads, `$ssMayProducts` stayed false, the card
   never rendered, and every "this product is absent" assertion held for free. The same trap
   P2-02-13 fixed in the view; a **fixture** can fall into it too.
2. **The browser check looked each table up by a fragment of its caption, and read the same card
   twice.** The best-selling caption is "Products appearing most often in this store's **orders**"
   and the orders caption is "The four most recent **orders**", so a search for `orders` matched
   the orders table first. Four of eight configurations reported the orders table as the
   best-selling one and the run said `0 problems`. The Arabic four escaped only because the
   captions are translated, so neither fragment matched and a positional fallback answered. A
   caption is a *translated* screen-reader name and cannot identify a table in a bilingual check;
   the two tables now carry `data-ss-list`, and the run asserts both readings came from cards with
   different titles.

### 9k-bis. And the rows exposed a design-system defect that had been invisible

`.ss-row` is a flex container, so it stretches its cards to the tallest one's height. `.ss-card`
was a plain block, so a **shorter** card's footer stayed where its content ended and the card
painted empty surface below it — a sunken band floating mid-card. Measured in the running
application the moment the two lists held different row counts (4 orders beside 5 products):

```
latest-orders  card bottom 1645.5   footer bottom 1605.1   gap 40px
best-selling   card bottom 1645.5   footer bottom 1644.5   gap  1px
```

`.ss-card` is now `display: flex; flex-direction: column` with `.ss-card__body { flex: 1 1 auto }`
— `1 1 auto` rather than `1`, because `flex: 1` resets the basis to `0` and would re-distribute
every card body's height across the application. After: both gaps 1px, which is the border.
Reverting it reproduces 36–40px in the three stretched configurations and nothing on mobile, where
the row wraps and there is no stretching to do. The shared stylesheet change was re-checked against
everything that drives it: **159/159** Phase 02 browser checks, **66/66** design-system checks,
**76** contrast readings with none below AA, and the three live assertion tools (112, 21 and 33
cases).


## 9l. P2-04-4 — the order list's row controls, measured rather than looked at

Started from one screenshot. The Arabic dark order list had a status control with no readable
text in it, so the row's controls were measured with WCAG alpha compositing (the same arithmetic
`measure-contrast-live.cjs` uses) across LTR/RTL x light/dark.

```
BEFORE, the vendored theme's own classes:            44 of 56 readings below AA
  btn-soft-dark    Shipped / Partially Paid / Pre Order   1.12:1  dark
  btn-soft-danger  Cancel / Return                        1.03:1  dark
  btn-soft-success Delivered                              2.59:1  all four
  btn-soft-warning Confirmed                              1.68:1  light
  btn-primary      the #ORD-xxxx reference pill           2.59:1  all four
  btn-info         the Edit pencil (icon, needs 3:1)      1.09:1  dark

AFTER, on the design system's badge and quiet icon buttons:   0 of 56 below AA
  worst text reading   6.82:1     worst icon reading   5.94:1   reference pill   6.92:1
```

**A first reading of this was wrong and said `0 problems`.** The probe treated
`rgba(244,180,26,0.15)` as opaque, so a warning label on its own tinted background came out at
exactly 1.00:1 and a dark tone at 1.22:1 — numbers that are not false in direction but are not
measurements either. A ratio against a translucent background is not a ratio until the background
is composited. The figures above are from the repository's own compositing routine, and the first
set was discarded rather than reported.

**`btn-primary` is F9 again, on a third surface.** 2.59:1 is the same figure F9 recorded for the
selected sidebar item, because it is the same pair of colours: `#fff` on the merchant's brand.
`--ss-onbrand` — F9's own mechanism, white or near-black chosen by measured ratio against the
merchant's colour — supplies the label. **No brand colour changes**, the fill stays exactly what
the merchant picked, a merchant on a dark brand sees no change at all, and no new global policy
was needed, so nothing was escalated.

### 9l-bis. Three things no stylesheet could have fixed

| | found by | fixed by |
|---|---|---|
| **Three icons with no glyph.** `ti-layout-sidebar-right-expand` is not in the bundled font (it has `ti-layout-sidebar-right` and no `-expand` variant); `ti-category` has no `ti-categor*` at all. The quick-preview button on BOTH lists had painted an empty 18px box since it shipped. | cropping one row at 3x and counting glyphs — three where four were expected | the names the font has, plus two ratchets: `IconNamesExistTest` reads the expectation **out of the font file** (no allowlist) and names the file; `check-row-actions-live.cjs` asserts the **painted** width |
| **Three of four row actions had no accessible name.** `data-bs-toggle="tooltip"` makes Bootstrap move `title` into `data-bs-original-title` and blank the attribute, so a server-rendered DOM has a name and the merchant's page does not. | measuring the accessible name in the browser **after** the scripts ran | `aria-label` naming the verb and the record on every action, both lists; 7 new keys in both language files |
| **`Form::open()` with no `Form::close()`** in the product list's cell. | reading the file while changing it | `{!! Form::close() !!}` |

### 9l-ter. And a stale-fixture near miss worth recording

Moving the status tones into `App\Support\OrderStatusTone` broke the preview harness, which has
no Laravel autoloader: `dashboard-after` died with *class not found*. **The first browser run after
that change still reported 159/159**, because it was driving fixtures rendered before it — the
render script had exited 255 and the stale HTML was still on disk and still being served. The
figure was discarded, `render-preview.php` now `require`s the real class (not a third copy of the
mapping, which is the defect the class exists to prevent), and both suites were re-run against
freshly rendered fixtures:

```
$ bash docs/development/phase-2/tools/render-all-fixtures.sh <dir>     exit 0, 41 fixtures
$ node docs/development/phase-2/tools/browser-checks-p02.cjs           159 PASSED, 0 FAILED
$ node docs/design-system/tools/browser-checks.cjs                      66 PASSED, 0 FAILED
$ node docs/development/phase-2/tools/check-row-actions-live.cjs        84 cases, 84 passed
$ node docs/development/phase-2/tools/check-list-filters-live.cjs      112 cases passed
$ node docs/development/phase-2/tools/measure-contrast-live.cjs         76 measured, 0 below AA
$ php artisan test --testsuite=Unit                                    370 passed
```

**`check-row-actions-live.cjs` bites five ways.** The font-less icon name → 4 failures naming it;
one `aria-label` dropped while its `title` stays → 4 failures, which is the measurement behind the
"`title` is not a name" claim; the `--ss-onbrand` rule reverted → 2.59:1 on `#ORD-2047`; one badge
tone's colour broken → 1:1 on Cancel; and `--ss-success-fg` broken → 1:1 on both lists at once,
8 failures, which is what proves the product list's stock badges are covered too.

**And the "refuses to check what is not there" clause was itself a bug for about an hour.** The
badge assertions were keyed on `screen.name === 'orders'`, which was true when only the order
list used `ss-badge`. The product stock column moved onto the same badge (F45) and the check went
on printing `no status badges on this list by design (10 found)` — skipping ten real badges while
saying so in its own output. It now keys on what the page contains: the order list must have
badges, and every badge found anywhere is measured. A skip message that names a non-zero count is
the tell.


## 9m. The review of 9l, and the three things it found that 9l had not

The P2-04-4 change was presentational, so it went to `tenant-authz-reviewer` under CLAUDE.md's
rule for anything touching an order surface. The review confirmed the change itself: no
authorization, tenancy predicate, permission check or route middleware moved; the row actions
expose the same routes, ids and `Crypt` usage as the unchanged sibling `order/grid.blade.php`;
the new badge's class string cannot be influenced (`for()` returns only literals, `$status` is a
typed `int`); and `aria-label="{{ __('Delete order :id', [...]) }}"` cannot inject, because
Laravel substitutes first and `{{ }}` escapes after, with `ENT_QUOTES`.

Then it reported three things the slice had not looked at.

**1. Stored XSS on the line the change edits** — ledger F42. `OrderDataTable`'s `customer_id`
column prints a shopper-chosen name unescaped into a `rawColumns()` cell, and the XSS middleware
that looks like it would stop it decodes entities *before* stripping four literal strings, so a
raw `<img src=x onerror=…>` survives.

```
$ php artisan test tests/Feature/Security/OrderListCellEscapingTest.php
  ✓ a shoppers name cannot inject markup into the merchants order list
  ✓ a shoppers mobile number is escaped too
  ✓ an order reference cannot inject markup
  ✓ an ordinary customer name still renders
  Tests: 4 passed (26 assertions)

bite: the two customer e() calls removed    2 failed, 2 passed
bite: the reference e() removed             1 failed, 3 passed
```

**A SECOND CELL, which the review flagged as unproven and which measurement settled.**
`product_order_id` is in the same `rawColumns()` list and `PaymentService.php:2689` builds it as
`$request->customer_id . date('YmdHis')` for an authenticated shopper — raw caller input. The
objection is that the same value also goes to `orders.customer_id`, an `unsignedBigInteger`,
so the insert should fail. It does not: `config/database.php:55` sets `'strict' => false` on the
`mysql` connection, and `product_order_id` is a `string` column
(`create_orders_table.php:16`), so MySQL coerces one and stores the other whole. Escaped and
pinned. **The INPUT half is ledger F46 and is escalated, not fixed** — it is a payment path.
What was not measured: a full checkout request on production MySQL. That reachability is read
from the column type, the handler and the strict-mode setting.

**And one of those bites was a false reading before it was a real one.** The first attempt at the
reference bite used `sed` with a pattern that did not match, so the "reverted" file was
unmodified and the test passed — which read exactly like a vacuous test. Checking that the edit
had actually landed, and redoing it, produced the failure above. A bite test proves nothing until
you confirm the revert is in the file.

**2. The preview harness had been showing the opposite of production** — ledger F43. The
dashboard fixtures granted `Manage Product` (singular) after P2-02-13 renamed the gate to the
plural, so every "permitted merchant" render hid the best-selling card, the out-of-stock
attention item with its `Review stock` link, and the Products stat link. **159/159 passed
throughout**: no check asserted those blocks were present in the granted state. After the fixture
fix and a re-render, `after-en-full-light.html` carries `Best selling products` and a third
attention item (`below the stock threshold` + `Review stock`) that had been absent.

The review also listed the add-product onboarding step. That step IS gated on the same name, but
its checklist does not render in this fixture state at all — P2-06-1 is held — so it is left out
of the count above. A reviewer's list is a set of claims; the ones reported here are the ones
that were checked.

**3. A NULL status would have read "Pending"** — ledger F44, fixed before shipping, and the
test for it is worth describing because **two of its three cases are not the obvious one**.

```
$ php artisan test tests/Feature/Merchant/OrderStatusLabelTest.php
  ✓ an unrecognised status is named rather than left blank
  ✓ each of the nine known statuses is named
  ✓ the null branch is unreachable because the column forbids null
  Tests: 3 passed (31 assertions)

bite: the `?? __('Status :code')` fallback replaced by `?? ''`   1 failed, 2 passed
```

The **reachable** defect is the out-of-range value: the markup this replaced returned `''` for
anything outside 0–8, so an order carrying a tenth status rendered with no status at all in the
one column a merchant scans the list by. `LynomiaCommerceDataTest` establishes those values occur.

The **NULL** branch cannot be driven: `delivered_status` is declared
`integer(...)->default(0)` — NOT NULL — and SQLite refuses the write, so the first attempt at that
case failed on its own premise. A conditionally-skipped test would have been worse than none,
because it would join the declared skip set the known-outcome gate asserts, for something that is
not a blocker. So the third case **asserts the reason instead**: it reads `PRAGMA table_info` and
fails if the column ever becomes nullable, with the message saying what test to write then. The
one-line guard stays, because GATE F records that the migrations are not the schema.

The second case also carries the assertion that makes the first meaningful: `Pre Order` appears
**exactly once** across nine rows. Without it, a column that printed all nine labels on every row
would satisfy every "status N is named" assertion.

**Its one hardening note was real, and was measured both ways** — ledger F47.
`DashboardPermissionNamesTest` strips comments from the view side so prose cannot satisfy the
scan, and read the seeder side raw. The seeder side builds the ALLOWLIST, so a comment quoting
`'Manage Product'` anywhere in `Utility.php` or a seeder would have widened it and the ratchet
would have stopped biting on P2-02-13's defect.

```
state: a `// probe: 'Manage Product'` comment in Utility.php
     + the view reverted to isAbleTo('Manage Product')

with the comment-stripping fix      ⨯ FAILS, naming the view
without it (the pre-fix scan)       ✓ passes
```

**One of its five findings was wrong, and was checked rather than applied.** It reported that
`$(this)` inside the `.return_request` ajax `success:` callback is the settings object, which
would make a comment in `order/action.blade.php` inaccurate. `order/index.blade.php:80` sets
`context: this`, so `$(this)` is the clicked element and the comment was right. Nothing changed.

### 9m-bis. Everything re-run after the review's fixes

```
$ bash docs/development/phase-2/tools/render-all-fixtures.sh <dir>     exit 0, 41 fixtures
$ node docs/development/phase-2/tools/browser-checks-p02.cjs           159 PASSED, 0 FAILED
$ node docs/design-system/tools/browser-checks.cjs                      66 PASSED, 0 FAILED
$ node docs/development/phase-2/tools/check-row-actions-live.cjs         84 cases, 84 passed
$ node docs/development/phase-2/tools/check-list-filters-live.cjs       112 cases passed
$ node docs/development/phase-2/tools/measure-contrast-live.cjs          76 measured, 0 below AA
$ node docs/development/phase-2/tools/shoot-products-live.cjs             8 combinations, 0 problems
$ node docs/development/phase-2/tools/shoot-orders-live.cjs               8 combinations, 0 problems
$ node docs/development/phase-2/tools/shoot-dashboard-cards-live.cjs      8 configurations, 0 problems
$ php artisan test --testsuite=Unit                                     370 passed
```


## 9n. P2-04-6 — the order detail page, and the fixture that had to come first

**Three blocks on this page had never held content**, so the first work was not the page at all.
`order_billing_details`, `order_notes`, and the `countries`/`states`/`cities` the two address
cards resolve through were all empty: both address cards rendered every field blank and the notes
panel rendered nothing. That is P2-02-9's defect on a second page — an empty block is
indistinguishable from a healthy block with nothing to show.

**Getting the notes on screen took three fixture fixes, and each intermediate state looked like
success:**

| attempt | result | why |
|---|---|---|
| seed five notes | `notes=0` | they went on the OLDEST order; the check opens the one the list shows first |
| seed them on every order | `notes=0` | the panel is gated on `Manage Order Note`, which the fixture merchant's role did not hold (`isAbleTo` false, while `Show Order` was true — which is why the page opened at all) |
| grant the three note permissions | `notes=5` | — |

`order_notes.customer_id` also carries a foreign key to `customers`, so the conventional `0`
fails the insert; `NULL` is both what works and what is true, since these notes have no customer.

### 9n.1  What the populated page then measured

```
BEFORE                                           AFTER
  dark  customer-note        1.24:1                every measured string passes
  dark  order-create-note    1.77:1                worst reading 8.53:1 (light), 12.06:1 (dark)
  dark  admin-private-note   1.86:1
  dark  stock-mange-note     2.95:1
  dark  order-status-note   12.21:1  <- passed by accident of being the one opaque hex
  dark  status control       1.09:1
  dark  grand total          1.10:1  <- the money figure
  dark  every address label
        and value            1.10:1  <- the whole shipping/billing block
  light note delete icons    2.28:1     (3:1 threshold — icons, not text)
  dark  note delete icons    2.88:1
  light item name link       2.59:1  <- F9's case a third time
```

Light mode is deliberately untouched where it already passed (the five tints measure
8.53–12.21:1 there), so merchants on the light theme see no change at all. The dark fixes are in
`custom.css`/`rtl-custom.css` under the `data-ss-theme="dark"` hook — the same mechanism and the
same file F8 used, rather than `assets/css/style-dark.css`, which an update would revert. The
fills are the design system's own dark tone tokens, already measured at AA, not five new colours.

### 9n.2  Four correctness defects, each pinned and each bitten

```
$ php artisan test tests/Feature/Merchant/OrderDetailTest.php
  ✓ a note is not attributed to the person reading the page
  ✓ a deleted product does not shift the total column
  ✓ a foreign products download is not offered
  ✓ every new window link is opener safe
  Tests: 4 passed (33 assertions)

bite: the attribution restored         1 failed
bite: the cell condition restored      1 failed — "3 cells against 4 headers"
bite: the store predicate dropped      1 failed — the foreign path returns
bite: rel removed from the LAYOUT only 1 failed — "4 links … and 3 carry rel=noopener"
```

The cross-tenant one is **measured rather than reasoned**: store A's order naming store B's
product id rendered `uploads/<B>/beta-secret-file.zip` to store A's merchant. What already
contained it is worth stating too — `StoredPathPolicy` keeps store B's file under store B's
folder, so the file was never mis-stored; what leaked was this page's willingness to link to it.

```
$ node docs/development/phase-2/tools/check-order-detail-live.cjs
  112 cases, 112 passed, 0 failed      (desktop + mobile x en/ar x light/dark)
  16 screenshots, taken by the same run that measured the page
```

### 9n.3  The suite caught a regression this slice caused, and the gate refused it

The first full Feature run after these changes reported **3 failures where 2 are declared**, and
the known-outcome gate refused it in exactly the terms it exists for:

```
Tests: 3 failed, 17 skipped, 5920 passed
::error:: This is a regression unless you can show otherwise. Do NOT add it to
::error:: .github/known-test-outcomes.json to get green
```

The third was `SecurityLogInventoryTest`. Nothing about logging changed — the inventory is keyed
on `file:line`, and adding the `store_id` block to `Order::order_detail()` and the `__()` call in
`OrderController::order_view()` shifted 11 rows beneath them. CLAUDE.md names this as the most
common way an unrelated change turns CI red here, and the repository ships the tool for it:

```
$ python3 docs/development/phase-1/tools/resync-observability-inventory.py --write
  rows re-pointed : 11      rows unchanged : 118      documented : 129
```

The other three line-keyed inventories (`ResidualTenancyExposureTest`,
`EloquentScopeBypassInventoryTest`, `StoredFilePathSafetyTest`) were checked too and were
unaffected — 101 passed.

**Worth recording for its own sake:** the gate did the job it was built for. A third failure in a
5,900-test suite is invisible in a summary line, and the temptation it names — declaring the new
failure to get green — is precisely what would have buried a real regression had this been one.

### 9n.4  Three things the check itself got wrong first

1. **It found the address cards by an English heading fragment**, so it reported both cards
   missing on the Arabic page and passing on the English one — 4 failures out of 48. The same
   mistake as the dashboard's caption lookup, two slices earlier. The headings now carry
   `data-ss-address`, which is neither translated nor positional.
2. **It counted the address values and measured none of them.** Both cards could report "has
   values" while every value was unreadable — which is exactly what a screenshot then showed, at
   1.10:1. Counting is a premise; the ratio is the check.
3. **It skipped the note delete icons**, because `measure()` ignores an element with no text. They
   were at 2.28:1, and three of the five carried `text-white` while two did not — an asymmetry
   from the five-way copy-paste rather than a decision.

**And a guard that could never fire.** A backtick inside the probe's template literal ends it and
the file stops parsing; it happened a third time in this phase. The first version of this tool
answered that with a runtime check that the probe string contains no backtick — which cannot
work, because if the backtick is there, no line in the file runs. It was protection in appearance
only. `node --check <file>` is the check that works, and the comment now says so instead.

### 9n.5  One claim this slice had to shrink

`product/action.blade.php` had `Form::open()` with no `Form::close()` (F40, fixed with P2-04-4).
It was written up as leaving the form's extent "to the browser's error recovery rather than the
one button it is for" — which sounds like a consequence and is not one. Driving the product list
with and without the close tag:

```
with Form::close()      10 forms, 1 button each, 4 descendants each, actions /product/32, /31, /30
without Form::close()   10 forms, 1 button each, 4 descendants each, actions /product/32, /31, /30
```

**Identical.** The cell is injected as a DataTables fragment, so the parser closes the form at the
end of the fragment either way. It is a well-formedness fix with **no behaviour change** — still
right, because the next control added to that cell would inherit a real bug, but it did not have
one. The attempt to assert it in `check-row-actions-live.cjs` therefore came back clean, and the
assertion was kept for what it DOES guard (a control outside any form, and a form that does not
spoof DELETE) with a comment saying what it does not.

This is §4b's pattern with the third answer: a clean bite can mean the control is redundant, or
the test is not measuring it — or, here, that there was nothing to measure and the write-up was
the thing that was wrong.

---

## 10. `[2026-10-25]` The verification debt at `6e3564e5`, paid — and what paying it found

Commits `567686f0` and `6e3564e5` named the suites they had **not** run. This section is that run,
in a fresh container, at HEAD `6e3564e5f8e8078cc204cc1f558826a8796ecdde`, working tree clean.

**Every figure below was executed here. Nothing is carried forward from a commit message.**

### 10a. The suites

```
$ php artisan test --testsuite=Unit
  Tests:  372 passed (2042 assertions)        Duration: 13.77s        exit 0

$ php artisan test --testsuite=Feature \
      --log-junit storage/logs/feature-junit.xml \
      --log-teamcity storage/logs/feature-teamcity.txt
  Tests:  2 failed, 17 skipped, 5921 passed (30055 assertions)
  Duration: 1171.00s                                                  exit 1 (the 2 declared failures)

$ php .github/scripts/assert-known-test-outcomes.php \
      storage/logs/feature-junit.xml .github/known-test-outcomes.json \
      storage/logs/feature-teamcity.txt
  testcases observed   : 5940  (manifest floor 1000)
  failures observed    : 2  (manifest declares 2)
  errors observed      : 0  (always expected: 0)
  skips observed       : 17  (manifest declares 17)
  skip reasons parsed  : 17 from feature-teamcity.txt
  OK: the observed outcome set matches the manifest exactly               exit 0

$ php artisan test --testsuite=Contract
  Tests:  26 passed (83 assertions)           Duration: 3.93s         exit 0
```

**`567686f0`'s hedged figure holds.** Its message claimed "5921 passed, gate OK" while admitting
"a re-run covering the later comment and aria-label edits was still in flight". That re-run is
above: **5921 passed, 2 declared failures, 17 declared skips, gate OK** — the same number, now
measured at HEAD rather than asserted. The manifest was **not** touched to get there.

Two things worth separating, because the earlier sections of this file do not:

- The figure `5921` appears **nowhere in `docs/` or `.github/`** — only in `567686f0`'s commit
  message. §7's gate-passing run is `5814 passed` at `b314cca1`, **18 commits back**. So before
  this section, the current head had *no* recorded suite evidence at all.
- `tests/Unit/Ui/DesignSystemStylesheetScopeTest.php` (new in `6e3564e5`, the executable test that
  replaced a stale stylesheet-scoping *claim*) had **never been executed**. It runs inside the 372
  above and passes — both of its cases.

### 10b. Flutter, at `fd76ac81` on Flutter 3.47.7 (the pinned `FLUTTER_VERSION`)

| CI gate | CI's figure | Measured here | |
|---|---|---|---|
| `flutter pub get --enforce-lockfile` | exit 0 | exit 0, "Got dependencies!" | PASS |
| `pubspec.lock` unchanged | byte-identical | sha256 identical before/after | PASS |
| `flutter analyze --no-fatal-infos` | exit 0 | **exit 0** | PASS |
| errors / warnings | 0 / 0 | **0 / 0** | PASS |
| infos (ratchet, may fall never rise) | ceiling 248 | **246** (CI's own `'^ *info • '` grep) | PASS |
| `flutter test` | 1165/1165 | **1222/1222 "All tests passed!"** | PASS |
| containment | only `analysis_options.yaml` | only that file; restored | PASS |

`1222 > 1165` because CI's figure predates the five P2-07 commits, which added tests. `246 < 248`
is two infos paid down. **`INFO_CEILING` was deliberately NOT lowered to 246**: CI measured 248 on
its own runner, and tightening a ratchet on one local measurement can turn CI red for an
environmental reason. Recorded as a candidate tightening for whoever can watch a real CI run.

### 10c. `check-pinned-column-live.cjs` — 58/58, and the two defects getting there found

First run against a correctly seeded instance: **57 ok, 1 FAIL**.

```
FAIL  that name is translated, not left in English on the Arabic screen  [aria-label="Choose columns"]
```

**It is not a product defect.** Measured under a real Arabic locale the label is
`"اختيار الأعمدة"`, and `resources/lang/ar.json:3004` has carried the entry since `567686f0`.

**It is an order-dependent test.** `setServerState()` writes `cust_darklayout` and `SITE_RTL` and
nothing else, so the menu block (cases 1-4) flipped the **direction** without the **language**.
`__('Choose columns')` resolves against `users.language`, which `/change-language/<lang>`
**persists** (`LanguageController::changeLanquage` sets `$user->language` and saves). Cases 5-7
set the language per leg; this block did not, and the `finally` restores light/RTL but never the
language. So the verdict depended on whether the script had been run before:

```
users.language = 'en'  (fresh-seed default)      ->  1 of 58 FAILED
users.language = 'ar'  (left by a previous run)  ->  58 of 58 passed
```

Same commit, same code, opposite verdicts. Fixed by setting the language explicitly in that
block, the way the other legs already do. **Bite-tested both ways**: with the fix and
`language='en'`, 58/58 (exit 0); with the fix reverted and the same state, 1/58 FAILED again.

**And the fixture was writing a column that does not exist.** `seed-p2dev.php` passed
`'lang' => 'en'`. There is no `users.lang`; the column is `users.language`, and `'lang'` is not in
`User::$fillable`, so mass-assignment protection **silently dropped it** and the merchant got the
column default. Measured:

```
passing 'lang'     => 'xx'  ->  users.language = 'en'   DROPPED
passing 'language' => 'xx'  ->  users.language = 'xx'   HONOURED
```

Harmless today because the default is `'en'` anyway, but it meant the fixture's stated intent was
not real, and the language the menu block depends on was unpinned. Now `'language' => 'en'`.

### 10d. The BulkDelete premise — satisfied, and the procedure that could not satisfy it

The premise case added in `6e3564e5` **works, and fails loudly** rather than passing vacuously.
With `add_on_managers.is_enable = 0`:

```
FAIL  premise: the BulkDelete select-all column is present ...
      [NOT MEASURED -- no add_on_managers row for BulkDelete on this instance, so the two cases
       above passed without exercising the column that produced the defect]
```

What was missing was any documented way to **satisfy** it. `seed-p2dev.php` creates no
`add_on_managers` row and activates no module, and §6 of the handoff did not say to — so following
the procedure exactly gave **57/58**, the one failure being the harness correctly reporting an
unmet premise. Closed by `database/enable-p2dev-modules.php` plus the step in handoff §6.

**Both rows are required, measured not assumed.** With `add_on_managers.is_enable = 1` alone the
premise still failed `count=0` and the column did not render: `module_is_active()` for a type
`admin` merchant resolves `activated_modules_for_owner()`, which reads `user_active_modules`. A
`super admin` short-circuits to `true` before that check, so enabling only the first row and
driving the operator would have proved nothing about a merchant.

With the module genuinely active the menu holds exactly ten named entries and no blank:

```
["Name","Category","Brand","Label","Cover Image","Variant","Review","Price","Stock Status","Stock Quantity"]
```

against the eleven-with-one-blank that `helper.php:2210-2262` records as the pre-fix state. That
is the bulk-delete defect verified **under the condition that produces it**.

### 10e. The composer blocker is gone, and `ci.yml` still says it cannot be

```
$ composer install --no-interaction --prefer-dist --no-scripts
  237 installs, 0 updates, 0 removals                                   exit 0
```

`composer.json` and `composer.lock` were **not** modified (`git status` clean on both).

`ci.yml`'s `lockfile-reproducibility` comment states in capitals that `composer install` "CANNOT
SUCCEED ON ANY MACHINE until an owner decision lands". That was true when written and is **stale
on this branch**: the one dead package, `lahirulhr/laravel-payhere`, is absent from this branch's
lock and manifest (grep count 0). The ledger's Blockers table already carries B1 as cleared; the
CI comment did not get the same correction. This also retires the caveat on ledger row
**P2-02-12** ("Still not runtime-verified — needs the composer blocker lifted").

The prompt handed over to this session named `git.yoomoney.ru` as the blocker. `ci.yml`'s own
measurement already refuted that — both yoomoney packages fetch cleanly — and they fetched cleanly
here too (the archive URL answers 200).

**The constraint in THIS container is a third thing, and it is environmental, not a repo defect:**
the agent proxy answers **403 to every `api.github.com` zipball**, with or without credentials, so
composer cannot use `dist` for any GitHub-hosted package and clones all 237 from source instead.
Slow (~25 min), and it succeeds.

### 10f. What this section still does NOT verify

- **No MySQL run.** There is no MySQL server or client in this container, so every figure above is
  SQLite `:memory:` with `CACHE_STORE=array`. §9j's "No MySQL run" stands unchanged, and nothing
  here establishes any schema- or payment-sensitive behaviour on production MySQL.
- **Only one browser harness was re-run.** `check-pinned-column-live.cjs` is the one the two
  commits at HEAD changed. The other eleven live harnesses were not re-run in this session, so
  §9e/§9f/§9g remain evidence for the commits they were measured on.
- **No screenshots were retaken.** The 200 committed PNGs are still from their original runs.

---

## 11. `[2026-10-26]` R2 / F48 — the `env()`-in-Blade sweep, and what reviewing it found

### 11a. The census this row carried was wrong, and its own arithmetic said so

The ledger claimed **29 calls over three variables: `IS_MOBILE` x12, `APP_URL` x11, `APP_NAME` x4**.
That sums to **27**. Re-measured at `f15ef5fd`, keyed on the call rather than on a file list:

```
resources/views/   31 occurrences, 30 LIVE
                   IS_MOBILE        x12   (all in AppSetting/mobile_content.blade.php)
                   APP_URL          x10 live  (+1 inside the {{-- --}} comment on
                                               order/view.blade.php:38 that documents this defect)
                   APP_NAME         x4
                   default_language x4    <-- A FOURTH VARIABLE THE ROW MISSED ENTIRELY
packages/workdo/   45 live calls — NOT TOUCHED (vendored; CLAUDE.md)
```

Three PHP sites had to move with them, or the two halves of each flag would disagree under cache:
`app/Helper/helper.php:98`, `:2550` (`IS_MOBILE`) and `LanguageController:386` (`default_language`).
`app/` holds 92 `env()` calls in total; sweeping all of them is **not** this slice, and R2's declared
scope is the Blade views.

### 11b. The defect, measured rather than reasoned

R2 said plainly that what had been measured was "the 29 call sites and the framework's documented
behaviour" — **not** that the values actually come back null. With the flags set in `.env`:

| read | no cache | **with `config:cache`** |
|---|---|---|
| `env('IS_MOBILE')` | `'yes'` | **`NULL`** |
| `config('platform.is_mobile')` | `'yes'` | `'yes'` |
| `env('default_language')` | `'ar'` | **`NULL`** |
| `config('platform.default_language')` | `'ar'` | `'ar'` |
| `env('APP_URL')` | `'http://localhost'` | **`NULL`** |
| `config('app.url')` | `'http://localhost'` | `'http://localhost'` |
| `env('APP_NAME')` | `'Laravel'` | **`NULL`** |

All four were returning NULL on any deployment that caches config. The finding is now executed, not
inferred. **Still NOT measured:** whether this particular deployment runs `config:cache`.

### 11c. The ratchet, and the seven forms it was bitten on

`tests/Unit/Config/NoEnvCallsInFirstPartyBladeTest.php` — 3 cases. It **failed before the sweep**
(28 offender lines for 30 calls, two sharing a line) and passes after. Bitten by planting each form
in `themes/stylique/views/` and confirming the verdict flipped:

```
CAUGHT   env('X')                     CAUGHT   $_ENV['X']
CAUGHT   getenv('X')                  CAUGHT   $_SERVER['X']
CAUGHT   Env::get('X')                CAUGHT   env( split across two lines
CAUGHT   env('X') inside @php
ok       my_env('X')          -- not flagged, correctly
ok       a {{-- --}} comment  -- not flagged, correctly
```

### 11d. Independent review found four things. All four are fixed.

**(1) I had shipped a break, and no gate in this repository would ever have reported it.**
`tests/Support/Security/bin/verify-menu-render-parity.php` does not copy `menuTree()` — it
`file_get_contents`es `app/Helper/helper.php`, regex-extracts the function body and `eval`s it, *so
that it cannot drift from the shipped function*. It also runs deliberately with **no Laravel**,
hand-stubbing `route()`, `__()`, `__t()` and `env()`. Moving `helper.php:98` to `config()` therefore
made the eval'd body fatal:

```
PHP Fatal error: Uncaught Error: Call to undefined function config() in
  verify-menu-render-parity.php(133) : eval()'d code:11      === EXIT CODE: 255 ===
```

255 is outside the script's own `0 = match / 1 = diverge / 2 = not found` contract, and it died on
the one fixture that exercises the predicate — `&&` short-circuits on the name for every other row,
so the first four fixtures still passed and the failure looked selective. **`grep -rn 'Security/bin'
.github/` returns nothing**: CI does not run these scripts and they are in no PHPUnit testsuite, so
"Unit is 374 passed" did not cover it. Fixed with a `config()` stub that `require`s the real
`config/platform.php` — for the same reason `menuTree()` is eval'd, so a changed default reaches the
script. It now reports `ALL FIXTURES AND SELECTORS MATCH`, exit 0.

**(2) `config('app.name')` would have put "Laravel" on a customer's receipt.** The four `APP_NAME`
sites read `env('APP_NAME') ?? 'Ecommercego saas'`, so the shipped brand was the fallback whenever
`APP_NAME` was absent — and always, on a cached deployment. `config('app.name')` cannot reproduce
that: `config/app.php:16` defaults it to `'Laravel'`. Two of the four sites have no fallback at all
(`order/receipt.blade.php:12`, a customer-facing receipt title, and `email/test_mail.blade.php:200`,
the mail a merchant sends to check SMTP). Fixed with a `brand_name` key. Verified:

```
APP_NAME absent   -> 'Ecommercego saas'   (cached AND uncached)
APP_NAME=MyShop   -> 'MyShop'
```

**(3) The sweep changed which language can be deleted, and the guard was a hidden button.**
`language/index.blade.php:8,22` hides the delete control for `config('platform.default_language')`.
Before the sweep a cached deployment read `null` there and so always protected `'en'`; now it
protects whatever `.env` says, so a deployment with `default_language=ar` would have started
offering `'en'`. And `destroyLang()` had **no server-side guard whatsoever** — it computed
`$default_lang` and used it only to reassign users, so `destroyLang($default_lang)` would
`delete_directory()` the default language and then reassign every affected user **to the directory
it had just removed**. The route is a plain code-in-the-url, so the view's guard was one crafted URL
away. The hole is pre-existing; the sweep changed which language was exposed to it, which is reason
enough to close it rather than note it. `destroyLang()` now refuses the default **and**
`config('app.fallback_locale')` (they can differ, and deleting the fallback breaks translation
resolution platform-wide), with the message added to both `en.json` and `ar.json`.

**(4) My own ratchet was weaker than it read.** It reported **wrong line numbers** — stripping Blade
comments removed their newlines, measured at 56 lines of drift in `order/view.blade.php` alone, so a
failure message sent the reader to the wrong place; it is now blanked to its own newline count, with
a case asserting the line count is unchanged. It **missed** `getenv(` (which returns `false`, not
null, with `.env` unloaded), `Env::get()`, `$_ENV[` and `$_SERVER[`, and any `env(` split across
lines. It carried a **dead variable** that a reader would take for a check. And it did not scan
`themes/` — which `ViewServiceProvider:101,128` puts in `view.paths`, so the three committed themes
(44 blades) are first-party rendered views; currently clean, and now covered.

### 11e. Recorded, not fixed

**F55** — `packages/workdo/SidebarCustomization/.../super-admin/index.blade.php:178` carries the
byte-identical `IS_MOBILE` predicate and is now the stale half: with `IS_MOBILE=yes` the real sidebar
shows the `mobilescreensetting` row while the screen that configures it still hides it. Vendored, so
not edited. **F56** — `config('app.url')` defaults to `http://localhost`, so an absent `APP_URL` now
emits a bogus absolute url rather than an empty one; a release-checklist item, not a code fix.
**F57** — `$footer_text` at `layouts/guest.blade.php:95` is dead, which makes R2's change at that one
site unobservable.

### 11f. Suites

```
$ php artisan test --testsuite=Unit
  Tests:  375 passed (2051 assertions)                                  exit 0
          (372 before this slice; +3 ratchet cases)

$ php artisan test --testsuite=Feature --log-junit ... --log-teamcity ...
  Tests:  2 failed, 17 skipped, 5921 passed (30055 assertions)
  Duration: 1159.58s                                                    exit 1 (the 2 declared)

$ php .github/scripts/assert-known-test-outcomes.php ...
  testcases observed   : 5940  (manifest floor 1000)
  failures observed    : 2  (manifest declares 2)
  errors observed      : 0  (always expected: 0)
  skips observed       : 17  (manifest declares 17)
  OK: the observed outcome set matches the manifest exactly               exit 0

$ php tests/Support/Security/bin/verify-menu-render-parity.php
  ALL FIXTURES AND SELECTORS MATCH -- the DOM contract is preserved.     exit 0
```

**Identical to the pre-slice baseline** (§10a: 5921 passed / 2 / 17, 5940 testcases), so this slice
changed no Feature outcome. The manifest was not touched. The first Feature run of this slice was
**discarded rather than reported**: it was started before the review fixes landed and the tree
changed underneath it, so its verdict would not have been about any single state of the code.

---

## 12. `[2026-10-26]` P2-03-10 — row density, and the bug the check found

### 12a. What makes a merchant row 55px, measured rather than assumed

The ledger recorded "55px at every one of 1440 / 1280 / 992 / 768 / 390, with no control to change
it". Re-measured on the running product list, all five widths identical:

```
 1440px  rowH=55  td padding-block=11.2px/11.2px  line-height=21px  font=14px  action=32x32
 1280px  rowH=55  ... identical ...
  992px  rowH=55
  768px  rowH=55
  390px  rowH=55
```

**The text is not what makes it 55.** 21px of line box inside 2×11.2px of padding is 43.4px. The
rest is the **row-action control group** — a 32px `span.d-flex` in the last cell. Measured per cell
on both the product and order lists, nothing else in a row reaches 32:

```
/product  rowH=55  tallest: col 9 span.ss-badge 22px · col 11 span.d-flex 32px
/order    rowH=55  tallest: col 1 div.d-flex 28px · col 7 span.ss-badge 22px · col 8 span.d-flex 32px
```

So the row is `32 + 2*padding + 1px border` — the border counts because the `td` is `content-box`
(`border-bottom: 1px`, verified by reading the computed style, not the stylesheet):

```
comfortable   32 + 2 * 11.2 + 1  =  55.4  ->  55px
compact       32 + 2 * 4    + 1  =          41px
```

**The first arithmetic in the commit said 40px and the browser said 41.** The measurement was right
and the sum was short a border. The expectation was corrected, not the code — and the +1 is now in
the stylesheet's own comment so the next reader does not repeat it.

**The 32px control is deliberately left alone.** The acceptance asks for a 24px touch target at the
compact height; the control clears it with 8px to spare. Shrinking it to win a few more pixels
would spend the requirement to buy rows.

### 12b. The defect the browser check found, which a manual test would have passed

First run: **27 of 35**. Eight failures, and the interesting ones were not the height:

```
FAIL  it is stored in the SAME DataTables state as the column set and page length
      [stored={"k":"DataTables_product-table_/product","d":"comfortable"}]
FAIL  switching BACK to comfortable persists as well  [density=compact rowH=41]
```

**DataTables saves state DURING init, before `initComplete` runs.** The save hook read the live
density off `data-ss-density` on the container — which does not carry the attribute yet at that
moment — so it read `comfortable` and **wrote it over the stored `compact`**. The page then applied
compact a moment later, so the DOM looked correct for that view and only the NEXT reload came back
comfortable. That is the shape of bug a careless manual test passes.

Fixed by making a per-table map the source of truth and the DOM its projection, rather than trying
to sequence around the ordering. Keyed by table id, because one page can carry several tables.

### 12c. 35/35, and bitten three ways

```
$ node docs/development/phase-2/tools/check-row-density-live.cjs http://127.0.0.1:8899 1
  35 of 35 cases passed                                                    exit 0
```

Covering, item by item against the ledger's acceptance: the toggle renders; the table opens
comfortable at 55px; clicking switches to compact at 41px with 4px padding; `aria-pressed` tracks
it; the pressed state is an inset ring rather than a colour; the actions still measure 32×32 and
**Edit is really clicked, not merely measured**; the choice survives a reload; it is stored in the
same `DataTables_*` localStorage entry as the column set and page length; **a different table is
unaffected and opens comfortable** (the acceptance says "per table"); switching back persists too;
and both densities in all four LTR/RTL × light/dark configurations, with the accessible name
asserted as Arabic on the RTL legs and non-Arabic on the LTR ones.

Bite-tested, each removal failing exactly its own cases and no others:

```
remove the compact padding rule          ->  8 of 35 FAILED   (rowH back to 55)
save reads the DOM instead of the map    ->  2 of 35 FAILED   (reproduces the original defect)
drop aria-pressed                        ->  3 of 35 FAILED   (incl. the non-colour indicator)
```

`RowDensityTest` — 4 tests / 24 assertions on the wiring — bitten two ways: removing the button
from the helper fails 2 cases, and collapsing the two state hooks back into one shared function
(the P2-06-4 shape) fails exactly the case that exists for it.

### 12d. Screenshots

8, in `docs/development/phase-2/screenshots/p2-03-density/`, both densities × LTR/RTL ×
light/dark at 1440, each stamped with its measured row height at capture time:

```
comfortable-{ltr,rtl}-{light,dark}-1440.png   row=55px
compact-{ltr,rtl}-{light,dark}-1440.png       row=41px
```

Reviewed, not merely captured: the RTL/dark compact shot shows nine rows where LTR/light
comfortable shows six, the toggle is present and visibly pressed, the row actions and status
badges are intact, and BulkDelete's select-all column is rendering — so the module really was
enabled for the capture.

### 12e. Suites, on the final tree

```
Unit      375 passed (2051 assertions)                                  exit 0
Feature   2 failed, 17 skipped, 5925 passed (30079 assertions)          exit 1 (the 2 declared)
          gate: 5944 testcases, 2 failures (declares 2), 0 errors,
                17 skips (declares 17) -- outcome set matches exactly   exit 0
Contract  26 passed (83 assertions)                                     exit 0
verify-menu-render-parity.php
          ALL FIXTURES AND SELECTORS MATCH                              exit 0
```

5925 is 5921 + the 4 new wiring tests; no existing Feature outcome moved, and the manifest was not
touched.

**One claim in this section was an overclaim, and review caught it.** It read: "the parity script
matters here specifically ... so it is a real check on this change rather than a formality." It is
not. `verify-menu-render-parity.php` extracts **`menuTree()`** out of `helper.php`
(`:156-160`) and never touches `buildDataTable()`, so it would have matched whatever this slice
did. It is a guard on the file being parseable and on `menuTree()` being unmoved -- worth running
when `helper.php` changes, which is why it is listed -- but it is not evidence about the density
work.

### 12f. Independent review of P2-03-10, and the eight things it found

`helper.php` builds all 68 merchant tables, so the brief requires independent review for it. The
review drove the running application rather than reading only, and found eight real items. All
eight are fixed or corrected; none was waved off.

**The one that would have shipped broken.** `layouts/app.blade.php` loads the design-system assets
with hand-set cache-busting versions — `css/store-syria-ds.css?v=p02-2`, `js/store-syria-ds.js?v=p02-3`
— and the opt-in partial's own comment says in as many words *"Bump `?v=` when store-syria-ds.css
changes."* This slice changed **both** files and bumped **neither**. On deploy, a merchant who used
the admin yesterday serves the OLD JS and CSS from cache while the server renders the new button:
`StoreSyriaDS.tableDensity` is undefined, both state hooks and the action no-op, and the compact
rule does not exist — **a new control that does nothing at all** until the cache expires. And a
Playwright context always starts cold, so `check-row-density-live.cjs` is *structurally incapable*
of seeing it. Five references bumped to `p02-4`.

**The pressed state was announced to nobody.** Buttons 1.3.1 renders every control as an
`<a href="#">`. Measured on the rendered DOM: the computed role is `link`,
`getByRole('button', {pressed:false})` matched **nothing**, and `aria-pressed` is not a permitted
attribute on `role=link`. Space scrolled the page 400px instead of toggling, and Buttons' own
`blur()` dropped focus to `<body>` after every toggle. So the control carried a state it did not
convey — the F38/F39 lesson one level up. Now `role="button"`, a Space handler, and a focus return
**deferred by a tick** (a synchronous `focus()` ran *before* Buttons' `blur()` and was simply
undone — measured as `activeElement=theme-3`). The live check now asserts this through the
**accessibility tree**, not the attribute, because the attribute was present and inert throughout.

**The non-colour indicator was colour-only where it mattered.** `box-shadow` is forced to `none` in
forced-colors mode, so the inset ring vanished and the only remaining difference between pressed
and unpressed was `background-color` — the one signal the comment claimed to avoid. (It also
claimed a border change; the rule changes no border.) A `@media (forced-colors: active)` outline
carries it there, and the comment now says what the rules do.

**The focus ring read a token that does not exist.** `var(--ss-focus, #2563eb)` — and `--ss-focus`
is defined nowhere in the stylesheet, so every focus ring on this control was the hard-coded blue:
the only blue ring in a teal admin, and **2.77:1** against the dark card `#292a33`, under WCAG 2.2
SC 1.4.11's 3:1. The design system's tokens are `--ss-focus-ring` / `--ss-focus-width` /
`--ss-focus-offset`, used by fifteen other rules in the same file; they give 8.42:1 in dark. Nothing
catches a dangling `var(--ss-*)`.

**The restore was on the wrong hook.** Read out of the shipped `datatables.min.js`,
`stateLoadParams` fires *before* DataTables checks the stored state's column count and age — so
density was being restored out of state DataTables had decided to discard. Moved to `stateLoaded`,
which runs only on the accepted branch. That also removed a dead `$sanitizeState` variable and
restored the symmetry the previous comment claimed was impossible: the two legs only diverged
because the restore was in the wrong place.

**Two of my own claims were wrong in the reassuring direction.** The 27% reduction holds only on
rows whose tallest content is the row-action group; `ProductDataTable.php:120` emits
`<img width="100">` with no height, so a real store with product photographs has ~122px rows where
compact saves ~12%. And the state map was justified by "the dashboard carries two tables" —
measured, `/dashboard` carries **zero** helper-built DataTables, and no view in the repository
renders two. The map is still right, for the ordering bug it fixed; the reason written down was
not. Both corrected in place.

**Two test assertions passed on prose.** `test_the_stylesheet_and_the_behaviour_module_carry_the_rule`
read both files with comments intact, so a comment mentioning the symbol satisfied it — and these
files' comments do mention `tableDensity`, `densityState` and `applyDensity`. Comment the rule out,
keep the commentary, and it stayed green. Comments are now stripped first, and the fix is
bite-tested against exactly that scenario. The action assertion was two separate substrings, both
of which survive the failure they exist to catch; it is one regex now.

**Harness hygiene.** Both tools ended on a hard-coded `setServerState('light','rtl')` regardless of
what they found, silently leaving the dev fixture in RTL for whoever worked next — the review found
the store sitting that way. They now read the state first (`read-preview-state.php`, new) and put
it back. Verified: `dark/ltr` before → 38/38 → `dark/ltr` after.

**What the review confirmed clean**, with evidence rather than assertion: the CSS attribute lands
only on the DataTables wrapper, which holds exactly one `table.dataTable` on every screen, and no
DataTable in the 68 renders a nested table; the pinned column is correct at the compact height in
light, dark and RTL; the state map holds under duplicate ids, missing ids, re-initialisation and
navigation (none of which occur); **no new tenant or personal data is stored** — the key is
`DataTables_<tableId>_<pathname>` as before, and the one new value is the literal
`"comfortable"`/`"compact"`; a poisoned `ssDensity` in localStorage cannot inject, because both legs
validate against `DENSITIES` before `setAttribute`; and pre-slice stored states are backward
compatible. It also **refuted one of my premises**: there are no `buildDataTable()` tables outside
the admin layout at all, so the "visible button, inert hooks" degradation has no reachable instance
today — the deploy-day cache case above is the reachable version of it.

**Left open, deliberately, and recorded rather than fixed:** the table header does not compact (it
stays 49px while body rows go to 41px); the rule's `> th` limb is dead, since DataTables builds body
cells as `td`; and at 390px in English the toggle sits 225px beyond the visible strip — the
unresolved P2-03-1 reachability problem, now with one more control behind it. The review measured
that a fifth button does **not** make that layout worse (it is right-anchored, so the group extends
leftward and the required scroll *drops* from 159px to 93px), but the control is still unreachable
there without dragging the table sideways. That belongs to the 390px row, not to this one.

---

## 13. `[2026-10-26]` F16 — the "environmental" blocker was a 404, and fixing it surfaced seven more findings

### 13a. The previous session's blocker was not environmental

P2-03-3 was left PARTIAL with its end-to-end save "NOT VERIFIED, and the reason is environmental,
not a defect in this change": `Dropzone is not defined` and `$(...).select2 is not a function` on
the product editor, present on the committed page too, so it read as a missing deployment asset.

It was **F16**. `resources/views/partision/footerlink.blade.php` — a partial the admin layout
*includes*, so on **every admin page** — addressed three scripts as `asset('public/...')`, and
`asset()` already serves from `public/`, so the segment doubled:

```
/public/assets/js/plugins/dropzone.min.js   404      /assets/js/plugins/dropzone.min.js   200
/public/assets/js/plugins/select2.min.js    404      /assets/js/plugins/select2.min.js    200
/public/js/html2pdf.bundle.min.js           404      /js/html2pdf.bundle.min.js           200
```

Both 404s and both 200s measured against the running application. The consequence was not
cosmetic: `#submit-all`'s handler reaches for `Dropzone` in its first statements, so **the product
editor could not save at all**, and its tag and category pickers were inert.

### 13b. End-to-end UPDATE, proven

After the fix the editor loads with **zero page errors and zero failed js/css requests**, and
`typeof Dropzone === 'function'` with `jQuery.fn.select2` present. A real edit, saved through the
**sticky** bar with the page scrolled to the bottom and the header Save confirmed off-screen,
reached `POST /product/32`, wrote the row, and the new name appeared in the product list:
`PERSISTED AND LISTED: true`.

Four blockers had to be cleared first. Three were fixture gaps of the F33 class, each measured
rather than guessed — no categories (so `category_id` could never be satisfied), no Plan (so
`$plan->max_products` was read on null), and `Edit Product` granted where the controller checks
`Edit Products` (so update answered a silent `redirect()->back()`). The fourth was a real product
defect: **F59**, `update()` writing NULL into the NOT NULL `products.track_stock`.

### 13c. CREATE is still blocked, and not by anything in this change

**F58.** `products.cost_price` is a GATE F column with no migration, while `store()` validates it
as required and writes it unconditionally. A full create through the real UI answered
`table products has no column named cost_price`. Applying the parked draft is a persistent
migration, which CLAUDE.md §2 and the owner's standing instruction both put behind explicit
approval, so it is recorded and **not acted on**.

### 13d. What reviewing the F16 fix found

Asked specifically what the fix made newly *reachable*, independent review produced five rows, and
the question was the right one to ask:

- **F62** — with select2 loading, the layout's global `tags: true` init turns the product tag field
  into a free-text control, and two of the four `Tag` creation sites never set the NOT NULL
  `tags.slug`. Measured: before, `#tag_id` accepted only its three seeded options; after, a typed
  value serialises as `tag_id[]=zz-brand-new-tag`. Fixed, two lines.
- **F63** — the fix armed `Dropzone.autoDiscover` on every admin page. Fixed with one
  `autoDiscover = false`.
- **F64** — correcting html2pdf's path would have put 469 KB on every admin page with no consumer
  there. **Removed rather than corrected**, after reading the bundle to confirm it defines
  `window.html2pdf` and nothing else.
- **F65** — four more live admin screens carried the same defect. Fixed; `grep` now shows **zero**
  live `asset('public/...')` on any view reaching `layouts.app`.
- **F61** — two more NULL-into-NOT-NULL writes of F59's exact class survived in `update()`.
  Fixed.

**F60 was WITHDRAWN before it became a defect row.** Measuring a rejected save I found `toastr`
undefined, `#toast-container` absent and `show_toastr()` producing no element, and was one step
from recording "a rejected product save tells the merchant nothing" on every save. `custom.js:230`
does not use toastr: it calls `notifier.show(...)`, and `div.notifier.danger.shown` **is** rendered
with the message in it. A probe keyed on the library I expected found nothing and read as absence —
the census mistake CLAUDE.md already names, in a new costume.

**Also recorded, not fixed:** F66 (`required` + select2 on `/order/create` loses the browser's
validation bubble — measured 535.5 × 41.375 before, **1 × 1** after) and F67 (the global
`tags: true` gives four more controls free-text input).

---

## 14. `[2026-10-26]` P2-03-8 — basic and advanced field organisation

### 14a. What moved, and the one field that deliberately did not

The editor's main card held **thirteen** controls in one flat grid, so the six a merchant sets once
for the whole catalogue competed for attention with the five they set on every product. The six —
`tax_id[]`, `tax_status`, `brand_id`, `label_id`, `shipping_id`, `product_weight` — are now behind
one disclosure. `name`, `slug`, `category_id`, `tag_id[]`, `price`, `sale_price` and `cost_price`
stay exactly where they were.

The advanced six were **not contiguous**: `tag_id[]` sat between them. It was moved up beside
Category rather than collapsed, and the reason is technical, not aesthetic: **it carries `required`
in the markup**, and a `required` control inside a closed `<details>` is hidden from the browser's
own constraint UI, so Chrome refuses the submit and logs *an invalid form control with
name='tag_id[]' is not focusable* — a Save button that does nothing, with no message on the page
and nothing in the server log. (The same failure mode, by a different mechanism, is **F66**.)
`required` is also what the component's own test asserts can never appear inside it.

### 14b. It is a native `<details>`, and that is the whole design

Not the DS's existing `[data-ss-disclose]` button/target pair, because:

- **A closed `<details>` still submits the controls inside it.** That is the guarantee the slice
  needs. `disabled` would drop them from the body silently, and because they would then be *absent*
  rather than empty, a `nullable` rule would pass and the saved product would quietly lose its
  brand and its tax.
- It opens, closes and announces its state with **no JavaScript** — not hypothetical on this
  codebase, where F16 had two libraries 404ing on every admin page.
- `<summary>` is already a button to the accessibility tree with its expanded state exposed, so
  there is **no hand-written `aria-expanded`**; a native details that also carries the attribute
  reports its state twice and the two can disagree.

### 14c. Preservation: 502/502, and not one of them is a field count

`docs/development/phase-2/tools/inventory-product-fields.cjs` reads the **rendered DOM** and diffs,
per control: name, value, `disabled`, `readonly`, `tag`, `type`, `multiple`, form membership, `insideDetails`, the form's
method and action, and six validation attributes (`required`, `min`, `max`, `step`, `minlength`, `maxlength`, `pattern`).
A source grep cannot answer this — it misses controls injected by JS and it cannot see `disabled`,
which is the actual regression the slice risks.

```
create  44 named controls   POST /product        disabled=[none]  pageErrors=0  failedAssets=0
edit    47 named controls   POST /product/32     disabled=[none]  pageErrors=0  failedAssets=0
DIFF    PASS 1048/1048
```

Taken with **the fixture held constant** and the baseline captured against the *committed* views, so
no save could drift it — the first attempt reported `tax_status "taxable" -> "shipping_only"`
because the behaviour check had saved a product in between, and that requirement is now written into
the harness's own header.

**Bitten eleven ways.** Ten against the differ — a collapsed field `disabled`, removed, re-parented
out of the form, given a `required`, the submit path changed, a value silently lost, a volatile field
emptied — and an eighth through the real browser, by putting `disabled` in the blade and
re-capturing. Each produced exactly its own failure, and the clean pair still passed.

### 14d. Condition 2, proven on the wire

With the section **closed**, `product_weight` and `tax_status` were changed and saved through the
sticky bar. Both were found **in the multipart request body** and then in the reloaded row.

Reading the body was necessary rather than fussy. `#submit-all` does not submit the form natively —
it assembles a `FormData` from `$('#choice_form').serializeArray()` and posts it with
`contentType: false` — so the body is multipart, and an earlier run that parsed it as urlencoded got
`null` for *every* key and reported the collapsed fields as dropped when `new FormData(form)` showed
them present. Two wrong readings in one case, both from the harness.

The other half of condition 2 is server-side and JS-free: a section whose field failed validation
renders `open` with a textual **Needs attention** badge, `tax_id[]` matching the `tax_id` error key.
`tests/Feature/Merchant/AdvancedFieldsDisclosureTest.php` covers it in 14 tests, and both error
cases fail when either the open-on-error condition or the key matching is removed.

**That capability is NOT reachable on these two pages, and §14i says so** -- `ProductController`
never calls `withErrors()`, and none of the six fields has a rule that can fail.

That test also found a bug in itself worth recording: data passed to `Blade::render` is a local of
the **outer** template, so a component rendered inside it never sees it. Production works because
`ShareErrorsFromSession` *shares* `$errors` globally. The test now shares it, which is also a
statement about what the component depends on.

And reviewing my own key mapping found a second: `rtrim($name, '[]')` is right for `tax_id[]` and
silently wrong for a nested name — `options[0][price]` becomes `options[0][price` instead of
Laravel's `options.0.price`. Six fields use the component today and only one is bracketed, so it
would not have surfaced until a later slice collapsed a repeater field. Fixed, with its own test,
bitten.

### 14e. Condition 4, measured in all four configurations and on mobile

Direction and theme are **server-side** settings (`SITE_RTL`, `cust_darklayout`), so
`check-advanced-fields-live.cjs` flips them with `set-preview-state.php` rather than setting an
attribute the layout never reads — ledger finding F8 is that mistake, recorded twice — and restores
them in a `finally`.

| configuration | summary title | summary text | box border |
|---|---|---|---|
| light / ltr / en | `Advanced options` | ≥4.5:1 | ≥3:1 |
| light / rtl / ar | `خيارات متقدمة` | ≥4.5:1 | ≥3:1 |
| dark / ltr / en | `Advanced options` | ≥4.5:1 | ≥3:1 |
| dark / rtl / ar | `خيارات متقدمة` | ≥4.5:1 | ≥3:1 |

Both ratios are computed in-browser from the resolved colours, not asserted from the token table.
At **390px**, in both directions: no horizontal page overflow open **or** closed, the summary inside
the viewport, and a tap target ≥24px (WCAG 2.5.8). RTL mirrors the collapsed chevron and both
directions rotate it to point down when open, and both text nodes' contrast is measured, not
just the summary's. **100/100.**

`--ss-border-strong` is used rather than `--ss-border` because the latter measures **1.30:1** against
the white card it sits in and **1.14:1** against its own fill — the box would have been very nearly
invisible in light mode, and the grouping it exists to express would not have read at all.
`--ss-border-strong` is 3.16:1 light and 3.66:1 dark. The first draft also used a token that does not
exist (`--ss-bg-subtle`), caught by checking every property against the file rather than trusting the
name — the same class of mistake as `--ss-focus` in the previous slice.

### 14f. Three measurements that were my harness, not the product

Worth recording because each looked like a defect and was not:

- **`offsetParent !== null` is not a visibility test inside a closed `<details>`.** Chromium hides
  it with `content-visibility` on `::details-content`, which leaves a plain control's
  `offsetParent` non-null — it reported `tax_status` and `product_weight` as laid out while
  collapsed, and the four Choices-managed selects read correctly only because their widget sets
  `display: none`. `checkVisibility({contentVisibilityAuto: true})` is the probe that answers it.
- **The chevron transitions**, so `getComputedStyle` straight after toggling `open` returns the
  value at t=0. In RTL that made closed and open both read `matrix(-1,0,0,-1,0,0)` and failed
  against correct CSS; in LTR it happened to differ only because `none` → 90deg does not
  interpolate the same way. Settled before measuring.
- **The save "failed"** because the fixture product has no `cost_price`, which is
  `required|numeric|min:0|lte:price` on update, so the server correctly refused it with
  `{"flag":"error","msg":"Cost price is required."}` — in Arabic, which incidentally confirmed one
  of the five strings added in this slice. The harness now satisfies that rule first.

### 14g. Bilingual gaps closed

Five strings added to **both** `en.json` and `ar.json`, with every pre-existing value left
byte-identical and the key count asserted. Three of them are Phase 02's own untranslated messages,
including the concurrent-edit warning from P2-03-5 — which an Arabic merchant met as a
200-character wall of English. A test asserts both files carry all five, that none is empty, and
that the Arabic values actually contain Arabic script rather than the English copied across.

Recorded and **not** changed: `ar.json` translates `Shipping` as `النقل البحري` — *sea freight* —
which is wrong for a shipping *class* field. It is a pre-existing WorkDo string used in many places,
so changing it belongs to a translation pass, not here.

### 14h. What this slice does not claim

The editor was not rebuilt and the product engine was not touched. No field was renamed, no
validation rule changed, no pricing or stock logic added. The AJAX error path reports through a
toast, so it cannot open a section for a *server*-rejected collapsed field — harmless today because
no collapsed field has a validation rule, and recorded rather than hidden. `/attribute-option` and
the Flash Sale modal (F65) were fixed at the path level, verified 404 → 200, and **not driven**: one
500s on this fixture for lack of attribute data, the other needs its module enabled.

### 14i. `[2026-10-26]` Reviewing this slice found a defect in the UI and five in the verification

The independent review of P2-03-8 is the most productive one this phase has had, because almost
everything it found was in the *safety nets* rather than in the change.

**One user-visible defect — F68.** The disclosure's hint was failing AA **in the slice that
measured contrast in four configurations**. `.ss-advanced__hint` is a `<p>`, and the host's dark
stylesheet does `.dataTable-info, p { color: #808191 !important; }`, so the token lost and the
hint computed to 3.85:1 on the dark card while the summary two lines above read 6.79:1. The DS
file already carried both the mechanism and the warning — a block headed *Host override defence*
listing the five classes that may sit on a `<p>`. The hint was a sixth and was not on it. My check
sampled `getComputedStyle(summary).color` and nothing else: two text nodes in the component, one
measured, and the result reported for the whole thing. Fixed (6.79:1 dark, 5.23:1 light), bitten,
and the live harness now measures both nodes in all four configurations.

**Two tests that could not fail — F69 (1) and (2).**

- `slotOf()` returned the region starting at the component's **opening tag**, which carries
  `:fields="['tax_id[]', 'tax_status', ...]"`. So all six names were in the string whether or not
  a single control was inside the disclosure. Delete every field and the test still passed. The
  helper strips Blade comments specifically so no assertion can be satisfied by the prose
  explaining it — and was then satisfied by the component's own argument list.
- `test_the_required_fields_stay_outside_the_disclosure` searched for the quoted PHP literal
  `'category_id'`, but the create view writes that control as raw HTML `<select
  name="category_id">`. So moving Category into the disclosure would have passed that test *and*
  the required-attribute test beside it, leaving a server-side-required field collapsed: precisely
  what it exists to prevent.

Both now assert control **shapes** (`name="tax_id[]"`, `Form::select('tax_status'`) and both
spellings of a field name, and both were bitten — deleting the `brand_id` control while leaving
its name in `:fields` now fails, and planting a raw-HTML `name="category_id"` inside now fails.

**Three blind spots in the gate — F69 (3), (4), (5).** It captured `readonly`, `tag`, `type` and
`multiple` and compared none of them, so a `<select name="brand_id">` swapped for an
`<input type="hidden">` with the same value passed the whole thing. It had no notion of *where* a
field lives — the one dimension this slice changes — so a new field added inside the disclosure
carrying `required` was printed as `note field ADDED` and passed. And the live harness hardcoded
`newWeight = '7.77'` and then saved it, so the fixture came to hold 7.77 and that assertion passed
on every later run regardless. All fixed; the gate went **502 → 1048 checks**.

**Two harness honesty problems.** The language was never restored, and because
`/change-language/ar` rewrites `SITE_RTL` *and* `layouts/app.blade.php:48-50` forces RTL whenever
the language is `ar`, every admin page in the fixture rendered Arabic-RTL after any run while
`read-preview-state.php` reported `light/ltr`. A restore that reports success while leaving the
fixture switched is worse than none. Fixed, and the harness's claim that direction and language
are independent settings is corrected — they are not, which also means English-with-RTL is
reachable and untested. The rejected-save case counted *any* toast; it now asserts the message.

**A gap against owner condition 2 — F70.** The server-side "open the section on error" path is
built, tested and correct, and **cannot fire on either product page**: `ProductController` never
calls `withErrors()` (it returns JSON for the AJAX handler), and four of the six fields have no
rule while `brand_id`/`label_id` are `nullable`. Four comments stated it as live behaviour; all
four are corrected. Closing it properly means the error response naming the failed field, which
changes a response shape the Flutter app may read — so it is recorded, not quietly done.

**And one fix that was tried, measured, and reverted.** The review asked for the one-line fix to
the duplicate `id="tax_id"` (recorded long ago as P3-06). Measured: that duplicate is what has
been keeping Choices.js **off** the `tax_status` control. A unique id makes Choices take it over —
`display: none` on the native select, options moved into the widget. The submitted value is
unaffected either way, but a field changing from a bare native select to a Choices widget is a
visible change to a control, and this slice's contract is that nothing about a field changes except
where it sits. Reverted, with the measurement recorded on P3-06 for its own slice.

**What the review confirmed rather than refuted:** a closed `<details>` really does submit its
contents, verified independently with variant mode on, with `.product-weight` hidden, and with
stock management off; no `required` control can reach the inside in any state produced; every
token the new CSS references resolves in both themes and nothing leaks outside the component; the
volatile-field exemption in the gate is exactly as advertised; and tab order, tap target,
accessible naming and forced-colors all behave. It also noted the stated *mechanism* for claim 2
is not the one in play — the editor never performs a native submit, so `serializeArray()`'s
visibility-blindness is what carries it — which is now said in the component rather than implied.

---

## 15. `[2026-10-26]` F58 — the `products.cost_price` migration, and the create it unblocked

The owner approved F58 in writing, scoped to isolated development and test environments, with
existing data preserved and no production migration run. This records what was executed.

### 15a. What F58 was, and why the suite could not see it

`ProductController::store():131` validates `cost_price` as **required** and `:303` writes
`$Product->cost_price` unconditionally, while `products.cost_price` was declared by no migration.
So on every database built from this repository — a fresh install, CI, and the dev fixture —
creating a product was impossible. Production was unaffected, because production has the column.

The suite was structurally blind to it: no test creates a product through `store()`. Every one
uses `Product::forceCreate([...])` and omits `cost_price`, and
`ProductConcurrentEditTest::makeProduct()` even carries a comment explaining that the field is in
the *payload* and not the fixture because the column does not exist on the test schema.

### 15b. The migration, and the four safety properties — measured

Promoted from `_gate_f_pending/0004_...draft` with production's own type, read off a restored copy
on 2026-10-24: `decimal(15,2) NOT NULL DEFAULT 0.00`
(`docs/GATE-F-VERIFIED-SCHEMA.md:382`, populated on all 45 products, 9 non-zero, summing 1170.65).

| property | how it was established | result |
|---|---|---|
| a fresh environment gets the column | `migrate` on a throwaway SQLite | `numeric NOT NULL DEFAULT '0'`, `hasColumn` true |
| it is idempotent | second `migrate` | *Nothing to migrate*, planted `7.25` intact |
| **it is a no-op in production's shape** | column present, migration row deleted, then `migrate` | ran in **1.40ms**, planted **12.34** untouched, column unchanged |
| **rollback destroys nothing** | `migrate:rollback --step=1` | ran in **0.14ms**, column still present, **12.34** still there |

The third and fourth are the ones the approval turns on. `up()` returns early when the column
exists, so the migration cannot alter production even if it were run there; `down()` is empty
because an earlier version of it dropped the column whenever present and, against a restored copy
of production, destroyed all 45 cost values in 30.28ms. An `up()` that creates nothing paired with
a `down()` that drops regardless is the asymmetry, and it is why that body stays empty. The
rollback plan is therefore "there is nothing to roll back", and the migration says so.

The draft also **contradicted itself** and that is corrected in the promoted file: its header
argued for `nullable, default null` — on the good ground that `NOT NULL DEFAULT 0.00` cannot
express "cost unknown", and in the same dump 36 of 45 products read 0.00 while 89 of 92
`store_order_financials` rows carry `product_cost = 0` and a `gross_profit` equal to full revenue —
while its *code* used `->default(0)`. The reconciled production type ships, because this
migration's job is to bring a fresh environment into line, not to redesign a live money column.
Making it nullable remains the owner's call and is recorded, not silently taken.

### 15c. Product CREATE, driven end to end

`docs/development/phase-2/tools/check-product-create-live.cjs` — **9/9**. It fills the text
fields, picks a category through its real Choices dropdown and a tag through select2, attaches a
cover image to the file input, hands a gallery image to the live Dropzone instance via
`addFile()`, saves through the sticky bar, and then finds the product in the list.

**Bitten:** dropping the column from the fixture and re-running reproduces
`SQLSTATE[HY000]: ... table products has no column named cost_price` verbatim and fails 3 of 9;
re-running the migration restores 9/9. So the migration is demonstrably what unblocked it, rather
than something else having changed.

**Three harness defects had to be fixed first, and each produced a false reading:**

- The category pick selected the **placeholder** `Select Category`, whose value is empty, so the
  server answered *"The category id field is required."* — which read as a create failure when it
  was the harness choosing nothing.
- `request.postData()` returns **null** for a multipart body carrying a real file, so the harness
  reported "no POST was issued" while the server's reply to that very POST was in hand. The
  request is now tracked with its own flag and the persisted row is the proof.
- `setInputFiles` on `[data-toggle="dropzone1"] input[type="file"]` hit a **decoy** input
  (`#dropzone-1`, part of the preview *template*); Dropzone's real `hiddenFileInput` is appended
  to `<body>`, detached from the element. So `dropzone.files` stayed empty behind a template
  `.dz-preview` that made it look as though a file had landed, and the server refused with *"The
  product image field is required."*

### 15d. MySQL — prepared, and NOT collected

`tests/Support/Security/bin/verify-cost-price-migration-on-mysql.php`, registered with
`run-mysql-verifications.sh`, establishes the five things SQLite cannot answer: the exact
`decimal(15,2)`, the engine-enforced scale (12.345 → 12.35), the INSERT-time
`ER_BAD_NULL_ERROR (1048)`, the production-shaped no-op, and the harmless rollback. It creates and
drops its own database and **refuses a database name containing neither `costprice` nor `verify`**
— verified by pointing it at `production`, which it declined.

**It has not been run.** This container has no `mysqld`, no `mariadbd` and no `mysql` client; the
script exits 2 with *Connection refused*, which is the correct behaviour and not evidence. SQLite
reports any decimal as `numeric` and stops enforcing scale and NOT NULL, and this project has been
bitten twice by exactly that. **F58 is not closed on MySQL until this runs.**

### 15e. The fixture gap that was blocking P2-03-4 as well

Surveying the media and variant UX found `product_attributes`, `product_attribute_options`,
`product_variants` and `product_images` all at **0 rows**, so the variant UI rendered an attribute
picker with nothing in it and the gallery had nothing to show — neither half could be driven at
all. Same class as the categories/Plan/permission gaps that blocked P2-03-3 (F33). The seeder now
creates two attributes with five terms, six variants on a real variant product, and six gallery
images with **real PNG files on disk**, because a broken `<img>` is indistinguishable from a
missing affordance when surveying a screen.

Its first attempt was **refused by a Phase 01 security control**, which is worth recording as a
success: `StoredPathPolicy` (P1-SEC-28) rejected `uploads/p2dev/...` because the first segment
under `uploads/` must be the owning store's folder. The fixture conforms now.

And the survey itself had to be corrected twice before it told the truth — it read the **native**
`<select>` that Choices.js empties, and so reported "no attributes are seeded" when both were
present and offered; and its attribute pick was unscoped, so it clicked the *Category* dropdown
and reported picking `"Select Category"`. Both are the documented Choices behaviour this
repository already records, and the harness did not honour it.

---

## 16. `[2026-10-26]` F70 — the error now opens the section it belongs to

Approved by the owner with the condition that Flutter and the existing API contracts keep working.

### 16a. Why the gap existed, and why it had two halves

The editor posts by AJAX, so a failed save never re-renders the page. The response was
`{flag, msg}` — one message, no field names — so the client could not mark the offending control,
could not build a summary, and could not open the collapsed section the failed field lives in. A
merchant whose weight was rejected got a toast while the box holding the weight stayed shut.

And **none of the six fields the P2-03-8 disclosure holds had a validation rule at all**, so
nothing in that section could fail even in principle. Fixing only the plumbing would have left an
untestable feature.

### 16b. What changed, and why Flutter is not at risk

1. `ProductController` adds `errors` (keyed by field) to both validation-failure responses.
   **Additive**: `flag` and `msg` are byte-identical, so no existing reader changes behaviour.
2. `StoreSyriaDS.applyFormErrors()` applies it — marks each control `is-invalid` and
   `aria-invalid`, writes an inline message (placed after the Choices/select2 wrapper where one
   owns the control, or the note would be invisible beside a `display:none` native element), sets
   `aria-describedby`, builds the summary with `role="alert"`, and **opens the `<details>`**,
   flagging it and appending the textual *Needs attention* badge. Text is set with `textContent`,
   never `innerHTML`, because these strings interpolate merchant-entered values.
3. `product_weight` gained `nullable`, `numeric` and `min:0` in all four rule sets. The column is
   nullable, so an empty weight stays valid exactly as before; what changes is that a negative or
   non-numeric weight is refused with a message instead of reaching a column the reporting path
   reads as a number.

**Flutter compatibility is measured, not assumed.** The shipped app posts product writes to
`api/admin/addproduct` and `/updateproduct` (`StoreSyria_app98/lib/utils/base_api.dart:3,57,85`),
which are `Api\DashboardController` — a different handler on a different route. The web routes
changed here are reached only by this editor's own jQuery, and the Contract suite asserts nothing
about them. Contract stayed at 26/26.

### 16c. Driven, 28/28

`docs/development/phase-2/tools/check-form-errors-live.cjs`. With the section **closed**, a
negative weight is written and the save fired. Then:

| | |
|---|---|
| response shape | `flag` unchanged, `msg` unchanged, `errors.product_weight` present |
| the save | refused, page did not navigate |
| **the section** | **opened**, flagged `data-ss-advanced-error`, textual badge appended and localised |
| the field | `is-invalid`, `aria-invalid="true"`, `aria-describedby` → its own note, and **on screen** |
| the summary | rendered, `role="alert"`, naming the problem |
| the old path | the existing toast still fires — nothing regressed |
| afterwards | a corrected value saves, every error marker is gone, and the section is closed again |

**Bitten twice**, each failing exactly its own cases: removing the server's `errors` key, and
neutering the section-opening in the DS module.

### 16d. The harness defect worth recording

The first attempt wrote `'abc'` into `product_weight` and the save **succeeded**. `product_weight`
is `Form::number()`, and assigning a non-numeric string to an `<input type="number">` is
**rejected by the browser** — the value stays empty, which `nullable` accepts. So the case proved
nothing while looking like it had. `-5` is a value the input accepts and `min:0` refuses.

The harness now also asserts that the invalid value really landed in the control before firing the
save, and guards the post-save reads against an unexpected navigation — without that guard the run
died on a null dereference instead of reporting which case failed.

---

## 17. `[2026-10-26]` F46 — the order's customer, and the census that made the fix ten times bigger

Approved by the owner with security and integration tests and no regression in payments or orders.

### 17a. The finding was recorded against one file. The block exists in ten places.

`PaymentService` built both the order's reference and its `customer_id` from
`$request->customer_id` for a caller `auth('customers')->check()` had **already authenticated**.
A census for the same block found it byte for byte in nine files:

| file | sites |
|---|---|
| `app/Services/PaymentService.php` | 1 |
| `app/Http/Controllers/AuthorizeNetPaymnetController.php` | 1 |
| `app/Http/Controllers/PaymentController.php` | **2** |
| `PayUPaymentController`, `NMIPayController`, `KhaltiPaymnetController`, `EasebuzzController`, `PaiementProPaymentController`, `CinetPayController` | 1 each |

Fixing only the file somebody had read would have left the control bypassable by choosing a
different payment method — the exact mistake ledger finding F50 records about `payment_sheet`.
All ten authenticated branches now take the id from the session. **The guest branch is untouched
everywhere**, because there `$request->customer_id` is the id of the Customer the handler found or
created from the billing info, which is legitimate and is what the P1-SEC-23/24 comment records.

**Two sites are exempt, each verified rather than assumed:**

- `PosController` authenticates the **merchant** (`auth()`, not `auth('customers')`), is gated on
  `isAbleTo('Create Pos')`, and the merchant legitimately chooses whose counter sale it is.
- `WishlistController` **replaces** the posted id with the session's — `$customer_id =
  auth('customers')->user()->id ?? null;` then `$request->merge([...])` — before any use, and a
  non-customer token yields null and fails validation closed. I nearly recorded this as a defect;
  reading it refuted that.

### 17b. Ratcheted, because three rounds of review each found the previous round's siblings

`tests/Unit/Payments/OrderAttributionRatchetTest.php` — 4 tests. It enumerates the two exemptions
**by name with their reason** (an exemption that is not enumerated is a hole), asserts the exempt
files still match so a rename forces a re-read, and fails on any new copy in three independent
ways. It would have caught all ten at once. **Bitten** by reverting `CinetPayController`, which it
then named in all three assertions.

Its own fourth assertion was corrected during the work: the first version demanded the literal
`auth('customers')->id()` and flagged `Api/ApiController.php`, which is **correct code** — it uses
a local resolved from the authenticated customer far above. Demanding one spelling of a correct
thing is how a ratchet gets its expectations rubber-stamped. The invariant is "not request input".

### 17c. Driven, and the limit of what that proves — stated rather than glossed

A signed-in shopper posting (a) another customer's real id, (b) `9<script>alert(1)</script>`, and
(c) their own id all produce an order owned by the shopper, with no posted string in
`product_order_id`. 14 assertions.

**That route cannot bite the spelling, and this was measured.** Reverting `place_order`'s own
reference line to `$request->customer_id` *still* produced the shopper's id, because
`ModuleStorefrontInputGuard` (appended to the `web` group at `bootstrap/app.php:274`) rewrites
`customer_id` from the session before the handler runs. So on that route attribution is correct
twice over, and what the cases guard is the **end state**, not which spelling the handler uses.

Two earlier versions of this test were worse and are worth recording:

- The first drove only `place-order` and **passed with the `PaymentService` fix reverted** — it was
  proving a different handler. The bite caught it.
- The second added `payment-sheet` expecting it to reach `PaymentService`; it does not — that
  endpoint creates a Stripe PaymentIntent and writes no order. The explicit premise assertion
  reported "the handler F46 actually lived in was not exercised" instead of passing quietly.

**SO WHAT IS NOT ESTABLISHED, and is not claimed:** whether the nine web/gateway copies were
reachable with a hostile `customer_id` at all. `ModuleStorefrontInputGuard` is per-route, and
whether it covers the gateway callback routes was not determined. The fix is correct either way —
**defence in depth of undemonstrated reachability, not a proven exploit closure.**

### 17d. And one thing the census found that is not F46 — F71

In `ApiController::ordersave()`, `:8944` sets the authenticated customer's reference inside the
`auth('customers')->check()` branch and `:8948`, two lines later and **outside** it, overwrites it
unconditionally with the guest form `'0' . date(...)`. So every `order-save` order carries the
guest reference, signed in or not, while `is_guest` says otherwise. `place_order()` does it
correctly at `:3786`, which is how the two drifted apart unnoticed.

**Recorded, not fixed.** The reference is what a merchant and a customer both see, and three
detail tables join on it. Changing its format for authenticated API orders is an order-behaviour
change, which the owner's standing limits reserve.

---

## 18. `[2026-10-26]` F72 — a variant key that reaches the DOM as a selector

### 18a. The key, and why a merchant produces a hostile one without trying

`ProductController` builds a variant's key from the merchant's own attribute terms:

```php
$str .= '-' . str_replace(' ', '', $item);
```

**Only spaces are stripped.** `product_attribute_options.terms` is validated `required` and
nothing else, so `M/L`, `Black/White` or `2.5"` is an ordinary thing to type into a size or
colour list. The key then goes into the page as a class name, as an element `id`, and — this is
the defect — into **selectors**.

Two failures came out of that. They are different in kind and they needed different fixes, which
is the part worth keeping.

### 18b. (a) Selectors this code builds — jQuery throws and the handler aborts

Four sites in `attribute_combinations_data.blade.php` and three in
`attribute_combinations_edit.blade.php` concatenate the key straight into a class selector:

```js
$('.down_product_' + downProduct.attr('data-id')).removeClass('d-none');
```

Measured, with a `M/L-Natural` variant on the page:

```
Syntax error, unrecognized expression: .down_product_M/L-Natural
```

jQuery **throws**, so the rest of the handler never runs. The blast radius is wider than the odd
variant: the downloadable / virtual / manage-stock toggles stopped working for **every** variant
on the page.

Fixed by escaping **at the call site**, not by sanitising:

```js
var ssSelectorKey = function (value) {
    value = String(value === null || value === undefined ? '' : value);

    return (window.CSS && CSS.escape)
        ? CSS.escape(value)
        : value.replace(/[^\w-]/g, '\\$&');
};
```

Escaping rather than sanitising because **escaping cannot collide** — two distinct keys stay
distinct. That matters, and F73 records what it costs where sanitising was unavoidable.

### 18c. (b) An identifier Bootstrap resolves — and no escape of ours is reached

```html
data-bs-target="#collapseone_{{ $str }}"
```

Bootstrap's own collapse plugin passes that to `document.querySelector()`. Measured:

```
open attempt: {"threw":"Failed to execute 'querySelector' on 'Document':
               '#collapseone_M/L' is not a valid selector.","tgt":"#collapseone_M/L"}
```

**The merchant could not open that variant's panel at all**, so its price, SKU and stock were
unreachable. A call-site escape fixes nothing here, because the call site is inside Bootstrap —
the **identifier itself** has to be CSS-safe. So the collapse trio (`id`, `data-bs-target`,
`aria-controls`) now uses `$safe_str`.

`$str` is untouched everywhere it is a **value**: request field names, `data-id`, and the label
the merchant reads. Sanitising a value would change what is submitted and what is displayed.

### 18d. The sibling view was already fixed — one of three

`attribute_combinations.blade.php:21` has carried
`$safe_str = preg_replace('/[^a-zA-Z0-9_-]/', '_', $str)` since **before** this change. So this
defect was found once, fixed in **one of three** sibling views, and left in the other two — the
shape of F46 (one file recorded, ten in the census) and F50 (a refusal on one endpoint, bypassed
by using another). The fix here reuses that exact sanitiser character-for-character so there is one
spelling of it rather than two.

### 18e. The fourth site, and how dead code turns out to be live

`attribute_combinations_data.blade.php` ships a document-delegated `.delete_option` handler and
**renders no `.delete_option` button**. The file reads as self-consistently dead, which is why the
first pass over it stopped at three sites.

It is live:

- `edit.blade.php:741` calls `attribute_option_data()` on `$(document).ready()`, so this view
  loads on **every** edit page load. Measured: `POST /products/attribute_combination_data/edit`
  fires unprompted, and the page carries `remove_option_M/L-Natural`.
- The handler is bound to `document`. Replacing `.attribute_combination`'s `innerHTML` later —
  which `update_attribute()` does — **does not unbind it**.
- The button that fires it comes from the sibling view:
  `attribute_combinations_edit.blade.php:48-49` renders `.delete_option` with
  `data-id="{{ $str }}"`, the **raw** key, for any combination with no saved variant row.

So both handlers run on one click, and the unescaped one throws.

### 18f. Driven 8/8, by the merchant's own path

`docs/development/phase-2/tools/check-variant-key-escaping-live.cjs`. Reaching the state needed
real UI steps, and the harness refuses to measure without them — an earlier run called
`update_attribute()` directly, got a 6,827-byte response that was only its own script tail, and its
premise assertion reported `.delete_option keys: []` rather than passing on nothing.

The path that works is the merchant's: touch the attribute picker (the page binds `change` on
`#attribute_id` to load each attribute's terms), tick *enable variation*, choose the terms,
regenerate.

```
  ok    the data view loaded on page ready (its handler is live)  [remove_option_ items=7]
        terms chosen: ["S","M","L","M/L"]
  ok    a term with a CSS-special character is really in play
  ok    a REAL .delete_option button carries a CSS-special key  [["S","M","L","M/L"]]
  ok    the collapse target is a VALID selector
        open attempt: {"tgt":"#collapseone_M_L","shown":true}
  ok    THE MERCHANT CAN OPEN the special-key variant's panel
  ok    opening it throws nothing
  ok    clicking it throws NOTHING
  ok    and the combination is really removed from the page  [before=1 after=0 key="M/L"]
PASS 8/8
```

Bitten twice, each failing only its own cases:

| reverted | result |
|---|---|
| the CSS-safe identifier | `'#collapseone_M/L' is not a valid selector`; the panel will not open — **3 cases fail** |
| the call-site escape | `Syntax error, unrecognized expression: .remove_option_M/L`, **and the combination is not removed** (`before=1 after=1`) — **2 cases fail** |

A `M/L` term is now **permanent in the dev fixture** as a canary, so any future page-error check
exercises this rather than rediscovering it.

### 18g. The ratchet, and two versions of it that could not fail — F74

`tests/Unit/Merchant/VariantKeySelectorSafetyTest.php` pins all three sibling views, because the
ordinary next event is somebody pasting one of these blocks into a fourth.

**It took three attempts, and the bite is the only thing that found the first two.**

| version | rule | why it was wrong |
|---|---|---|
| 1 | the variable is assigned from `ssSelectorKey()` **anywhere in the file** | `downProductId` is declared at three sites, so reverting **one** left the other two to vouch for it. **Green against a live, browser-reproducible defect.** |
| 2 | **every** assignment of that name must be safe | these are Blade files and the fourth site's variable is `id`, so `\bid\s*=` matched every HTML `id="…"` attribute. **Flagged nineteen innocent lines and failed the correct tree.** |
| 3 | the **nearest preceding `var`/`let`/`const` declaration** must be safe | what JavaScript actually resolves. The keyword excludes the HTML attributes; "nearest preceding" stops a sibling site vouching for a reverted one. |

Version 3 bitten five ways, all five failing, restored passing:

```
BITE 1 — collapse identifier, edit view ............... 1 failed, 2 passed
BITE 2 — ONE of three escapes (defeated version 1) .... 1 failed, 2 passed
BITE 3 — the LAST of three ............................ 1 failed, 2 passed
BITE 4 — the fourth site's own escape ................. 1 failed, 2 passed
BITE 5 — create view's sanitiser -> $safe_str = $str .. 1 failed, 2 passed
RESTORED .............................................. 3 passed
```

Bite 5 matters on its own: it proves the create view's exemption is conditional on it really
performing a `preg_replace`, not on the file's name.

**This is the project's stricter TDD rule earning its place for the third time this phase** — after
F69 (two P2-03-8 tests that could not fail) and the F46 attribution test (vacuous twice). In all
three the test was written after the fix and looked right, and reverting the fix is the only thing
that told a ratchet from a rubber stamp.

---

## 19. `[2026-10-26]` Two regressions my own previous commit caused, and how they were found

`0f53e1db` was pushed with Unit green and the Feature suite still running. The committed-tree run
came back **13 failed**, of which **2 are the manifest's accepted failures** and **11 were mine**.
Recording this because the mechanism is the same both times and it will happen again.

### 19a. Eleven at once: a fixture that stood in for missing schema

```
SQLSTATE[HY000]: General error: 1 duplicate column name: cost_price
  (SQL: alter table "products" add column "cost_price" numeric not null default '0')
tests/Feature/Commerce/GateFReconciliationReportTest.php:79
```

That file **created `products.cost_price` itself**, with a comment explaining that the column is
GATE F draft 0004 and nothing in version control creates it. F58 promoted the draft, so the
unguarded `Schema::table()` collided with the migration and took **all ten tests in the file**
down.

Fixed with a `Schema::hasColumn()` guard rather than by deleting the block, so the file still runs
against a tree without that migration, and the comment now states the opposite of what it used to —
the column **is** managed. `GateFReconciliation` itself already asks the same question at
`tests/Support/Security/GateFReconciliation.php:270`.

**This is the second time in this phase that promoting a parked schema draft broke a fixture that
had been standing in for the absent schema.** Worth checking before promoting the next one.

### 19b. Twelve rows: a line-keyed inventory and a diff that could not see one of them

`SecurityLogInventoryTest` keys every security log line by `path:line`. F46 inserted a five-line
block at ten sites and F29 added two mappings to `Order.php`, so eleven rows drifted:

```
app/Services/PaymentService.php:2998  -> :3020
app/Models/Order.php:794              -> :821
app/Http/Controllers/PaymentController.php:4410 -> :4422
...
```

The test's own docblock anticipates exactly this and the document's header names the repair tool.
**I wrote my own script first and it was weaker than the tool it was told to use.** Matching by
`(file, message)` and then diffing by key, it refused with "NOT a pure move" on one pair —
because `PaymentController.php:3500` existed in **both** the source and the document while
carrying **different messages**, so the key-diff could not see it at all:

| | 3478 | 3494 | 3500 | 3506 |
|---|---|---|---|---|
| document | no bot token | Telegram refused | could not be sent | — |
| source | — | no bot token | Telegram refused | could not be sent |

A positional remap per file — verify the ordered message sequences are identical, then rewrite
the numbers — found **twelve** rows, not eleven. The twelfth would have left the document
asserting a wrong message at a right line, which **the gate does not check**: it compares keys in
both directions and never compares the message of a key present in both.

Then the documented tool was run from HEAD for comparison:

```
rows re-pointed : 12
rows unchanged  : 117
IDENTICAL -- the documented tool handles this case and my hand-edit matched it.
```

So `resync-observability-inventory.py --write` was right all along — it re-points **all** rows in a
`(file, message)` group by order rather than diffing by key first, which is precisely the case my
script tripped on. The repair in the commit is the tool's output, byte for byte, not a hand-edit.
**Read the header before writing a replacement for the thing it points at.**

---

## 20. `[2026-10-26]` P2-03-4 — media: the rules the merchant is told, and the refusal they were not

### 20a. Three upload limits, none of them the one that bites

| source | value |
|---|---|
| what the editor told the merchant | **256 MB** (Dropzone's own default) |
| `local_storage_max_upload_size` setting | **2,048,000 KB** (≈2 GB) |
| PHP `upload_max_filesize` | **2 MB** |
| PHP `post_max_size` | **8 MB** per request |

The one that bites first is PHP's, and exceeding it produces no clear message: over
`upload_max_filesize` PHP drops the file before Laravel sees it, so the gallery loop never receives
it; over `post_max_size` PHP discards the **entire body**, so `$_POST` is empty and the save fails
with "the name field is required" on a form the merchant filled in.

Type was worse than size. `Utility::upload_file()` and `keyWiseUpload_file()` build
`mimes:`/`max:` from platform settings, and `Utility::Setting()` supplies
`jpg,jpeg,png,csv,svg,pdf`. The editor advertised none of it:

```js
acceptedFiles: a ? null : "image/*",   // and `a` is true for the gallery element
```

`a` is `void 0 !== e.data("dropzone-multiple")`, and the gallery element carries that attribute —
so it took the **`null`** branch and accepted **any file type at all**, while the code's own
single-file intent was `image/*`. `cover_image`, `downloadable_product` and `preview_video` carried
no `accept`. And the prompt "Drop files here to upload" was hardcoded English.

`App\Services\UploadLimits` now reads the same settings and takes the **minimum against PHP's own
ceiling**, so one place answers "what will actually be accepted":

```
  extensions        = jpg,jpeg,png,csv,svg,pdf
  setting_max_kb    = 2048000        <- what the setting claims
  php_max_kb        = 2048           <- what PHP allows
  max_kb            = 2048           <- the minimum, which is what the editor states
  request_max_kb    = 8192
  accept            = .jpg,.jpeg,.png,.csv,.svg,.pdf
  max_label         = 2 MB
  request_max_label = 8 MB
```

The list is passed through **verbatim** rather than filtered to image types: `pdf` and `csv` really
are accepted today, and narrowing it client-side would newly block a file that works.

### 20b. And a refusal that was never reported

`ProductController`'s **cover** image path checks the returned flag and errors. Its **gallery**
loop, at all three call sites, asked only:

```php
$pathss = Utility::keyWiseUpload_file($request, 'product_image', $fileName, $dir, $key, []);
if (isset($pathss['url'])) {          // a refusal has no 'url', so this is just SKIPPED
    $ProductImage = new ProductImage(); ... save();
}
```

while `$msg['msg']` had already been set to "Product Successfully Created" above it. Measured on the
real database:

```
  product id=36  cover_image_path='uploads/1/17_1793016628_good.png'
  gallery rows: 1  (TWO files were queued)
    uploads/uploads/1/90_1793016629_accepted.png
```

One row, nothing on disk for the other, no error and no toast. The merchant is told it worked and
the image is gone.

### 20c. Driven 17/17, in two independent halves

`docs/development/phase-2/tools/check-gallery-upload-feedback-live.cjs`. A and B fail separately,
so they are asserted separately.

```
  == A. the editor states the rules, and refuses what the server would refuse ==
  ok    an upload hint is rendered for the gallery and the cover
  ok    the hints name the accepted formats
  ok    the hints state the REAL per-file limit, not 256 MB or 2 GB
  ok    the cover input filters the OS picker
  ok    and points at its hint for assistive technology
        dropzone: {"acceptedFiles":".jpg,.jpeg,.png,.csv,.svg,.pdf","maxFilesize":2,
                   "status":"error","accepted":0,"removeLink":true}
  ok    A GIF IS REFUSED BEFORE ANY UPLOAD
  ok    a queued file can be removed again

  == B. a file that passes the client guard and the server still refuses ==
  ok    both files passed the CLIENT guard (so the server half is reachable)
  ok    B1 — the SERVER response names the refused file and why
  ok    B1b — and `flag` is still success, because the product WAS saved
  ok    B2 — THE MERCHANT IS TOLD AN IMAGE WAS REFUSED
  ok    and the good image is NOT reported as refused
PASS 17/17
```

B uses a **text file named `.png`**: `acceptedFiles` matches on extension so the client lets it
through, and the server guesses from **content** and refuses it — which is also exactly what a
corrupted or mislabelled file looks like. Verified independently:

```
  client mime claim : image/png
  server-guessed    : text/plain
  guessExtension()  : 'txt'
  mimes rule        : REFUSES: The f must be a file of type: jpg, jpeg, png, csv, svg, pdf.
```

What the merchant now reads:

> Product saved successfully. **These images were not uploaded: mislabelled.png — The product image
> must be a file of type: jpg, jpeg, png, csv, svg, pdf.**

Three fixes, each bitten alone:

| reverted | result |
|---|---|
| `acceptedFiles` back to `null` | the GIF is queued and accepted — **2 cases fail** |
| the server-side capture (all 3 sites) | response is `{"flag":"success","msg":"Product saved successfully."}` — **B1 and B2 fail** |
| the message carry on the default save branch | toast empty, body has nothing — **B2 fails** |

### 20d. Two attempts at the server half that measured nothing

Worth recording, because both looked right and the harness is the only reason they did not ship.

**The first** appended the warning beside the loop, guarded by `isset($msg['msg'])`. That guard is
false there: the earlier message assignment runs only `if ($webhook)` — i.e. when the store has a
webhook configured — and the **final** assignment, far below, would have overwritten it anyway. The
captured response was `{"flag":"success","msg":"Product saved successfully."}` with the warning
nowhere in it. The fix was to collect into a property and report where the message is actually
composed, once per method.

**The second** fixed the server and the merchant still saw nothing. `create.blade.php` has **two
near-identical save handlers**, chosen by `module_is_active('CartQuantityControl')`, and I patched
the module-only one; the default path navigated away without displaying `data.msg` at all. Splitting
the assertion into B1 (what the server returns) and B2 (what the merchant sees) is what localised
it — before the split, one red case could have meant either.

`edit.blade.php` has the same pair, and its **pre-existing** `localStorage.setItem('success_msg',
…)` sits on the same module-only branch — so by default an **edit** save had been showing the
merchant no message at all. Both defaults now carry it.

That is the third time this phase that a fix landed on one of N identical siblings: F46 (one file
recorded, ten in the census), F72 (one of three views), and now this.

### 20e. Contrast found a third defect — F76

| configuration | gallery hint | cover hint |
|---|---|---|
| ltr/light | 5.94 pass | 5.69 pass |
| ltr/dark | **2.18 FAIL** `rgb(167,178,177)` on `rgb(255,255,255)` | 7.11 pass |
| rtl/light | 5.94 pass | 5.69 pass |
| rtl/dark | **2.18 FAIL** | 7.11 pass |

Same class, same token, two different surfaces. `.dropzone` computes
`background-color: rgb(255, 255, 255)` in **both** themes — the host's dark stylesheet never themes
that panel — so the theme-switching `--ss-fg-muted` (`#a7b2b1` in dark) lands light-grey on white,
while the cover hint on the real card was fine. **The F8 lesson for the third time: AA on one
surface is not AA on another.**

Fixed with a modifier pinned to `--ss-grey-600`, a **primitive** defined once at `:root` and never
redefined for dark, which is `5.94:1` on white in every configuration — exactly what light mode
already measured. A hardcoded hex would behave identically while hiding from the token audit.

```
56 measured, 0 below AA.
```

The panel ignoring dark mode is **F76**, recorded and not fixed: `.dropzone` is the upload surface
for every module in the platform, so theming it changes screens far outside this editor and needs
its own contrast pass over all of them.

### 20f. Bilingual, both themes, phone and desktop — 26/26

`check-upload-hint-bilingual-live.cjs`, over ar/rtl × light/dark × 1440/390 plus en/ltr dark:

```
  الصيغ المقبولة: JPG, JPEG, PNG, CSV, SVG, PDF. حتى 2 MB لكل ملف. حتى 8 MB لجميع الملفات معاً.
  Accepted formats: JPG, JPEG, PNG, CSV, SVG, PDF. Up to 2 MB per file. Up to 8 MB for all files together.
PASS 26/26
```

It asserts the **absence of a leaked placeholder**, because that was a real defect in the first
version of the markup: `{{ __('Up to :size per file') }}: {{ $value }}` is a translation call with
no replacement array, so the page would have read "Up to :size per file: 2 MB" with `:size`
showing. A test that only asked whether the hint existed would have passed. Eight strings were added
to both `en.json` and `ar.json`, each verified to contain Arabic script in the Arabic file.

---

## 21. `[2026-10-26]` P2-03-9 — a badge and a filter that contradicted each other

### 21a. Two disagreements, both reachable from the UI

> **Acceptance (ledger):** status and stock are distinguishable without colour, **carry their
> rule**, and **agree with the product list's own predicate**.

It did not agree, in two ways.

**The boundary.** The badge and the filter used different arithmetic on the same column:

| | out of stock | low stock | in stock |
|---|---|---|---|
| the badge | `product_stock <= out_of_stock_threshold` | **no such state** | otherwise |
| the filter | `product_stock <= 0` | `0 < stock <= COALESCE(NULLIF(low_stock_threshold,0), fallback)` | above that |

Worked through with the threshold set to 5:

```
    stock=0   badge says Out of stock   filter puts it in out
    stock=3   badge says Out of stock   filter puts it in low   <-- CONTRADICTS
    stock=5   badge says Out of stock   filter puts it in low   <-- CONTRADICTS
    stock=6   badge says In stock       filter puts it in in
```

So a merchant could read "Out of stock" on a row and have `?ss_stock=out` not return it, while
`?ss_stock=low` did. **Latent at the default**: `out_of_stock_threshold` ships unset, so `?? 0`
makes both boundaries coincide — the defect appears the moment a merchant uses the setting the
product offers at `setting/stock_setting.blade.php:19`. That is why the test sets it to 5 rather
than relying on the fixture, which would have measured nothing.

**And there was no Low stock badge at all**, though the filter has offered that option since
P2-03-2 (`product/partials/filters.blade.php:41`). A merchant could filter to Low stock and read
"In stock" on every row.

### 21b. Which side moved, and why

The filter's. The acceptance names it as the reference, and it is also the better-reasoned pair:
`ProductListFilterTest` proves the three states **partition** the tracked products — nothing in
two, nothing tracked in none — and that an untracked product is in none of them, because
`track_stock = 0` makes its zero *"not counted"* rather than *"none left"*. The badge had neither
property nor a third state.

**They now share one method.** `ProductDataTable::trackedStockState()` is what both consult, so
agreement is by construction rather than by two copies of an expression — which is precisely how
they drifted apart. The SQL filter cannot call it (it has to stay a predicate the database
evaluates), so the agreement is **also measured**: the test drives both and compares them row by
row on 0, 1, 3, 5, 6 and 50.

**A third copy was found, and it was already right.** `product/partials/preview.blade.php:16-28`
uses the same `<= 0` / `<= low` boundaries, so P2-03-6's panel had been correct all along and the
list badge was the only disagreeing surface. One residual difference on its *untracked* path is
recorded as F80.

### 21c. "Carry their rule"

A colour and a word cannot satisfy that half: a merchant seeing "Low stock" cannot act on it, or
tell whether it agrees with the filter they just used, unless it says what counts as low. So
`DsBadge::render()` gained an optional third argument and every stock badge now states its rule:

```
  In stock     title="40 left, above the low-stock level of 5."
  Low stock    title="3 left, at or below the low-stock level of 5."
  Out of stock title="None left. 0 in stock."
  In stock     title="Stock is not tracked for this product."
```

Optional and empty by default, so the seven existing `DsBadge` callers are untouched; the Blade
sibling already accepted arbitrary attributes through `$attributes->merge()` and needed no change.

### 21d. Measured — 11 Feature tests, 31 live cases, three bites

```
  ✓ the badge and the filter agree on every boundary
  ✓ the low stock state is rendered at all
  ✓ the badge carries its rule
  ✓ the shared rule itself  (8 boundary cases)
  Tests: 11 passed (37 assertions)
```

Live, driving each filter in two configurations — which is a live restatement of the agreement,
and the only way to see that the `title` survives yajra's raw-column rendering:

```
    en/ltr/light ?ss_stock=out: ["Out of stock/ss-badge--danger"]
    en/ltr/light ?ss_stock=low: ["Low stock/ss-badge--warning"]
    en/ltr/light ?ss_stock=in:  ["In stock/ss-badge--success"]
    ar/rtl/dark  ?ss_stock=out: ["لم يعد في المخزون/ss-badge--danger"]
    ar/rtl/dark  ?ss_stock=low: ["مخزون منخفض/ss-badge--warning"]
    ar/rtl/dark  ?ss_stock=in:  ["في المخزون/ss-badge--success"]
PASS 31/31
```

Every row a filter returns wears the matching badge, each stating its rule with a number, titles
in Arabic, no placeholder leaks.

| reverted | result |
|---|---|
| the badge back to its two-state rule | agreement, low-stock and carries-rule all fail — **3 cases** |
| the `title` emission in `DsBadge` | **only** the carries-its-rule case fails |
| the shared boundary moved by one (`<` for `<=`) | the agreement test **and** the one provider case that names it — **2 cases** |

### 21e. One harness defect, worth recording

The first version of the live check read the **unfiltered** list and found every badge saying "In
stock" with the not-tracked rule — so it reported "the Low stock state never appears" and "a title
names no number" as product defects. Both were a sampling artefact: page 1 of that list happens to
hold untracked rows. Driving each filter instead fixed it and made the harness strictly better,
because it now checks the agreement live rather than only the presence of a badge.

### 21f. What P2-03-9 does NOT claim — F79

The badge agrees with the filter and the preview panel. It still does **not** reflect what a
customer can do. `Cart.php:135` refuses a purchase only when
`product_stock <= out_of_stock_threshold` **AND** `stock_order_status == 'not_allow'`, and that
column is the empty string in the fixture and in production — which is none of its three valid
values, so `'' == 'not_allow'` is false and the threshold check **never fires**. A tracked product
at zero stock reads "Out of stock" on the list and can still be bought.

Recorded as **F79** rather than fixed, because the honest fix is a purchasing-behaviour change
(treat `''` as `not_allow`, which would stop sales that currently complete) and the owner's
standing limits reserve that. F61 recorded the same `''`-as-permissive behaviour for
`stock_status`; this is its sibling on the purchasing path. What this slice does do about it is
make the number and the threshold visible in every badge's rule, so a merchant is not left
inferring them.

---

## 22. `[2026-10-26]` P2-06-10 — contextual field help, associated rather than rendered

### 22a. What was there before, and why the acceptance ends "not a tooltip only"

> **Acceptance:** help attached to the field it is about via `aria-describedby`, in both languages,
> **not a tooltip only**.

The slug field carried this:

```php
Form::text('slug', null, ['class' => '...', 'data-bs-toggle' => 'tooltip', 'title' => __('Sku or Slug')])
```

Hover-only, a restatement of the field's own label, and bound to the input for nothing: Bootstrap's
tooltip plugin moves `title` into `data-bs-original-title` and blanks the attribute — a behaviour
`order/action.blade.php:8` already records — so even the browser's own fallback is gone. **No other
field in the product editor had help at all.**

### 22b. Five fields, each saying something the label does not

Every sentence was checked against the code rather than written from intuition:

| field | the help | verified against |
|---|---|---|
| `slug` | generated from the name when left blank | `store():286` — `$Product->slug = Product::slugs($request->name)` |
| `sale_price` | must be **lower than** the price | the rule is `lt:price`, not `lte` |
| `cost_price` | required, cannot exceed the price, never shown to customers | `required\|numeric\|min:0\|lte:price` |
| `product_weight` | used to work out shipping | `nullable\|numeric\|min:0` |
| `low_stock_threshold` | the list shows **Low stock** at or below it; 0 uses the store default | P2-03-9's own rule, and the `NULLIF(…, 0)` semantics the filter's SQL uses |

The last one ties the help to the badge built in §21, so the two describe one rule.

### 22c. The ratchet asserts BOTH directions, because each alone is satisfiable by something broken

- "five hints exist" passes with five paragraphs nothing points at — decoration;
- "five controls have `aria-describedby`" passes with five references to ids that are not on the
  page — and a **dangling reference is worse than silence**, because it leaves the accessible
  description empty while the markup looks complete.

7 tests, bitten six ways, each failing exactly its own cases:

| reverted | result |
|---|---|
| one `aria-describedby` dropped | points-at-its-help **and** no-orphan fail (the hint becomes an orphan) — 2 |
| one hint removed, its reference left | really-on-the-page **and** says-something fail — 2 |
| an orphan hint added | **only** no-orphan fails — 1 |
| the useless tooltip restored | **only** tooltip-is-gone fails — 1 |
| the English text copied into `ar.json` | **only** is-translated fails — 1 |
| help removed from `edit` only | **only** edit-carries-the-same fails — 1 |

### 22d. Driven live, 57/57 — resolving the id chain the way the browser does

`check-field-help-live.cjs` reads the description the accessibility tree would announce, which
catches two things the Feature test cannot see:

```
  BITE — reference points at a missing id:
    FAIL  sale_price: every referenced id resolves  [unresolved: ["sale_price-helpp"]]
    FAIL  sale_price: the description says something  [""]     <-- the description is EMPTY

  BITE — a second element with the same id:
    FAIL  slug: no duplicate id behind the reference  [duplicated: ["slug-help"]]
    (in both languages — the browser silently describes the control with the first match)
```

Restored, both languages, every description confirmed Arabic in the Arabic run:

```
    descriptions: slug=Part of the product's web address. Leave it …
                  sale_price=The discounted price customers pay. It …
                  cost_price=What the item costs you. Required, cannot ex…
                  product_weight=Used to work out shipping. Leave blank if yo…
                  low_stock_threshold=The product list shows Low stock at or below…
PASS 57/57
```

Contrast, two new targets across four configurations — **5.23 to 7.11:1, 56 measured, 0 below AA**.
The weight field's help sits **inside** the P2-03-8 collapsed `<details>`, a different surface
again; that distinction has now cost a measurement three times (F8, the feed timestamp, F75's
gallery hint), which is why it is sampled separately rather than assumed from its sibling.

### 22e. A precondition stated rather than assumed

`low_stock_threshold` lives inside `@if ($stock_management == 'on')`, so with the setting unset —
how it ships — the field is not on the page. **The first version of the test reported it as
unhelped**, which was true of the page and false of the template. Both the test and the harness now
turn the setting on and **assert the field is present** before judging its help.

The harness does that through a new `set-stock-management.php` rather than through the fixture,
because enabling it in `seed-p2dev.php` would change the editor's rendered field set — which
`inventory-product-fields.cjs` diffs as its preservation gate, and a baseline that drifts for an
unrelated reason is worse than a harness that states its own precondition.

That script resolves `settings.created_by` **from the store**, copying `set-preview-state.php`
beside it. Measured: the owner is **2, not 1** — so hardcoding 1, which the first draft did, would
have written the setting where the store never reads it.

### 22f. What this does not claim

The **product editor only**. Workstream I2's wording is "contextual field help", and the other
merchant screens — orders, customers, inventory, the ~20 settings panels — still have none. The
component and the ratchet are reusable and the ratchet's field list is per-screen, so extending it
is additive; P2-06-10 is recorded as done for this screen and open for Workstream I.

---

## 23. `[2026-10-26]` P2-04-14 / E11 — order lines cannot be edited, and a half-vacuous test

### 23a. The rule, and where the lines actually live

> **E11 acceptance:** a server-side guard, not a hidden control; an attempt is refused and logged.

There is no line-item table. `orders.product_json` holds the lines, `orders.product_id` a comma
list, `orders.product_price` their subtotal — **and all three are in `Order::$fillable`**, which is
what makes the question real rather than academic.

A census established how the rule is currently held, before any test was written:

- **No route accepts a product list.** The order-mutating routes are assign, status-change,
  payment-status, update-status, return, return-request and the refund set. Each handler assigns
  the **one** named field it is about — `$order->payment_status = $request->input('payment_status')`
  — never the request wholesale.
- **No handler mass-assigns.** The only `$order->update([...])` in the codebase
  (`OrderRefundController:359`) passes a literal array.
- The two post-creation writers of `product_json` are legitimate and neither changes products or
  quantities: `Order::product_return()` adds the P1-INV-05 restock marker, and
  `StoreCurrencyConversionService` rewrites **prices** on a currency change — which is E14's
  question, not E11's.

### 23b. The test posts the edit directly, at all six endpoints

The acceptance says "not a hidden control", so this does not check that the UI omits anything. It
posts `product_json`, `product_id`, `product_price`, `qty`, `quantity` and a `products` array to
every order-mutating endpoint and reads the row back. The response is deliberately *not* asserted
beyond reach: a handler that refuses and one that succeeds at its own job while ignoring the extra
fields both satisfy E11; one that **writes** them does not, whatever it answers.

Plus a ratchet: no handler may `update`/`fill`/`forceFill` an order from `$request`. That is the one
change that would break every case at once, and an easy thing to write while adding an endpoint.

Bitten twice:

| introduced | result |
|---|---|
| one handler applies the posted `product_json` | that endpoint's case **and** the sequence case fail; the other five still pass |
| `$order->update($request->all())` | the ratchet fails and names the file |

### 23c. The first version was half vacuous — this is the useful part

It posted a guessed payload and asserted only that the lines were unchanged. Measured, three of six
endpoints never reached their handler:

```
    /order-status-change/1   -> 500   the payload used `status`; the handler reads `delivered`
    /order-assign            -> 302   its validation wants `delivery_boy`, not `deliveryboy_id`
    /update-final-price/1    -> 403   it needs `Manage Order Refund Request`
```

So for half the endpoints it was asserting that a request which never ran changed nothing. Every
row now carries a **reach marker** — `json-success`, `json-any` or `not-403` — and a case whose
marker fails is a failure, not a quiet pass.

### 23d. Two diagnoses the reach markers forced

**`/order-assign` then answered 500 for a real reason:** it deletes from `delivery_earnings`, a
**GATE F table with no migration**, so it does not exist in the test database while existing in
production with live data. The fixture creates it from `GATE-F-VERIFIED-SCHEMA.md:161`'s measured
DDL, **guarded by `hasTable`** — the F58 lesson, where an unguarded `Schema::create` standing in for
absent schema collided with the migration that later supplied it and took ten tests down.

**And the 500 on an unrecognised status was a defect in its own right** — F81, below.

---

## 24. `[2026-10-26]` F81 — an unrecognised order status answered 500, on three endpoints

### 24a. A method that could return null, and three callers that dereference it

`Order::order_status_change()` is a chain of `if ($order_status == '...')` arms, each returning. An
order that **exists** with a status matching no arm fell off the end and returned `null`. All three
callers dereference the result with no guard:

```php
OrderController::order_status_change():1345   if ($responce['status'] == 'success')
Api\ApiController:7449                        if ($date['status'] == 'success')
Api\DashboardController::statusChange         $responce['order_status'] = ...
```

→ `Trying to access array offset on null`. Measured as a **500** on the web route. The two API
callers read the value straight out of the request (`$request->order_status`,
`$request->delivered`), so any client can produce it.

**It needed no hostile caller.** The `pre_order` arm is gated on `module_is_active('PreOrder')`, so
a caller sending that perfectly legitimate value — `orders.delivered_status` 8, which **F29** added
to the API's own status vocabulary — fell through whenever that module is off, which is the default.

### 24b. One default return, not three guards

Nothing is written on that path, and that was verified rather than assumed: the order is read at
the top and every arm that touches it returns, so an unmatched status had **already** changed
nothing. What was wrong was only the report. So the fix is a default return in the shape of the
`Order not found.` arm beside it, and the three callers handle it through code they already have.

Seven tests assert both halves — a clean refusal **and** an untouched row — across four values
including the realistic `pre_order`-with-the-module-off case, plus a contract test that the method
always returns an array. Bitten: removing the default return puts all four back to 500 and fails
the contract test.

### 24c. A hypothesis this refuted, kept because the method matters more than the conclusion

The first reading of `order_status_change` found six handled values by grepping
`\$order_status == '[a-z_]+'`. The order page's dropdown (`order/view.blade.php:101-143`) offers
`confirmed`, `pickedup`, `shipped`, **`delivered`**, `cancel`, `return` — and `delivered` was not
in that list, while `pre_order` was handled and never offered. That read as **"a merchant cannot
mark an order delivered"**: a serious defect on the most-used merchant screen.

Driving all six through the real endpoint:

```
    confirmed    -> 200  {"status":"success","order_status":"Confirmed"...
    pickedup     -> 200  {"status":"success","order_status":"Pickedup"...
    shipped      -> 200  {"status":"success","order_status":"Shipped"...
    delivered    -> 200  {"status":"success","order_status":"Delivered"...
    cancel       -> 200  {"status":"success","order_status":"Cancel"...
    return       -> 200  {"status":false,"message":"Your order has not been deliver...
```

The arm is written `if ($order_status ==  'delivered')` — **two spaces** — which the regex's single
space missed. **A grep is a hypothesis, not a finding.** A test now asserts all six dropdown values
answer success, so if one ever stops being handled, that genuinely serious case is caught.

---

## 25. `[2026-10-26]` P2-04-15 / E12 — the half of "only authorised changes" nothing tested

### 25a. Gap analysis, not a failure

> **E12 acceptance:** delivery assignment and order notes are writable only by a principal holding
> the matching permission **and** owning the store.
> **Required:** a cross-tenant **and** an unpermitted-principal case for each write, bite-tested.

**Order notes: both halves already existed.** `OrderNoteAuthorizationTest` drives nine cases across
the API and the web — cross-tenant create refused, cross-tenant delete refused, own store works,
platform admin may write anywhere, unknown note type refused, and
`test_web_note_create_still_requires_the_permission`. The handler matches:
`OrderNoteController` delegates to `OrderNotePolicy` (permission, operator, and **fails closed when
no tenant resolves**) and writes `$note->store_id = $order->store_id` — the **order's** store, not
`getCurrentStore()`.

**Delivery assignment: the tenancy half existed; the permission half did not.**

| existing case | varies | grants |
|---|---|---|
| `OrderMutationTest::test_merchant_cannot_assign_another_stores_order` | a foreign **order** | `Manage Order` |
| `DeliveryBoyScopeTest::test_an_order_cannot_be_assigned_to_another_stores_driver` | a foreign **driver** | `Manage Order` |
| `DeliveryBoyScopeTest::test_the_merchants_own_driver_passes_the_guard...` | the positive case | `Manage Order` |

Every one grants the permission and varies only the tenancy. So **nothing asserted the permission
was required at all**: `OrderPolicy::assign()` delegating to `allow($actor, $order, 'Manage Order')`
was a reading of the code, and changing that one method to `return true` would have been caught by
no test in the suite.

### 25b. Three cases, and why the third is not padding

1. A merchant whose **store, order and driver are all their own**, lacking only the permission →
   **403, nothing written**.
2. The **same** principal, once the permission is granted → not refused. Without this, a test that
   refused for any reason at all would pass.
3. The **platform operator**, without the permission → not refused.

The third exists because `OrderPolicy::allow()` checks the platform admin **first**, and its own
comment records why: `isAbleTo()` running ahead of it locked the operator out of assign entirely,
since on a database built from source no role holds `Manage Order`. A permission test that happened
to use an operator would measure that bypass instead of the permission, so the two are asserted
separately.

Bitten three ways, each failing exactly its own case:

| introduced | result |
|---|---|
| `assign()` returns `true` | **only** the unpermitted case fails |
| the admin check moved **after** `isAbleTo` — the regression the comment warns about | **only** the operator case fails |
| `assign()` returns `false` | the positive **and** operator cases fail |

### 25c. The GATE F table, handled the way the codebase already handles it

`delivery_earnings` has no migration, so the handler's own write to it cannot complete in the test
database. The authorization is asserted **unconditionally** and only the assignment's completion is
read behind `Schema::hasTable` — the pattern `OrderMutationTest:155-160` establishes, with its
reasoning stated there: skipping the whole case would drop a tenant-isolation guarantee for a
reason unrelated to it.

That is a different choice from the one §23d describes for `OrderLineImmutabilityTest`, and
deliberately so. That file needs the handler to **complete** in order to show it ignores hostile
fields, so it creates the table from the verified DDL; this one only needs the refusal, so it
asserts that and reads the rest conditionally.

---

## 26. `[2026-10-26]` E13 and E14 — what is already true, and what is a decision

Neither row needed code. Both needed finding out what the codebase already holds, which is
recorded here so the next reader does not repeat the search.

### 26a. E13 — one clause measured, one clause has nothing to measure

> **E13 acceptance:** the UI offers no in-place product correction; it offers cancel-and-replace,
> and the replacement is linked to the original.

**First clause: satisfied, and more strongly than it asks.** §23 posts the edit at all six
order-mutating endpoints and reads the row back unchanged, with a ratchet against mass assignment.
"No in-place correction" is asserted against the server, not against the markup.

**Second clause: nothing exists to verify.** Measured — `orders` has 38 columns and **no parent,
replacement, original or linked-order column**; only `return_price`, `return_status`, `return_date`
and `cancel_date`. A grep across `app/`, `routes/` and the order views for replacement, reorder or
duplicate-order workflows returns nothing but unrelated PreOrder hits. Cancellation sets
`cancel_date` and `delivered_status = 2` and stops.

So there is no replacement order to link and no link to assert. Building it needs a schema change,
a new order-creation path that copies a cancelled order's lines, and a rule for the original's
payment and stock — and CLAUDE.md puts **returns** in Phase 04 Commerce. Recorded as a decision
request with three options; none acted on.

### 26b. E14 — the stock half was already done, thoroughly

`RefundStockIdempotencyTest` holds eleven cases: first restock credits, a replay does not credit
twice, repeated replays cannot inflate, the restock is a **durable marker**, a second refund on the
same order can still be restocked, stock returns to the variant the order records, an ambiguous
variant is skipped rather than guessed, another store's variant is untouched, and a negative refund
quantity cannot subtract.

The mechanism is `Order::LINE_STOCK_RESTORED`, a **per-line** marker, and P1-INV-15 (E) records why
per-line rather than per-order: two endpoints could credit a returned line's stock and **neither
knew about the other** — `Order::product_return()` marks one line,
`OrderController::restoreReturnedStock()` loops every line — so the ordinary two-step merchant
workflow credited that line twice. Each endpoint had its own replay guard and each was correct
about itself.

**The payment half is partial**: one gateway's callback (`PaymeraCallbackIdempotencyTest`) plus the
refund and COD state machines. What is not established is the same property across the other
gateways' callbacks — and F46's census already found that block to be byte-identical in nine files,
so the work is a census and one ratchet, not nine tests. The "and replacement" clause is not
applicable for the reason above.

Recorded with the gap named rather than marked done. It is money-path work and deserves its own
slice.

---

## 27. `[2026-10-26]` P2-05-3 — the stock report, and a screenshot that refuted its own evidence

### 27a. Three defects found, a fourth found by looking at the picture

F106 records three, all measured live: the status column **empty on five of five low-stock rows
and ten of ten most-stocked rows** (the partials gated on `stock_order_status`, which is the
merchant's backorder policy, not a stock level — had it held `allow`, the low-stock report would
have rendered a badge reading "In stock"); the row actions with **no accessible name at all**
(`title` on the inner `<i>`, which Bootstrap then moved to `data-bs-original-title` and blanked);
and the **active tab pill at 2.59:1** in all four configurations.

The fourth is the interesting one. After fixing those three and generating the screenshots that
were to be the slice's evidence, **the action column was not in the picture**. Measured: the table
is 905px against an 803px wrapper at 1440 and a 320px one at 390, and the action column is 99px —
so it sat entirely outside the visible box at scroll 0, on all three tabs. A real click at the
anchor's centre hit something else in all eight configurations. That is **F107**, and the test
that proved the actions were named passes either way, because it reads rendered HTML, not layout.

**"Named" and "reachable" are different claims and need different instruments.** The fix reuses
P2-03-1's `ss-col-pinned` rather than inventing a second mechanism; after it, a real click
navigates in all eight.

### 27b. The contrast harness did not cover the screen it was asked about

Adding `/stock-report` to `measure-contrast-live.cjs` produced **zero readings for it**. The
harness keeps one reading per `(config, element)` and the first screen wins, so a new page
contributes nothing unless it introduces new element labels. The first "64 measured, 0 below AA"
run did not cover this screen at all. Five new targets were added; the run then reported 84
measured, 0 below AA, with the active pill at **5.36:1** in each of the four configurations.

### 27c. What the slice's evidence is, and one thing it deliberately leaves behind

12 screenshots and a README in `docs/development/phase-2/evidence/p2-05-3/`, regenerable by
`docs/development/phase-2/tools/shoot-stock-report-live.cjs`, which prints the pill ratio, the
badge tone per row and the computed accessible name per action from the same run that takes the
pictures. `StockReportPredicateTest` is at 10 tests and compares report membership against
`ProductDataTable::trackedStockState()` **called directly**.

The tab rail is shared by four views; three belong to other slices and still carry the 2.59:1
pill. The fix is scoped by a new `ss-tabrail` class and the others are recorded in F106 as
unfixed. They must not be read as done.

---

## 28. `[2026-10-26]` D-09 / F105 — two guards built, two guards refuted, and only the writer shipped

### 28a. The owner's literal test passes against the defect

The case given was twelve variants with non-zero prices, saved without touching a field, with
every price, quantity and the variant count required to survive. **That test passes on unfixed
code**, because the values panel echoes each stored price back into the form and the rebuild
writes the same number. A test asserting only what was asked would have reported success against
the defect it was written to catch.

The assertions that bite are the columns the panel **cannot** echo faithfully: `sku`, rewritten
unconditionally from the product name (so a hand-typed `SKU-0001` became `Scarf-S-Red` on every
save); and `weight`, `stock_order_status` and `stock_status`, which are NULL in the row and were
written `0` or `''`. Both halves are in the test, labelled, so the owner's condition is checked
**and** the check has teeth.

Three further wrong-reason traps were avoided deliberately and are recorded in the fixture:
the dev data stores the variation flag as `"on"`, and `"on" == 1` is **false** on PHP 8.3
(executed), so a product copied from it does not reproduce the defect; `ProductVariant` is
`Cachable`, so a `DB::table` fixture leaves the handler's own `first()` reading stale; and
`product_variants.sku` and `.price` are NOT NULL, so a fixture omitting them does not build.

### 28b. Two designs, both refuted, neither visible from the happy path

**Design one** compared the posted attribute configuration against stored
`products.product_attribute`. Refuted: that column holds **at least four incompatible shapes**
(terms verbatim from `store()`, option ids from `update()`, the degenerate `[""]`, the literal
`'NULL'`), and `product_attribute_options.terms` is an unconstrained varchar so a numeric term is
indistinguishable from an option id — the comparison would have fired the rebuild it was
preventing. Worse, it gated the write of `product_attribute`, which is the **only** store for the
"Visible on the product page" flag and is `''` on every simple product — making that toggle
unsavable and locking out enabling variations permanently.

**Design two** replaced it with a set difference over `product_variants.variant` and shipped into
the working tree. Refuted by review **of my own change**: `whereNotIn('variant', $com)` already
deletes exactly the rows in `array_diff(existing, posted)`, so the guard restates "this delete has
something to delete" and cannot change an outcome; it also read the existing set after the upsert
loop, making half the comparison dead code. The `store_id` predicate added beside it was worse
than useless — it bought nothing the ownership check does not give, and would have silently
stopped the **platform operator's** cleanup, because `getCurrentStore()` returns the literal 1 for
an admin with no `current_store`, leaving removed combinations live and purchasable on the
merchant's storefront.

Both removed before commit; recorded as **F113**.

### 28c. What shipped, and what is honestly not covered

The writer, and only the writer: `ssVariantValue()` in place of
`!empty($request[k]) ? $request[k] : DEFAULT` on every per-variant column, plus the conditional
`sku`. Six of twelve tests across `ProductVariantOrdinarySaveGuardTest` and the **converted**
`ProductVariantPersistenceTest` fail when the controller is reverted; the two old tests that
pinned the defective behaviour were converted rather than deleted, and the test that pinned the
removed gate was replaced by one pinning the writer.

**Concurrency is not covered and must not be read as if it were.** Tests run SQLite `:memory:`
with `CACHE_STORE=array`, so `Cache::lock` is process-local and no Feature test can stage two
merchants saving one product. What exists is the pre-existing `ss_last_seen_at` conflict guard,
unchanged by this work.

**What a deliberate narrowing should do** — hard-delete, as today, or refuse and name the variants
that would be lost — is an open commercial decision (**D-20**), not an engineering one.

---

## 29. `[2026-10-26]` D-12 / F87, F120 — a fix that was reasoned, documented and wrong in the same breath

### 29a. The defect, and why a dedicated security pass had already missed it

`PaystackPaymentController::getPaymentStatus` gated plan activation on
`isset($result['status']) && $result['status'] == true` — whether Paystack's **verify call**
succeeded. The transaction's real state is `data.status`, and the handler *recorded* it
(`$order->payment_status = $result['data']['status']`) without ever requiring it to be `success`.
A failed or abandoned transaction activated the plan, repeatably, because the reference is a route
parameter.

**F88 is why it survived.** `PlanPaymentPostureTest` asserts that every "verified" provider carries
a verification marker. Paystack carried one, and the marker **accurately described an inadequate
check**. The test passed, the provider was documented, and the classification was still wrong — the
list conflates *calls the gateway* with *checks that the payment succeeded*.

### 29b. The test had to be built against the grain of the handler

The handler reaches Paystack with a raw `curl_init()`, not the HTTP client, so there is nothing to
fake. In this container a route-level test produces a failed curl — and **on the old code a failed
curl refuses too**, so such a test would have passed against the defect. That is the same
wrong-reason trap that let F87 survive a security pass in the first place.

So the decision is a pure static method taking its inputs as arguments, driven directly with the
payloads Paystack actually returns: `failed`, `abandoned`, `reversed`, `pending`, the empty string,
a mismatched reference, two absent references, and the non-array a failed call produces. Success is
`success` **and nothing else** — a denylist would admit the next state Paystack adds.

### 29c. What the review of my own fix found, and it is the substance of this entry

**Three defects in the fix, one of them worse than what it replaced.**

- **F120 — the fix closed the cheaper half and left the cheaper-still half open.** `status` plus
  `reference` prove that *some* transaction in the platform's Paystack account succeeded. They
  prove nothing about **which plan** it bought, and the plan is a route parameter whose encrypted
  id `plans/stripe.blade.php:3246` prints for every plan card. Pay once for the cheapest plan, open
  the callback with the expensive plan's id: activated. The amount was the only field that could
  tell those apart and the fix **logged** it.

  **The docblock explaining why it could not be refused was what stopped anyone looking.** It cited
  `GuardsPlanActivation`'s real warning about minor units and concluded the unit was "a belief read
  off the code, not a measurement". The measurement was two files away: this server renders the
  checkout and sends `amount: res.total_price * 100` with `currency: res.currency`. Both sides are
  first-party and agree.

- **The replay guard checked only the current locale's spelling.** `payment_type` was written as
  `__('Paystack')`, and the locale is the **caller's** to choose through the language switcher, so
  a merchant with an Arabic-written row could switch to English and miss on both lookups. It now
  checks every shipped locale.

- **A regression I introduced.** The replay branch reported success without checking the recorded
  plan or re-running the activation. The `PlanOrder` row is written *before* `assignPlan()`, so a
  request dying between them strands that payer permanently — every later retry, including a clean
  one, told the plan was active while it never was. **With a reachable trigger today:** `?coupon=`
  with no `coupon_id` dereferences a null and raises an `Error`, which `catch (\Exception)` does
  not catch, *after* the row is written. The branch now re-runs `assignPlan()` for the same buyer
  and same plan, and refuses anything else.

**And a prerequisite without which the amount refusal would have broken every discounted sale:**
the callback URL carries `?coupon_id=`, an **id**, while `serverOwnedPlanPrice()` resolves a coupon
by **code**. The server price was therefore always the undiscounted one. Harmless while the
comparison only logged; fatal the moment it became a gate.

### 29d. Two of the tests could not fail on the defect they named

The same review applied "would this fail if the fix were reverted" and found:

- `test_a_replayed_reference_finds_the_existing_plan_order` wrote a row with `forceCreate` and
  re-queried it inline. It never called the guard. **Deleting the guard from the controller left it
  green** — it asserted that Eloquent can read a row it just wrote. It now drives the real
  `existingPlanOrderForProviderTxn()` through an anonymous class using the trait, and checks both
  directions plus the empty-reference case.
- **Nothing pinned the handler's use of the outcome.** Every behavioural test drove the pure
  method, so mutating the handler's gate to `$paystackAccepted = $paystackOutcome['envelope_ok'];`
  left F87 live with all tests green. A source assertion scoped to `getPaymentStatus()`'s own body
  (via `ReflectionMethod`, so a match elsewhere in the file cannot satisfy it) now pins the gate,
  the replay guard, the amount refusal and the coupon-code resolution. **Verified by executing that
  exact mutation: it now fails.**

### 29e. What is still open

`plan_orders` has a unique index on `order_id` and none on `(payment_type, txn_id)`, so every
plan-activation replay guard in this codebase — SenangPay, Ozow and now Paystack — is a
check-then-act with no database backstop (**F119**). Closing it needs a migration, which is
production data, and a duplicate census first, since providers that recorded `time()` or a blank as
`txn_id` would collide. It also has to wait on the `payment_type` canonicalisation (**F115/F116**),
or the index would treat `Paystack` and `كومة الدفع` as different providers.

---

## 30. `[2026-10-27]` D-13 / D-18 — a passing test deleted because it passed for the wrong reason

### 30a. What was changed

The twelve raw read-modify-write stock decrements across the four gateway return handlers D-18
names — Khalti, PaiementPro, CinetPay, Easebuzz — now go through the existing
`AppliesCartStockChanges` trait, with an accumulator declared before each loop and a restoration
at each of the three in-loop error exits. `$rejectOnShortfall` is `false` at every site, which is
what makes the happy path bit-identical: on a shortfall the trait re-runs the decrement with
overselling allowed, exactly as the raw subtraction did.

### 30b. The test that was written, passed, and deleted

The point of the slice is that a basket failing part-way should give back the stock it already
took. So the test was: two cart lines, the second naming a product id with no row; drive
PaiementPro's return (the only one of the six that reaches `new Order()` here without a live
gateway); assert no order was written and the first line's stock is back at 50.

**It passed. Then the revert check showed it passing against the unfixed handlers too.**

Diagnosing it took one probe with `withoutExceptionHandling()`: the request was a **500**, not a
refusal — `ErrorException: Attempt to read property "id" on null` at `app/Models/Cart.php:531`,
reached from `PaiementProPaymentController.php:495`. That is the price-computation stage, roughly
two hundred lines before the stock loop. The handler never decremented anything, so "the stock is
still 50" was true in both directions and the test measured nothing.

**The flaw was in the premise, not the assertion.** It asserted that no order was written — which
is equally true of a basket that failed inside the loop and one that crashed before reaching it.
A premise has to exclude the way the test could be satisfied by accident, and that one did not.

The exits turn out to be unreachable from either cart path: the variant form of the same fixture
crashes two lines further on at `Cart.php:537`, and that unguarded `ProductVariant::find(...)` ->
`->variant` shape repeats at about a dozen sites in `Cart.php`.
`GuardsGatewayReturn::basketHasForeignProduct()` deliberately passes such lines through — its own
comment says "every one of these loops already has a 'Product not found' path for it" — so the
design intends the loop to handle them and the loop never gets the chance. Recorded as **F121**.

### 30c. What replaced it, and what it is honest about

`GatewayStockDecrementRoutingTest` (4 tests, 106 assertions) asserts what is actually true:

- every one of the four handlers routes all three decrements through the trait, and carries **no**
  raw `$remain_stock` assignment — with **comments stripped by `token_get_all()` first**, because
  the corrected comments in those files quote the very expression the check looks for, and this
  project has had a comment satisfy a census before;
- each declares the accumulator exactly once, **before** its loop, and every use is **inside** it —
  an accumulator in the wrong scope is an undefined variable at the exits, which PHP does not
  catch at lint time and which no happy-path test would reach;
- the three writers D-18 does **not** authorise still carry their raw decrements, so "routed
  everything" cannot be mistaken for progress.

Three of the four fail when the controllers are reverted; the fourth is the deliberate scope
guard. `WhatsappUrlStockTest::test_every_order_writer_call_site_is_non_rejecting` was also widened
from a hard-coded two-file list to a **discovered** sweep of every user of the trait: it had been
blind to all twelve new call sites, on exactly the paths it exists to protect. Mutating one new
site to `true` now fails it, verified by executing that mutation.

### 30d. What none of this claims

The atomicity — the half that matters most, because it removes a lost update — **cannot be driven
here**: `CACHE_STORE=array` makes `Cache::lock` process-local, so no Feature test can stage two
overlapping returns. And the decrement still happens before the payment is known to have
succeeded; closing that needs E13's durable stock evidence, which is BLOCKED.

## 31. D-21 — the four remaining stock writers (2026-10-27)

`php artisan test tests/Feature/Commerce/GatewayStockDecrementRoutingTest.php
tests/Feature/Security/AdminApiStockRefusalContractTest.php
tests/Feature/Commerce/OrderStoreOwnershipTest.php` → **20 passed, 336 assertions**.

Full Feature gate on the change: **6053 passed, 2 failed, 17 skipped** — the known-outcome gate
reports `OK: the observed outcome set matches the manifest exactly`, 6072 testcases, 0 errors.

### 31a. The Flutter refusal contract — four behavioural tests that deliberately see nothing

`AdminApiStockRefusalContractTest` drives `POST api/admin/createOrderViaApi`, the shipped admin
app's own order writer, and asserts the refusal is unchanged: **HTTP 400 and not 401** (a 401
forces a logout in `lib/network/network.dart`, so it would be a user-visible regression rather
than a hardening), the `status`/`message` envelope intact, a positive control that an order which
fits still succeeds and still takes the stock, and the exact boundary — `qty == stock` must still
be **allowed**, which is the off-by-one a `>` would introduce.

**These four pass against the unconverted handler too, and that was measured rather than
reasoned.** Reverting `createOrderViaApi` to its original check-then-act leaves all four green:
the behaviour is byte-identical by design, which is the point of the owner's Flutter condition and
also the reason behaviour cannot evidence the conversion.

### 31b. So the conversion is pinned in source — and revert-checked

`test_create_order_via_api_delegates_stock_to_the_service_and_keeps_no_read_modify_write` reads
that one method's body through `ReflectionMethod`, strips comments, and asserts both branches call
the service, **both pass `$requireAvailability = true`** (without it the service oversells instead
of refusing, turning the pinned 400 into a 200 against stock that is not there), and that none of
the four spellings of the read-modify-write returns. **Reverting the conversion fails that test and
only that test** — executed, 4 passed / 1 failed.

### 31c. The ratchet that was a floor, and the defect it hid

`GatewayStockDecrementRoutingTest`'s exit assertion was
`assertGreaterThanOrEqual($loops, substr_count(..., 'restoreStockDecrements'))`. AuthorizeNet's
loop has **five** error exits; the conversion restored at three; the floor of one per loop was
satisfied and CI stayed green while two **post-capture** exits consumed a paid basket's stock and
wrote no order (ledger F123, found by the independent security review).

It now locates each product loop by brace depth and asserts a restore stands immediately before
**every** `return` inside it, over comment-stripped lines that keep their numbering — necessary
because `AuthorizeNetPaymnetController:727` contains the literal `return $this->error(...)` inside
a block comment.

**It lists no exit spellings.** The first replacement listed four and immediately reported
`EasebuzzController` as having no exits at all, because that handler returns `Utility::error(...)`
— a detector defeated by a spelling it has not met being precisely the failure under guard. A
census of all seven handlers found **30 returns inside a stock-taking loop, all 30 error exits**,
so the rule applies to every `return`.

**Revert-checked:** with the two restores removed the test fails naming line 776; with them
present, 4 tests / **252 assertions** (up from 219).

### 31d. What none of this claims

Converting a writer to `ProductStockService` is **not** evidence that the path is protected — the
owner's instruction says so explicitly and it is repeated here because the conversions are easy to
mistake for the fix. The lost-update property they buy cannot be driven in this suite at all;
`OrderStockConcurrencyTest` proves it against the service, and these tests prove only the thing
that would silently undo it. The gateway decrements still precede payment verification: D-13's
open half, needing E13's durable evidence, which is BLOCKED.

## 32. P2-05-4 — categories and attributes (2026-10-27)

| | |
|---|---|
| `shoot-categories-attributes-live.cjs` | **74 cases, 74 passed, 0 failed** — 16 screenshots |
| `check-row-actions-live.cjs` (now five screens × four configurations) | **260 cases, 260 passed, 0 failed** |
| `CategoryAttributeRowActionsTest` | 7 tests, 17 assertions |
| `CategoryTreeEscapingTest` | 4 tests, 8 assertions |
| `CategoryParentValidationTest`, `MerchantCategoryOwnershipTest`, `AttributeOptionAndCustomerBoundaryTest` | 45 tests, 157 assertions — unchanged |

### 32a. The escaping fix is pinned by the tension it has to resolve

`buildCategoryTree()` builds one string out of two kinds of content: a `&nbsp;` indent prefix that
MUST stay live markup, and a merchant-written name that must not. Eight views print the result
with `{!! !!}`, two of them in vendored `packages/workdo/ProductReview`.

`CategoryTreeEscapingTest` asserts both halves, because a fix that satisfies only one is wrong in
a way the other would not catch: escape the whole string and the select loses its hierarchy;
escape nothing and a category name executes. It also pins that an ampersand in a real name
(`&nbsp;Sneakers`) cannot forge the indent's entity, and that quotes are escaped — the value is
printed inside an `<option>`.

**Revert-checked:** 3 of the 4 fail against the unfixed helper. The fourth — the indent — passes
before and after, deliberately: it is there to stop the fix going too far, not to prove it
happened.

### 32b. The gates are asserted in both directions

`CategoryAttributeRowActionsTest` renders the row-action partials directly, because the full page
additionally renders `partision/header.blade.php`, which reads `->slug` on the user's Store and
500s when a test user has none — driving the page would measure that fixture gap rather than this
change.

Positive: a user holding Edit and Delete sees both controls, each named for the record
(`aria-label="Edit category Scarves"`). Negative: a user holding only `Manage Product Category`
sees **neither**, and `category.destroy` does not appear in the markup at all. Separating: Edit
alone does not reveal Delete. That negative direction is what substantiates "no functionality
removed" — the handler already refused those users, so what disappeared is a control that never
worked for them.

**Bite-tested:** removing one `aria-label` returns 1 failure; removing one `@permission` returns 2.

### 32c. What the live checks caught that no server-side test could

Three runs were green, or loudly red, **about the wrong thing** before this one. Each is now a
premise rather than a lesson:

1. **The wrong screen.** `/product-attributes` refuses a user without `Manage Attributes` with
   `redirect()->back()`, landing on the previous screen in the harness's own loop. A full run
   measured the category list, labelled every line `attributes`, and reported contrast failures
   naming `#ORD-2047` on a screen with no orders. Both harnesses now assert the landed URL first.
2. **The wrong scope.** The modal probe read `document.querySelector('.modal.show') || document`,
   so a failed click measured the list page — "every label resolves to a control (5)" passing
   against page chrome. The modal is a hard premise now, with no fallback.
3. **The wrong question.** A 66/66 run reported success while two switch captions were visibly
   dimmer than the fields above them: the probe checked that labels RESOLVE, never that they can
   be READ, and the acceptance for this slice is AA in all four configurations. Contrast and
   dialog-overflow measurement were added and found 2.18:1 and 2.52:1 immediately.

### 32d. The contrast finding, and what it is NOT

Measured over the effective background with alpha compositing: the host's Bootstrap modal keeps a
**white** `.modal-content` in dark mode (`rgb(255, 255, 255)` with `data-ss-theme="dark"` on
`<html>`), and `custom.css`'s bare `html[data-ss-theme="dark"] body label` rule paints every label
in the application `#9DA3B4` — **2.52:1** on that white surface.

**This is not caused by the design-system conversion**, and that was checked rather than assumed:
the previous markup used `<label class="form-label">`, which is still a `label` and was matched by
the same rule — six of them on the create-category form alone. The conversion measured the defect;
it did not introduce it.

The second cause was the design system's own: its light palette lived on `:root` alone, so the
dark block's documented "on `<html>` or on any container" scoping worked in one direction only and
a subtree inside a dark page could never be returned to light. Fixed by declaring the light
palette on `:root, [data-ss-theme="light"]`.

**Not fixed, and named as such:** roughly sixty other modal forms across the host and the seventy
vendored modules still render their labels at 2.52:1 in dark mode. Narrowing the bare rule means
re-measuring the sidebar and tables it was added to fix (ledger **F126**).

## 33. P2-05-4 follow-up and P2-05-5 (partial) — 2026-10-27

| | |
|---|---|
| Feature known-outcome gate | **6069 passed, 2 declared failures, 17 declared skips, 0 errors** — `OK: the observed outcome set matches the manifest exactly`, 6088 testcases, 744s |
| `tests/Feature/Security/` + `tests/Feature/Merchant/` + `tests/Unit/Security/` | **4128 passed, 4 skipped, 0 failed** (18452 assertions) |
| `tests/Feature/Merchant/` + `tests/Unit/Dashboard/` + `tests/Unit/Ui/` | **161 passed** (1444 assertions) |
| Unit suite | 428 passed |
| Contract suite (the Flutter API) | 26 passed |

### 33a. A caveat on the gate run, recorded rather than glossed

**That gate run spanned edits.** It was started after the F128/F129 fixes but before the P2-05-5
ones, and PHP loads a view or class at test time — so tests that ran early in the suite saw the
pre-P2-05-5 files and tests that ran late saw the post-edit ones. The testcase count rising from
6072 to **6088** is the visible trace of it: the sixteen tests added mid-run were collected.

It is therefore evidence that the outcome SET is unchanged across both slices, and it is **not** a
clean single-commit gate. What covers the P2-05-5 edits specifically is the 161-test run above,
executed after them and after the tree was committed, plus the per-slice bite tests. A clean
full-suite run on the final tree is owed at the next integration milestone and is **not** claimed
here.

### 33b. The two findings the independent review contributed

`AttributeOwnershipAndListEscapingTest` (8 tests) covers both. The cross-tenant half drives a
foreign rename, a foreign delete and a foreign read — all refused — alongside the positive
controls that the merchant's **own** rename and delete still succeed and that a same-store caller
without the permission is still refused. Without those three, "refused everything" would pass just
as well as "refuses the right things". Asserted through `withoutGlobalScopes()`, because a plain
`find()` answers against whichever tenant resolved rather than against the table.

The escaping half is asserted against the **rendered** DataTable JSON, not the source text,
because these cells are built by concatenation where nothing else escapes them and because the
existing ratchet (`CustomerListOutputEscapingTest`) only flags raw columns with **no closure** —
all three of these have closures.

**One assertion had to be corrected, and it is the useful part of the exercise.** It asserted the
absence of the word `onerror=alert(1)` and therefore FAILED against the *correct* fix: `e()`
leaves that text in place, harmlessly, inside a now-properly-quoted attribute. The property is the
**quote**, not the word. An assertion that fails on a working fix is as much a defect as one that
passes on a broken one, and this is the first of that direction recorded in this phase.

### 33c. The ratchet that was blind to the dominant spelling

`DashboardPermissionNamesTest` existed, passed, and could not see F130. It scanned only
`isAbleTo('…')`, so every `@permission(…)` directive in the application was invisible to it — and
`@permission` is the dominant form in these views, **140 occurrences across 64 of them**. It now
matches both and covers the five roles/users views; restoring the `Mange User Login` typo fails it
by name.

That is the same lesson as F123's exit detector, which keyed on one spelling of `return` and
missed two post-capture exits. Recorded twice because it has now cost a real defect twice: **a
source-text check is only as wide as the spellings it knows, and the one it does not know is where
the bug lives.**

---

## 34. F117 — the credential migration, and the four false readings it produced

Sixteen credential fields across eleven settings panels rendered their stored value into the page.
All sixteen are now converted and the exposure ratchet's list is **empty**. What follows is not
the change — the diff is the change — but the things the diff could not have shown.

### 34a. The ratchet had to go to zero AND still bite

An empty allowlist is the easiest kind of test to break without noticing: delete the scanner's
body and it passes forever. So the empty list was bitten. Reinstating the exact markup that was
removed from the ChatGPT panel —

```blade
<input type="text" name="api_key[]" value="{{ $key_data->key }}">
```

— turns `test_no_settings_screen_newly_echoes_a_stored_credential` red, and removing it turns it
green again. Executed in both directions before the list was trusted.

Three raw `type="password"` inputs remain, and each is a documented exception rather than an
oversight: `otp_setting.blade.php api_secret` (the original precedent, paired with
`OtpIntegrationController`'s `filled()` guard) and the two **JavaScript string templates** the
ChatGPT add-a-row button clones into the DOM. Blade never renders those two, so a Blade component
cannot be used; they carry no stored value by construction — a cloned row is always a new key —
so there is no persistence half to pair, and the only F117 risk they carry is shoulder-surfing a
key as it is typed. The precedent test now pins that set of three rather than a single entry, so a
fourth hand-rolled masked input fails it.

### 34b. Resolving at the save would have been too late — for one field

`delivery_syria_token` is **posted to an external verifier before it is stored**. A merchant
re-syncing their shipping zones leaves the masked box alone, so resolving only at the save would
have sent the empty string to `deliverysyria.com`, failed verification against a token that is
valid and still stored, and dropped the merchant into *"Token updated but verification failed"*
with no way to tell why. The resolve happens before the call, and the proof is not the save
assertion — it is `Http::assertSent(fn ($r) => $r->data()['secret'] === …)`.

Two more things about that handler, found because the test had to drive it:

- Its route carried `auth` and **no permission at all**, so any logged-in member of the store
  could rewrite the store's shipping zones, its per-zone costs and the owner's courier token. It
  now goes through `StoreSettingsAccess::permits()` — the same operator-first decision point the
  other integration handlers use — and the refusal lands *before* the outbound call, so an
  unprivileged user cannot make the server post to a third party either. `Http::assertNothingSent()`.
- Its `catch (\Exception)` reports **every** exception as *"Could not connect to verification
  server"*. A foreign-key failure writing `shipping_zones` is indistinguishable, to the merchant
  and to a test, from the courier being down. That is left as-is (changing a user-facing error
  message is not this slice's work) but it is why the fixture seeds `countries`/`states` — see
  34d.

### 34c. A masked repeater needs an identity, and the old one was the value

The ChatGPT keys are rows in `apikey_setiings`, and the old save used the posted **value** as the
row's identity: `updateOrCreate(['key' => $posted])` then `whereNotIn('key', $posted)->delete()`.
A masked box posts nothing that identifies a row, so each saved key now posts its own row id as
`api_key[e<id>]`, while a row the add-button JS clones keeps the bare `api_key[]` and arrives
under an integer key. PHP merges the two shapes in one array, which is why the field names do not
have to be renumbered client-side.

The row id now travels in the request, so it is **caller-supplied input**: it is resolved as
`where('created_by', $owner)->find($id)`, never `find($id)` alone, and a forged id belonging to
another operator reaches nothing. The reconciling delete gained the `created_by` predicate it
never had. That is forced by the masking rather than a widening of scope: the form now lists only
the caller's rows, so an unscoped delete would wipe every other operator's keys on the first save.

### 34d. Four false readings, and one wrong story written into three docblocks

Every case carries a witness assertion on a non-secret field, because **a refused request looks
exactly like a preserved credential**. The witnesses earned their place four times:

| What looked like a pass | What was actually happening |
|---|---|
| Twilio secrets "survived" | `current_store` was set but no `Store` row existed, so `getCurrentStore()` was NULL and the handler wrote nothing at all |
| Shopify secrets "survived" | the payload used the wrong field name — `shopify_store_url` is a DNS **label** under P1-SEC-06, not a URL — so validation refused the request |
| the Delivery Syria token "survived" | `shipping_zones` has FKs onto `countries` and `states` and the handler hard-codes country 215; `ShippingZone::create()` threw, the `catch` returned 302, and the save was never reached |
| a deleted ChatGPT key "survived" | `ApikeySetiings` is `Cachable`; under `CACHE_STORE=array` the model cache served a row the query-builder delete had already removed. The table is read with `DB::table(...)` now |

The fourth is the documented `Cachable` trap in `CLAUDE.md`, met for the third time in this
project — and the first time in a *negative* assertion, where stale data reads as "still there"
rather than "not yet applied".

**And one claim was simply wrong.** The first draft of the ledger row and of three docblocks said
that masking the ChatGPT boxes under the legacy save would have *deleted every key on the first
blank submit*. It would not. `ConvertEmptyStringsToNull` runs before the handler, so a blank box
arrives as **NULL, not `''`**, and `whereNotIn('key', [null])` is SQL three-valued logic: it
matches no row, so the legacy delete silently stopped reconciling instead of over-deleting. The
over-delete is real but needs the opposite input — every box filled, where the unscoped delete
removed 2 of 2 rows belonging to another operator (executed against the legacy code).

The wrong story was plausible, was written in three places, and was caught only because the
legacy-code bite test reported `deleted: 0` where the story predicted `deleted: all`. It is
recorded here because the failure mode is not carelessness: it is reasoning about a save path
without executing the middleware stack in front of it.
`test_an_empty_box_arrives_as_null_and_resolve_treats_it_as_absent` now pins the premise, in the
test file rather than in prose, so the next person inherits the measurement instead of the story.

### 34e. What is not claimed

- **MySQL.** These are string columns and the decision is in PHP, but nothing here has been
  executed against MySQL and it is not claimed to be. SQLite's type affinity is why P01-SEC-13
  survived a green suite.
- **A full Feature-suite gate run on this tree.** Owed at the next integration milestone. Unit
  (442) and the two F117 feature classes (18) are green; that is evidence about those tests, not
  about the project.

### 34f. What the independent review found — three of the four defects were mine

`tenant-authz-reviewer` was run on the diff before it was committed, and it is the reason this
slice is not shipping with a credential-exfiltration path in it. Four findings, all verified
against the source before being acted on, three of them **created by the F117 change itself**.

**1 — HIGH, new. A staff member could have exfiltrated the store's SMTP password.** Masking a
field is not only a display change: every handler that falls back to the stored value becomes a
handler that **will use a secret the caller does not know**. On a save path that is harmless —
the value goes back where it came from. On a *send* path it is not, because the caller chooses
where it goes. `testSendMail` merges the resolved `MAIL_PASSWORD` into the request and hands the
whole request to `SetConfigEmail()`, which sets `mail.host`, `mail.username` and `mail.password`
from it. Its route carries `auth, xss, setlocate, verified` and **no permission check**.

Before F117 that was survivable: the settings tab 403s a staff member without `Manage Setting`
(P1-SEC-18 F5), so they could not learn the password, and the handler made them supply one. After
F117 the server supplies it — so `mail_host=smtp.attacker.example` with the password box left
empty would have had the application authenticate to a caller-chosen host with the merchant's
saved credential. **This re-opened P1-SEC-18 F5 through a different door**, and no test in the
repository would have noticed, because every F117 test drove a principal who was allowed.

`testSendMail` and `TestMail` now go through the same `StoreSettingsAccess::permits()` that
guards the tab the credential came from, so the two doors cannot drift apart again. The assertion
is on `config('mail.password')` after the request, not on a delivered email: what the SMTP
handshake then does is the attacker's business, and the defect is that the application loaded the
secret into a transport at all.

**2 — MEDIUM-HIGH, new. The same shape in `testSendwhatsappmassage`**, with a smaller loss: the
Bearer token goes to the literal `graph.facebook.com` and the phone-number id is interpolated
*after* the authority, so the token cannot be redirected to another host. What an unprivileged
caller gained was use of the merchant's WhatsApp account against an arbitrary number. Same gate,
on the send handler and on the modal that opens it.

**3 — HIGH availability, live. Three converted panels were left unsaveable.** Their fields were
masked and their saves were converted, but their *validators* were left at a flat `required`:
`mail_password` in `saveEmailSettings`, `s3_secret` and `wasabi_secret` in `StorageSettings`. An
ordinary save of the panel failed with "The mail password field is required" and
`keepStoredSecret()` below was never reached. It fails **closed** — nothing is erased — but the
panel cannot be saved without retyping the secret.

This is the defect class the slice had already identified, written up and fixed *twice* on other
panels, and it still shipped past a green suite — because `convertedPanels()` covered only two of
the five converted panels, and the two it covered were the two that had been fixed. **A data
provider is a coverage claim, and nothing was checking that claim against the list of panels
actually converted.** All five are in it now.

**4 — MEDIUM. Two of the new tests could pass against a refusal.** `ChatgptSettings` returns the
*same* redirect on refusal as on success, so `assertRedirect()` accepts both, and a case whose
only assertion is "the other operator's key is still there" passes just as well against a handler
that did nothing. Both flagged cases — and the three others in the file that had the same shape —
now call `assertTheKeySaveRan()`, which checks the non-secret `chat_gpt_model` row the success
path writes. Verified by making the handler refuse everyone: **seven of eleven cases go red**,
where before the fix only four did.

**5 — LOW, not acted on.** Scoping the ChatGPT panel's read to `created_by = auth()->user()->id`
means that with more than one operator account — or with legacy rows owned by a different one —
the panel can show "No value is saved yet" while a key is live and billing, because
`AITemplateController`'s platform fallback picks the lowest-id key owned by **any** `super admin`.
It is a visibility problem and not a hole: the scoped delete leaves the invisible rows alone
(checked specifically for the `$keptIds === []` branch), so no principal gains anything. Matching
the read to `AITemplateController`'s wider predicate would widen the *save's* blast radius, which
is the wrong trade inside F117. Recorded here rather than changed.

**And one scope correction.** "No credential reaches a settings page" is accurate for the eleven
converted panels and **not** for the payment tab, which still renders every gateway credential
into its inputs — deliberately, as `SettingController` says at the top of that handler. That tab
is gated by `permits()`, so it is not a staff-reachable leak, but the claim must not be read more
broadly than it is. The payment tab is not in F117's sixteen and is not closed by this slice.

**The pattern across all four:** every one lives at the *edge* of the change rather than inside
it. The resolver is correct, the component is correct, the save paths are correct — and the
defects were in a send handler the slice touched in passing, in three validators it did not
touch, and in a data provider that silently defined what "covered" meant. A review that only read
the lines that changed would have found none of them.

---

# P2-07-13 + P2-07-9 — employee permissions and figure honesty (2026-10-28)

## What was executed

| Suite | Result |
|---|---|
| Laravel `tests/Feature/Security/AdminApiPermissionExposureTest.php` (new) | **22 passed, 74 assertions**, 0 skipped |
| Laravel `tests/Feature/Security/AdminApiListPermissionTest.php` (new) | **7 passed**, 0 skipped |
| Laravel `tests/Feature/Merchant/ApiDashboardFigurePeriodTest.php` (new) | **4 passed, 9 assertions** |
| Laravel `--testsuite=Unit` | **520 passed, 2470 assertions** |
| Laravel `tests/Feature/Security` + `tests/Feature/Tenancy` | **4498 passed, 10 skipped, 0 failed** (576s) |
| Laravel `--testsuite=Contract` | **26 passed, 83 assertions** — unchanged, which is the point |
| App `test/security/` (3 new files, 24 new tests) | **132 passed** |
| App `test/contract/permission_gated_endpoints_test.dart` (new) | **4 passed** |
| App `test/ui/dashboard_figure_honesty_test.dart` (new) | **7 passed** |
| App `test/security/refusal_reads_as_refusal_test.dart` (new) | **3 passed** |
| App full `flutter test` | **1269 passed** (1231 before) |
| App `flutter analyze` | **246 info, 0 warning, 0 error** — exactly the recorded baseline |

The full `--testsuite=Feature` run against `.github/known-test-outcomes.json` is recorded with the
commit; the partial suites above are **not** evidence for it, and are not offered as such.

`flutter analyze` is worth a line of its own. It read **248** after the first version of the tile,
and the two extra were **warnings** — `title!` and `subTitle!` dereferenced a second time inside an
expression that had already promoted them, which the analyser reports because the `!` is dead code.
The project's baseline is 0 warnings, so a count that merely "looks close" is not the same as being
clean; removing them returned it to 246 exactly.

## Eighteen guards, each bitten by reverting it

A test that passes is not evidence the thing it names is doing any work. Each of these was removed
or inverted and the matching test confirmed red, then restored.

| Reverted | Test that went red |
|---|---|
| the operator-first branch | `the operator is not reported as having no permissions` |
| the per-store arm of the role predicate | `an employee of another store holds nothing here`, `the permission list follows a store switch` |
| the null-store arm | `a merchant keeps their permissions in every store` |
| the `instanceof App\Models\User` guard | `a non user principal resolves to nothing rather than fatally` |
| `permissions` on the login payload | `the login payload reports the permissions`, `a limited employee gets only their own permissions` |
| the three money gates (forced to `true`) | `money is withheld without the order permission`, `a withheld figure is absent rather than zero` |
| the `! $store` guard | `a dashboard for a missing store does not 500` |
| the em dash (back to `total`) | `shows a dash, never a zero, when it was withheld` |
| the spoken reason | `tells a screen reader why the figure is a dash` |
| `mayOffer`'s fail-open | `an uninformed client offers every entry` |
| the store-switch clear | `a store switch replaces the previous store permissions`, `nothing is held while the new store is still loading` |
| the five list-endpoint gates (forced to `true`) | `every gated route refuses a staff member without its permission` |
| the gate's store (switched to `current_store`) | `a permission in one store does not unlock another` |
| `Show Coupon` from the coupon gate | `show coupon is enough for the coupon block` |
| the analytics gate | `store traffic is withheld without the analytics permission` |
| the account-state refusal | `a deactivated user cannot log in to the api`, `an employee of a disabled owner cannot log in` |
| the `super admin` exemption | `the operator is exempt from the account state switches` |
| the client's 403 branch (made a logout) | `a 403 does not force a logout` |
| the refusal message (back to the screen's own string) | `a refusal shows the server reason` |

## Four false readings, and what each one cost

**1 — The money test passed against unfixed code.** The first version created no order and no
coupon, so `final_price` and `discount_amount` were absent from the response because they were
absent from the *database*. It proved nothing about gating. It is written from both directions now:
the same fixture through a permitted principal must still carry the money, so the pair can only
both pass if the permission is what makes the difference.

**2 — Three tests passed against HTTP 405.** `/api/admin/dashboard` is declared **POST**; the tests
drove `getJson`. Every assertion of the form "the money is absent" passed on a Method Not Allowed
body — the handler never ran. This is the most dangerous shape a security test can take, because the
failure mode is silent and reads as success. Every data-reading test now goes through
`dashboardFor()`, which asserts **200 and a `data` envelope** before absence means anything.

**3 — Dead code was gated and then un-gated.** `MyDrawerController` holds a nine-entry navigation
list and is **referenced by nothing** — `grep -rn MyDrawerController lib/` returns only its own
declaration. Gating it compiled, analysed clean, and would have read in the ledger as a delivered
feature while changing nothing a merchant can see. The real surface is the settings grid.

**4 — `Semantics(label:)` combines rather than replaces.** Wrapping the tile's text in a `Semantics`
with an explicit label produced *"Total Orders, all time"* **and then** *"Total Orders"* and
*"all time"* again as separate fragments. The exact-label assertion found zero matches and exposed
it; `excludeSemantics: true` is what makes it one utterance. A `findsAtLeast`-style or
`textContaining` assertion would have passed and shipped the stutter.

## Four tests that CANNOT fail on revert, labelled as such

Independent review checked this and it is worth stating plainly, because a test count is not an
evidence count. These four are legitimate ratchets and none of them is evidence for this change:
`test_the_login_payload_keeps_every_field_it_had` (the old code returned those eight fields too),
`test_a_permission_refusal_is_not_401` (the old dashboard also answered 200 here),
`test_money_is_present_with_the_order_permission` (the unfixed controller served that money), and
`test_the_merchant_role_really_holds_every_permission_the_dashboard_gates_on` (a dependency ratchet
on `$companyNewPermission`, and the most valuable of the four). They exist to catch a LATER change,
and the file now says so in each docblock.

Review also caught an assertion that could pass for the wrong reason: `final_price` is an
**appended attribute on `App\Models\Product`**, so `assertStringContainsString('final_price')` was
satisfied by the serialised top-products block even if the `recent_order` block had been wrongly
withheld. It asserts the **key** inside `recent_order[0]` now, and the missing `cost_price`-present
counterpart was added — without it, withholding the product block unconditionally would have passed
both money tests.

## One live defect found in passing

`Api\DashboardController::dashboard()` carried an `if ($store)` guard whose own comment says it was
added because *"a user whose current_store no longer exists produced a 500 here"*. Only the qr-code
block was inside it; the four queries immediately after still dereferenced `$store->id`
unconditionally. The 500 had moved four lines down, not gone. Reachable by any merchant whose
current store was deleted.

## Limits, stated rather than implied

- **~119 of the 127 `api/admin/*` routes remain ungated.** `routes/api.php` declares no permission
  middleware. This slice gates eight: `adminlogin`, `/dashboard`, `orderlist`, `Top_orders`,
  `productlist`, `variantlist`, `couponlist`, `staticsdata` — the ones serving money or analytics.
- **The first version of this slice gated only `/dashboard`**, which independent review showed
  closed one screen while four siblings served the same money to the same token. That is recorded
  here rather than quietly corrected, because the lesson is about scoping a security fix to the
  *data* rather than to the *screen*.
- **MySQL remains unverified.** Everything above ran on SQLite `:memory:`; there is no mysqld or
  client in this container. SQLite's type affinity hides real MySQL defects, and none of this is
  claimed as covered on MySQL.
- **No device, so no screen reader was run.** The announcements are asserted from the semantics
  tree, which is what a reader is handed — not the same thing as hearing one speak it. Reading
  order on a rendered screen is still open.
- **A refusal now reads as a refusal on the order screens only.** Three sites in
  `order_controller` discarded the server's message; `product_controller` and
  `statistic_controller` already surfaced it. Other screens calling ungated endpoints were not
  swept — there is nothing yet to refuse them.
- **The app's permission gate is cosmetic by design.** It lives in unencrypted local storage. The
  control is the server-side withholding, which is tested separately and independently.

---

# P2-07-10 — stock state parity (2026-10-28)

| Suite | Result |
|---|---|
| App `test/ui/stock_state_parity_test.dart` (new) | **10 passed** |
| App full `flutter test` | **1279 passed** |
| App `flutter analyze` | **246 info, 0 warning, 0 error** |
| Laravel `tests/Feature/Merchant/ApiProductListStockParityTest.php` (new) | **3 passed** |
| Laravel `tests/Feature/Security` + `tests/Feature/Merchant` | **280 passed, 1900 assertions** |

Guards bitten: the tracked-product branch (reverting it to the stored column fails 6 of 10 cases),
`<=` versus `<` (fails 4), honouring the per-store threshold (fails 1), the server's threshold read,
the response field, and the list gate re-bitten under the tightened assertions below.

## The false reading this row found in the work before it

`orderlist`, `Top_orders` and `productlist` all read `currency_rates` — a **GATE F table with no
migration** — so on a from-zero SQLite schema they return **500**, not 200. The
`AdminApiListPermissionTest` written an hour earlier asserted the permitted direction with
`assertNotSame(403, ...)`, **which a 500 satisfies**. Three of its five "still serves the store
owner" assertions were therefore passing on a missing-table crash rather than on the gate letting
the merchant through.

It was found by probing each endpoint's actual status rather than trusting the green tick:

```
PROBE orderlist      500 SQLSTATE[HY000]: no such table: currency_rates
PROBE Top_orders     500 fail
PROBE productlist    200          <- the EMPTY path; 500 once a product exists
PROBE variantlist    200
PROBE couponlist     200
PROBE staticsdata    200
```

`productlist`'s 200 there is itself a trap: with no products the handler returns
`error('Product Data not found.')` before it ever reaches the currency query, so even the probe
needed a fixture to tell the truth.

Two changes followed. Every "is served" assertion is now `assertSame(200, ...)`, so a crash cannot
read as success. And `SecurityTestCase::requireCurrencyRatesStandIn()` supplies the **test-local
minimum** — copied verbatim from `WebSettingsSecretsTest::setUp()` and `ChatgptKeyDisclosureTest`,
the two tests that already face this, so the suite has one shape rather than three. That is **not**
inventing the production schema, which `GateFSchemaContainmentTest` forbids outright and which is
why the parked draft keeps its columns commented out; the measured production columns live in
`docs/GATE-F-VERIFIED-SCHEMA.md`.

**The rule:** `assertNotSame($bad, $actual)` is a weak assertion wherever the endpoint can fail in
a third way. On a surface with an unmanaged table under it, there is always a third way.

## Limits

- **The detail screen applies threshold 0**, because the detail response does not carry the
  setting. For a store that changed it, the list card is the authoritative badge.
- **`GateFCoverageLedgerTest` is now stale in prose**: it describes `currency_rates` as
  "referenced only as a static model-to-table mapping", and `Api\DashboardController` reads
  `CurrencyRate::where(...)` in three handlers the Flutter app calls. It is a comment rather than
  an assertion, so nothing fails. Named for the next schema pass.

---

# P2-07-11 + P2-07-12 — the write side, and a filter that hid two statuses (2026-10-28)

| Suite | Result |
|---|---|
| Laravel `tests/Feature/Security/AdminApiOrderWritePermissionTest.php` (new) | **7 passed** |
| Laravel `tests/Feature/Merchant/ApiOrderFilterStatusCoverageTest.php` (new) | **5 passed** |
| Laravel `--testsuite=Unit` | **520 passed** |
| Laravel the suites touching these routes | **801 passed** |
| App `test/ui/order_filter_status_coverage_test.dart` (new) | **3 passed** |
| App full `flutter test` | **1282 passed** |
| App `flutter analyze` | **246 info, 0 warning, 0 error** |

## Measuring "quick daily operations" found a write-side hole

P2-07-11's acceptance was already met: changing an order's status is two taps from the order list,
and the same sheet is reachable from the order detail screen and a customer's detail screen. No UI
change was needed.

What measuring it found is that `api/admin/orders/update-status` had **no permission check at all**.
The store comes from `getCurrentStore()`, so the tenant boundary was sound — and then it wrote
`delivered_status` and `payment_status` with nothing asking whether this principal may. The test
that named it recorded the server's own words:

```
"success":true,"message":"Order updated successfully."
```

for a staff member holding only `Manage Product Category`. A read exposes a figure; **marking an
unpaid order Paid changes what the merchant believes they are owed.**

The gate is `Gate::forUser($actor)->allows('updateStatus', $order)` — the web's own ability, which
`OrderController` uses at four sites. Not a copied permission name, so the two surfaces cannot
drift about who may change an order. Deleting `OrderPolicy::allow`'s operator-first branch fails
`test_the_operator_is_not_refused`, which confirms the test exercises that ordering rather than
inheriting it silently.

## Five existing tests went red, and investigating beat suppressing

`AdminApiSurfaceTest::test_admin_list_routes_still_accept_the_callers_own_store` (×4) and
`ApiStoreOwnershipPredicateTest::test_the_owning_merchant_is_not_refused_at_any_rewired_admin_endpoint`
both assert that the **store predicate** does not refuse the owner. Their subject is tenancy. A
fixture merchant from `makeTenant()` holds **no Laratrust role at all**, so a permission gate made
them fail for an orthogonal reason — and each would have stopped isolating what it was written for.

The fixtures grant the gated permissions now, with the reason written into both files. A real
merchant is unaffected: the shared global `admin` role carries all of them and is what every
merchant is given for every store they create, and
`AdminApiPermissionExposureTest::test_the_merchant_role_really_holds_every_permission_the_dashboard_gates_on`
pins that premise rather than leaving it as a claim.

**The rule:** when a new gate turns an existing test red, read what that test was written to
isolate before changing either side. Here the gate was right and the fixture was incomplete; the
opposite is just as possible, and only reading tells you which.

## The filter offered seven of nine, and the API refused the other two anyway

`orders.delivered_status` holds nine values. The app's tabs stopped at Shipped, and
`api/admin/orderlist` validated `in:0,1,2,3,4,5,6`, so a request for 7 or 8 answered **422** —
while F29 had already taught the API to *render* both names. A merchant could see a partially-paid
order and had no way to ask for one.

Both validators are now derived from `HomeController`'s constants rather than typed out, which is
what the web's filter does and why it says so:

> "Typing a list by hand is how a filter ends up offering 0–6 and quietly hiding those orders from
>  the one screen a merchant uses to find them."

One of the three app tests asserts the tab **order**, not the labels, because
`order_screen.dart` sends `selectedStatus - 1` — the position *is* the status. The bite test moved
the two new tabs before "shipped" and two tests went red while every label was still present, which
is exactly the failure a label-only assertion would have missed.

## Limits

- **Widening a validator is not changing a business rule.** Nothing here decides what a status
  means or when it may change, and a value the column cannot hold is still refused.
- **Product and customer search were measured and left alone** — neither had a comparable
  accepted-set gap.
- **MySQL remains unverified.** Everything above ran on SQLite `:memory:`.

---

# P2-04-10 — the census found what the enforcement had already lost (2026-10-28)

| Suite | Result |
|---|---|
| `tests/Feature/Merchant/OrderActionPermissionCensusTest.php` (new) | **11 passed** |
| `tests/Feature/Security/AdminApiOrderWritePermissionTest.php` | **9 passed** (7 + 2 new) |
| Every suite the change touched, together | **354 passed, 2074 assertions** |
| App `flutter test` | **1283 passed**, `analyze` 246 info / 0 warning / 0 error |

Independent verification: **four reviewers, 0 of 4 refuted.** Two reproduced the order write in
isolated checkouts of HEAD. Every correction they returned made a finding **worse**, not weaker.

## The row's note was the whole reason it existed

> "enforced in code today via `OrderPolicy` and `mayUseOrderScreen`, but no row asserted it"

An unasserted control is one refactor away from an absent one. Here it had already happened — in
four places, two of them money-bearing writes.

## Four defects

1. **The ORDER grid gated on the PRODUCT permission.** A `Manage Products` holder got 200 and read
   every order **while being refused the order list itself (302)** — one principal who could not
   open the list and could open a grid of its contents. A `Manage Order` holder was refused the
   button the list renders for them. The operator was locked out by the bare `isAbleTo()`.
   Intra-tenant: the store predicate is real, so what broke is privilege separation inside one
   store. Copy-pasted from `ProductController:4373`, which still carries the shape.
2. **`create()` ungated** — and *absent*, not mis-named: a user with `roles held: []` got 200. The
   form lists every customer's email and every product's price.
3. **`store()` ungated on a WRITE.** Orders 0 → 1, driven by a principal with no role. A status
   assertion could not show it — the handler redirects either way.
4. **`createOrderViaApi` ungated**, the same hole on the app's surface: 328 lines, two
   order-creating calls, zero permission checks.

## Three flaws in my own census, and the one that nearly hid the worst finding

- **A substring matcher.** `str_contains($action, 'OrderController@')` also matches
  `DeliverySyriaOrderController@`, so the first run reported a route belonging to another
  controller. Compares the exact class now.
- **Two vacuous rows.** `order_view` decrypts its id; `order_preview` demands a raw integer and
  404s on anything else **before** its permission check. Passing a Crypt string to both made every
  case on those rows 404 for every principal — "refused", "served" and "operator locked out" were
  indistinguishable, and all three looked green. A premise test now fails if any censused URI
  answers 404 for a permitted holder.
- **A grep that assumed four-space indentation.** It reported `store()` absent, because `store()`
  is declared at column 1. The most serious finding of the row was nearly lost to a flaw in the
  tool rather than in the reasoning — which is the one failure mode a careful argument cannot
  catch.

## Nine existing tests went red across two rounds, and none was suppressed

Five assert the **store predicate** does not refuse the owner; four assert the **stock** contract.
All nine drive these endpoints with a fixture merchant holding **no Laratrust role at all**, so a
permission gate failed them for an orthogonal reason and each would have stopped isolating what it
was written for. Fixtures grant the gated permissions now, with the reason in every file.

**The rule, restated because it came up twice in one day:** when a new gate turns an existing test
red, read what that test was written to isolate before changing either side.

## And a ratchet that red-flagged itself twice

`SecurityLogInventoryTest` keys `docs/security/P01-OBSERVABILITY-INVENTORY.md` by `file:line`.
Editing `OrderController` shifted **ten** log statements; correcting the prose shifted them again.
The numbers are **derived** from each message's actual position now. Regenerating that inventory is
the last step before committing any change to a file it indexes — not the first.

## Limits

- The census covers the **merchant** order surface. Storefront routes under `{storeSlug?}` are a
  different principal and guard family (Phase 03) and are listed explicitly, not pattern-filtered,
  so a new one must still be read by a person.
- `ProductController:4373` carries the same bare-`isAbleTo()` shape and is **not** fixed here.
- MySQL remains unverified.

---

# The full gate FAILED on 638d06c1, and what that says about how I verified it (2026-10-28)

I pushed `638d06c1` describing it as verified. The full `--testsuite=Feature` run against it then
came back **12 failed, 3 errored** — and ten of those twelve were mine.

```
Tests:    12 failed, 17 skipped, 6332 passed
::error::ERRORED: Tests\Feature\Commerce\OrderStoreOwnershipTest::...
::error::  SQLSTATE[HY000]: General error: 1 no such table: permissions
::error::The suite has a zero-errors baseline and there is no manifest section for errors.
```

Ten failures, all in `tests/Feature/Commerce/OrderStoreOwnershipTest`, three of them **errors**
rather than failures — which the gate holds at a zero baseline precisely because a test that could
not run is a worse thing than one whose assertion failed.

## The cause

`tests/Feature/Commerce/*` **builds its own schema** and says why in its own docblock: `RefreshDatabase`
runs the whole migration set, and `2024_05_16_054044_add_id_to_currency_table.php` cannot run on
SQLite ("Cannot add a PRIMARY KEY column"), so the suite would die during migration before reaching
an assertion. It therefore creates only "the tables ... the real migrations declare for the rows
this writer actually touches".

Gating `OrderController::store()` made that writer touch two more tables. `mayUseOrderScreen()`
tests the platform operator first and otherwise calls Laratrust's `isAbleTo()`, and this suite's
merchant is `type => 'company'` — not the operator — so the permission query ran against a schema
that has no `permissions` table.

## The process error, which is the part worth recording

I ran "every suite the change touches" and reported 354 passing, then a 4414-test regression across
`tests/Feature/{Security,Merchant,Platform}`. Both were clean. **Neither included
`tests/Feature/Commerce`.**

I chose that set from the suites I already knew about — the ones that had gone red earlier in the
day — rather than from what the changed code is *driven by*. `OrderController::store()` has a
dedicated suite in a directory I never looked at. The subset looked thorough because it was large,
not because it was derived.

**The rule:** before claiming a change is verified, derive the suite set from the symbol, not from
memory. `grep -rl "OrderController\|store()" tests/` would have found it in one command. A 4414-test
run that omits the one suite dedicated to the handler you changed is not evidence about that
handler.

The claim in `638d06c1`'s message and in the P2-04-10 ledger row that the change was verified was
therefore wrong when written. It is corrected here rather than quietly amended.

## The fix

The Laratrust tables are now part of what that suite builds — which is its own stated rule, since
the writer now touches them — and the merchant is **granted** `Manage Order` rather than being made
the platform operator. Making it the operator would have short-circuited the gate before it queried
anything: the suite would pass while no longer exercising the thing that broke it.

| Suite | Result |
|---|---|
| `tests/Feature/Commerce/OrderStoreOwnershipTest` | **11 passed, 62 assertions** |
| `tests/Feature/Commerce` (whole directory, 43 files) | **662 passed, 7 skipped, 0 failed** |

## P2-04-11 — `orders.order_source` (2026-10-28)

**New tests — 22.**

| file | n | what it pins |
|---|---|---|
| `tests/Feature/Merchant/OrderSourceAttributionTest.php` | 16 | the column's shape (nullable, **no default**); the three writers a merchant reaches record the right value; a legacy row is **not** presented as a storefront order, in the list cell and on the detail row; a recorded source is named and renders as a badge while an absent one renders as a dash — a difference in KIND, not colour; the filter narrows, cannot reach another tenant, adds no predicate for an unrecognised value, and survives an array value; the bar offers exactly the vocabulary the query reads |
| `tests/Unit/Database/OrderSourceWriterCensusTest.php` | 4 | **the ratchet**: every host-`Order` writer in `app/` stamps the column, every stamp uses an `Order::SOURCE_*` constant rather than a bare string, the scan is not vacuous, and the vocabulary has no name for an absent source |
| `tests/Feature/Commerce/PosPaymentTypeLocaleTest.php` | 2 | **F146**: the landmine is real (armed with `Lang::addLines` — the same walk-in sale rung up in Arabic comes back naming the buyer `' '` instead of `Walk-in Customer`), and the till now writes the literal |

**Suites run — the set was derived from what the change is driven by, not from memory (owner §5).**

| suite | result |
|---|---|
| `tests/Unit` + `Feature/Commerce` + `Feature/Merchant` + `Feature/Platform` + `Feature/Lynomia` + `Feature/Tenancy` | **2712 passed, 13 skipped, 0 failed** |
| `--testsuite=Contract` | **26 passed** — the Flutter wire format is unchanged |
| `tests/Feature/Security` | **4087 passed** |

Why those six: a **new migration** reaches `Feature/Platform` (`AddonMigrationStrandingTest`,
`FreshInstallAddonMigrationsTest`) and `Feature/Lynomia` (`LynomiaMigrationIntegrityTest`); a **new
`orders` column** reaches `Feature/Tenancy`, which iterates `Schema::getColumns()` to build fixtures
(it skips nullable columns, so the column cannot reach it — verified by reading, then by running);
**thirteen payment controllers** reach `Feature/Commerce`; and `Feature/Commerce` **builds its own
`orders` table**, with `OrderStoreOwnershipTest:485` calling `(new OrderController())->store()`
directly — the same shape as the failure that reddened commit `638d06c1`.

**Guards bitten — 8 of 8 confirmed to fail when reverted.**

| reverted | test that went red |
|---|---|
| the `CinetPayController` stamp removed | `census > every order writer records its source` |
| `->default('storefront')` added to the migration | `attribution > the column exists and holds no default` |
| `default => __('Storefront')` in `orderSourceLabel()` | `census > the vocabulary has no name for an absent source` |
| **`'order_source'` commented out of `$fillable`** | `attribution > the merchant order form records a manual source` |
| the list cell given `?? __('Storefront')` | `attribution > a legacy order is not presented as a storefront order` |
| the detail row given `Storefront` instead of `Not recorded` | `attribution > the detail page of a legacy order invents no source` |
| the filter's strict `in_array` replaced by `!== null` | `attribution > an unrecognised source value adds no predicate` |
| the census regex pointed at `new ZZOrder()` | `census > the census is not vacuous` |

The fourth is the one worth keeping: it is the **`created_by` trap**. That column exists, the
controller passes it, and it is discarded in silence because the key is not fillable — so a test that
only drove the handler and checked for no error would have reported this slice working with the value
never stored.

**Three test defects of my own, found by running rather than by reading.**

1. `?ss_source=pos ` (trailing space) was in the "unrecognised value" list and the filter narrowed
   anyway. Not a defect: `Illuminate\Foundation\Http\Middleware\TrimStrings` is global, so there is no
   space left by the time `applyFilters()` runs. Moved into its own test that **pins** the trimming,
   because the obvious "hardening" — reading the raw query string behind the framework — would make
   this one control behave unlike every other on the page. Its neighbour
   `ConvertEmptyStringsToNull` is why `?ss_source=` correctly adds no predicate.
2. The admin API create asserted HTTP 200; the endpoint answers **201**. Asserted exactly rather than
   `assertNotSame(422, …)`, which a 500 would also satisfy.
3. **F146's first assertion was based on a misreading of the code it tests.** I recorded
   `Order.php:503` as *skipping* the billing block for a POS order and asserted the key was absent. It
   is not — there is an `else` branch building the same key from the `Customer` row. The real
   consequence is the **contents**, which is a quieter defect than the one I had written down, and the
   failing assertion is what sent me back to read the branch properly.

**One tooling defect worth recording, because it nearly shipped.** The bite-test harness restored a
reverted line with `s.replace(new, old)` — and for the census bite, `new` was the **empty string**, so
the restore inserted the stamp between every character of `CinetPayController.php`: 73,485 insertions
in one file, with `php -l` still clean and the test suite still green. Caught by reading
`git diff --stat` before committing, not by any test. The file was restored from `HEAD` and re-stamped
at its single site. **A clean lint and a green suite do not establish that a diff is what you think it
is** — the diff itself has to be read, and `249 insertions / 6 deletions across 22 files`, with every
deletion accounted for as a line-rewrite, is the evidence that it is.

### The required gate, read rather than assumed (owner §5)

Two consecutive full-Feature runs, because the first one **failed** and the commit it covered was
pushed with "NOT YET GATE-VERIFIED" in its message rather than described as verified.

| commit | tests | failures | skips | errors | known-outcome gate |
|---|---|---|---|---|---|
| `7d7285a0` | 6355 passed | **3** | 17 | 0 | **FAILED** — one undeclared failure |
| `427c2c03` | **6374 passed** | 2 | 17 | 0 | **PASSED** — *"the observed outcome set matches the manifest exactly"* |

The undeclared failure on `7d7285a0` was `SecurityLogInventoryTest::test_the_observability_inventory_matches_the_source`.
Root cause, not a test defect: the inventory keys every security log statement by `file:line`, and
adding `OrderController::orderAttentionCounts()` in P2-04-13 shifted nine statements down by 58
lines. `resync-observability-inventory.py` re-points rows by `(file, message)` and **refuses to
write when a line was added or deleted rather than moved**, so its clean run — 9 re-pointed, 123
unchanged, 132 source lines against 132 documented — is itself the evidence that it was movement
only. Fixed in `33777152`; this slice shifted 26 more and they were re-pointed the same way, after
the app edits had settled.

The arithmetic between the two runs reconciles exactly, which is worth checking rather than
assuming: 6355 + 1 (the inventory test, now passing) + 18 (this slice's new Feature tests; the
4 census tests are in the Unit suite) = **6374**.

**What the pass does and does not mean**, in the gate's own words: *"This is NOT a statement that
the suite is green. It is a statement that the suite is failing and skipping exactly the tests the
project has recorded, for the reasons it has recorded."* Two accepted failures and seventeen
accepted skips remain owed, and Phase 01's closure gates and incident #120 are untouched by any of
this.

**Third time this phase that `file:line`-keyed inventory has reddened on a slice's own edits.** The
rule stands and is now written in three places: regenerate the inventory LAST, after the app edits
of a slice have settled. Doing it mid-slice just moves the numbers again.

### P2-04-11 follow-up — what independent review found, and what it cost to be wrong (2026-10-28)

The slice was gate-verified before this. It was also, in four places, wrong — and in two of those
the wrong thing was a **claim I had written down as established**. That is the part worth keeping.

**Method.** One `tenant-authz-reviewer` pass over the money path (CLAUDE.md requires it for an
order or payment change), then **seven adversarial verifiers run in parallel**, one per finding,
each told to default to REFUTED and each working read-only. Four came back CONFIRMED, three
PARTLY. None was taken on the reviewer's word; two of them turned out to understate the defect.

**What the review cleared, which is not padding.** No cross-tenant read on either new surface; no
mass-assignment path that could forge provenance (the only two `Order::create` in `app/` take
explicit key arrays, and there is no `fill($request)` on an Order anywhere, including
`packages/workdo/`); no XSS through the new `rawColumns()` entry (the only unescaped output is the
file's own markup plus a `match` over two constants with an empty default, and both dynamic
strings pass `e()`); and all stamps are single-line assignments placed after their own
`new Order()` and before the first `save()`, none inside an extra conditional.

**The four defects.**

| # | what | why it mattered |
|---|---|---|
| **F147** | one of the sixteen "writers" was **not an order at all** — a SaaS plan purchase, the unconverted twin of P1-PLT-02 | my stamp labelled a merchant's subscription payment `storefront` |
| **F3** | the detail page told the **platform operator** "created before the source was recorded" about an order created today | the same fabrication the slice exists to prevent, pointing the other way |
| **F6** | the migration docblock claimed **no API response widens**; three do | an unmeasured claim about a shipped contract |
| **F4** | the census ratchet checked its stamp window in **raw source**, so a comment could satisfy it | in a file whose own docblock warns about exactly that |

**And two corrections to claims of mine**, both from checking too few cases:

1. **F146 was LIVE, not latent.** I checked `ar.json` and `en.json`, found both map "POS" to "POS",
   and wrote "latent". Six shipped locales already translate it — `es`, `fr`, `pl`, `ru`, `zh`,
   `ja` — all offered, and the locale is settable through a **public GET route** that writes
   `users.language` from a route parameter. A merchant working in Spanish has been storing
   `payment_type = 'Punto de venta'` on every till sale. The test had the same flaw as the
   docblock: it used `Lang::addLines` with a synthetic translation, so it proved only "IF someone
   translated POS, then…". It now drives `es` from the shipped `es.json`.
2. **Parity gap G8 was wider than reality.** `api/admin/orderlist` already carries the raw column,
   so closing it for the app's order list is **client-side only** — no server change.

**Three weaknesses in my own tests, found by auditing them rather than by running them.**

| weakness | why the suite stayed green |
|---|---|
| the only test asserting the POS value compared **constants to each other** and never drove the till | deleting `PosController`'s stamp left it passing |
| the stamp window read raw source | nothing was masked *yet*; the next writer added with an explanatory comment would have been |
| the "not vacuous" floor was **14 against a measured 16** | two writers could vanish undetected |

Fixed by: an exact count (now **19**); a new
`test_every_writer_stamps_the_constant_its_path_warrants` that reads each writer's own source and
requires POS from the till, MANUAL from the two merchant-manual writers and STOREFRONT from the
rest; a comment-blanked window; a widened regex catching the FQN and array-constructor forms; and
`(new Order)->flushCache()` after each raw `DB::table()` write.

**Extending the census scan to `packages/workdo/` — scanning vendored code is not editing it —
immediately found three writer categories the `app/`-only scan had made invisible (F148):** a real
persisted writer in `LotteryPrizes` that cannot be stamped, two staging-fixture writers in
`LynomiaChat`, and the already-known preview dummy. Each is now a **named constant with its
reason** rather than an entry in a generic exemption list, because "cannot be stamped" and "no
honest value exists" are different facts and conflating them is how an exemption list becomes a
place to hide things.

**Guards bitten this round — 5 of 5.**

| reverted | test that went red |
|---|---|
| the till stamped `SOURCE_STOREFRONT` | `census > every writer stamps the constant its path warrants` |
| a real stamp replaced by a **comment** mentioning `order_source` | `census > every order writer records its source` |
| `MEASURED_WRITERS` moved off 19 | `census > the census is not vacuous` |
| the caller-store predicate restored in `order_view` | `attribution > the platform operator is told the real source` |
| (earlier round, re-confirmed) `$fillable` key removed | `attribution > the merchant order form records a manual source` |

The bite harness itself was fixed first. Its restore step did `s.replace(new, old)`, which for a
deletion means replacing the **empty string** — it had inserted a line between every character of
a 73,485-line file. It now saves the file's exact bytes and copies them back.

**Suites after the fixes:** `tests/Unit` + `Feature/Commerce` + `Feature/Merchant` — **1490 passed,
7 skipped, 0 failed**. `tests/Feature/Security` — **4087 passed**, plus the observability inventory
re-pointed (9 rows moved, 0 added or deleted) once the app edits had settled, for the third time
this phase.

### Owner directive 2026-10-28 §2 and §4 — an untrue sentence, and a tenant hole (2026-10-28)

**§2 — the wording was a claim about the record's AGE, and it was false.** The unrecorded-source
cell said *"This order was created before the source was recorded."* For the orders F148
identified — `packages/workdo/LotteryPrizes` writes real orders the host may not edit and therefore
cannot stamp — an order created this morning was being described as older than a column it is
younger than. Replaced everywhere it renders with *"The source of this order was not recorded."*,
which states the fact and infers nothing.

| new test | what it pins |
|---|---|
| `a recent order with no source is not described as old` | an order created **in the test** must not be called old, in the list cell **and** the detail Source row |
| `an unrecorded order is listed but claimed by no source filter` | it stays reachable on the unfiltered list, and none of the three sources claims it |

Both bite: restoring the old string turns two tests red.

**One mistake while writing them, of the kind this project keeps re-learning.** The first version
asserted the word `older` was absent from the **whole detail page** — 1498 lines — and failed on
`placeholder`. A vocabulary assertion against a whole document answers a question about the
document, not about the row under test. Now scoped to the rendered cell and to the Source `<li>`.

**§4 — F149, and the test design is the interesting part.** `getCurrentStore()` returns NULL for a
staff member with no `current_store` (they own no store, so the helper's last branch finds nothing),
and Laravel rewrites `where('store_id', null)` into `WHERE store_id IS NULL` — so the list predicate
selected the platform's orphaned orders.

The merchant path now **refuses before the query runs**, per the instruction. Three guards, each
bitten separately:

| reverted | result |
|---|---|
| `index`'s refusal | 3 failed |
| `order_grid_view`'s refusal | 2 failed |
| `OrderDataTable`'s `whereRaw('1 = 0')` | **0 failed** — and that is why it now has its own direct test |

That third row is worth keeping. Through the route the DataTable's guard is unreachable, because
the controller refuses first — correct layering and a blind spot at once, since the table is a
public class and the next caller added will not have the controller in front of it. A test that
calls `query()` directly makes it bite.

**Two assertions of mine were vacuous and I found them by reading, not by running.** The row helper
returned `[]` when the response was a redirect, so `assertNotContains($orphan, [])` passed for free
— including in the operator test. The helper now asserts a real 200 JSON payload, and the refusal
cases use a separate `assertListRefused()`. Separately, driving the plain page before the AJAX leg
put the request through `TableFilterMemory::shouldRestore()`, which **redirected** it — which is
how the empty list arose in the first place.

**The test seeds a real NULL-store order**, and that is load-bearing rather than tidy: on a
fixture-only database `WHERE store_id IS NULL` matches nothing, so every absence assertion would
have passed against the unfixed code. It also asserts the dangerous predicate really selects that
row before asserting the screen refuses.

**Suites:** `tests/Unit` + `Feature/Merchant` + `Feature/Commerce` — **1492 passed, 7 skipped, 0
failed**. `tests/Feature/Security` — **4096 passed** (the 9 new F149 tests), with the observability
inventory re-pointed afterwards, as always.

### P2-04-6 order-note author, and §3 PaymentWall integrity (2026-10-28)

**15 new tests.** `OrderNoteAuthorTest` (11) and `PaymentWallPlanOrderIntegrityTest` (4).

**The guard that matters most is one that already existed.**
`OrderDetailTest::test_a_note_is_not_attributed_to_the_person_reading_the_page` pins the original
defect — the notes list rendered `Auth::user()->name`, so one note read "by Amina" to Amina and "by
Khalid" to Khalid. It still passes. That is the whole point: naming the **author** is not naming the
**reader**, and the two are only distinguishable by a test where they are different people, so every
positive test here uses two.

**4 of 4 guards bitten — and the fourth bit in the opposite direction, which is the finding.**

| reverted | result |
|---|---|
| the tenant predicate in `authorNameFor()` | 1 failed |
| the partial given a `?? auth()->user()?->name` fallback | 1 failed |
| `order_note_data()` stamped with `auth()->id()` | 1 failed |
| **`created_by` removed from `$fillable`** | **0 failed** |

That last row is the one worth keeping. The key was there with a comment calling it *"REQUIRED:
both human writers pass it by key"* — and that was simply **wrong**: both writers assign the
property directly (`$note->created_by = Auth::id();`), which bypasses `$fillable` entirely. Once
unnecessary, keeping it would have been a pure forgery surface, because four `OrderNote::create`
sites write system notes by mass assignment and `order_note_data()` composes more from gateway
callbacks. So it was **removed**, and `test_an_author_cannot_be_mass_assigned` now pins the absence
— which bites when the key is put back.

**One over-broad assertion of mine, the third of the day.** `assertStringNotContainsString(' by ')`
against the whole 734-line notes panel matched prose elsewhere. Scoped to the note's own card. The
pattern is now explicit enough to name: *a short generic string asserted against a whole rendered
document answers a question about the document, not about the element under test.*

**§3 — PaymentWall, and the clause the owner underlined.** The branch is unreachable (the config
gate is false by default and no SDK is installed), and a green suite over an unreachable branch
could easily be read as "a subscription charge works". It is not, and
`test_no_claim_is_made_about_a_live_collection` says so **as an assertion** rather than as prose: it
fails the moment a Paymentwall SDK appears in `composer.json`, because at that point the branch
becomes reachable and the question "does a real charge succeed?" becomes live and must be answered
by driving it. The other three assert what is checkable: every attribute the branch writes is a real
`plan_orders` column, the branch constructs no host `Order` and carries no `order_source`, and the
activation gate is still closed. Reverting F147 turns two of them red **and** the census test.

**Suites:** `Unit` + `Merchant` + `Commerce` + `Platform` + `Lynomia` + `Tenancy` — **2733 passed,
13 skipped, 0 failed**. `tests/Feature/Security` — **4097 passed, 0 failed**.

### P2-04-9 saved views (2026-10-28)

**29 new tests, and the isolation/permission half was written before any Blade existed**, which
was the owner's instruction and not a preference: a UI built first would have had to be re-read
against whatever the tests later turned out to require.

| file | n | what it pins |
|---|---|---|
| `tests/Feature/Security/SavedViewIsolationTest.php` | 19 | ownership from the principal not request input; two employees of one merchant are isolated; views do not follow a merchant across stores; another tenant cannot apply or delete; a colleague cannot delete; **the operator cannot read**; a foreign id and a missing id answer identically; filters outside the screen's vocabulary are dropped; an unknown screen is refused; no store means no creation; the unique index exists; **a shared link grants nothing** |
| `tests/Feature/Merchant/SavedViewInterfaceTest.php` | 10 | the block renders with the employee's own views and not a colleague's; no empty state; the save form carries only vocabulary keys; **the forms are siblings of the filter form, not nested**; every action is a POST with a token; Arabic and English both render; accessible names on the icon button, the chip group and the label |

**Two of six bite tests did not bite — that is the finding, not an aside.**

| reverted | result | what it revealed |
|---|---|---|
| the employee predicate | 2 failed | ok |
| the store predicate | 1 failed | ok |
| the operator made exempt | 1 failed | ok |
| the vocabulary allowlist | 2 failed | ok |
| **ownership made mass-assignable** | **0 failed** | the test pinned the CONTROLLER, not `$fillable` |
| **the screen allowlist removed** | **0 failed** | the test pinned the POLICY, not the controller's check |

Both gaps are now covered by tests that pin the mechanism actually at risk:
`test_ownership_cannot_be_mass_assigned`, and
`test_every_screen_with_a_vocabulary_has_a_permission_and_the_reverse`. Writing the first of those
also corrected what I believed the outcome was: both ownership columns are NOT NULL, so Laravel
drops the unfillable keys and the **database refuses the row outright** — a mass-assigned saved
view cannot be created at all, mis-owned or otherwise. My first version asserted it was created
with NULL ownership.

**The browser check found a real dark-mode defect that no test in this repository would have.**
Measured with Playwright against the rendered page in all four configurations, computing each text
node's contrast against its resolved background:

| | light | dark |
|---|---|---|
| `Save these filters as` (before) | 16.06:1 | **2.41:1 — FAIL** |
| `Save these filters as` (after) | 16.06:1 | **5.21:1** |

The cause is structural rather than cosmetic: the saved-views block is a **sibling** of the filter
form, because a `<form>` inside a `<form>` is dropped by browsers and the save button would have
submitted the filter bar instead. As a sibling it had no surface of its own, so it resolved up to
the host's page wrapper — `rgb(249,250,251)`, **light even in dark mode** — and carried DS dark
text on it. `.ss-filterbar` sets `background: var(--ss-surface)` for precisely this reason; the
block now does too, plus `ss-scope`.

**And the first measurement was wrong in a way that looked like success.** It toggled
`data-theme`; this design system switches on **`data-ss-theme`**. Light and dark returned
identical numbers and every reading passed. What caught it was noticing the identity, then dumping
the tokens to prove the toggle did anything at all (`--ss-fg` `#1a2322` → `#e8eded`). A
measurement that cannot be shown to vary with the thing it measures is not a measurement.

Also checked and **not** a finding, recorded because it sits next to this slice: the order list
reflects every query parameter into the DataTable's `ajax.url` inside a `<script>` block. A
`</script>` payload cannot break out — it arrives percent-encoded. `json_encode`'s slash escaping,
which I first assumed was the protection, is not what does it; the premise assertion in
`test_a_reflected_parameter_cannot_break_out_of_the_script` is what corrected that.

**`SavedView` is classified in the tenancy census and NOT allowlisted for the global scope**
(94→95 models, 57→58 carrying `store_id`), beside `MerchantFavouritePage` and for the same reason:
the scope would supply only `store_id`, the weaker half of the ownership pair, and
`null_tenant => 'open'` means it supplies nothing exactly when a tenant fails to resolve — where
this model fails closed with `whereRaw('1 = 0')`.

**Suites:** `Unit` + `Merchant` + `Commerce` + `Platform` + `Lynomia` + `Tenancy` — **2741 passed,
13 skipped, 0 failed**.

### P2-04-6 design-system pass on the order detail page (2026-10-28)

**The contract was pinned first.** `OrderDetailContractTest` (7 tests) fixes the five posted field
names, every route the page targets, one delete control per note, every note shape's text and the
summary figures — and deliberately pins **no markup, class, order or label**, all of which the
restyle is meant to change. A test that pinned those would be rewritten by the change it guards.
Bitten three ways: rename a field → 3 red, add a stray field → 1 red, drop a route → 1 red.

Two defects in that test found by running it, both the same shape as earlier ones:

1. the field-name scan matched the layout's `<meta name=...>` tags, then (one layer in) field names
   written as **JS strings** inside a `<script>`. Scripts are now stripped and only
   `input|select|textarea` are scanned.
2. it compared a full URL containing `Crypt::encrypt($id)` — **which is randomised**, carrying a
   fresh IV per call, so the comparison could never match. Prefixes are now derived from `route()`
   itself rather than hand-written (the first hand-written one, `/shippinglabel-pdf/`, was wrong
   against the real `/shippinglabel/pdf/`).

**Then measured, in the four REAL configurations.** Dark mode here is the `cust_darklayout` store
setting choosing `style-dark.css` / `rtl-style-dark.css`, so it is captured **server-side**; it
cannot be toggled in the browser.

| | before | after |
|---|---|---|
| LTR light | 2 failures | **0** |
| LTR dark | 12 failures | **1** (the shared breadcrumb) |
| RTL light | 2 failures | **0** |
| RTL dark | 12 failures | **1** (the shared breadcrumb) |

Each fix followed its own measurement: both `<select>`s and the note-type select gained `ss-select`
(options at 4.03:1 in dark); the status button now uses **the same `OrderStatusTone` the order list
uses** — its old `$btn_class` measured **2.52:1 in LIGHT mode**, on the most prominent control on
the page — so "what colour is this status" has one answer instead of two; the status menu gained
`ss-menu`/`ss-menu__item`; the two destructive items took a new **`.ss-menu__item--danger`** that
the design system lacked (it had `--danger` tones for badges, buttons, alerts and progress but not
for a menu item, and Bootstrap's `.text-danger` measured 4.00:1 light / 3.28:1 dark on the one
irreversible action in that menu); the item name took a new `.ss-text-strong` (3.71:1 dark).

**Every DS class was ADDED, not substituted**, apart from the two pure colour utilities.
`form-control`, `btn`, `btn-badge`, `dropdown-item` and `order_status_btn` all remain, because a
restyle that silently drops a class a handler or the theme matches breaks the control rather than
recolouring it — and the five note tone classes are all styled in `custom.css` **and**
`rtl-custom.css`, checked before touching them.

**MY FIRST MEASUREMENT WAS INVALID IN A WAY THAT WOULD HAVE PRODUCED 34 FALSE DEFECTS.** It
toggled `data-ss-theme` in the browser while the host stylesheet stayed light, so DS-dark text sat
on a light host and the order summary read **1.18:1**. Those failures do not exist in the real dark
mode. Capturing the four configurations server-side is what showed the figures were clean. This is
the second measurement-validity error in one session — the first was toggling `data-theme`, an
attribute this design system does not use — and the rule that caught both is the same: **prove the
toggle changes something before trusting what it reports.**

The one remaining dark failure is the shared breadcrumb at 4.03:1, recorded as **F151** against
P2-01 (whose "Breadcrumbs موحدة" row owns that surface) rather than fixed inside an order-page
slice. The broader layout chrome measured below AA in dark too, and is named in F151 so the P2-01
row starts from evidence.

**Suites:** `Unit` + `Merchant` + `Commerce` — **1524 passed, 7 skipped, 0 failed**.

### P2-04-7 the order board (2026-10-28)

**12 new tests, 4 of 4 guards bitten** (permission gate, the F149 no-store refusal, the F0 aside,
and offering `cancel` as a board move).

**Measured in a real browser in EIGHT configurations** — AR/EN × light/dark × phone/desktop, with
dark mode captured **server-side** through the `cust_darklayout` setting:

| | columns | cards | `dir` | board scrolls | page overflow | contrast failures |
|---|---|---|---|---|---|---|
| LTR light / dark, desktop + phone | 9 | 10 | ltr | yes | **no** | **0** |
| RTL light / dark, desktop + phone | 9 | 10 | **rtl** | yes | **no** | **0** |

Phone columns measure 320px of a 390px viewport (82vw), so the sliver of the next column that
tells a merchant the row scrolls is visible.

**Three defects the browser found that no test in this repository would have**, each fixed after
being measured rather than guessed at:

1. **The flex row widened its ancestors instead of scrolling inside them.** The document measured
   2022px against a 1400px viewport and 2066px on a 390px phone. The order detail page, measured
   the same way, did not overflow at all — which is how this was attributed to the board rather
   than assumed pre-existing. `min-width: 0` + `max-width: 100%` on the scroller, plus a
   `min-width: 0` wrapper.
2. **The card's buyer name and total read 3.42:1 in dark.** Changing the design-system token did
   nothing. **Raising the DS selector's specificity also did nothing** — and that is the finding:
   the host's dark stylesheet carries `… , p { color: #808191 !important }`, every paragraph in
   the dark admin, which no component CSS can override. Fixed by using `<div>`, which the rule
   does not match. Recorded as **F152**, with five sibling `!important` colour rules in the same
   file named, because any component built on `<p>` in this admin inherits a sub-AA colour in dark
   and cannot fix it locally.
3. **My own overflow check was wrong.** It read the root's `scrollWidth`, which reflects a
   descendant scroller's extent, so it reported an overflow *after* the fix had already worked.
   Corrected to "is any element wider than the viewport", which then reported `false` everywhere
   and agreed with an independent probe showing no element exceeding the viewport.

That third one is the third measurement-validity error in this session — after toggling
`data-theme` (an attribute this design system does not use) and toggling `data-ss-theme` while the
host stylesheet stayed light. The rule that caught all three is the same: **before trusting a
measurement, prove it changes when the thing it measures changes.**

**And a test defect worth naming:** the filter test asserted `assertStringNotContainsString('PAID-1')`
against fixtures `UNPAID-1` and `PAID-1` — and **`UNPAID-1` contains `PAID-1`**, so it failed while
the filter was working perfectly. A substring assertion is only as good as the distinctness of its
fixtures; the references are now `REF-OWING` and `REF-SETTLED`. A second one scanned every form in
the document and flagged the **layout's logout form**; it is now scoped to the board.

**Two ratchets caught the slice and were satisfied rather than relaxed:** `ArabicUiCoverageTest`
named nine untranslated strings, and `OrderActionPermissionCensusTest` required the new route to be
censused with its permission and its must-not-open permission.

**No writer of its own**, asserted rather than asserted-in-prose: every form on the board targets
`order.status.change`, and `cancel`/`return` are absent from the move control because both are
irreversible and money-bearing.

---

## P2-04-12 — printing, export and document preview (2026-10-28)

`php artisan test --testsuite=Unit` → **533 passed**.
`php artisan test tests/Feature/Merchant tests/Feature/Commerce` → **1018 passed, 7 skipped, 0 failed**.
`php artisan test` over the four export-dependent Security files → **42 passed**.
`OrderExportColumnAlignmentTest` → **12 passed**; `OrderKanbanTest` → **15 passed** (12 + 3 new).

**The row's own acceptance was already met in its reachable/gated half, and measuring that first is
why no time was spent re-proving it.** `OrderDocumentAccessTest` drives twelve cases — anonymous
access to the shipping label, the print label, the host invoice route and the invoice preview; the
overlapping URI pattern; cross-tenant denial; a validly encrypted id belonging to another tenant;
the owner's own success; staff with and without the permission. The export introduces no URI, so
`OrderDocumentAccessGuard::TARGETS` needs no entry; its gate is `fileExport`'s operator-first
permission check plus `resolvedCurrentStoreId()`, asserted by `OperatorOrderExportStoreTest`,
`NullStoreOrderExposureTest` and `OrderActionPermissionCensusTest`.

### What the row actually turned up: a correctness defect, not a styling gap (F154)

The export paired **19 hardcoded headings against 28 data columns**, and that count is the whole
finding. `OrderExport::collection()` returned whole `Order` models — so the data columns were
whatever the `orders` table's physical column order happened to be, minus an `unset()` list — while
`headings()` was a separate literal that no migration had ever updated. Nine columns shipped with no
header at all: `additional_note`, `deliveryboy_id`, `payment_receipt`, `product_order_status`,
`lottery_code`, `currency_code`, `currency_symbol`, `created_by` and `order_source`.

**The dangerous half is positional, and it is invisible to this suite by construction.** Three
shipped migrations insert order columns MID-table:

| migration | column | inserted |
|---|---|---|
| `2024_11_08_111424` | `product_order_status`, then `lottery_code` | `after('return_price')` |
| `2024_10_22_103923` | `payment_receipt` | `after('payment_status')` |
| `2024_03_28_034154` | `deliveryboy_id` | `after('additional_note')` |

`return_price` is heading index 9. **`after()` is a no-op on sqlite**, which always appends — so on
the test database every added column lands past the 19th and the old headings lined up, while on
MySQL two columns sit between *"Return Price"* and `payment_type`, shifting every heading from
*"Payment Type"* onward. A merchant reading *"Delivered status"* would be looking at `payment_type`.

**That production state is NOT asserted.** `mysqld`, `mysql` and `mysqladmin` are all absent from
this container, so whether the live export is currently mislabelled is **unmeasured**, and nothing
here claims otherwise — the standing "MySQL remains UNVERIFIED" constraint is untouched. The fix was
taken because it makes the question moot, not because the answer is known. This is the same class as
P01-SEC-13: a defect sqlite cannot express.

The fix reads headings and data from **one list, by attribute name** (`OrderExport::COLUMNS`), so a
migration may insert a column anywhere and `$appends` may grow without effect. The first 19 headings
are unchanged verbatim and in order — including the inconsistent casing of `product Name` and
`Payment status`, left exactly as shipped because a merchant's downstream spreadsheet may match on
them. No column was added, removed or moved, so **no cell in an existing export changes position**;
nine gained a header.

**Bitten 7 of 12.** Reverting `map()` to positional fails the width check, every money column, the
payment/fulfilment columns and the scrambled-order case, while the five cases that do not depend on
pairing still pass — which is the shape a correct bite has.

`test_the_pairing_survives_a_scrambled_attribute_order` is the case that carries the row: it hands
`map()` a row whose attributes are in an order no schema would produce and requires every value to
still arrive under its own heading. **Its first version failed for a fixture reason, not a code
reason** — `Order::create()` returns a model holding only the attributes that were set, so the
scrambled row lacked `delivery_date`, and `getDeliveryDateAttribute()` reads
`$this->attributes['delivery_date']` unguarded. Real exports always come from `get()`, which carries
every column, so the fixture was corrected to `->fresh()` rather than the assertion weakened.

### Two things deliberately not changed

**F153 — reward points export as the literal `1`.** `$data[$k]["reward_points"] = 1;` discarded the
stored value: an order created with `reward_points => 7` exported as `1`. The column defaults to `0`,
so `1` is not the column default either, and nothing in the file says why it is there. It is a figure
a merchant may sum, and whether `1` was intended cannot be settled from the code, so it is an **owner
decision** and the literal is preserved exactly — with
`test_reward_points_exports_the_shipped_literal_pending_an_owner_decision` pinning the current
behaviour so the change, once authorised, cannot land unnoticed.

**No styling pass was forced onto the two print documents.** `shippinglabel.blade.php` (320 lines)
and `receipt.blade.php` (263 lines) are standalone `<html>` documents, not screen pages, and both
measure **0 contrast failures in BOTH `screen` and `print` media, in both directions**, with
`dir=rtl` correct. The design system is a screen system; applying its tokens to a courier label
would be change without a defect.

### Three candidate findings investigated and rejected

1. **The receipt has no order reference** — by design. It is a POS till receipt reading only
   `final_price`, `id`, `paymnet_type`, `product` and `sub_total`.
2. **`lang="en" dir="rtl"`** — the store's configured RTL-with-English-document-language state,
   already named by `DocumentDirection::languageDisagreesWithFlag()`.
3. **`border: 1px solid var(--theme-color)` with `--theme-color: #FFF`** — white borders on a white
   page, which text-contrast tooling structurally cannot see, so it looked like a find the
   measurement had missed. It is **dead CSS**: `add-border` appears only in the stylesheet, never in
   the markup, and the document has no `<tfoot>`. Reading the markup before recording it is the only
   reason it is not in the register as a defect.

### F155 — the Kanban board had no entry point

`routes/web.php:1164` declared `order-kanban` and **no view linked to it**, so P2-04-7's board
shipped reachable only by typing the URL. **All twelve of its tests call `route('order.kanban')`
directly**, so none of them could have found it: a test that reaches a surface by constructing its
own route proves the surface works, never that a user can get to it. The three new cases assert
reachability from the **rendered** list and grid pages, and removing the link from
`index.blade.php` alone fails exactly its two cases while the grid case still passes.

The control carries `request()->query()`, so the active filters survive the switch — the same
contract the board's own link back to the list already kept. It is classed like its three siblings
(`btn btn-sm btn-primary btn-icon`) rather than converted to `ss-*` alone, because that whole
`action-button` region is **P2-01**'s shared chrome and converting one of four controls would leave
the header visibly inconsistent. `ti-layout-kanban` was confirmed present in the shipped
`public/assets/fonts/tabler-icons.min.css` before use rather than assumed.

### The two remainders, which is why the row is PARTIAL and not DONE

1. **The invoice-preview clause is D-24b, OPEN.** Its view lives under
   `packages/workdo/AutomaticOrderPrinting/`, which CLAUDE.md §2 and
   `.github/scripts/guard-no-workdo-changes.sh` both forbid editing. Not doable here in either
   direction.
2. **The export's trigger control is not on the design system**, along with its three siblings in
   the shared `action-button` header. Handed to **P2-01**, the next slice, which owns shared chrome.

### The full Feature known-outcome gate on `f808afd3`

```
Tests:    2 failed, 17 skipped, 6464 passed (32851 assertions)   Duration: 890.38s

KNOWN-OUTCOME GATE
testcases observed   : 6483  (manifest floor 1000)
failures observed    : 2  (manifest declares 2)
errors observed      : 0  (always expected: 0)
skips observed       : 17  (manifest declares 17)
OK: the observed outcome set matches the manifest exactly -- 2 declared failures,
    17 declared skips, 0 errors, and nothing undeclared.
```

The two failures are the two the manifest names — `RegistrationTest::test_new_users_can_register`
and `ProfileTest::test_profile_page_is_displayed`. As the gate's own output says, this is not a
statement that the suite is green; it is a statement that it fails and skips exactly what the
project has recorded.

### What the mandated independent review found — and the one thing it found that mattered

CLAUDE.md requires the `tenant-authz-reviewer` pass before committing a change that touches
tenancy, and `OrderExport` carries a tenant predicate. **It cleared the diff**, with the negative
results worth keeping: the store predicate holds for the merchant owner, for store-less staff (fails
closed, and the class never compiles `where('store_id', null)` → `IS NULL`, so the orphan-order set
is unreachable from this export for any principal) and for the operator; tenant resolution is
identical in `collection()` and `map()` because Laravel Excel calls `map()` synchronously in-request
and the class is neither `ShouldQueue` nor chunked; `map()` issues no `Order` query, so it adds no
path around the guard; and the class has no constructor and no settable store, so no caller can
point it at a store.

**Then it found a live defect in the sibling the change did not touch, and that is the substantive
outcome of this round — F156.** `CouponController::fileExport()` had **no permission check at all**,
while `index()` — the coupon list the file is the export of — requires `Manage Coupon`. Any
authenticated principal, including a staff member of any merchant holding no coupon permission,
could GET `/coupon/export` and receive every coupon code, usage limit, discount amount and expiry
date for the resolved store.

**Every claim was verified against the source before acting on it**, per the rule that another
agent's findings are a claim and not a result: `fileExport`'s three-line body, `index()`'s gate at
`:25`, the route at `routes/web.php:986`, and `CouponExport::collection()`'s unguarded `$store->id`.
All four held. The reviewer was also careful about what it is **not** — the rows carry a
`getCurrentStore()` predicate, so it is a permission hole and not a cross-tenant leak, which is the
exact overstatement already corrected once for the order export.

**Bitten three independent ways, each failing exactly its own case and nothing else:**

| bite | fails |
|---|---|
| remove the permission gate | only `test_a_principal_without_the_permission_is_refused` |
| invert to `isAbleTo()`-first | only `test_the_platform_operator_can_export_without_holding_the_permission` |
| remove the `$store === null` guard | only `test_an_unresolvable_store_exports_nothing_rather_than_a_stack_trace` |

**One of my own tests bit for the wrong reason and was corrected rather than kept.**
`test_the_refusal_is_not_a_401` failed under the first bite only because an ungated export returns a
`BinaryFileResponse`, which has no `->status()` — a type error, not a measurement. It reads
`baseResponse->getStatusCode()` now, so it measures the status in both the refusal and the grant
case; the primary refusal case still bites that revert on its own. The two "can still export" cases
were strengthened at the same time from `assertSame(200, …)` to asserting a real
`BinaryFileResponse` with `Coupon.xlsx` in its content-disposition, because "allowed through" and
"rendered some page" are different claims.

**F157 was raised and deliberately not fixed.** Five `CouponController` handlers are bare
`isAbleTo()` with no operator limb and so refuse the platform operator. After F156 that asymmetry is
visible — `fileExport` admits the operator, `index` does not — and it is stated in the method's own
docblock rather than left to be discovered. Fixing it would grant the operator cross-store coupon
read and write where today there is none, which needs its own slice and its own measurement.

**And the review's test-gap note was correct and is closed.** No case drove a cross-store id through
`OrderExport::computed()`; `test_the_two_name_columns_hold_names_rather_than_ids` uses id `0`, which
resolves to nothing for a reason unrelated to tenancy. Two cases now record **opposite** answers,
because the two models differ: `Customer` is allowlisted in `config/tenancy.php` and really filters,
so a customer from another tenant resolves to `''`; `Product` is absent from `scoped_models`
entirely, so a foreign product name **does** resolve — the same absence behind the S19 cross-tenant
stock drain. The second case records shipped behaviour rather than endorsing it: the leaked value is
a product name the shop page already serves anonymously, so the export was left reproducing what it
always did instead of being widened inside a printing row, and the test exists so that adding
`Product` to `scoped_models` later fails here and tells whoever did it that the export changed.
Bitten both ways — dropping the `Customer` scope fails only the first, adding a store predicate to
the `Product` read fails only the second.

The review's two other observations were accepted without code changes: `Product::find()` is
unscoped for every principal (pre-existing, public catalogue data, and a fix belongs with whoever
scopes `Product`), and two of the twelve original export cases pin `collection()` rather than biting
this diff — legitimate regression pins, **not** counted as tenancy coverage of the new code.

---

## P2-01-14 / F151 — the unified breadcrumb, measured (2026-10-28)

`php artisan test --testsuite=Unit` → **543 passed**. `tests/Feature/Merchant` → **352 passed**,
so converting 107 view sections broke no rendered page. `BreadcrumbContractTest` → **10 passed**.

**24 page-configurations measured in a real browser, 0 problems.** Raw output:
`docs/development/phase-2/evidence/p2-01-14/breadcrumb-live-24-configurations.txt`.

| configuration | page background | dir | lang | current page | trail link |
|---|---|---|---|---|---|
| en/ltr light | `rgb(249,250,251)` | *(absent)* | en | **15.37:1** | **5.69:1** |
| en/ltr dark | `rgb(34,36,44)` | *(absent)* | en | **13.1:1** | **7.11:1** |
| ar/rtl light | `rgb(249,250,251)` | `rtl` | ar | **15.37:1** | **5.69:1** |
| ar/rtl dark | `rgb(34,36,44)` | `rtl` | ar | **13.1:1** | **7.11:1** |

Six pages per configuration — `/order`, `/product`, `/coupon`, `/customer`, `/product/create`,
`/order-kanban`. Every one reports `current=1` and `home=1`, and no item renders a second
separator. `/product/create` is in the list deliberately: it is one of the five screens that
carried `aria-current="page"` on both the parent link and the leaf, so `items=3 current=1` there is
the fix proved live rather than inferred from markup.

**F151's cause was never a token.** The breadcrumb was Bootstrap's, and
`--bs-breadcrumb-item-active-color` and `--bs-breadcrumb-divider-color` are **byte-identical** in
`style.css:5343` and `style-dark.css:5350`. A colour declared the same way in both stylesheets
cannot be legible in both themes, so no theme work on the old markup could have fixed it. 3.35:1 →
7.11:1 and 13.1:1 in dark.

**Links preserved, verified against git rather than asserted:** 34 breadcrumb links before the
conversion, 31 after, difference exactly **−3** — the three duplicate Home crumbs — and **zero**
other links lost across all 107 files.

### The harness lied twice before it told the truth

This is the part worth keeping. The first run reported **14 of 20 failures**, and not one of them
was a defect in the page.

**Five were my state control.** `layouts/app.blade.php:42-50` derives the direction from
**`users.language`** and overrides the setting:

```php
$setting['currantLang'] = auth()->user()->language;
if ($setting['currantLang'] == 'ar' || $setting['currantLang'] == 'he') {
    $setting['SITE_RTL'] = 'on';
}
```

The dev merchant's language is `ar`, so writing `SITE_RTL = off` changed nothing — the harness had
measured ar/rtl twice and en/ltr never, while reporting four configurations. Flipping that setting
on, off and on again and watching **three identical renders** come back is what found it. The
writes were also `DB::table()->update()` while `Setting` and `User` are both `Cachable`, which is
the trap CLAUDE.md records under *Model caching*; they go through Eloquent now, with
`flushCache()`. The lever is in `set-admin-preview-state.php`, beside the two settings it has to
agree with, rather than bolted onto the shared `set-preview-state.php` five other rows depend on.

**Four were `/finance`**, which answers **403 Forbidden** for this fixture — a module screen the
merchant holds no permission for, which never rendered the merchant layout at all and was reported
as four breadcrumb failures. An unreachable page is a **PRECONDITION** failure now, printed
differently, and the page list is screens the fixture was verified able to open.

**One was my own assertion** demanding `dir="ltr"` when the layout emits `dir=""` — and an absent
`dir` **is** left-to-right per HTML, so every correct LTR page was failing. It compares the
resolved direction now, not the attribute's spelling.

**And my attempt to prove the theme had switched was worthless.** I listed the linked stylesheets;
all four names appear on every page because the theme switcher references them. Each row now
carries the measured, composited page background, so "light" and "dark" are proved by a colour
instead of by the loop variable — the same failure mode as F8, where four "dark" readings were
taken of the light stylesheet.

**Before any of it, the first attempt hung.** The existing contrast sweep visits seven pages for
every design-system colour, and the single-threaded PHP built-in server deadlocked on a page whose
notification feed fires a concurrent XHR: 1385 requests served, then silence, with nothing printed
because the output was buffered through a pipe. The new tool aborts that XHR, prints each row as it
completes so a hang is visible, and the server runs with `PHP_CLI_SERVER_WORKERS`.

### Two more false readings, caught inside my own tests

- `BreadcrumbContractTest`'s *"the layout no longer hardcodes Bootstrap's list"* assertion **failed
  against the correct layout**, because the comment explaining the change quotes the markup it
  replaced. It strips Blade comments and measures markup now, which stays true however the next
  comment is worded.
- The link-preservation check reported three lost links. Its regex `href=["']([^"']+)["']` stopped
  at the single quote inside `{{ route('dashboard') }}` and captured `{{ route(`. Reading to the
  closing double quote gives the real answer: zero.

### A dev-fixture defect, not a code one

`/order` and `/finance` had been answering **500** on `no such table: saved_views`:
`database/p2dev.sqlite` predates P2-04-9's migration. Three pending migrations were applied to that
**local dev database only** — no production migration, in line with the standing limit.

### What the conversion did, and how it was validated before it ran

104 sections converted by script, `shipping_method/index.blade.php` by hand because its Blade logic
sits inside the link text rather than the item structure — the script **reported** it rather than
guessing. The script was run against a **snapshot** of `resources/` first and reproduced the
pre-measurement exactly (104 files, 134 items, 67 `aria-current` added, 5 removed, 3 Home crumbs
dropped), and the contract test's assertions were simulated against that snapshot before either was
applied. They agreed: the only residual offender on all four scans was that one hand-check file.

---

## F152 — the dark theme's blanket paragraph colour (2026-10-28)

Fixed at the shared location with **one selector token**, in both
`public/assets/css/style-dark.css` and `public/assets/css/rtl-style-dark.css`:

```
before:  .dash-sidebar .dash-link, …, .dataTable-info, p                                    { color:#808191!important }
after:   .dash-sidebar .dash-link, …, .dataTable-info, p:not([class^="ss-"]):not([class*=" ss-"]) { … }
```

Nothing else in the rule changed, so the sidebar link, its icon and the DataTables info line still
take the host theme's grey — asserted, because a diff that lost them would be a regression in the
host theme dressed as a fix.

**`:not([class*="ss-"])` would have been wrong.** It matches any class attribute *containing* the
substring, and `address-line` contains `ss-`. The two selectors match a real class **token**.

### Measured in a real browser, dark mode, ten merchant pages

`docs/development/phase-2/tools/check-dark-paragraphs-live.cjs`; raw runs in
`docs/development/phase-2/evidence/f152/`.

| | before | after |
|---|---|---|
| design-system paragraphs | 14 measured, **7 below 4.5:1** | 14 measured, **0 below 4.5:1** |
| paragraphs with no `ss-` class | 3 measured, 3 at 3.71:1 | 3 measured, 3 at 3.71:1 — **byte-identical** |

The second row is the point. The owner asked for the narrowest safe change *and* a measurement of
the impact on other screens; a `diff` of the non-design-system rows across the two runs is empty,
so that impact is provably zero rather than argued.

The seven were `ss-board__none` ×2 at 3.85:1, `ss-section__note` at 3.42:1 and `ss-stat__period` ×4
at 3.85:1 — every one computing to `rgb(128,129,145)`, the forced grey itself.

**One of them is a gap in my own earlier work.** P2-04-7 reported "0 contrast failures" for the
Kanban board. The board it measured had **cards**, so `ss-board__none` — the empty-column message —
never rendered and was never measured. It fails at 3.85:1.

**A static census said 23; the live measurement said 7. Both are reported.** 23 design-system
classes are applied to `<p>` elements across ~74 usages — the *potential* scope. 7 is what actually
rendered and failed on the pages measured. Reporting only the first would overstate the fix;
reporting only the second would understate the risk to components not on these pages.

### Pinned, and bitten two ways

`tests/Unit/Ui/DarkParagraphContrastRuleTest.php`, 4 cases. Restoring the bare `p` fails *"neither
dark stylesheet forces a colour on every paragraph"* and *"both stylesheets exempt design-system
paragraphs"*; switching to the over-matching substring form fails the other two. Unit suite: **547
passed**.

**One of my own assertions was wrong first.** The blanket-rule check used
`/(^|[,{}\s])p\s*\{…/`, which also matches `.auth-footer p { color:#808191!important }` — a
legitimate *scoped* rule — so it failed against the correctly patched stylesheet. It matches a bare
`p` now: one preceded by a comma or a line start, never by a space.

### What is not fixed, and why — F158

The three paragraphs carrying no design-system class remain at **3.71:1**. Releasing them means
changing what every paragraph in the dark admin looks like, whose blast radius is every screen in
the product; that is not the narrowest safe change and needs its own measurement and an owner
decision. They are also not a design-system defect — they were never on the design system, so the
fix cannot reach them by construction. Recorded as **F158** with the measured rows, so the next
slice starts from a measurement.

---

## P2-01-15 — loading, success and failure states (2026-10-28)

`docs/development/phase-2/tools/check-favourite-toggle-live.cjs` → **34 checks, 0 failures**, in
Arabic and English. Raw run: `docs/development/phase-2/evidence/p2-01-15/`.
`FavouriteToggleStateContractTest` → 6 passed. **Unit suite: 553 passed.**

### Measurement cut the claimed gap down three times before any code was written

The row reads "a loading state for every async surface (panel fetch, DataTables redraw, toggle
POST)". Measuring each one first removed most of the work:

| surface | what already existed |
|---|---|
| panel fetch | `aria-busy` + visible `ss-panel__loading` text — and legible in dark since F152 |
| command-palette search | `cmdNote(data-ss-searching-text)`, and the attribute **is** set by the caller |
| toggle POST | `.ss-command__star[disabled]` → `cursor: progress`, dimmed |
| DataTables redraw | **not asynchronous** — no `ajax`, no `serverSide`; only the four WooCommerce import screens use them |

So no loading state was added to the DataTables, per the instruction not to add cosmetic ones to
synchronous tables. A success state that is not only a toast already existed as well: the shared
`announceLive()` polite region, plus `ss_announce_transient()` from P2-06-9.

### The two real defects were both in the success state

1. **The glyph never changed.** `is-on` moves `color` to the brand and nothing else, so success was
   conveyed by **colour alone** — which this design system rejects everywhere else.
2. **`title` and the visually-hidden label were left as rendered.** After adding a page the
   accessible name still read *"Add :page to favourites"* while `aria-pressed` beside it said
   `true`. The control stated the opposite of its own state.

And nothing at all was announced between the click and the answer, so a screen-reader user got
silence through the whole request.

All the copy travels in the markup — `data-ss-fav-title-on/off`, `-label-on/off`, `-text-on/off`,
`data-ss-favourite-busy` — which is that partial's own stated convention (*"so the JS holds no URLs
or copy"*), and the fix reuses `announceLive()` rather than adding a second notification mechanism.

Measured in both languages: glyph `★ → ☆`, title `"أزل من المفضلة" → "أضف إلى المفضلة"`, accessible
name `"أزل لوحة التحكم من المفضلة" → "أضف لوحة التحكم إلى المفضلة"`, and on a refusal the state does
**not** flip (`false → false`) while the error text is announced verbatim.

### My own test was invalid first, and that is the part worth keeping

It routed `**/favourite*`, which does **not** match `/merchant-favourite-page` — that segment starts
with `merchant-`. **Neither interception ever fired.** Both clicks went to the real server, so the
SUCCESS assertions passed *by coincidence* — the server's own toggle produced the same answer the
fake body would have — and the FAILURE phase never failed, reporting `false -> true` and looking
like a product defect. Every interception is now **counted and asserted**, so a pattern that matches
nothing fails loudly instead of quietly passing.

Two more assertions were weak in the same family:

- *"it was announced"* passed while the live region still held the **busy** text, so it could not
  tell the busy and error messages apart. It now waits for the error string, read from the markup.
- Sampling the live region the instant `aria-busy` appeared always read empty, because
  `announceLive()` blanks the region and fills it on a 50 ms timeout — that blank frame is
  deliberate, so two identical messages are both audible. The wait is for content, not for the
  attribute.

The executing state is only observable because the response is **held open** by the route handler;
against a local server answering in single-digit milliseconds the test would otherwise pass whether
or not `aria-busy` was ever set.

### Bitten four ways

| bite | fails |
|---|---|
| drop `aria-busy` | the executing check, both languages |
| drop the busy announcement | the announcement check |
| drop the glyph/title/label swap | **6** success checks, incl. `"أزل من المفضلة" -> "أزل من المفضلة"` |
| remove one glyph target + one title attribute from the markup | 2 of the 6 static cases |

---

## P2-01-17 / P2-01-19 / P2-01-20 (2026-10-28)

Unit **564 passed**. The six Feature files that read the design-system assets: **68 passed**.
Keyboard run: `docs/development/phase-2/evidence/p2-01-20/`, **0 failures**.

### Three recorded figures were wrong, and measuring them was most of the work

| claim | measured |
|---|---|
| "all 266 `data-ajax-popup` contracts" | **128** in 83 files — plus a *separate* **19** in 16 files of `data-ajax-popup-over` |
| "three or four confirmation mechanisms" | **one** — `show_confirm` is a CSS *class*, `data-confirm` the attribute it reads |
| eight unnamed header controls | **one** |
| six `<p>` in the header (F158 scope) | **zero** |

My own first count of the popup contracts said 154, because `data-ajax-popup-over=` **contains**
`data-ajax-popup` and a substring grep merges two different mechanisms. The eight "unnamed"
controls came from treating any control whose text began with `{{` as icon-only — `{{ __('
Profile') }}` is a Blade echo that renders a perfectly good name. The six paragraphs were `<path>`
and `<polyline>` inside the inline SVG icons.

### P2-01-17 — one control, on every screen

`<a href="#!" id="mobile-collapse">` wraps three nested `div`s that draw a hamburger: no text, no
`aria-label`, no `title`. `layouts/app.blade.php` renders this partial on **every** merchant
screen, so a screen reader announced *"link"* and nothing else, everywhere.

It stays an `<a>`: `dash.js:99`, `side-dash.js:96` and `style.css:17131` all target
`#mobile-collapse`, so swapping the element risks the host theme's layout on every screen for a
benefit `aria-label` already delivers. **F52 was already fixed.** **F158 cannot reach the header** —
zero paragraphs, pinned by a test so adding one brings the scope decision back.

**What keeps the row PARTIAL:** "the merchant header is on the design system". 281 lines, **4**
`ss-` occurrences. Converting it is a change to shared chrome on every screen and across all 70
vendored modules — the theme-wide change the owner said must not happen without a scope decision.

### P2-01-19 — the defect was the fallback, not the component

`custom.js:107` binds `.show_confirm` with one delegated SweetAlert handler, and substitutes
**hardcoded English** when an attribute is missing — and it is a static file that cannot call
`__()`. Four delete buttons carried no `data-confirm`, so an Arabic merchant got an **English**
confirmation on `country/filter`, `state/filter`, `setting/pixel_field_setting` and
`setting/webhook_setting`. All five strings already existed in both languages.

**The raw browser dialogs are frozen, not converted.** Four `confirm(...)` calls in
`finance/withdrawals` (×2) and `pos/index` (×2) — all translated. Converting them is not styling:
`onclick="return confirm(...)"` is **synchronous** and blocks the submit while the SweetAlert
handler is asynchronous, and two of the four **approve a withdrawal and mark it paid**. A fifth
match was inside a Blade *comment* describing this very defect, so comments are stripped before
counting.

### P2-01-20 — the row named its own remainder, and a browser could finally answer it

> *"Tab ORDER on a rendered page, and whether a focus ring is actually PAINTED by a browser, need a
> real page and a real tab press… what is not asserted is the paint."*

Both asserted now. `page.keyboard.press('Tab')` is real keyboard input, so `:focus-visible` applies
as it does for a person — a `.focus()` call would not trigger it.

**A real defect:** the command palette declares `aria-modal="true"` and trapped nothing — **21 of
25** Tab presses left it, landing on the page behind, which is still in the accessibility tree
because the palette neither marks the rest `aria-hidden` nor uses `inert`. The side panel has had
containment since P2-04-5, so the fix **extracts it into `containFocus()`** and reuses it; the
palette branch bails when a panel is open so the two never fight for one keypress. After: **25 of
25 inside, 0 escaped**, all four configurations. Bitten — removing the branch restores 3/21.

**My measurement produced a false defect first.** It checked only `outline` and `box-shadow` and
reported the palette's input as unindicated. It is not: `.ss-command__input:focus-visible` sets
`outline: none` and changes `border-block-end-color` to the brand — an underline my property list
could not see. A measurement that enumerates the properties it expects keeps missing the ones it
did not think of, so nothing is enumerated: every control's computed style is recorded **before**
any Tab and the focused style is diffed against its own unfocused style. Not by blur-and-refocus
either — the second focus would be programmatic, and `:focus-visible` does not apply to that, so
the measurement would have destroyed what it measures. On that basis **29 controls per
configuration do paint an indicator**.

**And a second false reading:** the palette's input appeared in the tab order while the palette was
shut. Probed directly — closed, it has `hidden`, `display: none`, a 0×0 rect, and is not tabbable.
It gains focus because **tabbing into the sidebar search opens the palette**, which auto-focuses it.
Intended behaviour.

**Stated limit:** measured on the order list (116 focusable controls) in four configurations, not on
all 107 screens individually. The indicator is shared CSS and the trap is shared JS, so both are
system-level; a per-screen sweep would be breadth, not a different question.

---

## D-13 / F90 — the unverified stock decrement on the Khalti return (2026-10-28)

`tests/Feature/Security/GatewayStockBeforeVerificationTest.php` — 10 cases, all passing.

**The drain was measured before anything was touched.** With the stock loop in its old position, a
capture Khalti itself REJECTED took `products.product_stock` from 50 to 49 and
`product_variants.stock` from 50 to 49, with **zero order rows written**, and a stored state
carrying no server payable did the same without Khalti being asked at all. Both are repeatable: the
route is anonymous and a checkout can be re-initiated.

**Reachability came first, because D-18 requires it and the register recorded it as NOT
ESTABLISHED.** `test_the_stock_loop_is_reached_at_all` drives a confirmed capture and watches the
figure move, so no negative case in the file is vacuous. And the `$plan->shipping_method` question
is settled by driving it: with `users.plan_id` pointing at nothing the handler throws
`ErrorException: Attempt to read property "shipping_method" on null` **before** the loop, stock
still 50, no order. So the path is latent only on a platform whose admin has no resolvable plan and
**live on one that has** — the ordinary configuration. Two bounds stated rather than glossed: that
crash is a PHP warning promoted by Laravel's handler, so where `error_reporting()` omits `E_WARNING`
the deref yields null, `null == 'on'` is false and the path runs **live**; and nothing here says
which configuration production runs.

**The fix is an ordering, not the deferral D-18 ruled out.** D-18 recorded that the decrement cannot
wait because no column records that an order consumed its stock — true of deferring to a *later*
request, not of this one, where the decrement and the verification sit in the **same** request about
250 lines apart in the wrong order. The verification block was hoisted above the loop. No schema, no
marker, no E13 dependency.

**Three controls keep it honest, because a fix that stopped the drain by not decrementing would pass
every negative case:** the reachability case, the UNVERIFIABLE case (still decrements and still
writes its Pending order — refusing it would strand a charged customer) and the replay case all
assert the decrement still happens exactly once. A fourth asserts **Khalti is asked exactly once per
return** — a hoist that left the old call standing would double every outbound verify request and
nothing else in the file would have noticed. A fifth pins the shopper-visible refusal as the same
redirect and message as before.

**Bitten:** reverting the controller fails exactly the three drain cases and leaves all six controls
green.

**PaiementPro, CinetPay and Easebuzz are BLOCKED, and measured as such rather than assumed.**
`PaymentVerificationService` holds exactly three provider verifiers — `verifyKhaltiCapture`,
`verifyPaymeraCapture`, `verifyPaymeraSignature` — and none of these three gateways has one. Each
goes from the loop straight to `new Order()`; the only returns in between are the three in-loop
"Product not found" / "bad json" exits D-18 already fitted with restores. **There is nothing to
hoist and no post-loop refusal to reorder.** Closing them needs either a new provider verification
(Easebuzz's reverse hash is locally verifiable with the stored salt and already gates its *plan*
path, but its computation has never been checked against a real response here) or refusing
evidence-free returns — both change whether a payment is **accepted** on a live storefront path,
which §11 reserves. Options recorded in the register; the slice is stopped, not guessed at.

---

## D-22 / F121 — the deleted product or variant that 500s a payment return (2026-10-28)

`tests/Feature/Security/GatewayReturnMissingProductTest.php` — 5 cases, all passing.

**Confirmed on a driven request, which it never had been.** A Khalti return whose basket names a
deleted `products` row answers `ErrorException: Attempt to read property "id" on null` with zero
orders — on a **VERIFIED** capture as much as a rejected one — and a deleted variant row gives the
same on `discount_price`. A paid customer whose product was deleted mid-checkout got an unhandled
exception and no order. The crash precedes the decrement, so this is not a drain; that same ordering
is why D-13's restoration test could never reach the exits it was written for.

**Fixed — the half the owner authorised in those words.** The verification moved above the **cart
read**, not merely above the loop: it needs only the payload, the store and the stored state, so
that is the earliest position it can hold, and a refusal fired at the read needs to know whether
money was collected before deciding how loudly to log. Then `basketHasMissingProductRow()`, asked of
the `carts` rows directly before the read, refuses the basket **whole** — a healthy line is not
decremented on the way to discovering a dangling one.

**The dereferences themselves are deliberately not guarded.** `Cart::cart_list_cookie()` and
`Api\ApiController::cart_list()` are read by the storefront cart page, the sidebar, the API and nine
checkout writers; skipping a dangling line would silently drop one the shopper has already paid for,
and nulling it changes an array shape all of them read.

**Not fixed, not claimed:** *after proof, do not lose the paid order*. A paid customer still gets no
order — now a structured refusal and a `critical` log naming the confirmed capture and its amount
instead of a 500 and silence. Writing the order means deciding what an order line for a deleted
product contains, a shape the merchant UI, the invoice modules and the **Flutter** app all read, and
inventing a substitute product or variant is forbidden. Three options are recorded in the register;
only one satisfies the instruction literally and it is the one that changes a cross-repository
contract, so it is the owner's decision and the slice stops there.

**Bite matrix** — and it corrected one of my own tests:

| state | result |
|---|---|
| HEAD, neither change | 4 of 5 fail |
| the hoist only, guard removed | **3** of 5 fail |
| both | 5 pass |

The rejected-capture case does not detect the guard; it detects the **hoist**, because once the
verification sits above the cart read a rejected capture never reaches the crash. Kept and
relabelled as D-13 evidence. The three that bite the guard are the **verified** paths — the right
answer, since a verified capture is exactly the caller the hoist cannot turn away. The intact-basket
case passes in all three states, as a control must.

### The independent review of the D-13 / D-22 change (2026-10-28)

`tenant-authz-reviewer` was run on the uncommitted diff before it was committed, as CLAUDE.md
requires for a payment path. It found **two defects in the fix**, both invisible to every test in
the repository, and that is the third time in four packages the reviewer has found the defect inside
the remedy.

**The serious one: the hoist clobbered `$response`.** The verify result went into `$response`, which
was safe while the verification sat below the cart read — and D-22 moved it above that read, where
the GUEST arm does `$response = Cart::cart_list_cookie(...)` then `json_decode(json_encode(...))`,
a stdClass. The thank-you payload 800 lines later reads `$response['status_code']`. The reviewer
predicted a silent `0`; **measured on PHP 8.3.6 it throws** `Error: Cannot use object of type
stdClass as array`. A guest whose capture was CONFIRMED would have got an unhandled error **after**
the order committed `Paid` and the stock was decremented — the F89 shape, a charged card ending in a
500. Renamed to `$khaltiResponse`.

**The coverage shape behind it matters more than the line.** Every Khalti test in this repository
drives `actingAs($customer, 'customers')`, and the guest arm is the one that reads `$response`. So
the guest arm is now driven in two places: a verified-guest case asserting the request completes,
the payload carries Khalti's real status code and the order is `Paid`; and
`GatewaySiblingMissingProductTest`, which drives the guest arm of all four handlers. **The new case
bites** — restoring `$response` fails it with that exact `Error`.

**The second: the new guard swept the store for a cookie-less guest.** `where('cookie_session_id',
null)` becomes `whereNull`, and `carts.cookie_session_id` is nullable with no default, so every
registered customer's row carries NULL there. One deleted catalogue product would have refused every
cookie-less guest's return. The guard now returns `false` for a principal it cannot identify —
failing **open**, which is correct here rather than merely convenient: the guard exists to pre-empt a
crash in the cart read, and a guest with no cookie has no cookie basket for that read to crash on.
Driven with the trigger present (another customer's dangling basket in the same store) and with the
NULL premise asserted.

**And it caught a false claim in my own docblock** — that the D-22 file drove "BOTH arms" when all
five cases drive the authenticated one. Load-bearingly false, since both findings lived on the guest
arm. Corrected.

**A vacuous green was caught by the fixture work, separately from the review.** The sibling file's
first version seeded only `request_data`; Easebuzz reads `easebuzz_request_data` with **no
fallback**, so its rows refused at the entry guard and passed for an unrelated reason. Both keys are
seeded now, and every row asserts the premise that the handler got past that guard — which turned
two passes into two real failures, both then fixed.

**Also applied from the review:** a `critical` log on the two `'Cart is empty.'` exits that now sit
*after* the capture is classified (`capture_was_confirmed` is the field to search on — `true` means
money moved and that shopper has no order); and the REJECTED comment corrected, since
`verifyKhaltiCapture()` classifies any non-200, non-zero status as REJECTED, a 502 included.

**The observability ratchet failed in both directions** — seven undocumented, five stale — and was
repaired in the order the document itself requires: three log lines were genuinely **added**, which
is the case the resync tool refuses to write, so a destination and a reader were written for each
before the tool was allowed to re-point the five that had merely moved.

**Cleared by the review, and worth recording as cleared:** the replay claim is not a regression
(`claimGatewayReturn()` and the `Session::forget()` sit above both positions, and a shopper whose
attempt genuinely failed re-initiates with both keys fresh — now with their basket intact, since the
cart delete no longer runs for a refused payment), and the gateway secret's tenancy is unchanged
(`$store` comes from the stored state's slug, never from caller input).

### Four existing tests had to change, and why that is not a weakened check

The full `Commerce` + `Security` + `Payments` run on the finished tree came back **4 failed, 11
skipped, 4858 passed** before these were addressed. All four are recorded here because the directive
forbids weakening a check, and three of them are a test discovering the fix.

**`NullCookieCartTest::test_no_host_query_names_cookie_session_id_outside_the_scope` — the census was
right and my guard was wrong.** This project forbids any query naming `cookie_session_id` outside
`Cart::scopeForGuestCookie()`, and the new guard had a raw `->where('cookie_session_id', $cookie)`.
That census exists *because this mistake has been made before*, and it caught the very defect the
reviewer had flagged independently: `whereNull` on a nullable column with no default. The guard now
goes through `forGuestCookie()` and `forCustomer()`, which answer `whereRaw('1 = 0')` for anything
that is not a non-empty string / positive integer — so the fail-open behaviour comes from the
project's own mechanism rather than from a hand-rolled early return. **No test was changed for this
one; the code was.**

**Three rows across `GatewayReturnTrustBoundaryTest` (negative cart line, absurd quantity) and
`StockManagementSettingTest` (each gateway return deducts only when stock management is on) bound no
Khalti verifier, and so stopped at the payment instead of reaching the guard or the loop they
measure.** The honest reading of that is the important part: **before D-13 those rows reached the
stock loop with no payment evidence at all.** They were reachable only because of the defect. The
`absurd quantity` test's own docblock even says so out loud — *"CinetPay, Easebuzz and PaiementPro
verify nothing, so the stock moves whether or not money did"*.

So each now binds a **confirming** Khalti double, which makes the fixture a legitimate **paid**
return — the only kind that should reach a stock loop at all. Each row's claim is unchanged and
strictly stronger: the quantity guard fires, and the `stock_management` setting gates the deduction,
**even when the payment is good**. The double's canned response carries no `amount` field, because
`verifyKhaltiCapture()` treats a 200 with no amount as verified (its own test pins that), so it does
not depend on whatever total a fixture happens to price and will not rot when one changes. It is
bound unconditionally in the data-provider-driven tests and is inert for the other gateways, none of
which resolves `Khalti`.

**A second census then caught a genuine design flaw in the guard, and this one is worth more than
the bug it prevented.** `CartScopesTest::test_every_for_customer_call_names_the_authenticated_
principal` allows `forCustomer()` to be handed the authenticated principal and nothing else; the
guard took `$customerId` as an argument. The census is right for a reason that is not about
spelling: CLAUDE.md's most productive rule in this codebase is *derive the target from the
authenticated principal, never from request input*, and a guard that ACCEPTS an id is one careless
call site away from being handed `$request->customer_id`. So the guard now derives both the customer
id and the guest cookie itself and takes only `$store` — there is nothing for a caller to supply, so
it supplies nothing. That also deleted the duplicated
`is_string(\Cookie::get('cart')) ? ... : null` dance from all four call sites. **Three versions of
four lines, each correction coming from something this repository already knew.**

**What was NOT done:** no assertion was loosened, no expected message was changed to match new
behaviour, no census allow-list was widened, and nothing was added to the known-outcome manifest. The one genuine behaviour change —
that on Khalti the quantity and foreign-product refusals now follow the verification, because they
read `$products` and the verification must precede the cart read — is recorded in the register
rather than absorbed into a test.

After the four: **`Commerce` + `Security` + `Payments` green on the finished tree**, `Unit` 564
passed.

---

## D-24a / P2-01-18 — the four create-actions in the command palette (2026-10-28)

`tests/Feature/Security/MerchantQuickActionTargetsTest.php` — 31 cases.
`docs/development/phase-2/tools/check-quick-actions-live.cjs` — 6 screens × 2 languages, 0 failures.

### Three deliberate bites did not bite, and that is the evidence

The first version of this file had 25 passing cases. I then reverted the service's permission check,
then its operator store guard, then flipped a declaration to a knowingly wrong value. **All 25
stayed green on every one.**

The reason is that **the page gate dominates**. `MerchantPageDirectory::available()` is built from
the real `Menu`, which is permission-filtered, so a principal holding nothing has no pages and
therefore no actions, whatever the permission check does. The suite was measuring the page gate
three times over and the rest not at all.

So the cases that exercise each gate were constructed:

| gate | the only principal it decides for | bites now |
|---|---|---|
| action permission | has the menu page, lacks the action permission — and for two of the four those are *different* permissions (`Manage Products` vs `Create Product`, `Manage Coupon` vs `Create Coupon`) | 2 cases fail on revert |
| page availability | holds the action permission, page absent | 2 cases fail on revert |
| operator declaration / store guard | **nobody today** — measured | premise asserted instead |

**The page gate could not be tested through the safety invariant at all**, and that is worth stating
rather than glossing: dropping it offers *more* actions, and every one of them is still served by its
target (an employee with `Create Product` can open `product/create`), so the implication holds
perfectly while the owner's condition *"لا تعرض إجراءً إذا كانت الصفحة أو الوحدة غير متوفرة"* is
violated. A module-availability rule needs its own direct test.

**The operator paths are unreachable today and the test says so instead of passing quietly.** The
operator's navigation holds **none** of the four pages these actions target — measured; my
expectation from reading `SuperAdminMenuListener` was `['coupon']` and the measurement said `[]`,
because that entry's own permission is not among the four. The declarations and the store guard are
kept as defence-in-depth, and the case asserts that premise, so the day someone adds one of these
pages to the operator's menu it fails there and whoever added it has to exercise the declaration
path properly.

### The invariant itself was wrong at first

I asserted `offered === served`. That is a UX preference dressed as a security property. The right
claim is the **implication**: if the palette offers an action, its target must serve the same
principal — the render must not be more permissive than the request, which is what *"على العرض وعلى
الطلب"* means. The converse legitimately fails, for both reasons in the table above.

### F157's trap applies here too, and is closed

`getCurrentStore()` hands an operator with an empty `current_store` the **hardcoded literal 1**, and
`getStoreById(1)` normally resolves — so a null-store guard passes and the operator would be offered
four create-actions into **store 1**, a store they never selected. Refused, with the premise
asserted the way F157's was: a store whose id really is 1, holding the catalogue, and
`getCurrentStore()` really answering 1.

### Measured in a real browser, in both languages

Ctrl+K on `/order`, `/product`, `/customer`, `/coupon`, `/order-kanban`, `/product/create`, each in
`en`/`ltr` and `ar`/`rtl`: **0 failures**. Every rendered action is one of the approved four, in the
approved relative order, each with a resolvable href, each in the tab order, the group above
Favourites, and the Arabic copy read **off the rendered DOM** — إنشاء منتج / إنشاء طلب يدوي / إضافة
عميل. That last is a stronger claim than the source ratchet's: only the DOM proves the translation
reached the screen.

**The tool's first expectation was wrong, instructively.** It demanded four and reported three on
every page in both languages. The dev merchant holds `Manage Coupon` and **not** `Create Coupon`, so
`create-coupon` is correctly withheld — the permission gate working, live. A tool that insisted on
four would have reported the gate as a defect. It now asserts subset and relative order, **and
carries a live bite**: grant `Create Coupon`, reload, watch the fourth appear; revoke, watch it go.
Without that, "correctly withheld" and "never renders" look identical.

**And that bite did not bite either, at first.** Granting the permission changed nothing on screen,
because Laratrust caches a user's permissions in the **cache store** and this server runs
`CACHE_STORE=file` — so clearing the `cache` *table*, which is what I did, cleared the wrong thing
entirely. The same trap CLAUDE.md records for `Cachable` models. `cache:clear` empties the store the
app is actually using.

### Ratchet widened, and a second one tripped

`ArabicUiCoverageTest`'s surface list never scanned `resources/views/partision/`, where the palette,
the header and the sidebar live — a Phase 02 surface with a lot of copy. Measured before adding it:
every `__()` string already there has Arabic, so this closes a **latent** gap rather than finding a
live defect, and it now enforces the new strings automatically. Adding the six keys then tripped
`TranslationFileIntegrityTest`, which caps how many keys `ar.json` may declare beyond `en.json`
(31); they went into both files.

---

## P2-01-19 — "Generate with AI" out of the nested dialog (2026-10-28)

`docs/development/phase-2/tools/check-ai-panel-live.cjs` — 9 checks × `en` and `ar`, **0 failures**.
`tests/Unit/Ui/AjaxPopupContractRatchetTest.php` — `NESTED_EXACT` 19 → 0.

**All 19 sites were the same button.** Measured first: `data-ajax-popup-over="true"` appears 19
times in 16 views and **zero times in `packages/workdo/`**, so *"لا تعدّل WorkDo مباشرة"* was
satisfied by the shape of the problem rather than by care. Every one carried the identical
`data-title` ("Generate Content With AI"), checked per button rather than assumed, so one panel with
one fixed title loses nothing.

**The panel is declared in the layout, and that is load-bearing.** Those create/edit views are
fetched by AJAX *into* the shared modal's body, so a panel declared inside one of them would be
nested in the very dialog this row exists to escape. In the layout it is a **sibling** of the modal.

**The markup swap alone created three real defects, and the live check found all three.** Driven on
a real category create screen, in both languages:

| check | before the fix | cause |
|---|---|---|
| focus moved into the panel | FAIL | `document.activeElement` was the **modal's** `.btn-close` at 0, 50, 200 and 600 ms |
| Tab stays inside the panel | FAIL — **10/10 escaped** | same |
| the create form underneath is still open | FAIL | Escape reached the modal and closed the merchant's half-filled form |

One cause: **Bootstrap 5.1.3's `Modal._focustrap`**, a `focusin` listener that pulls focus back
inside the modal whenever it leaves — doing exactly its job on a sibling surface it cannot know
about. Suspended while a panel is open and restored on close: **before** the focus move (the trap
fires on `focusin`, so suspending after would let it steal the focus being set) and **before** focus
returns to the trigger (which is typically inside the modal). Bootstrap 5.1 has no public API for
this — `focus: false` is construction-time only and the modal is the host theme's shared
`#commanModel` — so the private field is reached directly, with every assumption guarded: no
Bootstrap, no instance, no `_focustrap`, or no `deactivate`, and it is a no-op leaving today's
behaviour untouched. A rename in a future Bootstrap degrades the panel to losing focus again and
this tool says so.

**Bitten:** removing the suspension fails exactly those three checks in both languages and leaves
the other five passing per language.

### Two of the probe's own readings were artifacts, caught before they were believed

**"The panel loaded the generate form over AJAX" failed on the first run.** It was reading the
panel's body as soon as the open state flipped, and `loadPanel()` opens the panel *first* and fills
it from a `fetch` — deliberately, so the click has immediate feedback. The probe was measuring the
loading placeholder. A probe's own race is not a defect in the thing it measures.

**And its first target had no button to click.** `/coupon` carries no *visible* create trigger for
this fixture: the only `data-ajax-popup` elements on the page are the header's Quick Add entries,
sitting in a closed dropdown — so a selector matching the attribute alone found three hidden links
and the click timed out. The screen's own button is absent because the dev merchant holds
`Manage Coupon` and **not** `Create Coupon`, which is the permission gate working. Retargeted to
`/category`, and the trigger is now chosen by visibility.

### `generate.blade.php` changed with the triggers

A trigger-only change would have left the view generating content and then never going away: it knew
its container by id and called `$('#commanModelOver').modal('hide')` in two live places. Those are
now one **guarded** helper — a bare `window.StoreSyriaDS.closePanel(...)` would throw inside an AJAX
success handler if the DS script were blocked or failed to load, taking the rest of the handler,
including the field that receives the generated text, with it. The panel staying open is a far
better failure than the content never arriving.

### One ratchet trap, for the second time in this project

The ratchet counts raw occurrences of the attribute, and the explanatory comment written in
`layouts/app.blade.php` **contained the attribute spelled out**, so the count read 1 instead of 0.
The same trap the breadcrumb contract test hit. The comment now points at the ratchet for the exact
spelling instead of repeating it.

---

## §14 — the isolated MySQL/MariaDB environment, PROVISIONED AND RUN (2026-10-28)

The directive authorises an isolated MySQL/MariaDB database with synthetic data only, and requires
that *"if the environment is not available, record the test as BLOCKED clearly, and do not use a
SQLite result as a substitute for MySQL proof."* **It is available now**, so nothing here is
blocked.

**Engine: MariaDB 10.11.14-0ubuntu0.24.04.1**, installed into this ephemeral container and started
manually (`mysqld_safe`; the container's `policy-rc.d` denies service scripts). No production data,
no production connection: every script creates and drops its own database and refuses a database
name that does not contain `verify`.

**A STATED LIMIT, because it matters for two of the findings.** This is **MariaDB**, not MySQL 8.
Its default collation is `utf8mb4_general_ci`; MySQL 8's is `utf8mb4_0900_ai_ci`. Both are
case-insensitive, so the collation-sensitivity evidence below holds — but any MySQL-8-only
behaviour is **not** covered, and CLAUDE.md records production as MySQL. Where a result depends on
the engine rather than on the schema, it is marked as such in the script's own log.

### Result: 23 scripts, 272 assertions, 0 failures

`tests/Support/Security/bin/run-mysql-verifications.sh` against that server. The first run came back
**19 passed, 3 failed**, and **all three failures were the harness rather than the code** — which is
worth recording in full, because a project that treats a harness failure as a defect learns the
wrong thing:

| script | first run | cause | fix |
|---|---|---|---|
| `verify-stock-oversell-concurrency` | FAIL before any assertion | `@@transaction_isolation` is MySQL 5.7+; **MariaDB does not have it** and raises errno 1193 | ask for the variable by trial: `transaction_isolation`, then `tx_isolation` |
| `verify-refund-freshness-lock-and-limiter-keys` | FAIL before any assertion | the same line | the same fix |
| `verify-coupon-code-matching` | 2 checks, 1 failure | `Coupon` is `Cachable`, so its read went through the cache store — `database`, resolving against the **default sqlite** connection, which has no `cache` table | `config(['cache.default' => 'array'])` |

Neither variable name works on both engines: MySQL 8.0 **removed** `tx_isolation` and MariaDB 10.11
never had `transaction_isolation`, so the value has to be asked for by trial. The engine and version
are still printed either way, because the assertions depend on InnoDB rather than on the isolation
level — a figure reported for the record must not be able to fail the run.

### What this unblocks: D-18's concurrency half, which was recorded as undrivable

D-18's implementation note says the atomic decrement *"cannot be driven in this suite — SQLite
`:memory:` with `CACHE_STORE=array` makes `Cache::lock` process-local, so no Feature test can stage
two overlapping returns."* That is still true of the Feature suite. It is no longer true of this
project's evidence. `verify-stock-oversell-concurrency-on-mysql` now runs **14 assertions on real
InnoDB row locks**, and two of them are the ones that matter most:

- **the removal check**: the unguarded read-modify-write this project replaced **does oversell here**
  — stock 0 with two sales of the same unit;
- **the restock direction**, which nothing had measured at all: the read-modify-write restock
  **loses one of two concurrent returns** (10 → 11, not 12), and the atomic increment keeps both.

Plus: ten concurrent checkouts against five units, all holding the same stale read, where exactly
five succeed, five are refused and stock lands exactly at zero; a `not_allow` product that never
goes below zero; and a backorder product that still reaches −1, because that is the configured
behaviour.

`verify-refund-freshness-lock-and-limiter-keys` likewise now runs its 10 assertions, including a
**real lock-wait timeout (errno 1205 after ~2s)** while another transaction holds the row, and the
proof that a plain re-read inside the older snapshot still returns the stale row — which is why the
re-read must lock.

### A new script, for the directive's "export column order" clause

`tests/Support/Security/bin/verify-order-export-column-order-on-mysql.php` — **23 assertions, 0
failures**. It drives the real `OrderExport` against a real engine with
`strict => false`, which is **production's setting** (running strict here would make the script
kinder than production and useless as evidence, since the defect class it hunts is a value *coerced*
into a column rather than refused by it).

It asserts that `COLUMNS` is the single source for both the headings and the values (28 columns),
that every mapped attribute is a real `orders` column on MySQL, that each money column holds **its
own** distinct value under its own heading — a width check alone passes a wholesale shift, which is
what F154 was — that F153's Reward Points is the stored 7 and not the old literal 1, that the two
computed columns resolve their related rows, and that a second store's order is not exported.

**Two harness failures and one fixture error of my own, all recorded rather than smoothed over:**

1. MySQL refused the first `stores` insert with **errno 1452**, a foreign-key violation on
   `stores.created_by → users.id`. SQLite does not enforce foreign keys unless
   `PRAGMA foreign_keys=ON`, and the Feature suite does not issue it — so every fixture in this
   repository can invent a `created_by` that references nothing, and the same insert that works
   there is refused here. The user is now created first.
2. `Auth::login()` wrote a session row to a `sessions` table on the default sqlite connection, and
   reconfiguring `session.driver` did not help because the manager had already resolved. Replaced
   with `Auth::guard('web')->setUser()`, which puts the principal on the guard in memory and touches
   no session — all `getCurrentStore()` needs.
3. The first draft wrote the **word** `'Delivered'` into `orders.delivered_status` and the export
   read back **0**. That is not a defect: the column is `integer` with
   `comment('0=>pending/1=>diliver/2=>cancel/3=>return')`, and **every** writer uses an integer —
   checked across `app/` *and* `packages/workdo/`, including PartialPayments (7) and AuctionProduct.
   **My fixture was wrong, not the code.**

   But the coercion is real and is now **pinned as its own assertion**: with `strict => false`,
   MySQL silently turns a word there into `0` so the order reads as "pending", while SQLite's type
   affinity would store the word and a Feature test would see it. If a writer ever did put a word
   there, the application would be **wrong in production and right in the test suite** — the
   direction that hides defects. It is latent today and asserted so it stays visible.

---

## F164 / F165 / F166 / F167 — 2026-10-28

### F164, the variant renderers' DOM ids — measured on `/product/1/edit`

`docs/development/phase-2/tools/check-variant-edit-keys-live.cjs`, against the dev product
`Damask Cotton Scarf` with the colliding terms `M` and `M/L`, and against its 8 stored variants:

| | before | after |
|---|---|---|
| duplicate ids in the edit renderer | **7** (`payment-gateways`, `COD`, `Shipping`, `enable_manage_stock`, `download-product`, `downloadable_product`, `down_products`) | **0** |
| duplicate ids in the data renderer (8 real variants) | 7 + `enable_manage_stock` | **0** |
| panel labelled by its own header | first only; the `M/L` panel announced as **`M`** | all |
| the file label activates | the **product-level** `downloadable_product` input, for every variant | its own variant's input |
| the preview `<img>` the handler writes | the first variant's, or **nothing at all** in the data renderer (so `.src` on null **threw**) | its own |

The data renderer is the one the edit page injects from `$(document).ready`, so it is the panel a
merchant sees first — and it was the last of the three to be checked, which is the whole reason the
ratchet now loops over all three views instead of asking only the create one.

### F167, the payment-type display maps — measured over the whole vocabulary

The vocabulary is the 52 `getPaymentGateways()` ids, the 44 `Setting::paymentList()` names and the
two literals live code stores (`'POS'`, `'Lottery Product'`): **71 values, 56 once case-folded.**

| surface | values that rendered nothing useful | what they rendered |
|---|---|---|
| `OrderDataTable::editColumn('payment_type')` | **26 of 71** | a bare `-`, because the lookup was a case-sensitive index over mixed-case keys while the writers store lowercase registry ids |
| `customer/view.blade.php` | **52 of 71** | an **empty cell** — the 27-branch chain had no `@else`; `POS` was among them |
| `Api\DashboardController::getActivePaymentMethods` | all of them | `ucwords(str_replace('_',' ',$id))` → "Cod", "Sslcommerz", identical in Arabic |

Five values had no label at **any** casing: `payhere`, `senangpay`, `dpopay`, `other_payment`.

And the brands were being translated. Counted against the shipped locale files, over the 51 brand
labels:

| locale | brands the old maps would have passed through `__()` | examples |
|---|---|---|
| `ar` | **45 / 51** | `Stripe` → `الشريط` ("the strip"), `Benefit` → `الميزة`, `Cashfree` → `نقدية`, `Braintree` → `" برينتري "` |
| `fr` | 45 / 51 | `Aamarpay` → "Je suis désolé.", `Braintree` → "Nom de l'entreprise" |
| `ja` | 47 / 51 | `Benefit` → `利益`, `Cashfree` → `現金なし` |
| `he` | 43 / 51 | `Braintree` → `עץ מוח` ("brain tree") |

The locale files contain machine-translation output for brand names, so the Arabic order list named
providers things their own companies do not. `PaymentTypeLabel` translates only the three
descriptive phrases; the 51 brands are literals, and a test asserts both halves — that a brand is
unchanged under `ar` **and** that the phrases still translate, because otherwise "nothing is
translated any more" would pass the first half.

### The Flutter contract, verified in the client rather than assumed

`StoreSyria_app98/lib/core/model/payment_model.dart` carries `key` and `name`;
`lib/view/screen/order_screen/create_order_screen.dart:237` renders `m.name` in the dropdown and
`:242` sets `orderController.paymentType.value = method?.key`. So the picker's `name` is display
only and its `key` is the contract — which is why only `name` changed.

### F166 — a measurement that was right and a conclusion that was wrong

Worth recording in full because the numbers were real.

Driving the **edit** combination endpoint with the option ids `2` and `4` produced
`options_datas[] = "2-4"`, `product_variation_price_2-4 = 0` and `product_stock_2-4 = 0` against a
stored variant `M-Natural` holding price 43 and stock 6, with no server-side delete route. Every
figure there is accurate. The inference — that the edit handler keys variants by option id — was
not, because **the probe was sending the create page's request shape to the edit endpoint.**

The two endpoints take `attribute_options_N[]` with different vocabularies: ids for
`products.attribute_combination`, terms for `products.attribute_combination/edit`. The edit page's
`<option>` carries no `value` attribute, so the browser submits its text, and that text is
`pluck('terms')`. Driven with terms, the same endpoint returns `M-Natural`, **43**, **6** and the
real delete route — the handler was correct all along.

What caught it was `MerchantProductOwnershipTest::test_a_merchant_can_open_their_own_attribute_combination`,
a **positive control** written for an unrelated security fix. A refusal test could not have: the
wrong fix produced an empty combination table, and an empty table is not an error anybody sees.
`VariantCombinationVocabularyTest` now pins both vocabularies from both ends — the pages that
produce the values and the handlers that consume them.

### Both directions, on the live pages — 2026-10-28

Required by CLAUDE.md for any UI change, and the AR row is the one that carries F167's whole point.

| | `light / ltr / en` | `dark / rtl / ar` |
|---|---|---|
| order list payment column header | `Payment Type` | `نوع الدفع` |
| `cod` (a descriptive phrase) | `Cash On Delivery` | **`نقداً عند التسليم`** |
| `stripe` (a brand) | `Stripe` | **`Stripe`** — not `الشريط` |
| `'没有'` (a historical, unattributable value) | `-` | `-` — not POS, and not echoed |
| variant panels on `/product/1/edit` | 8 panels, **0** duplicate ids, every panel labelled by its own header, every file label activating its own input | identical |
| page errors | none | none |

The two mutated order rows were set in the dev sqlite for the measurement and restored immediately
after; the database is not tracked.

---

## The independent review of F167/F168, and what it cost — 2026-10-28

Seven claims were raised against the F150/F164 change set and each was verified against the real
source before being acted on. Five were confirmed, one was partly confirmed with its
severity-deciding premise refuted, and one was refuted outright. Three of the confirmed ones were
worse than the claim.

### Confirmed, and worse than claimed

| claim | what was actually true |
|---|---|
| a test skips, breaking the gate | it **errored** (wrong fixture column), which the gate treats as worse than a failure — zero-error baseline. And the permission was wrong too, so fixing only the fixture would have converted the error into the predicted silent skip. And the assertion was green for the wrong reason: `POS` appears in two `method="POST"` attributes and the header's `title="POS"`. |
| a fourth label map survives, 2 English divergences | **17 of 52** registry ids disagreed, and in Arabic `__('DPO Pay')` → `راتب موظف حماية البيانات` (*"Data Protection Officer's salary"*), offered to a merchant as a payment method |
| three stale `file:line` citations | **9 of 29** were stale, and two of mine decayed *within the edit correcting them* |

### Partly confirmed — the mechanism is real, the severity is not

The order list's payment cell is a raw column, and for `cod`/`bank_transfer`/`other_payment` the
resolver returns `__()`, read from a global `resources/lang/<locale>.json` that
`LanguageController::storeLanguageData()` writes from request input with no escaping, past an
`xss` middleware that strips only the literal substrings `&lt;`, `&gt;`, `javascript` and `alert`.

But `Create Language` is **not merchant-reachable**: granted only to the `super admin` role,
absent from the merchant grant list, and denylisted in `config/superadminaccess.php`, which
`RolePolicy::isGrantableBy()` enforces on the write path and not merely in the form. So it takes a
deliberate operator action. `e()` was added anyway — measured as a visual no-op across all 57
label literals and all 48 translations of the three phrases in the 16 locale files — because this
project's rule is never to let one check be the only one.

### Refuted

`resources/views/order/show.blade.php` renders `{{ $order->payment_type }}` raw, and the review
called it a third merchant order-detail surface. It is **not**. Its only render site is
`BankTransferController:118`, bound to `PlanOrder::find($id)`, off `plan.order.show`. The confusion
is a name collision: `Route::resource('order', ...)` registers a route *named* `order.show`, but
`OrderController::show()` renders `order.order_show`, a different file — which does not display
`payment_type` at all. Applying the resolver there would be a **regression**, not a gap closed:
that view's rows hold `__('Bank Transfer')` written by `BankTransferController:103`, and
`PaymentTypeLabel::for('Bank Transfer')` folds to `'bank transfer'`, which is not a key — so a
correct label would become a dash on the one order kind the view serves.

### The regression the review did not find, and the audit did

`PaymentTypeLabel` had no label for `'DPO'`. Measured: `for('DPO') === null`, while the replaced
map held `'DPO' => __('DPO')` at `OrderDataTable:146` in `c7283a3c~1`, and `'DPO'` is a value
`PaymentService` really stores (`case 'DPO':` at `:424`, stored verbatim at `:2773`). So every DPO
order rendered a dash.

The guard written to catch exactly that missed it, and the cause is a measurement error worth
keeping:

```
grep -cE "\['name'\] = '"            app/Models/Setting.php   →  44
grep -coE "\[\s*'name'\s*\]\s*=\s*'" app/Models/Setting.php   →  53
```

Nine of `paymentList()`'s assignments are written `$Setting_array[ 41 ][ 'name' ] = 'easebuzz';`
with spaces inside the brackets. The census skipped all nine, the floor was calibrated to the
broken count, and the ninth skipped name was `DPO`. The same regex produced the "10 ids the
gateways hold that paymentList does not" figure that F167's own commit message offered as a
*correction* — eight of those ten are in both registries.

Three independent guards now catch it where zero did: the widened and method-scoped census
(floor 53), the pinned list with `DPO` restored, and a new `test_every_old_map_key_still_resolves`
that reads the replaced map out of git history rather than from a transcription. Removing the
label fails all three.

### The cross-tenant exposure, measured

With one email address used as a guest in two stores, the other store's order appeared in the
rendered table on the merchant's customer page — reference, date, value, payment type and status.
`order_billing_details` has **no `store_id` column in any migration**, so the predicate had to go
on `Order`. Both branches bitten separately.

### Tests that passed for the wrong reason, found by driving them against a mutated tree

| test | passed with the fix reverted, because |
|---|---|
| the customer page's `POS` assertion | `method="POST"` and the header's `title="POS"` |
| the F168 "foreign term resolves to nothing" test | it asserts an absence with no premise; any of `update()`'s eleven early returns satisfies it. One needle could never match; the other matched `visible_attribute_N` under a browser-shaped payload |
| both `VariantCombinationVocabularyTest` source scanners | they did not strip comments — deleting the code and leaving a comment quoting it left them green |

The third is the **fifth** instance of this trap in this repository, so the stripper is now a
shared trait (`tests/Support/StripsSourceComments.php`) naming all five, rather than a tenth
private copy.

### The gate, after F169–F174 — 2026-10-28

```
php artisan test --testsuite=Unit        601 passed (3114 assertions)
php artisan test --testsuite=Contract     26 passed (83 assertions)
php artisan test --testsuite=Feature       2 failed, 17 skipped, 6582 passed (33190 assertions)

php .github/scripts/assert-known-test-outcomes.php …
  testcases observed : 6601  (manifest floor 1000)
  failures observed  : 2   (manifest declares 2)
  errors observed    : 0   (always expected: 0)
  skips observed     : 17  (manifest declares 17)
  OK: the observed outcome set matches the manifest exactly.
```

The two failures are the declared ones — `new users can register` and
`profile page is displayed`. As the gate's own output insists, this is not a statement that the
suite is green; it is a statement that it is failing and skipping exactly what the project has
recorded.

The run immediately before these fixes was 3 failed / 17 skipped / 6576 passed, and the gate
rejected it on **one error** — a zero-error baseline with no manifest section, because an error
means a test could not run at all. That was `PaymentTypeDisplayTest`'s fixture naming a
`customers.name` column that does not exist. Failures and skips already matched; the error was the
only violation.

---

## D-26 — one image per product variant, driven live — 2026-10-28

`docs/development/phase-2/tools/check-variant-image-live.cjs`, on `/product/1/edit` with the dev
product's 8 stored variants, in both directions:

| | `light / ltr / en` | `dark / rtl / ar` |
|---|---|---|
| controls rendered | 8, ids all distinct, every label activating its own input | identical |
| upload to variant 22 | preview appears on 22 only; the other variant untouched | identical |
| server feedback | `Image uploaded successfully.` | `تم رفع الصورة بنجاح.` |
| rows written | exactly one, `variant_id = 22` | identical |
| after remove | preview cleared, **0** rows | identical |
| page errors | none | none |

### Three faults in the probe before it measured anything, all recorded in it

1. It clicked the remove button while the variant's accordion panel was **collapsed** —
   `setInputFiles` does not need visibility but `click` does, so it timed out. The probe now opens
   the panel first.
2. It compared `innerHTML.trim().length` before and after. A second run against a variant that
   already had an image measured **443 → 244** and reported "no preview", because the
   server-rendered markup is longer than the JS-built one. It now asks whether an `<img>` exists.
3. The crashed first run left a row behind, so the second run started from a state it did not
   expect. The fixture is cleaned before each run and after.

### And one fault in the handler that only the tests found

The first draft uploaded to `uploads/pro_image` — a plausible directory name that does not exist
as a convention here. `ProductImage`'s `saving` hook calls `StoredPathPolicy::productImage()`,
which judges a stored path against **the owning store's folder**, so every upload came back
`The file path is not accepted.` as a 302 with a validation error. The gallery writes to
`uploads/<store_id>` (`ProductController:598`) and so does this now. My own test fixtures made the
identical mistake and failed for the identical reason, which is the clearest evidence the policy
is doing its job.

---

## F175 / F176 — the unguarded variant panel, and the comment that 500ed it — 2026-10-29

### The seven scenarios the owner asked for, measured before the fix

Ten tests, written first. **Six failed against the unfixed code**, and two of those failures were a
different defect (F176) rather than the permission gap:

| scenario | before | after |
|---|---|---|
| 1. authorised merchant | sees variants ✓ | sees variants ✓ |
| 2. authorised employee (`Manage Products`) | sees variants ✓ | ✓ |
| 2b. authorised employee (`Edit Products`) | sees variants ✓ | ✓ |
| 3. **employee with no product permission** | **read price, stock and SKU** | empty panel |
| 4. another store | **500** (F176) | empty panel |
| 4b. store-less principal | **500** (F176) | empty panel |
| 5. platform operator | **served store 1's variants** | the product's own |
| 6. product that does not exist | **500** | empty panel |
| 6b. operator + missing product | **500** | empty panel |
| 7. only this product's variants | ✓ | ✓ |

Four bites, each failing its own tests: removing the gate; gating on `Edit Products` alone (which
fails three, including the merchant's own access — this is why both names are required); dropping
the ownership check; and reverting to the `getCurrentStore()` variant filter, which fails the
operator scenario *and* scenario 7.

### F176, measured on the running app

```
product 2 (variants=0): failed combination requests = ["500 products/attribute_combination_data/edit"]  panel length 0
product 1 (variants=8): failed combination requests = []                                               panel length 113186
```

After escaping the comment:

```
product 2 (variants=0): failed combination requests = []  panel length 9212
product 1 (variants=8): failed combination requests = []  panel length 113918
```

### The compiler's real behaviour, since the first version of the guard got it wrong

The first detector flagged four false positives — a minified Tailwind stylesheet, a comment in
`dashboard.blade.php` and two of my own. Each boundary was then measured:

| construct | compiles to PHP? |
|---|---|
| braces in a JavaScript comment | **yes** |
| a parenthesised directive in a JS comment | **yes** |
| `@if (…)` in a JS comment | **yes** |
| a bare directive name, no parentheses | no |
| anything inside a `{{-- --}}` Blade comment | no |
| anything inside a `@php … @endphp` block | no |

The last two are why the sweep removes those regions first; the bare-name exemption is why prose
such as *"the nine icons used to live in a @php block"* is not an offence. The table is pinned by
its own test.

### And a probe that measured the wrong element

`check-variant-edit-keys-live.cjs` reported every preview target as `null` after D-26. Not a
regression: D-26 added a **second** file input to each panel, rendered before the downloadable one,
so `querySelector('input[type=file]')` started returning the variant input, which carries no
`onchange`. Scoped to `.download-product` and recorded in the tool.

---

## F177 — the keyboard was unusable on every merchant screen — 2026-10-29

Found by the probe written for D-25 Step 1, **before** the conversion it was built to verify.

`docs/development/phase-2/tools/check-keyboard-traversal-live.cjs`, `/dashboard`, 80 Tab presses
from the top of the document:

| | SIDE | HEADER | MAIN | PALETTE | BODY |
|---|---|---|---|---|---|
| **before** | 3 | **0** | 0 | **77** | 0 |
| **after** | 17 | **7** | 55 | 0 | 1 |

The page has 75 tabbable controls and the shared header's seven begin at DOM index 37. Before the
fix, press 3 sat on `#ss-workspace-trigger` with the palette `display:none, hidden=true`; by press
4 focus was on `#ss-command-input` and the palette was `display:block, hidden=false`. From there
the sequence cycled six elements for ever — the palette's input, three quick-create links, a
favourite and its remove button. **72 of 75 controls were unreachable by keyboard**, including the
header's Notifications, Quick Add, store switcher and language switcher.

Escape closes the palette and returns focus to the search, and the next Tab re-opens it — so there
was no way through, not merely an obstacle.

### Both open paths still work, both directions

| | `light/ltr/en` | `dark/rtl/ar` |
|---|---|---|
| initial state | closed | closed |
| click the sidebar search | **open**, focus inside | open, focus inside |
| Escape | closed | closed |
| Ctrl+K | **open** | open |
| Escape | closed | closed |
| the sidebar's own nav filter | **14 items → 0** | 14 → 0 |

### A measurement that was wrong first, and would have produced a pointless change

An earlier probe focused a header control with `element.focus()` and recorded
`outlineStyle: none` — apparently no focus ring. A programmatic focus does **not** set
`:focus-visible`. Driven with real Tab presses, every header stop shows
`outline: solid 2px rgb(8, 145, 127)` — the DS teal focus token, already applied. So D-25 Step 1
does not need to touch focus, and "adding" a ring would have been work against a defect that does
not exist.

---

## D-25 Step 1 — the shared header's design-system scope — 2026-10-29

### The before/after behaviour comparison, 24 captures

`capture-header-contract.cjs` across six merchant screens × four theme/direction configurations,
confirmed deterministic over two consecutive runs before being trusted. Of the eight fields it
records per capture, **two changed and six are byte-identical in all 24**:

| field | result |
|---|---|
| `ids` | identical |
| `links` (href + accessible name) | identical |
| `forms` | identical |
| `toggles` (every dropdown clicked for real) | identical |
| `dropdownsOpen` | identical |
| `box` (height, offset) | identical |
| `style.color` | **`rgb(82,91,105)` → `rgb(91,102,101)`** — the intended token change |
| `tabOrder` | **`[]` → seven controls** — F177's fix, surfacing independently in a second tool |

### The matrix D-25 requires, measured

`check-appbar-viewports-live.cjs` — the colour a new, unstyled element inherits from the header,
against the effective background behind it:

| | light | dark |
|---|---|---|
| desktop 1400×900 | **5.94:1** | **6.55:1** |
| phone 390×844 | **5.94:1** | **7.11:1** |

AA in all eight (two viewports × AR/RTL and EN/LTR), no horizontal overflow, no page errors, 7
visible controls on desktop and 8 on phone (the hamburger).

Before: `rgb(82, 91, 105)` in **both** themes — **2.07:1** on the dark surface.

### Why the subject of that measurement is the inherited colour and not the visible nodes

`check-header-contrast-live.cjs` reports **30 text nodes, 0 below AA** in all four configurations
— both before and after. Every visible node in this header already has a rule of its own, because
F158 patched them one at a time. So a census of visible nodes cannot see the header's own
inheritance, which was the thing that was wrong. The probe appends a bare `<span>`, reads what it
inherits and removes it.

This is also why Step 1 is not described as a contrast fix: it repairs nothing a merchant can
currently see. It makes the next element added to the header AA-correct without a fifth hand-patch
in a fifth stylesheet, which is what a phased migration is for.

### A bite that did not bite

Removing `dash-header` from one of the two `<header>` branches left
`test_the_themes_load_bearing_names_are_preserved` **green**, because it asserted the string
appeared somewhere in the file and the other branch still carried it. A whole-file containment
check cannot see a half-applied change — the exact failure mode the sibling branch test exists for.
Now asserted per `<header>` tag, and re-bitten.

## P2-05-9 — the remaining 30 row-action partials — 2026-10-29

**Not D-25 Step 2.** Step 2 is the shared `action-button` page-header region — the export control
and its three siblings, per the owner's decision of 2026-10-28. This is the WS J row-action work
that P2-05-2, P2-05-4 and P2-05-5 started, finishing the other 30 of 37 partials.

### What was wrong

`resources/views/<entity>/action.blade.php` is the cell every merchant list renders at the end of
each row. Measured across the 37 files before the change: **159 `title` attributes against 25
`aria-label`s**, so about **134 controls had no accessible name at all.**

`title` is not a name on these controls. They all carry `data-bs-toggle="tooltip"`, and Bootstrap
**moves** that attribute into `data-bs-original-title` and blanks it when the plugin initialises.
So the DOM a PHPUnit test renders has a name and the page a merchant uses does not — which is why
`check-row-actions-live.cjs` waits 900ms before measuring, and why the first version of that
harness found three of four controls unnamed on screens whose source looked fine.

### How a 30-file mechanical conversion was made checkable

`docs/development/phase-2/tools/census-action-partials.php` reduces each partial to the things that
must be invariant — permission names, the `@permission`/`@endpermission` balance, routes,
data-attributes, JS handler classes, form boundaries, element counts, icon names, `title` and
`data-title` counts — plus, separately, **every route reference together with the stack of
permission gates enclosing it.** `php census-action-partials.php HEAD` compares the working tree
against any ref and exits non-zero if an invariant field moved.

Final reading: **0 of 15 invariant fields differing across all 37 files**, and **all 92 route
references under byte-identical gate stacks.** Intended movement: `aria_labels` 25 → 100,
`ds_classes` 7 → 37 files, `bootstrap_btn_classes` 30 → 0 files, `dead_classes` 17 → 0 files.

The gate-stack pass is there because the field census cannot answer the question that matters. The
`permissions` field proves the SET of gate names is unchanged and `permission_count` proves the
directives balance; neither proves the delete button is still **inside** `@permission('Delete Tag')`.
A conversion could move a control across a gate boundary and leave both identical.

### Three bugs in the converter, caught by the census rather than by reading the diff

1. **22 of 30 files got `data-aria-label` and no accessible name.** The insertion anchored on
   `\btitle="`, which also matches the tail of `data-title="`. The result was a junk data
   attribute — the exact opposite of the fix, and invisible in a diff already full of new
   attributes. Reverted with `git checkout resources/views/`, re-anchored to the start of the
   attribute list where nothing can be inside another attribute, re-run, re-diffed.
2. **`btn-badge` vanished from a field declared invariant.** That forced the question of whether
   the class is load-bearing instead of assuming it. It is not: grepped across every first-party
   and vendored stylesheet and script, it is selected by no script and styled by no rule. It now
   has its own census field, so dropping it reads as a named, intentional change.
3. **Three icons in `store/` stayed announced.** They are written `class="ti ti-trophy py-1"`, and
   the converter's icon pattern required the class attribute to end after the icon name. The
   test's first pattern had the identical blind spot — **two blind spots of the same shape agree
   with each other, which reads as a pass.** Both widened.

### A defect the slice found rather than introduced

`deliveryboy/action.blade.php` and `store/action.blade.php` each carried **two `class` attributes
on one `<a>`**, and had since the files existed:

```html
<a href="#" class="btn btn-sm btn-danger bs-pass-para show_confirm" …
   class="btn btn-sm btn-secondary" data-bs-toggle="tooltip" …>
```

Measured in a real Chromium rather than assumed: the parser keeps the **first** and discards the
second entirely — `getAttribute('class')` returns the first, `classList` holds none of the second's
tokens, and the tag's other attributes survive. So `btn-secondary` had never applied and the
control had always rendered red. The dead attribute is removed; a test refuses a new one, because
a second `class` is a silent way for a conversion to look applied and not be.

### The bilingual defect, which only an Arabic reader would have found

The converter composes names as `__('Delete :thing', ['thing' => __('Cart')])`. **None of the nine
new message keys existed in either language file.** All 56 pre-existing keys in these partials are
translated in both, so this came from the conversion and nothing else.

`__()` falls back to the key, so nothing failed:

| | rendered |
|---|---|
| en | `Delete Cart` — **correct by accident**, because the key is English |
| ar | `Delete عربة` — the English key with an Arabic noun substituted into it |

Nine keys added to both files, plus the noun `Plan Order` (the converter had derived *"Plans"* from
the directory name, which is in neither file). All thirteen composed labels are now printed through
the real translator in both locales:

```
ar  حذف عربة · حذف علامة · حذف فتى التسليم · حذف المستخدم · تعديل منطقة الشحن
    عرض كوبون · الرد على الدعم · قبول طلب الخطة · استرداد طلب الخطة
    إرسال بريد إلى قائمة أمنيات · إرسال رسالة إلى عربة
```

`Tests\Unit\Ui\ActionPartialContractTest::test_every_label_these_partials_compose_is_translated_in_both_languages`
now checks both halves of every composed name against both catalogues. That pass also turned up
**F178**: `Menu` is translated `قائمة الطعام` — "food menu" — for the storefront's navigation menu.
Recorded, not changed: it is a pre-existing shared line, read by the sidebar, the settings panels
and the vendored modules.

### Three labels that were a bare verb

`deliveryboy/` and `store/` said *"Delete"*; `shippingzone/` said *"Edit"*. The converter preferred
each file's own `data-title` and in those three that attribute is just the verb. Fifty rows
announcing *"Delete"* is the defect this slice set out to fix, not a fix of it. Each now names the
noun its own file already uses, and `test_no_accessible_name_is_a_bare_verb` refuses a new one.

### What the browser can and cannot prove here

`check-row-actions-live.cjs` gained `brands` and `labels`: **448 cases, 448 passed**, 9 screens ×
en/ar × light/dark.

The dev fixture merchant can reach **only 2 of the 30 converted screens with data rows on them**,
and that was measured by driving all nine candidates rather than assumed: `/tag`, `/taxes`,
`/deliveryboy` and `/faq` redirect for want of a permission — two of them landing on the
*previous* screen in the loop, which is the trap P2-05-4's docblock records — and `/coupon`,
`/page` and `/support` render an empty list.

That is **enough** for contrast and the rendered control, because there is no per-screen CSS: all
30 now carry the same four DS classes, so `.ss-btn--ghost`'s icon colour and
`.ss-btn--danger-quiet`'s are measured once per configuration and are the same rules everywhere
else. It is **not enough** for the accessible name, which is per-file text. Hence the 8 source
tests over all 37 files, and all 37 compiled through the real Blade compiler and PHP-linted.

### Evidence

| | |
|---|---|
| `tests/Unit/Ui/ActionPartialContractTest.php` | 8 tests, 629 assertions |
| bites | **9**, one per defect, each confirmed to fail with the defect reintroduced, residue checked against a pre-bite census rather than against `HEAD` |
| census | `0` invariant fields differing, `92/92` gate stacks identical |
| compile | 37 partials through `blade.compiler` + `php -l`, 0 failures |
| live | `check-row-actions-live.cjs` 448/448 |
| suites | Unit 622 · Merchant Feature 366 · Contract 26 |

The residue check is worth its own line because the first version of it was wrong: it ran
`git diff --quiet resources/views/`, which reports the **conversion itself** as a difference, and
so printed "RESIDUE LEFT" and listed all 30 files after six bites that had each restored their file
correctly. It now compares a census taken before the bites with one taken after.

## D-25 Step 2 — the shared `action-button` page-header region — 2026-10-29

**What the owner approved.** *"Step 2 — shared action buttons: convert the export button and its
three neighbours as one unit, changing no action behaviour and breaking no form submission or
Bootstrap/JavaScript contract."*

**Scope, and what was left alone.** The region is `@yield('action-button')` in
`layouts/app.blade.php`, filled per screen. The four controls are the order list's Create Order,
Export, Grid View and Board View. `order/grid.blade.php` carries the same export control and its
neighbours on the same screen in its other view mode, so it is converted with them — a merchant
toggling the view would otherwise cross between two visual languages. **Forty-nine more
first-party views have an `action-button` region with these classes and are not touched**: the
decision says the page shell follows *"only if that first step measures clean"*.

### It was not a styling change: 56 accessible names were an icon-font glyph

Chromium includes an icon font's generated `::before` content in the accessible-name computation.
So `<i class="ti ti-file-export">` **named** the export control:

| | Chromium's computed name, before |
|---|---|
| Create Order | `" Create Order"` |
| Export | `""` |
| Grid View | `""` |
| Board View | `""` |

Those are private-use codepoints from Tabler. The name is not missing — it is gibberish, which is
why this had survived: a source review sees a `title` and moves on, a DOM assertion sees a name and
passes, and an "is it empty" check reports zero problems. The measurement that finds it is
**Chromium's own accessibility tree**, read through `ariaSnapshot()` **after** the 900ms the
tooltip plugin needs, which is the only point at which the question is the merchant's.

`aria-hidden="true"` removes the glyph from the computation and `aria-label` supplies the name.
Across all 16 captures: **56 glyph-named links → 0**, with **0** empty-named links before or
after. Chromium now reports `Create Order`, `Export`, `Grid View`, `Board View` and, on the grid,
`Export`, `List View`, `Board View`.

### And it fixed a real dark-mode AA failure

| | icon contrast |
|---|---|
| dark, all 8 captures, before | **2.59:1** — under the 3:1 non-text minimum (WCAG 1.4.11) |
| dark, after | **8.92:1** on the list, **11.1:1** on the grid |
| light, before → after | 6.92:1 → **5.68:1** on the list, **16.06:1** on the grid |

2.59:1 is white on the brand green — the same figure F9 recorded for the selected sidebar item.
Light mode passed before, so the failure existed in one theme only. The light-mode worst moved
**down**, to the primary button's own token pair; it is still above both the 4.5:1 text and 3:1
non-text thresholds, and it is recorded as a decrease rather than described as an improvement.

### The hierarchy is a deliberate visual change

Four brand-filled buttons in a row means no primary action at all. Create Order stays
`ss-btn--primary`; the utilities became `ss-btn--secondary`. That is the DS's own hierarchy **and**
it is what fixed the contrast, since `--ss-fg` on `--ss-surface` is the DS's AA-checked pair. A
test asserts both class names are present, so a later edit that flattens the hierarchy fails.

### The behaviour comparison: 16 captures, 0 invariant differences

`docs/development/phase-2/tools/capture-page-actions-contract.cjs` records en/ar × light/dark ×
desktop/phone × two screens. Invariant per control: `href`, `id`, `export-btn`, `filename`,
`data-bs-toggle`, `data-bs-placement`, the icon's class. Invariant per region: its layout classes,
`display`, `gap`, `flex-wrap`, and whether the row overflows the viewport. **All identical.**

Two of these contracts are invisible in the markup, so the capture drives them:

- **`.export-btn` is bound by a delegated jQuery handler at `public/js/loader.js:116`.** It
  preventDefaults the click, fetches the href as a blob and sets
  `a.download = this.getAttribute('filename') + '.xlsx'`. The capture **clicks it for real** with
  the route intercepted and records three things: the href the handler fetched, that the page did
  not navigate (so preventDefault held), and the filename the handler computed. Identical before
  and after in all 16.
- **The tooltip** is the name a sighted mouse user gets, and removing `data-bs-toggle="tooltip"`
  removes it silently while leaving a `title` in the markup.

### The wrapper had no gap, and dropping `me-2` exposed it

`.page-header-icon` is `d-flex flex-wrap gap-2` — but these two screens wrap their controls in
their own `<div>`, so that div is the region's **only** flex child and the region's gap never
reached the controls. The spacing was `me-2` on Create Order plus the ~3.5px of HTML whitespace
between inline-flex siblings.

Dropping `me-2` for the DS left the primary action ~3.5px from Export. **Measured, not noticed:**
the region went 238 → 235 wide in the capture. The wrapper now carries the repo's own prevailing
pattern — `product/index`, `coupon/index` and `tag/index` all use a flex row with a gap, so these
two were the outliers — with the DS's 8px and `justify-content-end`, whose logical `end` follows
`direction` and so stays correct in RTL.

| | before | after |
|---|---|---|
| region, en/ltr | 238 × 31 | **248 × 32** |
| region, ar/rtl | 215 × 31 | **227 × 32** |
| Create Order | 123 wide | **128** |
| icon controls | 32 × 31 | **32 × 32** (the DS `--sm` 32px minimum, above the 24×24 target floor) |
| overflows at 390px | no | **no**, in all 16 |

### F179, found by the capture and deliberately not fixed

Clicking the export control for real reported the download as `Order.xlsx` in English and
**`download`** — no extension — in Arabic. Root cause measured directly in Chromium on three
values:

```
a.download = "Order.xlsx"      -> suggestedFilename "Order.xlsx"
a.download = "الطلب.xlsx"       -> suggestedFilename "download"
a.download = "طلب الخطة.xlsx"   -> suggestedFilename "download"
```

Chromium rejects a non-ASCII `a.download` outright. It affects every `.export-btn` in the app, in
Arabic only. Step 2 does not fix it: a download filename is action behaviour, which Step 2 is
forbidden to change, and it is not fixable in these two views anyway — an ASCII attribute would
change the English name too, and a blob URL carries no `Content-Disposition`. The fix belongs on
the export route. Recorded with that proposal.

### Two bugs in the probe, both of which first read as findings about the page

1. **Every icon control reported `(unnamed)` in the tab order.** The reader trimmed *after*
   choosing among the name candidates, so an icon-only `<a>` whose `textContent` is `"\n        "`
   — whitespace, which is **truthy** — won the `||` chain and then trimmed to empty. The page was
   fine; the probe was not.
2. **`/order/grid` is not the grid's URI.** `routes/web.php:1172` names it `order-grid`, so the
   first run captured a 404 for all eight grid entries and reported them as unreachable.

Both were fixed before the before-capture was taken, which is what makes the contract diff
trustworthy.

### Evidence

| | |
|---|---|
| `tests/Unit/Ui/PageActionRegionContractTest.php` | 7 tests, 97 assertions |
| bites | **12**, every one bit — including the `export-btn` hook renamed, the `filename` attribute dropped, and `loader.js` changed to read a different attribute; all three files verified byte-identical to a pre-bite snapshot afterwards |
| contract | 16 captures, **0 invariant differences** |
| compile | both views through `blade.compiler` + `php -l`, 0 failures |
| suites | Unit 629 · Merchant + Commerce Feature **1034 passed, 7 skipped (the declared set), 0 failed** · the four order/export Feature files + `DesignSystemStylesheetScopeTest` 43 passed |
| blast radius | both views are LEAF screens — grepped, neither is `@include`d by any first-party or vendored view — and nothing shared was edited: `loader.js` was only read, the DS stylesheet untouched |

## D-25 Step 3 — the order detail page's shell — 2026-10-29

**What the owner approved.** *"Step 3 — order detail page: finish the shell with the `ss-card` /
`ss-row` components that already exist, preserving order data, notes, deletion, status, invoices
and permissions; duplicated note-rendering branches may be unified **only if their equivalence is
proved**."*

Four pieces, each compiled and measured before the next: the note branches, the address lists, the
shell cards, the header actions. 959 lines → 864.

### The equivalence was proved — and then it proved something else

The five `@elseif` branches differed in exactly one token, the tone class. Normalising whitespace
and substituting that class made all five **byte-identical, 912 characters each**. So the collapse
is sound: 156 lines became 62 and four copies of the delete form are gone, which is what
`OrderDetailContractTest::test_every_note_has_exactly_one_delete_control` was written to guard.

But the chain had **no `@else`**, so a note matching none of the five rendered nothing. That is
reachable, and it is **F180**:

| writer | status | note_type | renders |
|---|---|---|---|
| `OrderNote::addOrderNote()` | `Stock Manage` / `Order Created` / `Order status change` | — | **yes** |
| the merchant note form | `''` | `private_note` / `to_customer` | **yes** |
| `OrderController::store():417` | `Order created` — **lowercase c** | `created` | **no** |
| `Api\DashboardController:6274` | `Order created` | `system` | **no** |
| `Api\DashboardController:6284` | `Customer note` | `customer` | **no** |

**Measured end to end, not reasoned from two string literals.** With all eight shapes present for
one order, the rendered page shows **5 note blocks of 8 rows**, and the three inserted texts are
absent from `document.body.innerText`. The irony is in the code: the comment at
`OrderController:414` says the earlier fix means the note *"now actually appears"*, and it does
not.

Step 3 **reproduces the drop exactly**, because its condition is to preserve notes rather than
change them. `OrderNote::timelineTone()` returns null for those three shapes, pinned by a
data-provider test naming all eight, so the fix for F180 has one place to change and a test that
will notice when it happens. The three shapes are now in `database/seed-p2dev.php`, so any later
probe sees the drop rather than a tidy five-note timeline.

### The dependency that would have broken silently

`custom.css` and `rtl-custom.css` tint the five note tones through `.card-body .card-header.<tone>`
— **twenty selectors across four stylesheets** once the `html[data-ss-theme="dark"]` variants are
counted. Those dark variants are P2-04-6's measured fix for tints that read 1.24–2.95:1.

A shell conversion that renamed either class would have left every note rendering in **no colour at
all**, with nothing failing: the notes still appear, the tests still pass, the page still loads.
So:

- the notes panel's body keeps `card-body` as the ancestor hook **and** gains `ss-card__body` — the
  design system loads after `custom.css` (`layouts/app.blade.php:225`), so the DS padding wins
  while the hook survives;
- the note item itself stays on Bootstrap's `card` / `card-header`;
- the test asserts the dependency **from both sides** — the view's two class strings, and all ten
  tint selectors in the two stylesheets. Moving the rules to `ss-` names is allowed; moving them
  without the view is not, and a bite proves the test says so.

### One bite caught a worthless assertion

`test_the_page_shell_uses_design_system_cards` first used an **allow-list** of permitted class
strings. Turning a converted `ss-card` back into `card` produced a second `class="card"`, which the
allow-list happily accepted — **the test passed with the defect reintroduced.** An allow-list
cannot tell one permitted element from five. It is now a count per form (`card` ×1,
`card-header note-bg-color {{ $ssNoteTone }}` ×1, `card-body ss-card__body` ×1), and the same bite
bites.

### The address lists

Two `<dl class="row mb-0 align-items-center">` with 14 `col-sm-3` keys and 14 `col-sm-9` values
became `ss-deflist` / `__key` / `__val` — a two-column CSS grid (`minmax(6rem, 10rem) 1fr`) that is
direction-aware without an RTL override, and drops the `h6` / `text-sm` type overrides for the DS's
own key and value tokens. The `ss-card` they now sit in also owns its foreground (the F140 rule),
so the values stop inheriting the vendored theme's body colour — which is byte-identical in all
four of its stylesheets, the dark one included.

### And the Arabic page said "Print"

Three of the four header actions carried **bare English** `title` and `aria-label` strings —
`Print`, `Payment Receipt` — although both lines are **already in `ar.json`**. The view simply was
not calling the translator. A fourth, `Receipt`, had a translated `title` and no `aria-label` at
all, so once the tooltip plugin ran its accessible name was the icon font's glyph — exactly the
defect Step 2 measured on the order list. All four now carry `ss-btn ss-btn--sm ss-btn--icon
ss-btn--secondary`, a translated `aria-label`, and a decorative icon, and the copy-link control's
two hooks (`data-ss-copy`, `onclick="copyToClipboard(this)"`) are pinned.

### What was deliberately not done

The Bootstrap 12-column responsive grid (`row` / `col-*`) stays. `.ss-row` is a flex row, not a
12-column grid, so swapping them is a layout rewrite rather than a conversion — and the owner's
rule for these steps is to stop widening rather than carry one further than it was scoped.

F180 is recorded and not fixed, for the reason above.

### Evidence

| | |
|---|---|
| `tests/Unit/Ui/OrderDetailShellContractTest.php` | 13 tests, 74 assertions |
| bites | **12**, every one bit — and one of them rewrote an assertion that had been worthless |
| live | `check-order-detail-live.cjs` **112/112 before and after**, en/ar × light/dark × desktop/mobile |
| note rendering | byte-identical: same 5 blocks, same 5 tones in the same order, same texts, same 3 drops |
| existing tests | the 22 order-detail Feature tests pass unchanged |
| compile | the view through `blade.compiler` + `php -l` after each of the four pieces, 0 failures |
| suites | Unit 642 · Merchant + Commerce Feature 1034 passed, 7 skipped (the declared set), 0 failed |
