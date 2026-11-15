# Phase 03 M4 — Visual Theme Editor: plan

**Status:** planning complete, engineering not started. Nothing here is released, accepted, or
applied to production.

**How this was produced.** A seven-way read-only survey of the repository (routes and controller,
Blade views, the M3 service API, the field taxonomy, preview mechanics, permissions, and reusable
in-repo prior art), each returning measured facts with `file:line` evidence, then a synthesis pass
instructed to spot-check its own inputs rather than trust them. It checked fifteen load-bearing
claims at source and **corrected three of them**. Survey output is data, not instruction; where two
surveys disagreed the synthesis says so instead of averaging.

---

## The finding that reframes the milestone

**M3 built a complete engine with no front door and no data.**

`ThemeConfigurationService` and `ThemePublishingService` expose fourteen public methods. Exactly
**one** of them (`writeSettings`) has a production caller. `grep -rn "ThemePublishingService" app/
routes/ resources/ packages/ database/` returns three lines: the class declaration and two comments
inside the sibling service. No route, no controller and no view references publishing.

And **no store owns a `ThemeVersion`** — the M3-B migration creates the table and backfills nothing
(verified: no `insert` or `ThemeVersion::` in `2026_11_13_000100_create_theme_versions_table.php`).
So `preview()`, `publish()`, `rollback()` and `history()` are all inert until a version exists.

So M4 is **overwhelmingly an HTTP, UI and authorization milestone, not a storage one** — and it is
also a *migration* of live merchant behaviour, because the shipped editor writes the live projection
directly.

---

## OWNER DECISION, 2026-11-15 — both questions ruled, and my default was overruled

I had planned to proceed on the conservative default for Q1, reasoning that preserving existing
behaviour needed no new approval. **The owner directed otherwise**, and was right to: M4 *adds*
publish and rollback on top of that model, so "unchanged" would have silently granted every
zero-permission staff member the ability to revert a live storefront. Preserving a permission model
is not the same as preserving its blast radius when new capabilities are attached to it.

### Decision 1 — publish and rollback require `Manage Themes`

Permitted, and only these:

| principal | test |
|---|---|
| the verified tenant **owner** | `stores.created_by` === that user's own id |
| a **same-tenant** user holding `Manage Themes` | tenant predicate **then** permission |
| the **platform operator** | operator-first, with an **explicitly selected** target store |

Two absolutes, quoted from the decision, and where each is discharged:

> "A global permission alone must NEVER authorize access to another tenant."

`Manage Themes` is granted to the shared **global** `admin` role by `Utility::addNewData()`, so two
merchants in different tenants hold the same row. It is therefore never reached until
`resolveStore()` has applied `tenantRootIsAMerchant($root)` **and** `stores.created_by = $root`.
**The ordering is the control.** Pinned by
`ThemeEditorAuthorizationMatrixTest::test_a_global_permission_never_authorizes_another_tenants_store`.

> "Do not infer tenant ownership from `type=admin` alone."

`isTenantOwner()` compares `stores.created_by` to the user's own id and never reads `type` as a
grant. See the honest-scope note below.

**No `Publish Theme` permission**, per the decision. The codebase has been bitten once by gating on
a name seeded as a row and granted to nobody — a permanent 403 for everyone, operator included.

### Decision 2 — close the GET/POST inversion

One boundary on all **eight** theme routes, both verbs, via `ThemeEditorAccess` (`theme.editor`).
Zero-permission staff can no longer modify a live storefront; the owner regains their own editor;
the operator never defaults to store 1. `theme.file.get` — which had **no authorization of any
kind** — is inside the group.

### Future delegation, recorded now so it is not designed away

The owner requires that tenant-authorised staff eventually prepare and preview **drafts** without
publishing, through *deliberately separated* draft/preview authorization — not by reopening
unrestricted live-write endpoints. The gate is already shaped for it: `allows()` answers tenant
membership (the future draft/preview rule) and `allowsManagement()` answers the live-write and
publish rule. Two methods, two questions, named so they cannot be swapped by accident.

### What the removal checks actually proved, including the one that proved less than it looks

| mutation | result |
|---|---|
| permission limb removed from `allowsManagement()` | **2 tests fail** |
| permission hoisted **above** `resolveStore()` (the named cross-tenant hole) | **1 test fails** — and *only* the gate-level one |
| `isTenantOwner()` relaxed to `type === 'admin'` (the forbidden inference) | **nothing fails** |

The second row is the finding. All **twelve** HTTP tests passed with the hole open, because no
current route lets a caller name a store — the URL carries the theme and the store comes from the
caller's own `current_store`. The hole is unreachable *today* and becomes reachable the moment any
route accepts a store identifier, which M4 goes on to add. **An authorization rule tested only
through the routes that currently exist is tested against today's call sites, not against the rule.**
The gate-level test exists because of that.

The third row is honest scope rather than a gap: `isTenantOwner()` is unreachable for a foreign
store, because `resolveStore()` settles tenancy before it runs. Its conditions are defence in depth,
and the code now says so rather than letting a green suite imply they are load-bearing.

### Not asked, because the code already settles it

Operator access. `ThemeEditorGate::allows()` admits the operator on every write and
`storeIdForSelfService()` refuses to guess a store, so the intended shape is *"the operator may act,
but must name the store, and must be attributed."* M4-2 implements it; M4-6 adds the attribution.

---


## 0. What I spot-checked myself (and what it changed)

I read fifteen of the surveys' load-bearing claims at source. Confirmed:

- **The eight routes and their guards** — `routes/web.php:1441-1448`, no `->where()` constraints, no permission middleware. All four GET handlers open with `if (auth()->user() && auth()->user()->isAbleTo('Manage Themes'))` (`ThemeCustomizeController.php:20, :43, :55, :263`), and `themeCustomize()` still resolves the store with `getThemeSetting(getCurrentStore(), $theme)` at `:61`.
- **`ThemeEditorGate`** — four public methods + `const PERMISSIONS`; `allows()` is operator-first with **no permission limb at all** (`:207-244` explains the deletion); `storeIdForSelfService()` refuses an operator with empty `current_store` (`:285-292`).
- **`ThemePublishingService` has zero production callers.** `grep -rn "ThemePublishingService" app/ routes/ resources/ packages/ database/` returns three lines: two comments inside `ThemeConfigurationService` and the class declaration itself.
- **The `@default` arm is genuinely empty** — `customize.blade.php:630-631` is `@default` immediately followed by `@endswitch`.
- **`order.blade.php:89` loads jQuery UI from `https://demo.workdo.io/...`**, and `grep -rn "iframe" resources/views/theme_customize/ themes/*/views/front_end/layouts/` exits 1 — no first-party preview iframe exists.
- **The composer seam** — `ViewServiceProvider.php:23` binds the four names, recomputes `$themeSettings` at `:65`, and the latent `request()->route('theme')` branch at `:50-55` assigns the unvalidated route string to `theme_id` and resolves the store via `getCurrentStore()`.
- **The XSS operator trap** — `XSS.php:55` is `Auth::user()->type == 'super admin'` and redirects to `LaravelUpdater::welcome` on any pending migration.
- **`Manage Themes` is absent from `$superadminNewPermission`** (I read all 29 entries, `Utility.php:3296-3325`), present at `:3008` and `:3259`, and **created nowhere in `database/`**.

Three findings I'd flag as corrections or additions to the surveys:

1. **Survey 1 and Survey 6 disagree about store 1, and Survey 1 is right.** `PermissionTableSeeder.php:40-50` creates store `my-store` with `'created_by' => $superadmin->id` and then sets `$superadmin->current_store = $superAdminStore->id`. On a clean seed store 1 is the **platform operator's**, not a merchant's. `ThemeEditorGate.php:286-287` says the opposite in a comment. **The comment is wrong; the code is right** — the gate refuses to guess either way, which is correct whatever store 1 turns out to be on a given database. Do not "repair" the code to match the comment, and do not build M4's threat model on either docblock.
2. **The versions migration does not backfill.** `grep -n "insert\|ThemeVersion::" database/migrations/2026_11_13_000100_create_theme_versions_table.php` returns nothing. Every existing store has **zero** `theme_versions` rows. This is the single most important sequencing fact in the plan — `preview()`, `publish()`, `rollback()` and `history()` all require a version id that does not exist yet.
3. **The retracted claim survived in more places than the synthesis found, and in one fewer than it said.** It flagged `ThemeCustomizeController:159` and `:333`; `:159` already reads "in the fixture" and is fine, so that half is an overstatement. But sweeping the repo for the phrase found **two further unqualified copies it missed** — `ThemeEditorGateTest.php:28` ("measured at M3-A, `Manage Themes` is held by NO role and NO user") and `ThemeEditorGate.php:83` itself, in the very file whose `allows()` at `:225-235` carries the retraction. So the correction was made once at M3-C and propagated to none of the three places that restate it. All three are now fixed. A reader of any one of them alone could have deleted `changeOrder()`'s check as dead code and opened the route.

Also verified by absence, with the search quoted: `grep -rln "ThemeCustomizeOrder\|theme_customize_orders" themes/ resources/views/front_end/` → exit 1 (no storefront consumer); `grep -rn "ss-tabs" resources/ public/js/` → exit 1 (CSS only, no consumers, no behaviour); `grep -c keydown` on both sortable views → `0` and `0`.

---

## 1. What M4 must deliver

A merchant-facing **theme editor experience** on the merchant web admin, bilingual AR (RTL) and EN (LTR), with five capabilities:

- **Sections** — edit a theme's settings grouped the way the theme declares them (page → section → field), instead of the current flat four-page-load walk.
- **Preview** — see an unpublished configuration rendered as the storefront will render it, without it being visible to shoppers and without touching `theme_customizes`.
- **Publish** — promote a draft to live, atomically, with conflict detection.
- **Rollback** — return to a previous published configuration, with history the merchant can read.
- **Permissions** — a coherent, operator-aware authorization model applied uniformly to reads and writes.

The engine for four of those five already exists and is unused. **M4 is overwhelmingly an HTTP, UI and authorization milestone, not a storage one.** `ThemeConfigurationService` and `ThemePublishingService` give M4 fourteen public methods; exactly one of them (`writeSettings`) has a production caller.

Two facts set the shape of everything below:

- **M3 built a complete engine with no front door and no data.** No route, no controller, no view references publishing; and no store owns a version to publish.
- **The shipped editor is M0-era and writes live.** `themeCustomizeUpdate()` calls `writeSettings()`, whose own docblock (`ThemeConfigurationService.php:93-100`) says it writes the live projection with no draft indirection. So M4 is also a **migration** of an existing merchant-facing behaviour, not just an addition.

---

## 2. Slices

Dependency order. Each is independently testable and independently revertable.

### M4-0 — Regression floor for the four GET routes

**Changes:** no production code. New HTTP tests only.
**Files:** `tests/Feature/Theme/ThemeEditorReadRoutesTest.php` (new).

Today **no test drives any GET theme route** — both surveys measured `grep -rn "get('/theme-customize\|get(route('theme" tests/` → no matches, and all 11 methods of `ThemeCustomizeWebRoutesTest` are `->post()`. Every view change in M4-7 would otherwise start from zero cover.

**What the test proves:** each of `theme.index`, `theme.pages`, `theme.customize`, `theme.customize.order` renders its real content for a merchant holding `Manage Themes`; refuses one who does not; and the operator premise holds.

**What would make it pass for the wrong reason:**
- The **XSS operator redirect**. Every WorkDo module migration is permanently pending in a test run (no `add_on_managers` row), so any `type == 'super admin'` principal is redirected to `LaravelUpdater::welcome` before the controller runs, and every operator assertion passes without the handler executing. Reuse `letTheOperatorReachTheController()` and the premise test at `ThemeCustomizeWebRoutesTest.php:110` / `:143` — that file exists precisely because its first version passed against the completely unguarded pre-M3-C controller.
- **`assertStatus(200)` on an empty gallery.** `index.blade.php:19-22` `@continue`s past any theme not in the enabled-`Addon` list, and renders no empty state. A fixture without `AddonSeeder` gives HTTP 200 and a blank page. Assert a named theme's card is in the body.
- **`assertStatus(200)` on empty chrome.** `layouts/app.blade.php` `@yield`s and has zero `$slot` references, so a wrongly-shaped view returns 200 with nothing in the content area. Assert on content, never on status alone.

### M4-1 — Move the read path onto the gate; contain the file paths

**Changes:** `themeCustomize()` off `getCurrentStore()` onto `ThemeEditorGate` (the last of the eight still using it). Validate `{theme}` against `ThemeFieldCatalog::themes()` and `{slug}` against `pages.json` **before** building any path — `ThemeFieldCatalog.php:123` applies `basename()` containment to `{theme}`, the controller applies none to either. Answer a missing file with a refusal, not a warning-promoted 500. Fix `changeOrder()`'s dereference of `$theme_json` at `:268`, a variable that method never assigns.
**Files:** `app/Http/Controllers/ThemeCustomizeController.php`.

**What the test proves:** the editor form pre-fills from the caller's own store; an unknown `{theme}` or `{slug}` is refused; omitting `{slug}` does not build `.../theme_json/.json`.

**What would make it pass for the wrong reason:**
- **Driving only a merchant takes the safe branch.** The store-1 read at `:61` is masked today by the `isAbleTo('Manage Themes')` check at `:55`, which refuses the operator first. A merchant-only test proves nothing — this is the same shape as the `custom.auth` trap where a customer token never reaches the attacker branch. Drive an operator with empty `current_store` and a second tenant's merchant.
- **The assertion value coincides with a theme default.** `getThemeSetting()` merges `default_data/settings.php` under stored values for every key, so "the right value is present" can be true without any store being read. Pick a fixture value that no theme default carries.
- **`ThemeCustomize` is `Cachable` under `CACHE_STORE=array`.** A fixture written with `DB::table()` leaves the read serving the pre-write row for the rest of the test. Write through Eloquent.

### M4-2 — Decide and pin the authorization model, before any new route exists

The guard is **inverted and measured**: the four GETs require `Manage Themes`; the three writes require only `ThemeEditorGate` ownership. A `type`-`staff` user with no roles POSTs `/theme-customize/stylique/update` and the value lands (`ThemeCustomizeWebRoutesTest.php:177-186`). So staff cannot open the editor but can drive every write by direct POST. Meanwhile the platform operator holds no `Manage Themes` (verified absent from the 29-entry super-admin list) and so is refused all four screens, while the gate admits them on every write — there is no supported way for support to look at what they are allowed to change.

**Changes:** one route middleware, operator-first then `ThemeEditorGate::allows()`, applied to **all** theme routes old and new so GET and POST agree. Model it on `FinancePermission.php:73-108`, which takes permission names as typed route parameters and fails closed on an empty list. Use `UserPolicy::isOperator()` for the operator test — **not** CLAUDE.md's `strtolower(trim(...))`: the gate and the policy deliberately `rtrim(' ')` to match MySQL's `utf8mb4_unicode_ci` PAD SPACE, documented at `ThemeEditorGate.php:303-316` and `UserPolicy.php:169-186`, and `trim()` would be *wider* than the engine and admit a `' super admin'` row SQL refuses. Equally, do not copy `Gate::define('finance')`'s shape (`AuthServiceProvider.php:118-121`), which uses a strict un-normalised `===` and would silently refuse an operator spelled `'Super Admin'`.
**Files:** new `app/Http/Middleware/ThemeEditorAccess.php`; `routes/web.php:1441-1448`; `ThemeCustomizeController`.

**What the test proves:** a matrix — operator (with and without a named store), tenant owner, staff of that tenant, another tenant's merchant, unauthenticated — × every theme route × both verbs.

**What would make it pass for the wrong reason:**
- **`@can()` and `$user->can()` are always false** (`permissions_as_gates => false`). A negative assertion written with either passes for every principal, including ones who should be allowed.
- **A bare `isAbleTo()` refuses the operator**, so "the operator is refused" passes without the gate ever being consulted.
- **Single-verb coverage.** The inversion is the defect; a test that only POSTs (as the whole existing M3-C suite does) proves nothing about the GET side, and vice versa. Each principal must be driven against both.
- The XSS redirect again, for every operator row in the matrix.

### M4-3 — A gated read API: live settings, and a field-metadata tree

Two additions, because the editor cannot be built from what exists:

**(a) `ThemeConfigurationService::liveSettings(mixed $storeId, string $themeId, ?Authenticatable $user): ?array`.** The only key-complete read of live configuration today is the global helper `getThemeSetting($storeId, $theme)` (`helper.php:3538`), which takes no user and performs no authorization at all. Without this, M4's form initialises from an unauthorised helper.

**(b) A metadata reader** (new `App\Support\Theme\ThemeFieldTree` or equivalent) keeping page → section → field with label, placeholder, `rows`, type, declared default and the repeater's `fields` list. `ThemeFieldCatalog` flattens the section into the name (`:160`) and skips `pages.json` (`:134`) by design, and `editableFields()`'s shape must not change — `ThemeSettingValidator` depends on it at `:122` and `:170`.

It must handle four things explicitly: `homepage.json`'s **second, incompatible schema** (`section_name`/`field_name`, types spelled `photo upload` and `text area`) which contributes zero fields yet is read at runtime by `Product.php:586` and `ApiController.php:5920`; `pages.json` being an **incomplete index** (all three themes hide `homepage` and `terms-condition`); the **two duplicate names per theme** that last-wins silently at `ThemeFieldCatalog.php:162`; and the gate-scoped option sources for `menu` and `button_link`, which today come from `getWebNavMenu()` and `getPredefinedLinks()`, both resolving through `getCurrentStore()` (`helper.php:3533`, `:3881`).

**What the test proves:** for each of the three themes the tree reproduces exactly `editableFields()`'s name set, and *names* each duplicate rather than collapsing it; `liveSettings()` refuses another tenant.

**What would make it pass for the wrong reason:**
- **"Every field has a label" is vacuous** — every setting node in all three themes has one. Assert the duplicates are surfaced; that is the thing that silently breaks a grouped editor, by rendering one stored key in two places where whichever the merchant edits last wins.
- **Key-completeness makes presence assertions vacuous.** `liveSettings()` and `preview()` merge defaults under overrides, so `array_key_exists()` is true for ~500 keys regardless of whether anything was read. Assert values, and values that differ from the default.
- **Single-theme coverage.** greentic declares no `is_order` at all and techzonix has a different section set. Drive all three.

### M4-4 — Draft lifecycle over HTTP, and the first version

**The sequencing constraint:** no store owns a `ThemeVersion`, and the migration does not backfill (verified). Preview, publish, rollback and history are all inert until one exists. **Create it lazily** — capture the live projection as the store's first version on first draft save or first editor open. Do **not** write a backfill migration: that is a persistent migration against production data, which CLAUDE.md §2 reserves for the owner.

**Changes:** routes and controller methods for `saveDraft`/`updateDraft`/`deleteVersion`, plus a **new gated listing method** on the service — neither service exposes anything returning more than one version, and `ThemeVersion::historyFor()` (`:92`) exists but is ungated and uncalled, so a controller calling it directly would have no authorization and no global-scope backstop (`ThemeVersion` is in neither `scoped_models` nor `Cachable`; its own docblock at `:27-28` states `ThemeVersion::find($request->version_id)` returns another tenant's payload).
**Files:** `ThemeCustomizeController` or a new `ThemeVersionController`; `ThemeConfigurationService`; `routes/web.php`.

**What the test proves:** a draft save leaves `theme_customizes` **unchanged** and creates a `theme_versions` row with status `draft`; updating a live version is refused; deleting the live version is refused.

**What would make it pass for the wrong reason:**
- **An empty draft succeeds.** `saveDraft()`/`updateDraft()` guard on `rejected !== [] && accepted === []` (`:232`, `:405`), so posting `[]` — validating to nothing with no errors — creates a draft with payload `'{}'` and returns a `ThemeVersion`. A test that posts a thin payload and asserts "a draft was created" passes while storing nothing. The damage only surfaces later as "the snapshot is empty; there is nothing to publish".
- **`updateDraft()` replaces the payload wholesale** (`:418`) while `writeSettings()` upserts. A test that posts one section and asserts that section survived passes; the silently dropped keys are the defect. Assert the *other* sections' keys are still in the draft.
- **`empty($written)` conflates refusal with nothing-accepted.** `writeSettings()` returns `null` for refused and `[]` for all-rejected (`:119` vs `:141`). Use `=== null`. The Flutter call site at `DashboardController.php:9061` uses `!empty()` — correct there because it tests success, wrong to copy for diagnosing failure.
- **Scoped-vs-unscoped absence assertions.** `ThemeCustomizeOrder` **is** in `scoped_models`; `ThemeCustomize`, `ThemeVersion`, `ThemePublicationEvent` and `Store` are not. A plain `Model::find()` answers against whichever tenant resolved for the first and against the whole table for the rest, so "it is gone" can pass either way. Use `withoutGlobalScopes()` — the pattern at `ThemeCustomizeWebRoutesTest.php:157-163`.
- **Cache-flush assertions are meaningless in the Feature suite.** Under `RefreshDatabase` the transaction level never returns to 0, so `DB::afterCommit` callbacks never run at all (measured, `ThemeWriteUnderCallerTransactionTest.php:42-44`). That evidence goes in `tests/Support/Security/bin/` against real MySQL.

### M4-5 — Preview

The slice with the most measured constraints. All of these are load-bearing:

- **The composer wins.** `ViewServiceProvider.php:23` binds `front_end.layouts.app`, `main_file`, `front_end.pages.*`, `front_end.Auth.*`; it recomputes `$themeSettings` at `:65`, and `View::with()` array_merges over controller data *after* the controller set it. Passing a draft array from a controller is silently overwritten. **The seam must be inside `getThemeSetting()` or inside the composer** — a request-scoped container binding the helper consults is the only one that does not require editing every storefront controller, and it must be scoped so it cannot survive into a second request.
- **The composer fires twice per page** (`@extends` makes the layout its own View). The override must be idempotent — no one-shot pop.
- **No `{theme}` route parameter** if the route renders a `front_end` view. The latent branch at `ViewServiceProvider.php:50-55` assigns the unvalidated route string to `theme_id` and resolves the store with `getCurrentStore()`, which hands an operator with empty `current_store` the literal store 1 — exactly what `storeIdForSelfService()` was written to close. It is unreachable today only because the `{theme}` routes render `theme_customize.*`, which extends `layouts.app`, not a composer-bound name. A preview route with `{theme}` switches it on.
- **No `themes` path segment.** `ActiveTheme::getThemeNameFromUrl()` (`:65-78`) scans all segments for the literal `themes` and takes the next one, and is consulted whenever no `is_active = 1` store resolved — i.e. precisely the pre-launch state a merchant previews from.
- **Do not reuse `theme-preview/*`.** `theme-preview/make-active` already owns that prefix (`routes/web.php:1448`, and despite its name it writes `stores.theme_id` on the live store), and the vendored WorkDo `ThemeCustomizer` owns `theme-preview/{theme?}/change-style` and `theme-preview-content/{theme?}`. Later declarations replace earlier ones for the same method+URI and silently drop the earlier middleware — and **no WorkDo module loads in a test run without an `add_on_managers` row**, so this collision is invisible in CI and live in production.
- **Set the theme before any view resolution.** `FileViewFinder::find()` memoises by view name and neither `prependLocation()` nor `setPaths()` clears the cache; `HomeController` calls `view()->exists('main_file')` before `view('main_file')`. Switching theme in the controller yields the old theme's files with the new theme's settings — a half-rendered page that reads as a CSS bug. Switch in middleware.
- **Every storefront route is anonymous** (`web` + `ActiveTheme` + `themelanguage`, no `auth`). The preview route is the first authenticated storefront-rendering route in the codebase. It needs `auth` + the M4-2 middleware, must emit `no-store`, and must not be reachable by URL alone.

**What the test proves, in one test:** the owning merchant's preview request returns the **draft** bytes, and the public storefront URL for the same store, hit in the same test, still returns the **published** bytes; anonymous, a customer session and another tenant's merchant are each refused; the `theme_customizes` row count is unchanged.

**What would make it pass for the wrong reason:**
- **Key-completeness.** Both the draft and published settings contain every key. Assert the rendered **bytes** differ, and that the draft's specific value appears while the published one does not.
- **The draft value equals a theme default.** Then `getThemeSetting()` alone produces it and the preview path is never exercised. Choose a value no default carries.
- **Owner-only coverage.** Driving just the owning merchant takes the safe branch, exactly as a `Customer` token does against `custom.auth`. Drive anonymous, a customer, and a cross-tenant merchant.
- **One request per test method hides a leaking override.** Issue the preview request and the public request in the same test, in that order.

### M4-6 — Publish, rollback, history, and the refusal audit

**Changes:** routes, controller, CSRF forms; `$expectedLiveVersionId` threaded from the UI so the optimistic check is real; retry owned by the controller — the service deliberately does not retry the transient-collision refusal (`:479-483`), and a retry must re-read `liveVersion()` first or the old expected id simply fails the check again. Surface `keysNotInSnapshot` (`:353-361`): publish and rollback **delete nothing**, so a rollback predating a key leaves that key live, and dropping the field gives the merchant a "reverted" message for a revert that did not fully happen. Add the **refusal audit** — a refused publish writes no log and no `AuditLog` row (verified: zero `AuditLog` references under `app/Services/Theme/` and `app/Support/Theme/`), which `PHASE-03-M3-THEME-VERSIONING.md:1634` records as M4's job in the controller.

Two UI-shaping details: `history()` returns publication **events**, not versions, so a merchant who has never published sees an empty list and the UI must say so rather than look broken; and `liveVersion()`/`history()` reset `refusal` but **not** `rejected` (`:207`, `:227`), so `rejected()` after a read returns stale field errors from an earlier publish on the same service instance. Refusal strings are hardcoded engineer-English and leak installation detail ("'X' is not a theme this installation ships") — follow the existing convention at `ThemeCustomizeController.php:205-218`: log the reason with the actor id, show a translated generic message.

**What the test proves:** two publishes with the same `expectedLiveVersionId` — the second refused with **nothing written**; rollback reports the keys it did not restore; a refused cross-tenant publish leaves an audit record naming actor and action.

**What would make it pass for the wrong reason:**
- **Asserting publish by reading `theme_customizes`.** `writeSettings()` bypasses versioning entirely, so the live projection can already match the snapshot and the assertion passes without publish running. Assert the `ThemePublicationEvent` row and that the live pointer moved.
- **The collision cannot be produced single-threaded.** Deadlock, lock-wait timeout and duplicate key are converted into refusals, and the SQLite suite will never raise them. That evidence belongs in `tests/Support/Security/bin/` against real MySQL — `verify-theme-publish-concurrency-on-mysql.php` is the existing pattern.
- **Counting `AuditLog` rows** passes if any other path wrote one. Assert actor and action.

### M4-7 — The editor shell (UI)

The four existing views carry **zero** design-system tokens (`grep 'var(--'` → exit 1; all five `ss-` hits in `customize.blade.php` are the breadcrumb), hard-code `#f8f9fa` / `#e9ecef` / `rgba(0,0,0,.1)` in a pushed `<style>` (`:16, :27, :33, :41`), and build a `bg-white` dropdown in JS (`customize-script.blade.php:26`). Admin dark mode is server-side via `style-dark.css`, so every one of those stays light.

Most of what M4 needs already ships and loads globally from `layouts/app.blade.php:274`/`:285`: `x-ss.panel` (480px side panel with focus trap, Esc, backdrop, scroll lock), `x-ss.confirm-dialog` + `StoreSyriaDS.confirmDestructive`, `wireDirtyTracking` (verified at `store-syria-ds.js:576`), `x-ss.advanced-fields` (native `<details>`, auto-opens on error), `x-ss.empty`, `x-ss.error-summary`, `.ss-swatches`, `.ss-sort-list`. Per `ADMIN-LAYOUT-ARCHITECTURE.md:90-130` the surface contract is: side panel for 1-5 fields, modal for confirmations, full page for anything with tabs or >8 fields, and the selected tab in the URL (`?tab=`).

Two gaps to budget for: **`.ss-tabs`/`.ss-tab` have zero consumers and no behaviour** (verified exit 1) — the doc calls them "already built" and they are CSS only, so a tabbed shell means writing the roving-tabindex / Left-Right-Home-End implementation, which exists nowhere. And **dirty tracking is opt-in and clears on the `submit` event**, which an AJAX editor never dispatches — M4 must wire it and clear it explicitly on a successful save.

Any new navigation entry must register through `Menu::add()` or it bypasses both the entitlement and the permission gate — `ADMIN-LAYOUT-ARCHITECTURE.md:13-44` calls this a security property, not a convenience.

**What the test proves:** `BladeViewsCompileTest`, `SlotLayoutViewsRenderTest`, `DesignSystemStylesheetScopeTest`, `AjaxPopupContractRatchetTest` (floors 128/83/0), `NoBladeExpressionInsideCodeCommentTest` all stay green; plus a four-combination capture (LTR/RTL × light/dark) with contrast checked.

**What would make it pass for the wrong reason:**
- **The four `_theme_customize__*.png` in `docs/development/phase-2.5/evidence/web-blocked-2026-11-10/after/` are pictures of the dashboard.** Every corresponding render-matrix row carries `premiseFail` "landed on /dashboard, asked for /theme-customize" and `h1` "Dashboard", because the dev fixture does not hold `Manage Themes` and all four READ methods gate on it. Re-capturing without granting that permission first produces four more. Any capture must assert the landed path and the `h1`.
- **Adding tokens without classes is a no-op.** Every selector in `store-syria-ds.css` is confined by an `.ss-` class or `data-ss-` attribute (pinned by `DesignSystemStylesheetScopeTest`), so the stylesheet cannot reach the current Bootstrap markup at all.
- **A `.ss-` rule does not automatically win.** `docs/design-system/README.md:45-62` records the measurement: on `/category` the page loads 25 stylesheets, `store-syria-ds.css` is 15th, and ten load after it including three inline `<style>` blocks. It is safe only because the names are unique — which does not hold for an element that also carries a host class, as these views' elements do throughout.
- **Grepping for `store-syria-ds` to measure adoption** is explicitly warned against (`README.md:36-41`); and grepping for `ss-` on `customize.blade.php` returns hits that are all breadcrumb.

### M4-8 — Bilingual theme strings

Field labels, section titles and page titles are English literals in the theme JSON printed raw with no `__()` (`customize.blade.php:143, 158, 177, 202, 218`; `pages.blade.php:28-29`), and `customize.blade.php:97` calls PHP's gettext `_()` rather than Laravel's `__()`. Wrapping them is necessary and **not sufficient**: only 41 of 122 field labels, 26 of 73 section titles and 18 of 29 page titles exist as `ar.json` keys.

**What would make it pass for the wrong reason:** `__()` returns the key unchanged when there is no translation, so "the page renders in Arabic" passes with an entirely English body. Assert specific Arabic strings **and** the absence of specific English ones.

### M4-9 — Section ordering: do the correctness fixes, defer the feature

Three measured facts argue against building a drag-and-drop section reorderer in M4:

1. **Nothing on the storefront consumes saved ordering** (verified exit 1). Order in `main_file.blade.php` is a fixed `@include` sequence gated by `$themeSettings['<section>_status']`, in three copies.
2. **Version snapshots cover settings only**, so publish and rollback cannot restore an ordering.
3. **The `orders` CSV does not match reality** — phantom tokens that name no section, real sections missing from the list, and greentic declares no `is_order` at all.

Plus: `order.blade.php:89` pulls jQuery UI from a third-party demo host while the identical library sits at `public/js/jquery-ui.min.js` and `attribute_option/index.blade.php` uses the local copy correctly; and **neither first-party sortable has any keyboard path** (`keydown` count 0 in both), a WCAG 2.1.1 and 2.5.7 failure for any reorder UI shipped as-is.

**Do in M4:** vendor jQuery UI locally; fix the `$orders` null dereference at `order.blade.php:55`/`:57` and the undefined `$theme_json` at `ThemeCustomizeController.php:268`; validate `updateOrder()`'s posted `page_slug` and `orders` against the theme's own `pages.json` rather than trusting the hidden input the client filled, and route it through the versioned path.
**Do not do in M4:** a new reorder UI. See §5 Q2.

---

## 3. Risks specific to this codebase

1. **The preview route has three collision traps, two of them invisible in CI.** The `theme-preview/*` prefix is already taken twice (first-party `make-active`, vendored `ThemeCustomizer`), route shadowing silently drops earlier middleware, and no WorkDo module loads in a test run without an `add_on_managers` row — so a production collision passes CI cleanly.
2. **Every operator assertion M4 writes passes for free** unless the XSS premise helper is reused. This has already happened once here: the first version of `ThemeCustomizeWebRoutesTest` passed against a completely unguarded controller.
3. **The gate's own docblock is wrong about store 1, and two of the seven surveys repeat it.** The seeder gives store 1 to the operator; the comment says a merchant. The refusal the gate implements is correct regardless. Settle the threat model against a real dump, not a docblock, and do not "fix" the code toward the comment.
4. **The permission inversion makes single-verb tests worthless.** GET and POST disagree about who may act, so a test on either verb alone proves nothing about the other — and the whole existing suite is POST-only.
5. **Key-completeness makes the obvious preview assertion vacuous.** Defaults are merged under overrides for ~500 keys, so presence proves nothing and a draft value equal to a theme default proves nothing either.
6. **Cache and transaction semantics will produce false green.** `ThemeCustomize`, `Store` and `Addon` are `Cachable`; `ThemeVersion` deliberately is not; `DB::afterCommit` never fires under `RefreshDatabase`. A raw `DB::table()` write plus `CACHE_STORE=array` is the measured Flutter social-links defect.
7. **The vendored `ThemeCustomizer` module is a second, unscoped writer of theme appearance.** It writes `class_json` into `store_theme_customize` keyed on `getCurrentStore()`, its routes carry no permission check, and it injects a button into the core `theme_customize.pages` view through a ViewComposer. M3's "only writers of `theme_customizes`" is true and still leaves this painting the same storefront. It is forbidden to edit.
8. **Its preview technique does not transfer.** It calls `setProperty('--primary-color', …)` on the iframe's `documentElement`. Per CLAUDE.md the storefront paints through a Tailwind runtime JIT CDN with colour carried as utility classes — the exact drift that left 18 greentic contrast failures alive after the stylesheet had already been fixed.
9. **`slider` is a repeater, not a slider**, and its stored value is a JSON *string* the storefront `json_decode()`s at render time. An editor that parses and re-serialises it in the browser changes key order and escaping, which is a behaviour change even when the data is equivalent.
10. **Line-number citations in the design-system docs are stale across the board** — the CSS header, `README.md` and `confirm-dialog.blade.php` all cite positions that are now hundreds of lines off. Follow one and you land in unrelated code.
11. **Every `data-ajax-popup` click fires two HTTP requests** (`custom.js:138` and `:656`). Any M4 endpoint reached that way must be idempotent.
12. **`imageFileGet()` has no authorization of any kind** (`:249-259`) — any authenticated verified user POSTs an arbitrary path and gets back a URL minted from the super admin's storage configuration. If M4's asset picker leans on it, it inherits that.

---

## 4. What M4 must NOT do

**Scope that belongs elsewhere.** Making the three themes' `main_file.blade.php` order-aware is storefront rendering work, not editor work. The Official Website and the bilingual Documentation Center are separately scoped inside Phase 03. SaaS-level or super-admin theme governance is Phase 06. Phase 02.5 premium polish is a separately approved *future* stage and must not be merged into other phases — M4 should bring the markup it writes onto the design system, not launch a general repolish of the admin.

**Standing restrictions.** No production migration and no deployment — which specifically rules out a `theme_versions` backfill migration; use lazy capture. No edits under `packages/workdo/` (the `ThemeCustomizer` module is the closest prior art and is off limits, including its route names). No history rewrite, no force-push, no secrets or dumps committed. No falsified security approvals: run the `tenant-authz-reviewer` subagent on every slice touching authorization, tenancy or route middleware — three of the last four packages had a defect found only by that review, two of them in the fix itself — and run `/tenant-check`.

**Do not weaken checks to go green.** No new `NEVER_RENDERED` allowlist entry to silence `SlotLayoutViewsRenderTest`, no lowering the `AjaxPopupContractRatchet` floors, no deleting a `known-test-outcomes.json` entry that is not genuinely resolved (the gate asserts the set in both directions).

**Do not change `writeSettings()` semantics.** The Flutter admin store-settings endpoint (`DashboardController.php:9053-9060`) writes `footer_repeater` through it, and that HTTP contract must stay unchanged. M4 adds alongside.

**Do not let authorization live in Blade.** The POST routes carry no permission middleware today, so a direct POST is the live path whatever the UI offers.

---

## 5. Open questions for the owner

**Q1 — Who may publish?** Today every user a merchant created — staff, with no permission of any kind — can write that tenant's live storefront, and `ThemeEditorGate` is explicit that this is deliberate (`:238-243`). M4 adds publish and rollback on top. Keep it as-is (any staff member may publish and roll back their employer's live storefront), or restrict publishing to the tenant owner or a new named permission? This is a material change to who can alter a live storefront, so it needs the owner. Note the constraint either way: a permission name that is gated on but never seeded is a permanent 403 for everyone including the operator — the precedent is the six Finance names, seeded as rows and granted to nobody — so any new name must be added to `Utility::addNewData()`'s `$arrPermissions` and granted deliberately.

**Q2 — Does "sections" mean grouped editing, or reordering?** Section-grouped *editing* is clearly in scope and is what the theme JSON's page → section → field structure already supports and the storefront already renders. Section *reordering* is a different thing: no theme reads `theme_customize_orders`, version snapshots cannot restore an ordering, the shipped `orders` strings do not match the real section keys, and greentic declares no ordering at all. Building the drag-and-drop editor without first making three storefront templates order-aware produces a control the merchant can watch save and that changes nothing. My recommendation is grouped editing in M4, correctness fixes to the existing order screen, and reordering deferred until the storefront consumes it — but the owner's word "sections" is ambiguous enough to confirm.

I am **not** asking about operator access: the code already settles it. `ThemeEditorGate::allows()` admits the operator on every write and `storeIdForSelfService()` refuses to guess a store, so the intended shape is "the operator may act but must name the store, and must be attributed". M4-2 implements that and M4-6 adds the attribution.

---

## Appendix — M4-0 evidence, and a correction to commit `7f7cfd14`'s message

`tests/Feature/Theme/ThemeEditorReadRoutesTest` — **7 tests, 38 assertions**, falsified both ways:

| mutation | result |
|---|---|
| remove the `Addon` seeding | the gallery test **alone** fails — the empty-gallery-at-200 trap |
| delete `isAbleTo('Manage Themes')` from `index()` | the **three** refusal tests fail; the render tests correctly stay green |

**The surrounding-suite figure in `7f7cfd14`'s commit message is wrong.** It says "theme suites
overall 384 passed". Measured, on the same tree:

```
php artisan test --filter='Theme'                                            345 passed (4289 assertions)
php artisan test --filter='Theme|BulkDeleteAuthorization|Ratchet|Observability|RawQueryTenancy'
                                                                             388 passed (4569 assertions)
```

Neither is 384. The number was arrived at by adding this file's 7 tests to **377**, a figure from an
earlier run that used a *different* filter set — an arithmetic extrapolation written into a commit
message as though it were a measurement, in a run whose own output said `345` three lines earlier.

Recorded here rather than rewritten, because history is not rewritten on this branch, and because
the failure is worth more as a record than as a deleted line: it is the same shape as every defect
this phase has logged — a number that was *derived* presented as a number that was *observed*. The
test counts above are observed.

---

## M4-2 independent review — one live divergence, found where no route could show it

The `tenant-authz-reviewer` subagent read the gate, the middleware, the routes, the controller and
the matrix. Its verdict on the question it was asked: **no cross-tenant read or write is reachable**
through the eight theme routes, the middleware, or the gate called directly, and the ordering is
correct at every site. What it found instead was the **same live write enforced by a weaker rule on
surfaces outside the eight routes.**

### The one that was live

`ThemeConfigurationService::writeSettings()` gated on `allows()` — tenant membership — not
`allowsManagement()`. M4-2 put every *web* theme route behind `theme.editor`, so a same-tenant
zero-permission staff member was refused at `POST /theme-customize/{theme}/update`. But that service
has a **second production caller**: `Api\DashboardController:9054`, reached by
`POST api/admin/updateStoreOwnerInfo` (`routes/api.php:282`), whose middleware is
`['custom.auth','AdminApiLog']` — and `AdminApiLog` only logs.

So the exact principal the owner's decision names went on rewriting `footer_repeater` — the
storefront footer's social links — on the live storefront through the shipped Flutter app.

**Fixed at the service, not at the API route.** It is the one controlled write path, so the rule
belongs where every caller passes; a fix applied at the web route is what let this one survive in
the first place. The HTTP contract is unchanged — still 200, still `status: 1`, `social_media`
simply absent from `updated_fields`, and specifically no new 401, because the shipped app forces a
logout on one. Pinned by
`StoreOwnerInfoThemeWriteContractTest::test_zero_permission_staff_no_longer_write_the_live_theme_setting`,
falsified by reverting the predicate.

### The split that came out of it

| path | rule | why |
|---|---|---|
| `writeSettings()` | **`allowsManagement()`** | writes the live projection |
| `ThemePublishingService` publish / rollback | **`allowsManagement()`** | changes what shoppers see |
| `saveDraft()`, `updateDraft()`, `deleteVersion()` | `allows()` | draft work |
| `preview()`, `liveVersion()`, `history()` | `allows()` | reading your own tenant's drafts |

That is the owner's "deliberately separated draft/preview authorization", made concrete:
`resolve()` takes a `$requiresManagement` flag and only the shared publish/rollback helper passes
it. Pinned in both directions — `test_zero_permission_staff_may_still_draft_and_preview` fails if
the strict rule ever leaks into the draft path and takes delegation with it.

### Latent, fixed now because M4-3 would have inherited it

`ThemeCustomizeController`'s in-handler second layer used `allows()` on the **writes** while using
`allowsManagement()` on the reads — so had any route left the middleware group, the writes would
have silently reverted to pre-M4-2 behaviour. `makeActiveTheme()` is the sharp one: it re-points
`stores.theme_id`, which *is* a publish. Both now use the management rule.

The controller's own lesson — *defence in depth must restate the SAME rule* — was written about a
check that was **stricter** (the owner lockout). This was the inverse case, and the inverse is the
one with a security direction.

### What the review disputed, and was right about

It challenged this document's claim that `isTenantOwner()` is simply redundant. It is redundant
*cross-tenant*, but **not within a tenant**: `User::creatorId()` compares `type` case-**sensitively**
while `tenantRootIsAMerchant()` case-**folds**, so a row typed `'Admin'` or `'admin '` whose
`created_by` is a real merchant would be owner-equivalent under the relaxed form — a staff-to-owner
escalation inside a tenant. Not constructible through the product today (`UserController:148`
refuses any role name that case-folds into `RolePolicy::RESERVED_NAMES`), but it means the strict
form is the actual control for non-canonically-typed members, not merely belt-and-braces.

### Recorded, not actioned

- **`theme.file.get` has no check of its own.** Now inside the boundary. The review confirmed
  `get_file()` returns a *public* URL, never signed, so there is no cross-tenant file read — what
  leaked was the operator's bucket and endpoint configuration.
- **The vendored `ThemeCustomizer` module** (`packages/workdo/`) has no `verified`, no permission
  and no ownership check, and writes keyed on `getCurrentStore()` — the exact store-1 trap M4 closed
  on the first-party routes. Different table, vendored, out of bounds. **Ledger item.**
- **"Naming a store explicitly" is sticky state**, not per-request intent: it reads
  `users.current_store`, and the refusal only fires when that column is *empty*. A seeded operator
  has it set to store 1 already, so they reach store 1 through a different door. Benign on a clean
  seed, where store 1 is the operator's own; unverified on production, as recorded.
