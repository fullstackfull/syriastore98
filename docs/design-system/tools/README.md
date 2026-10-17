# Design system verification tools

The checks behind the numbers quoted in [`../README.md`](../README.md). They are run by hand, not
wired into CI — Sprint 01 deliberately changed no CI configuration.

| File | What it does | Needs |
|---|---|---|
| `check-contrast.py` | Resolves every `--ss-*` `var()` chain and computes real WCAG 2.1 ratios for 20 foreground/background pairs, in light and dark separately. | Python 3 only |
| `render-preview.php` | Renders a Blade view outside Laravel, with a stand-in layout that mirrors `layouts/app.blade.php`'s real chrome, the real `public/` stylesheets and the real `resources/lang/<locale>.json` strings. | PHP 8 |
| `preview-server.php` | Router for `php -S`: serves rendered previews while asset requests fall through to `public/`. Also provides an iframe wrapper for true mobile viewports and an element-level overflow probe. | PHP 8 |
| `browser-checks.cjs` | Keyboard traversal, focus-ring presence and contrast, tab order, accessible names, horizontal overflow at 7 widths, RTL mirroring, preserved-functionality contract, permission gating, and a before/after comparison. | Node + Playwright + Chromium |

## Running them

Contrast — no setup:

```bash
python3 docs/design-system/tools/check-contrast.py public/css/store-syria-ds.css
```

Browser checks — render the previews, serve them, then drive them:

```bash
export SS_PREVIEW_DIR=/tmp/ss-preview && mkdir -p "$SS_PREVIEW_DIR"

php docs/design-system/tools/render-preview.php \
    resources/views/attribute_option/index.blade.php en full "$SS_PREVIEW_DIR/new-en-full.html"
# ...repeat for ar/full, en/empty, ar/empty, en/noperm, and for the pre-sprint view
# if you want the before/after section to run

php -S 127.0.0.1:8099 -t public docs/design-system/tools/preview-server.php &
node docs/design-system/tools/browser-checks.cjs
```

`SS_CHROMIUM` overrides the browser path; `SS_PREVIEW_BASE` overrides the URL.

## What these do and do not prove

`render-preview.php` implements only the Blade subset these screens use, and it stubs `route()`,
`asset()`, `__()`, the Laratrust `@permission` directive and the `Form` facade. It is a **preview
harness, not the application**.

**Covered:** the view's own markup, the real stylesheets, the real translation strings, real
`dir` handling, real focus and keyboard behaviour, real layout at real viewport widths.

**Not covered here:** routing, authentication, tenancy, subscription entitlement, the yajra
DataTables stack, and the controller queries behind each screen. Those need a booted Laravel
application and the PHPUnit suites.
