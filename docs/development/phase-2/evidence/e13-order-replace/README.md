# E13 / D-32 — the cancel-and-replace control, driven in a real browser

**9 configurations, 211 checks, 0 failures.** Log: [`browser-9-configs.txt`](browser-9-configs.txt).
Screenshots: [`../../screenshots/e13-order-replace/`](../../screenshots/e13-order-replace/) (19 PNGs).
Harness: [`../../tools/check-order-replace-live.cjs`](../../tools/check-order-replace-live.cjs),
committed and re-run from the repository to prove it reproduces.

## Why it exists

`OrderReplacementTest` (17 cases) proves the operation obeys D-32, and
`OrderReplacementEntryPointTest` (7 cases) proves the control is offered or withheld for the right
reasons. Neither can see a rendered page. This is a **destructive, money-adjacent merchant action**
that cancels a real order and issues another, and it shipped with no browser evidence: nothing had
measured that it is legible in Arabic, legible in dark mode, fits a phone, is keyboard-reachable,
or — the one that matters most — that clicking it opens a confirmation instead of cancelling the
order on the first click.

## Running it

The preview server must be up with the documented invocation (`php artisan serve` is **not** a
substitute — it routes static assets through Laravel and the page then loads without jQuery, so the
confirmation never appears):

```bash
cd public && DB_CONNECTION=sqlite DB_DATABASE=$PWD/../database/p2dev.sqlite \
  CACHE_STORE=file SESSION_DRIVER=file PHP_CLI_SERVER_WORKERS=10 \
  php -S 127.0.0.1:8899 -t . ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
```

```bash
node docs/development/phase-2/tools/check-order-replace-live.cjs
```

## The nine configurations

| | locale | direction | theme | viewport |
|---|---|---|---|---|
| 1–2 | en | ltr | light, dark | 1440×1000 |
| 3–4 | ar | rtl | light, dark | 1440×1000 |
| 5–6 | en | ltr | light, dark | 390×844 |
| 7–8 | ar | rtl | light, dark | 390×844 |
| **9** | **en** | **rtl** | light | 1440×1000 |

## What is proved in every configuration

The control is present on a replaceable order and **actually visible once the menu is open**; it
carries the replacement route rather than only its CSS class; it has an accessible name; it is
keyboard-reachable; its icon is hidden from assistive tech; its label reaches **9.1177:1**; it sits
inside the viewport and the page does not scroll sideways. Clicking it **opens a confirmation and
sends nothing**; Escape dismisses it and sends nothing. It is **withheld** on a cancelled order
(ORD-2045) and on a dispatched one (ORD-2044). In Arabic the title, body and label are Arabic and
not the English literal.

Once, in configuration 1, the confirmation is **accepted for real**: exactly one POST is sent, a
replacement row exists and is linked, and the original is cancelled. The fixture is then put back by
[`../../tools/restore-p2dev-order.php`](../../tools/restore-p2dev-order.php) — without that, every
later run would open a cancelled order where the control is *correctly* absent, and the harness
would report passes for the wrong reason.

**Not covered here, stated rather than implied:** the PERMISSION gate. Every user in
`seed-p2dev.php` holds `Manage Order`, so a browser absence case would need the fixture mutated, and
that gate already has seven PHPUnit cases plus `OrderActionPermissionCensusTest`.

---

## Two findings from building this, both about the evidence rather than the feature

### 1. `set-preview-state.php` must be called AFTER login, and every other harness here calls it before

`login()` ends with `/change-language/<locale>`, and `LanguageController::changeLanquage()` does not
only set `users.language` — it `updateOrCreate`s the store's `SITE_RTL` to `'on'` for `ar`/`he` and
**`'off'` for everything else** (`:130-156`). A harness that writes the store state *before* logging
in therefore has its direction silently overwritten by the language switcher.

**Measured, not reasoned.** With the old ordering this harness asked for `en`+`rtl` and the page
answered `dir=ltr`. With `SITE_RTL=on` written after login and no language call, the same page
answers `dir="rtl" lang="en"` and loads `style-rtl.css` — so the per-store setting does work, and
the earlier reading was the ordering.

**It did not corrupt any existing ar/rtl or en/ltr evidence**, because there the switcher and the
setting agree on the same value. It can only bite a MIXED configuration — which is exactly what a
mixed configuration exists to test, so the flaw would have been invisible for as long as nobody
tried one.

**This corrects the reasoning behind `evidence/f184/README.md`'s fifth configuration**, which asks
for *an Arabic store with `SITE_RTL` off* on the premise that direction comes from the store setting
and not the locale. On the admin layout that state is **unreachable**:
`layouts/app.blade.php:48-50` forces `SITE_RTL = 'on'` in-view whenever the user's language is
`ar` or `he`, so an Arabic merchant is always RTL regardless of the stored value. The reachable
mixed state is the inverse — an English merchant in an RTL store — which is configuration 9 here.
Direction and language are coupled in **two** places, the controller and the view, and only one of
them can be overridden by writing the setting.

### 2. The dashboard's chart XHR answers 500 on the SQLite preview fixture (B5, already recorded)

Nine configurations initially failed `no page errors or 5xx` on
`POST /top-brand-category-chart`. The exception is
`SQLSTATE[HY000]: no such function: SUBSTRING_INDEX (Connection: sqlite)` —
`HomeController::top_brand_category_chart()` builds its joins on MySQL's `SUBSTRING_INDEX` and
`CURDATE`. **This is B5**, recorded in the ledger (`:153`) and the handoff (`:443`), and it is a
preview-environment limitation rather than a production defect:
`SELECT SUBSTRING_INDEX('a,b,c', ',', 1)` runs on this container's MariaDB 10.11.14, measured.

It reaches a harness about the *order* page because the post-login redirect lands on `/dashboard`
(`RouteServiceProvider::HOME`) and the response listener spans that navigation. **Exempted by URL,
not by dropping the assertion** — any other 5xx still fails the run.
