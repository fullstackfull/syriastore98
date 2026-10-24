# Phase 02 — test evidence

Every claim below names the command, the engine it ran on, and the output. A claim with no command
under it is not in this file. Where something could **not** be run, that is stated instead of being
worked around.

---

## 0. What has NOT been run, and why it matters most

**The PHPUnit suites have not run in this container at all.** Not the Unit suite, not Feature, not
Contract, and not `.github/scripts/assert-known-test-outcomes.php`.

```
composer install
  ...
  In Git.php line 602:
  Failed to execute git clone --mirror -- https://git.yoomoney.ru/scm/sdk/yookassa-sdk-validator-php.git
  fatal: unable to access 'https://git.yoomoney.ru/...': CONNECT tunnel failed, response 403
```

`yoomoney/yookassa-sdk-php` and `yoomoney/yookassa-sdk-validator` are production dependencies in
`composer.json`, and the environment's network policy denies that host. The proxy's own
documentation is explicit that a 403 is a policy denial to report rather than route around, so it
was reported and not worked around. **No `vendor/` therefore exists, and nothing in this phase is
backed by the PHPUnit suites.**

What that means for reading the rest of this file: the evidence here is a contrast checker, a real
browser, `php -l` and `node --check`. That is genuinely strong for presentation, RTL, theming and
accessibility — it is a browser rendering the real stylesheets — and it says **nothing** about
routing, authentication, tenancy enforcement at runtime, the DataTables stack, or the controller
queries. Those remain unverified here.

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
75 PASSED, 0 FAILED
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

## 5. Visual evidence — 16 screenshots, reviewed

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
