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
  Tests:  5798 passed, 3 failed, 17 skipped (29134 assertions)   [first run]

$ php .github/scripts/assert-known-test-outcomes.php \
      storage/logs/feature-junit.xml .github/known-test-outcomes.json \
      storage/logs/feature-teamcity.txt
  failures observed : 3  (manifest declares 2)
  ::error::NEW FAILURE, not in the manifest:
      SecurityLogInventoryTest::test_the_observability_inventory_matches_the_source
```

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
| `MerchantSearchAuthorizationTest` | 10 | the global search: cross-tenant, per-section permission, unresolved tenant, LIKE wildcards |
| `TenantSwitchTest` | 12 | F12 — a GET answers 405 having moved nothing; CSRF asserted structurally |
| `ProductConcurrentEditTest` | 5 | a stale save is refused and A's work survives |
| `DashboardPermissionNamesTest` | 1 | every `isAbleTo()` name in the merchant views can actually be granted |
| `AdminLayoutAssetsTest` | 2 | every `asset()` path in the admin layout resolves to a real file |

### Bite tests — every control reverted, and what failed

This project's rule is stricter than TDD's: after the fix, revert it and confirm the test fails
again. Recorded in full because **three of these revealed tests that passed for the wrong reason**.

| control reverted | result |
|---|---|
| `Menu::add()` stops recording denials | 1 failed — the right one |
| F5's escaping + the route-existence drop | 4 failed, one of them `RouteNotFoundException` — the availability defect, reproduced |
| `getSideMenu()`'s gate call site | **first attempt: 0 failed.** Every assertion drove the helpers directly. `CustomSidebarRenderPathTest` was written to drive the function; now 1 fails |
| the iframe ordering fix | **first attempt: 0 failed** — `sidemenubuilder.iframe` does not resolve in a test run, so the row was dropped for the wrong reason. A stand-in wrapper route is registered and asserted; now 2 fail |
| the order preview's **handler** check | 1 failed — exactly the direct-call case |
| the order preview's **guard** entry | 1 failed — exactly the registration case |
| the concurrent-edit check | 1 failed — exactly the stale-token case |
| both contrast stylesheets | 14 of 32 readings below AA, from 0 |
| the singular permission name restored | 1 failed, with the plural named in the message |
| search: the store predicate, or the unresolved-tenant refusal | **0 failed, either way.** The two controls are redundant: `where('store_id', null)` compiles to `IS NULL`, and Customer's global scope covers the resolved case. **No endpoint test can fail on a single removal.** Stated in the test file; the hazard underneath is pinned by its own test, which measures that `Customer::query()` for a tenant-less principal compiles with NO store predicate and returns both tenants' rows |

## 9e. Contrast, measured in the running application

`docs/development/phase-2/tools/measure-contrast-live.cjs` — a real login, a real session, and the
host's own server-side theme/direction switch, reading composited colours off the painted page.

```
before:  32 measured, 14 below AA
after:   32 measured,  0 below AA
```

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
- **P2-03's list and editor, P2-04's list and detail page, all of P2-05, P2-06's notifications and
  preferences** are not built, so there is nothing to verify.
