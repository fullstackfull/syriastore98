# Phase 02 — handoff

**State: Phase 02 engineering is PARTIALLY COMPLETE.** Read §1 before anything else.

**Branch:** `claude/store-syria-p02-merchant-experience` in both repositories, mirrored to
`claude/brave-cori-byyw4s` at the same SHA. Nothing merged to `main`, nothing deployed.

---

## 1. The honest state, in one table

| Slice | Workstream | State |
|---|---|---|
| P2-00 discovery and reuse audit | — | **done** |
| P2-01 shared layout and core components | A, F | **done except navigation** — the menu registry still emits an HTML string, so the sidebar zones and workspace chip are not built |
| P2-02 merchant dashboard | C | **done except the chart** and the recent-orders/top-products blocks |
| P2-03 products | D | **not started** |
| P2-04 orders | E | **partial** — the status breakdown is honest and reconciles; list, detail, Kanban, saved views and the preview panel are not built |
| P2-05 common merchant screens | J | **not started** |
| P2-06 search, notifications, preferences, onboarding | G, H, I | **not started** |
| P2-07 Flutter | K | **partial** — the P0 RTL defect, the vendor-host leak, accessible names and the parity matrix are done; the RTL visual pass and the dashboard honesty pass are not |
| P2-08 integration and acceptance | — | this document and the evidence file |

**Do not read the passing suites as "Phase 02 is finished".** They say that what *was* built is
verified. More than half the workstreams have not been started.

## 2. What blocks the rest, and what merely remains

Two different things, and conflating them is how a handoff lies.

### 2a. Genuine external blockers

| # | Blocker | What it stops |
|---|---|---|
| **B1** | `composer install` cannot complete. The environment's network policy denies **`git.yoomoney.ru`** (403 at the proxy) and `yoomoney/yookassa-sdk-php` + `yoomoney/yookassa-sdk-validator` are **production** dependencies. | **No `vendor/`, so no PHPUnit at all.** Unit, Feature, Contract and the known-outcome gate have not run once. It also blocks anything needing a booted application: the yajra DataTables stack, routing, runtime tenancy. **Fix: add that host under Allowed domains in the environment's Network access settings.** |
| **B2** | No device, emulator or Flutter rendering. | No screen of the Flutter app has been *seen*. The RTL visual pass that the P0 fix unblocks cannot start here. |
| **B3** | Syncfusion charts licence. | An owner/commercial decision. Blocks release of the statistics screen, not engineering. |

### 2b. Deliberately not done, with reasons

- **The shared `<x-datatable>` wrapper is untouched.** 65 merchant screens use it, and the preview
  harness explicitly cannot drive the yajra DataTables stack. Changing it blind would put every
  list screen in the product at risk with no way to verify. It needs B1 fixed first. **This is the
  single biggest piece of remaining Workstream D/E/J work and it is gated on B1.**
- **Workstream G (preferences) and H (search) need server work that cannot be tested here.** A
  scoped, permission-filtered search endpoint is exactly the shape CLAUDE.md warns about, and
  preference persistence needs a schema change. Both want B1 fixed and a tenant/authz review with
  runnable tests.
- **The Flutter dashboard honesty pass (G3 in the parity matrix)** is partly a server change —
  `/dashboard` should not return a figure the caller may not see — which makes it an API change and
  therefore an owner escalation, not a quiet edit.

## 3. What a new session must not assume

1. **`main` still contains the 20 MB production database dump.** This branch deletes it from the
   tree; that is **not** remediation — the blob stays fetchable from history, and purging it
   rewrites `main`, which is the owner's call. `P1-SEC-40` (#120) is open and Phase 02 claims
   nothing about it.
2. **Do not merge `claude/store-syria-p01b-design-foundation`.** Measured: it would delete ~148 k
   lines including the whole Phase 01 CI and security apparatus. Its design content is already in
   this baseline, verified blob-for-blob.
3. **The preview harness is not the application.** It proves markup, stylesheets, translations,
   `dir` handling, focus and keyboard behaviour at real viewport widths. It proves **nothing**
   about routing, authentication, tenancy enforcement at runtime, or DataTables.
4. **`resources/lang/ar.json` has 17 duplicated keys.** Re-serialising it silently drops the
   earlier of each pair — it changed `"Symbol"` once during this phase before being caught. Edit it
   as text, one exact line at a time, and verify `changed=0 removed=0` for everything you did not
   intend.
5. **`orders.delivered_status` is one column carrying four concepts** (ledger F0). Its migration
   comment documents four of the nine values it really takes.
6. **A `@permission` is not a `@can`.** Laratrust resolves through `hasPermission()`; `@can` goes
   through Laravel's Gate and is **always false** here.

## 4. Owner decisions waiting

| # | Decision | Why it is yours |
|---|---|---|
| 1 | **The eight Arabic corrections need a native Syrian reviewer.** `Order` read "النظام" (the system) and `Orders` read "أوامر" (military commands) across the merchant surface. They are now `الطلب` / `الطلبات`. | User-facing content. The commit asserts only that the previous strings denoted the wrong thing. |
| 2 | **Unblock `git.yoomoney.ru`, or decide to drop the YooKassa dependency.** Phase 01 retired the *provider* but kept the *package*. | Dependency change with a release consequence. |
| 3 | **Syncfusion licence** for the Flutter statistics screen. | Commercial. |
| 4 | **Splitting `delivered_status`** into order / payment / fulfilment. | Schema and commerce — Phase 04. |
| 5 | **`P1-SEC-40` / #120** — the dump in `main`'s history. | Requires rewriting `main`. |
| 6 | Whether to **de-duplicate `ar.json`'s 17 duplicated keys**. | Changes strings nobody asked to change. |

## 5. How to verify what is here

```bash
# contrast, light and dark -- no setup needed
python3 docs/design-system/tools/check-contrast.py public/css/store-syria-ds.css

# render the previews, serve them, drive them
export SS_PREVIEW_DIR=/tmp/ss-preview && mkdir -p "$SS_PREVIEW_DIR"
#   dashboard: docs/development/phase-2/tools/previews/dashboard-{after,before}.blade.php
#   components: docs/development/phase-2/tools/previews/components.blade.php
#   pilot: resources/views/attribute_option/index.blade.php
#   locales en|ar, states full|empty|noperm, themes light|dark
php docs/design-system/tools/render-preview.php <view> <locale> <state> "$SS_PREVIEW_DIR/<name>.html" [light|dark]

php -S 127.0.0.1:8099 -t public docs/design-system/tools/preview-server.php &
node docs/design-system/tools/browser-checks.cjs          # 66, pre-existing
node docs/development/phase-2/tools/browser-checks-p02.cjs # 100, Phase 02
```

Flutter (needs an SDK with Dart ≥ 3.11.0; 3.41.2 was used):

```bash
flutter pub get      # NOT --enforce-lockfile: see B2 in the evidence file
flutter analyze --no-fatal-infos      # 246 info, 0 warning, 0 error
flutter test                          # 1189 passed
```

**`php artisan test` cannot be run until B1 is fixed.**

## 6. Where the detail is

| | |
|---|---|
| scope and refusals | `PHASE-02-SCOPE.md` |
| what was reused and why | `PHASE-02-REUSE-INVENTORY.md` |
| per-task state, blockers, findings | `PHASE-02-TASK-LEDGER.md` |
| web ↔ Flutter parity, 91 endpoints | `PHASE-02-WEB-FLUTTER-PARITY.md` |
| every command run and its output | `PHASE-02-TEST-EVIDENCE.md` |
| the design decisions, written before the code | `design/P2-DESIGN-FOUNDATION.md` |
| screenshots | `screenshots/p2-01-components/`, `screenshots/p2-02-dashboard/` |

## Deploy note added by P2-01-11

`config/merchant-navigation.php` is a **new** config file, and `generateMenu()` reads it through
`config()` with a `false` default. On any environment that has run `php artisan config:cache`, the
cache must be rebuilt or **zones silently stay off** — which looks exactly like the kill switch
working, not like a deployment step that was missed.

`public/css/store-syria-ds.css`, `public/css/custom.css`, `public/css/rtl-custom.css` and
`public/js/store-syria-nav.js` all changed; the cache-busting token moved `p02-1` → `p02-2` in all
six places that reference them.

