# Phase 03 M3 — the versioned storefront theme-configuration data model

**Branch:** `feature/phase-03-storefront` · **Entry commit:** `6af5742e` · **Measured:** 2026-11-13

M3 is the data foundation for the merchant's visual theme editor (M4): drafts, preview, publish,
version history, rollback, with tenant and permission isolation and no storefront regression.

This document is the **measured inventory and the design that follows from it**. Where a number
appears it was produced by running something, and the command is named. Where something is inferred
it says so.

---

## 1. The compatibility contract, measured first

Everything in M3 is constrained by one function. `app/Helper/helper.php:3538`:

```php
function getThemeSetting($storeId, $theme)
{
    // 1. the theme's own defaults, with the M0 fallback to config('theme.active')
    //    when themes/<theme>/default_data/settings.php does not exist
    // 2. the merchant's overrides:
    $themeSettings = ThemeCustomize::where('store_id', $storeId)
                    ->where('theme_id', $theme)
                    ->pluck('value', 'name')
                    ->toArray();
    // 3. merge: an override wins per key; override-only keys are appended
    return $mergedSettings;   // a FLAT name => value array
}
```

Its callers, measured (`grep -rn "getThemeSetting(" app resources themes packages`):

| caller | what breaks if the contract changes |
|---|---|
| `app/Providers/ViewServiceProvider.php:65` | **every storefront request** |
| `app/Http/Controllers/ThemeCustomizeController.php:61` | the merchant's editor form |
| `app/Http/Controllers/StoreController.php:709` | store settings |
| `packages/workdo/Donation/.../CustomerDonationController.php:52` | vendored — **must not be edited** |
| `packages/workdo/CouponList/.../CouponListController.php:44` | vendored — **must not be edited** |

26 files reference `ThemeCustomize` in total (76 occurrences), but the table itself is queried from
exactly **four** places:

| | file:line | shape | cache |
|---|---|---|---|
| READ | `app/Helper/helper.php:3576` | Eloquent `pluck('value','name')` inside `getThemeSetting()` | **Cachable** |
| READ | `app/Http/Controllers/Api/DashboardController.php:7042` | **raw** `DB::table()` … `name='footer_repeater'` → `first()` | always fresh |
| WRITE | `app/Http/Controllers/ThemeCustomizeController.php:132` | Eloquent `updateOrCreate` | flushes |
| WRITE | `app/Http/Controllers/Api/DashboardController.php:9032` | **raw** `DB::table()->updateOrInsert()` | **does NOT flush** |

All four constrain both `store_id` and `theme_id`, **and that is the only isolation there is** —
`ThemeCustomize` carries no global scope, does not use `BelongsToStore`, and is absent from
`config/tenancy.php`'s `scoped_models`, so the tenancy scope adds no predicate for any principal.

**Two of the four are the Flutter admin app** (`POST api/admin/updateStoreOwnerInfo`, middleware
`custom.auth` + `AdminApiLog`), which edits exactly one setting: `footer_repeater`, the storefront's
social links. The directive requires Flutter compatibility to be preserved, so that path is part of
M3's surface, not an afterthought — see §2.3.

`getThemeSetting()` never returns null and never throws on a miss: a nonexistent store, an unknown
theme and a null store id each return the full merged default array (511 keys for stylique). So a
wrong `theme_id` degrades to defaults silently rather than failing — measured.

> **THEREFORE: `theme_customizes` stays exactly as it is, and remains the live published
> projection.** Two of its readers are vendored packages this project is forbidden to edit, so any
> design that changes what that table means would either break them or require editing them. Every
> existing reader keeps working with **zero** changes, because none of them is touched.

### 1.1 The vendored `ThemeCustomizer` module is not in the way — it is dead code

`packages/workdo/ThemeCustomizer/` also deals with theme customisation and has its own model, so the
directive's "do not build a second theme engine" had to be checked rather than assumed. Measured:

- `add_on_managers` holds **zero rows**, so **no** WorkDo module is enabled at all.
- Therefore the module's PSR-4 namespace is never registered:
  `class_exists('Workdo\ThemeCustomizer\app\Models\StoreThemeCustomizer') === false`, and
  `module_is_active('ThemeCustomizer') === false`.
- None of its routes exist (`Route::has('themecustomizer.settings') === false`) while
  `Route::has('theme.customize') === true`; `php artisan route:list | grep -i theme` lists only the
  first-party routes.
- Its view namespace is unregistered: `view()->exists('theme-customizer::pages.style') === false`.
- Its only table, `store_theme_customize`, **does not exist**, and it never touches
  `theme_customizes`. When active it merely *decorates* the first-party UI through two Blade stacks.

**Caveat stated rather than buried:** that is measured against the **development fixture**. Whether
production has `add_on_managers` rows cannot be determined from here, and the owner's restrictions
forbid asking production. The design does not depend on the answer: the module owns a different
table and decorates rather than replaces, so first-party versioning neither needs nor conflicts with
it — and `packages/workdo/` is not edited either way.

### 1.2 A duplicate row makes the reader and the writer disagree — measured

There is no uniqueness on `(store_id, theme_id, name)` (§2.1). The reader `pluck()`s the group and
the writers take `limit 1`, and they do not agree on which row of a duplicate group wins. Measured
end to end: a merchant's save succeeds and reports success while the storefront keeps rendering the
shadow row. Nothing in the product can produce a duplicate today, but nothing prevents one either,
and a partially-applied legacy import would. This is the reason §4.3 ships a reconciliation reporter
before any unique constraint is proposed.

### 1.3 NEW, untracked, and measured: a Flutter social-links save does not reach the storefront

Searched first: no entry in `docs/`, no test in `tests/`, and no Contract test covers `social_media`
or `footer_repeater`. So this is reported as new rather than re-reported as known.

`Api/DashboardController.php:9032` writes with raw `DB::table()->updateOrInsert()`. The storefront
reads through `getThemeSetting()`, which is Eloquent on a `Cachable` model. A raw write does not
invalidate that cache. Probed on MariaDB with `CACHE_STORE=database` — production's setting, not the
suite's `array`:

```
  BEFORE the merchant's save
    storefront : [{"link":"https://old.example", "icon":"fab fa-facebook-f", ...
    flutter    : [{"link":"https://old.example", "icon":"fab fa-facebook-f", ...

  AFTER the save
    storefront : [{"link":"https://old.example", ...     <-- UNCHANGED
    flutter    : [{"link":"https://NEW.example", ...     <-- the app shows success

  AFTER an explicit flushCache()
    storefront : [{"link":"https://NEW.example", ...
```

The merchant saves social links in the app, the app reads them back correctly because its own read
is raw, and **the storefront footer keeps showing the old links.** What was measured is the shared
`database` cache store, which is the same store every later request reads, so the stale entry is not
confined to one process; what was *not* measured is how long it persists before a TTL or an
unrelated Eloquent write clears it.

This is a real M3 dependency rather than scope creep: M3 makes `theme_customizes` the published
projection, so every writer must go through one service. Routing this endpoint through that service
fixes the staleness as a consequence and keeps the HTTP contract byte-identical — the request and
response shapes do not change, only the internal write path, so the shipped Flutter app is
unaffected.

---

## 2. What the tables really are

### 2.1 `theme_customizes` — measured on **MySQL**, not inferred from SQLite

The project rule is "the migrations are not the schema", and here SQLite and MySQL genuinely
disagree. Migrated into a throwaway MariaDB 10.11 database and read back with `SHOW CREATE TABLE`:

```sql
CREATE TABLE `theme_customizes` (
  `id`         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name`       varchar(255) DEFAULT NULL,
  `value`      longtext     DEFAULT NULL,
  `store_id`   bigint(20) unsigned DEFAULT NULL,
  `theme_id`   varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `theme_customizes_store_id_foreign` (`store_id`),
  CONSTRAINT `theme_customizes_store_id_foreign`
    FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

**`PRAGMA index_list` on the SQLite fixture reports NO indexes at all.** MySQL has one, because a
foreign key requires an index and InnoDB creates it. "`store_id` is unindexed" would have been a
false finding taken from SQLite — this is the same class of error as P01-SEC-13.

What IS true on both: **no index on `name` or `theme_id`, and no uniqueness anywhere.** The
storefront's per-request lookup filters on `(store_id, theme_id)` and can only use the first.

Fixture contents (`database/p2dev.sqlite`): 53 rows — 12 per themed store, 5 for the themeless
store. `GROUP BY store_id, theme_id, name HAVING COUNT(*) > 1` returns **no duplicates**, so the
application's implicit uniqueness holds there. **It cannot be asserted for production**, which is
why §4.3 adds a non-unique index and not a unique one.

### 2.2 `landing_page_settings` — the directive's question, answered: **NO**

| | |
|---|---|
| columns | `id, name, value, created_at, updated_at` |
| indexes | `landing_page_settings_name_unique` UNIQUE(`name`) |
| **`store_id`** | **does not exist** |

The table has **no tenant column at all**. It cannot hold per-store configuration, so it is not and
cannot be part of a *merchant's* storefront publishing lifecycle. `UNIQUE(name)` is the correct
constraint for a platform-global key-value store, and an older plan's flag that it "may prevent
storing multiple rows for one logical key" was reading it as a per-store table, which it is not.

**M3 does not touch `landing_page_settings`.** It belongs to M5 (the official bilingual Store Syria
website on the WorkDo LandingPage foundation). The directive's instruction — do not modify a
uniqueness constraint because an older plan flagged it — is followed: measured, and left alone.

---

## 3. The field taxonomy already exists — M3 must not duplicate it

The directive warns against duplicating the taxonomy and against hundreds of schema columns. Neither
is necessary, because the taxonomy is already declared as data.

Each theme ships `themes/<theme>/theme_json/`: a `pages.json` listing **25 pages**, plus one JSON
file per page. A page declares `sections`, each section declares `settings`:

```jsonc
// themes/stylique/theme_json/footer.json
{ "title": "Footer", "slug": "footer", "detail": "...", "icon": "ti ti-heading",
  "sections": [
    { "title": "Footer", "slug": "footer", "key": "footer", "settings": [
        { "label": "On/Off",      "key": "status",      "type": "switch",   "value": 1 },
        { "label": "Description", "key": "description", "type": "textarea", "value": "..." },
        { "label": "Logo",        "key": "logo",        "type": "image",    "value": "..." },
        { "label": "Social Link", "key": "repeater",    "type": "slider",
          "fields": ["icon","link"], "value": "[{\"icon\":\"fab fa-facebook-f\",...}]" }
    ]}
  ]}
```

**The stored setting name is `{section.key}_{setting.key}`** — not inferred, read from the renderer
itself, `resources/views/theme_customize/customize.blade.php:119,130,187,204`:

```blade
name="{{ $json_setting['key'] . '_' . $fields['key'] }}"
```

So `footer` + `description` = `footer_description`, which is exactly the name the storefront reads.

Applying that rule across all three themes yields the complete allowlist:

| theme | allowed `(name → type)` pairs |
|---|---|
| stylique | 307 |
| techzonix | 321 |
| greentic | 328 |
| **total tuples** | **956** (379 distinct names across the three) |

The directive's "approximately 959 configurable field declarations" is confirmed: a raw walk for
nodes carrying a `type` finds **962**; six of those are entries inside a repeater's `fields` list
rather than settings in their own right, leaving **956** addressable settings.

Only **seven** value types actually occur, across all three themes:

| type | count | note |
|---|---|---|
| `text` | 474 | |
| `switch` | 288 | 0/1 |
| `image` | 95 | a stored path |
| `textarea` | 71 | |
| `slider` | 28 | **a repeater** — its `value` is a JSON *string* with a declared `fields` list |
| `menu` | 3 | |
| `date` | 3 | |

`slider` being a repeater whose value is a JSON string is the one type that has to round-trip
byte-exactly: it is `json_decode()`d at render time, so a re-encode that changes key order or escaping
is a behaviour change even when the data is equivalent.

### 3.1 Three key spaces, not one

`default_data/settings.php` and `theme_json/` do **not** describe the same set, and the difference
decides what a write may touch versus what a snapshot must carry:

| theme | defaults (read space) | theme_json (write space) | both | editable, no default | defaulted, not editable |
|---|---|---|---|---|---|
| stylique | 511 | 307 | 295 | 12 | 216 |
| techzonix | 523 | 321 | 318 | 3 | 205 |
| greentic | 521 | 328 | 324 | 4 | 197 |

Of the "defaulted but not editable" keys, only some are read by any Blade view — the rest are dead:

| theme | defaulted-not-editable | of those, read by a view | of those, **never read** |
|---|---|---|---|
| stylique | 216 | 66 | **150** |
| techzonix | 205 | 55 | **150** |
| greentic | 197 | 41 | **156** |

The dead ones are an older naming scheme. `default_data` carries `abouts_us_description`; the
current `theme_json` declares section key `abouts_us_full` with setting `description`, so the live
name is `abouts_us_full_description` and the bare one is never read or written.

**Design consequence:** a version's payload must be the merchant's **override set** — the pairs
`theme_customizes` holds — and **not** the merged array. Storing the merged array would freeze ~150
dead keys per version and would change behaviour the moment a theme ships a new default, because
today a key the merchant never touched always resolves to the *current* default. Storing overrides
keeps the semantics byte-identical to today.

---

## 4. The design

### 4.1 Shape

```
                     ┌──────────────────────────────┐
  merchant edits ───▶│ theme_versions               │  immutable payload once published
                     │  status: draft|published|    │  payload = the OVERRIDE SET (JSON)
                     │          archived            │  ~31–35 KB at full size
                     └───────────────┬──────────────┘
                                     │ publish (atomic, in a transaction)
                                     ▼
                     ┌──────────────────────────────┐
                     │ theme_customizes  UNCHANGED  │◀── getThemeSetting() — every storefront
                     │  the live published          │    request, and two VENDORED packages
                     │  projection                  │
                     └──────────────────────────────┘
```

- **A draft writes only `theme_versions`.** It cannot modify the storefront, by construction, because
  the storefront never reads that table. The directive's "saving a draft must never modify the
  currently published storefront" is satisfied structurally rather than by a check that could be
  forgotten.
- **Publishing replaces the projection** for one `(store_id, theme_id)` from the chosen version's
  payload, inside one transaction.
- **Rollback is a publish of an older version**, so it uses the same atomic path.

### 4.2 Tables (all additive; no existing column is altered)

**`theme_versions`** — the snapshots.

| column | type | why |
|---|---|---|
| `id` | bigint unsigned PK | |
| `store_id` | bigint unsigned NOT NULL | FK → `stores.id` ON DELETE CASCADE, matching `theme_customizes` |
| `theme_id` | varchar(255) NOT NULL | a version belongs to one theme; a store that switches theme keeps both histories |
| `version_no` | unsigned int NOT NULL | per `(store_id, theme_id)`, monotonic |
| `status` | varchar(16) NOT NULL | `draft` / `published` / `archived` |
| `label` | varchar(255) NULL | merchant's own name for it |
| `payload` | longtext NOT NULL | the override set as JSON |
| `payload_hash` | char(64) NOT NULL | SHA-256 of the canonical payload — immutability evidence |
| `created_by` | bigint unsigned NULL | FK → `users.id` ON DELETE SET NULL |
| `published_at` | timestamp NULL | |
| `published_by` | bigint unsigned NULL | FK → `users.id` ON DELETE SET NULL |
| timestamps | | |

Constraints: `UNIQUE (store_id, theme_id, version_no)`; index `(store_id, theme_id, status)`.

**`theme_published_versions`** — the pointer, so "exactly one live version" is enforced by the
database and not only by application code.

| column | type |
|---|---|
| `store_id` | bigint unsigned NOT NULL, FK → `stores.id` ON DELETE CASCADE |
| `theme_id` | varchar(255) NOT NULL |
| `version_id` | bigint unsigned NOT NULL, FK → `theme_versions.id` **ON DELETE RESTRICT** |
| `published_at`, `published_by` | | 

`UNIQUE (store_id, theme_id)` — at most one published version per store and theme, enforced by the
engine. `ON DELETE RESTRICT` makes a published version undeletable while it is live.

**`theme_publication_events`** — append-only audit, so history survives re-publishing an older
version.

| column | type |
|---|---|
| `id`, `store_id`, `theme_id`, `version_id` | |
| `action` | varchar(16) — `publish` / `rollback` |
| `from_version_id` | bigint unsigned NULL — what was live before |
| `payload_hash` | char(64) — what was actually published, independent of later edits |
| `actor_id`, `created_at` | |

Rolling back to version 3 appends an event and re-points the pointer; version 7 stays in history as
`archived`, and the event log records that 3 became live again and when. Nothing is deleted and
nothing is rewritten.

### 4.3 The one change to an existing table, and why it is safe

Add a **non-unique** index `(store_id, theme_id)` to `theme_customizes`. Today the per-request
lookup can only use the single-column FK index. This is additive, it is reversible without data
loss (dropping an index destroys nothing — unlike the GATE F draft that dropped a *column* because
it existed and destroyed 45 `cost_price` values), and it cannot fail on existing data.

**A UNIQUE index is deliberately NOT added.** Uniqueness on `(store_id, theme_id, name)` is what the
application assumes and what the fixture shows, but production cannot be measured from here, and a
unique index that meets one duplicate pair fails the migration for that deployment. M3-B instead
ships a read-only reconciliation reporter so the owner can see whether production is clean before
any uniqueness is proposed.

### 4.4 Integrity has to live in the constraints, because the connection is not strict

`config/database.php:55` sets **`'strict' => false`** on the production MySQL connection. A
non-strict connection coerces rather than refuses — the documented mechanism behind P1-INV-01, where
`NULL` into a `NOT NULL` column landed on MySQL while SQLite refused it. So every integrity rule
above is a real constraint (`NOT NULL`, `UNIQUE`, `FOREIGN KEY`, `RESTRICT`) and not an application
convention.

### 4.5 Publish atomicity — measured, with one correction to the obvious implementation

Probed against the throwaway MariaDB with `CACHE_STORE=database` (production's setting, not the
suite's `array`):

| step | observed |
|---|---|
| 1. baseline read | `site_title='Legacy A Shop'`, 4 rows |
| 2. after `DB::table()->update()` | **still `'Legacy A Shop'`** — the documented stale-read trap, confirmed on MySQL |
| 3. after `(new ThemeCustomize)->flushCache()` | `'RAW-WRITE'` |
| 4. after an Eloquent `delete()` + raw `insert()` in a transaction, **before** any flush | already correct (`'PUBLISHED-V2'`, 2 rows) — the Eloquent delete flushed as part of its own lifecycle |
| 5. after an explicit flush | unchanged |
| 6. after a **rolled-back** publish, before flush | `'PUBLISHED-V2'` — the previous publish, intact |
| 7. after flush | `'PUBLISHED-V2'` |

Steps 6–7 are the safety result the directive asks for: **a failed publish did not leave a partially
applied theme.** InnoDB rolled the DELETE and INSERT back and the next read saw the prior projection.
Both tables are `ENGINE=InnoDB`, so this generalises.

**The correction:** step 4 shows the cache flush happens *inside* the transaction, as a side effect
of the Eloquent delete. That is not sufficient. Between that flush and the commit, a concurrent
storefront request can re-populate the cache from the still-committed **old** rows and then serve
them after the new ones commit. So the publish must **flush after commit**, not only inside it. M3-D
implements it that way and M3-E proves the race with a concurrent reader, following the
`verify-*-concurrency-on-mysql.php` precedent already in `tests/Support/Security/bin/`.

### 4.6 Only allowed settings and values

The directive requires that theme configuration hold "only allowed settings and values, not
arbitrary executable HTML or JavaScript". The allowlist is §3's 956 tuples, derived from the themes'
own `theme_json` with the renderer's own composition rule — nothing new is invented or duplicated.

The exposure this closes is **already tracked**, not a new finding:
`docs/discovery/03-FEATURE-MATRIX.md:223`, *Theme settings write authorization*, **HIGH / P0** —
`ThemeCustomizeController::themeCustomizeUpdate` has its `isAbleTo('Edit Theme')` check **commented
out** while its siblings at `:20`, `:43`, `:55` and `:156` all check, so any authenticated tenant
user can write arbitrary key/value pairs with no allowlist, and those values render through
unescaped output.

Re-measured on this tree, because the audit's figure needed refreshing:

| | audit | measured 2026-11-13 |
|---|---|---|
| unescaped `{!! … $themeSettings … !!}` sites | 62 | **55** across 150 Blade files |
| distinct keys emitted unescaped | — | **43** |
| keys any view reads | — | 368 |

**All 43 of those keys are in the allowlist, and their declared types are only `text` (26) and
`textarea` (17).** No `image`, `switch`, `slider`, `menu` or `date` value is ever emitted unescaped.
So the entire persistent-output surface is 43 plain-text fields, and a type-aware write rule reaches
every one of them at the source — which is a far smaller and more verifiable change than editing 55
Blade sites.

**Existing stored values are not rewritten.** The legacy corpus already contains markup
(`footer_description` = `<p>Legacy <b>HTML</b> already stored</p>`), and silently stripping it would
be a destructive update of published settings, which §6 forbids. M3 sanitises **on write** and
M3-B's reporter lists, read-only, which existing rows a future pass would change. Whether to clean
them is the owner's call, not a migration's.

---

## 5. What M3 deliberately does not do

- **It does not build a second theme engine.** `theme_customizes` keeps its meaning, the three themes
  keep theirs, and the vendored `packages/workdo/ThemeCustomizer/` module is not edited.
- **It does not duplicate the field taxonomy** — the allowlist is derived from `theme_json` at
  runtime, so adding a field to a theme needs no code change and no migration.
- **It does not add per-field columns.** A full snapshot is 31–35 KB of JSON; 500 child rows per
  version would be the alternative.
- **It does not touch `landing_page_settings`** (§2.2) or its unique constraint.
- **It does not change the storefront read path**, so M2's appearance work, the three themes' colour
  identities, RTL/LTR and the shopper's own `localStorage` preference are untouched. The merchant's
  theme configuration and the shopper's appearance choice live in different places and neither can
  overwrite the other.
- **It applies nothing to production.** Migrations are written and validated against throwaway
  databases only.

---

## 6. M3-B — the migrations, and what running the rollback found

Four additive migrations. **No existing column is altered and no existing row is touched.**

| | table | what the engine enforces |
|---|---|---|
| `2026_11_13_000100` | `theme_versions` | `UNIQUE (store_id, theme_id, version_no)`; FK to `stores` CASCADE; FKs to `users` SET NULL |
| `2026_11_13_000200` | `theme_published_versions` | **`UNIQUE (store_id, theme_id)`** — at most one live version; a **COMPOSITE** FK on `(store_id, theme_id, version_id)` → `theme_versions (store_id, theme_id, id)`, **RESTRICT** |
| `2026_11_13_000300` | `theme_publication_events` | append-only; `created_at` only, no `updated_at`; deliberately **no FK** to `theme_versions` |
| `2026_11_13_000400` | `theme_customizes` | one **non-unique** index `(store_id, theme_id)` |

### 6.1 The constraints were proved, not asserted

`tests/Support/Security/bin/verify-theme-version-integrity-on-mysql.php` — **21 passed, 0 failed,
0 inconclusive** on MariaDB 10.11.14, **under production's `sql_mode`** (see §6.5). It creates its own throwaway database, migrates, checks, and
drops it.

Each refusal is checked for its **SQLSTATE and the constraint's NAME**, because "it threw" is not
evidence that the intended constraint fired:

```
  ok  a duplicate (store, theme, version_no)                refused (23000, tv_store_theme_version_unique)
  ok  a SECOND live version for the same store+theme (own)  refused (23000, tpv_store_theme_unique)
  ok  deleting the version that is LIVE                     refused (23000, tpv_version_identity_foreign)
  ok  store B publishing store A's version                  refused (23000, tpv_version_identity_foreign)
  ok  the WRONG THEME of the right store publishing it      refused (23000, tpv_version_identity_foreign)
  ok  a version owned by no store (single row)              refused (23000, NOT NULL)
  ok  a MULTI-ROW publish carrying a null store id          refused (23000, tv_store_id_foreign)
  ok  a version for a store that does not exist             refused (23000, tv_store_id_foreign)
  ok  a pointer to a version that does not exist            refused (23000, tpv_version_identity_foreign)
```

### 6.1.1 The cross-tenant hole the first draft left, and the key that closes it

The pointer's foreign key was originally on `version_id` **alone**. That proves the version *exists*
— not that it belongs to the store doing the publishing. Since the only isolation the M3-C/D handlers
will have is hand-written `where('store_id', ...)` predicates (no global scope, not in
`scoped_models`, `null_tenant => 'open'`), and this project has already produced **four separate
defects** from a tenant predicate built out of caller-supplied input, that was a cross-tenant
publication primitive sitting in the schema waiting for one forgotten predicate.

The fix is a **composite** foreign key: `theme_versions` gains `UNIQUE (store_id, theme_id, id)` as a
referenceable identity, and the pointer references it on all three columns. Measured on MariaDB
10.11 before adopting it, and now asserted by the harness:

```
  ok  store B publishing store A's version            refused (23000, tpv_version_identity_foreign)
  ok  the WRONG THEME of the right store publishing it refused (23000, tpv_version_identity_foreign)
  ok  store A publishing its OWN version               accepted
```

The engine refuses it. No handler cooperation is required, which is the point.

When that key replaced the single-column one, **two previously-passing checks failed** — they
expected `tpv_version_id_foreign`, which no longer exists. That is the harness working as intended:
it asserts the constraint's NAME, so a silently renamed or removed constraint cannot pass. The
expectations were updated to the composite key, not the assertions weakened.

and six legitimate operations are asserted to **succeed**, because a constraint that blocks real work
is also a defect: the same `version_no` for a different store, the same for a different theme of the
same store, a second theme's live version for one store, deleting a version that is *not* live, an
append-only event, and an event naming a version that has since been pruned. The remaining six checks
cover the store cascade (§6.6) and the new index.

**Why the script reads the ids back instead of assuming them.** The first hand-run of these checks
was wrong in both directions and that is why the script exists. Rows were inserted and then
referenced by assumed ids. Auto-increment had not restarted, so "a SECOND live version" was refused
with `1452` (FOREIGN KEY) rather than `1062` (DUPLICATE KEY) — it looked like a pass but the unique
key was never reached — and "deleting the version that is LIVE" was **accepted**, which read as a
missing `RESTRICT` when it had merely deleted a row that did not exist. A false refusal and a false
acceptance from one mistake. Every check now asserts its premise first and reports INCONCLUSIVE
rather than passing when the premise fails.

### 6.2 THE ROLLBACK TRAP — found by running it, not by reasoning about it

`(store_id, theme_id)` has `store_id` as its leftmost column, so InnoDB can use it to enforce the
existing foreign key — and on adding it **MariaDB silently drops the FK's own single-column index**
as redundant. Measured, `SHOW CREATE TABLE` after `up()`:

```sql
  PRIMARY KEY (`id`),
  KEY `theme_customizes_store_theme_index` (`store_id`,`theme_id`),   -- the only one left
  CONSTRAINT `theme_customizes_store_id_foreign` FOREIGN KEY (`store_id`) ...
```

The foreign key now depends on *this migration's* index, so the obvious `down()` fails — and fails
mid-batch:

```
  SQLSTATE[HY000]: General error: 1553 Cannot drop index
  'theme_customizes_store_theme_index': needed in a foreign key constraint
```

A `down()` that looks obviously safe and is not is the same shape as the GATE F drafts that dropped
a column *because* it existed and destroyed 45 real `cost_price` values. `down()` now restores the
foreign key's own index **first** and only then drops the composite one.

Full cycle on a legacy-shaped database (3 stores, one themeless, rows containing markup and a JSON
repeater), with the data fingerprinted as `COUNT(*)` plus a CRC over every row:

| step | migrations | `theme_customizes` indexes | data fingerprint |
|---|---|---|---|
| pre-M3 | — | `PRIMARY(id)`, `store_id_foreign(store_id)` | `4\|9300402612` |
| upgrade | 4 applied | `PRIMARY(id)`, `store_theme_index(store_id,theme_id)` | `4\|9300402612` **identical** |
| rollback | 4 reverted | `PRIMARY(id)`, `store_id_foreign(store_id)` — **restored exactly** | `4\|9300402612` **identical** |
| re-apply | 4 applied | composite again | `4\|9300402612` **identical** |

M3 tables remaining after rollback: **0**. No merchant row was read, written or altered at any step.

`ALGORITHM=INPLACE, LOCK=NONE` was verified by executing it, so adding the index does not block
concurrent reads or writes. **That is the measurement, not permission to run it anywhere.**

### 6.3 The duplicate question, answered read-only instead of by a constraint

`tests/Support/Security/bin/report-theme-customize-duplicates.php` issues no DDL and no writes. It
reports whether a UNIQUE key on `(store_id, theme_id, name)` would succeed on a given database, and
it also counts rows with a NULL `store_id` or `theme_id` separately — MySQL treats NULLs as DISTINCT
in a unique index, so those rows would **not** collide even though the application cannot tell them
apart, and a unique key does not fix them.

Falsified before being trusted: injecting two duplicate pairs and one NULL-tenant row made it report
both groups with their id ranges, flag the NULL row, and exit 1; restoring the corpus returned it to
clean and exit 0. Against the development fixture (53 rows) and the legacy corpus (8 rows) it reports
no duplicates.

### 6.4 `GateFSchemaGuard` could not be used here, and that was checked

`GateFProductionSchema::ddlFor('theme_customizes')` **throws `InvalidArgumentException`** — GATE F
knows exactly 15 tables and none is theme-related. `GateFSchemaGuard::differences()` resolves the
expected DDL *before* it checks `hasTable()`, so a migration wrapping it in a `catch
(GateFSchemaMismatch)` would swallow the wrong exception type. The M3 migrations therefore guard with
`Schema::hasTable()` and an explicit, driver-aware index-existence check instead.

Stated plainly: **there is no production-measured schema for any theme table.** For these tables the
project's "the migrations are not the schema" rule cuts the other way — the migration is currently
the only description that exists, and production is unmeasurable from here.

### 6.5 The harness was measuring the wrong engine

A raw PDO connection inherits the **server's** `sql_mode` — on a stock MariaDB 10.11 that is
`STRICT_TRANS_TABLES,…`. Production sets `'strict' => false` (`config/database.php:55`), which
Laravel turns into `SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'`: MySQL **coerces** a bad value
instead of refusing it. So the first version of the harness asserted strict behaviour while all three
migration docblocks are justified by *non-strict coercion*. It now sets production's mode explicitly
and prints it.

That changed what the checks prove, and one prediction turned out to be **wrong**:

| | strict | production (`NO_ENGINE_SUBSTITUTION`) |
|---|---|---|
| single-row `INSERT … VALUES (NULL)` into `NOT NULL` | ERROR 1048 | **ERROR 1048** — still refused |
| **multi-row** insert containing a NULL | ERROR 1048 | **ACCEPTED, NULL → 0** |
| 200 chars into `varchar(191)` | ERROR 1406 | **ACCEPTED, silently truncated to 191** |

A review predicted the single-row NULL would coerce to 0 and be caught by the foreign key. Measured:
it is refused by `NOT NULL` in **both** modes. Non-strict downgrades it only for a **multi-row**
insert — which is exactly the shape a publish uses, writing a snapshot's rows in one statement. A
check for that was added, and the foreign key is what catches it:

```
  ok  a version owned by no store (single row)          refused (23000, NOT NULL)
  ok  a MULTI-ROW publish carrying a null store id      refused (23000, tv_store_id_foreign)
```

It matters past this table: **`theme_customizes` has a NULLABLE `store_id`**, so the same coercion
there yields a row owned by nobody that no constraint objects to. That hop is guarded by the handler
alone.

### 6.6 The contradiction inside the new design, found by a critic rather than by a test

`theme_publication_events`'s docblock argues that *an audit log the audited thing can delete is not
an audit log*, and cites `audit_logs` as precedent. The first draft then gave `store_id`
**ON DELETE CASCADE** — destroying exactly the record the table exists to keep, one paragraph after
arguing it must not.

The justification was also **factually wrong about the table**. Measured against its own column list,
it holds `store_id`, `theme_id`, `version_id`, `from_version_id`, `action`, `payload_hash`,
`actor_id`, `created_at`: no payload, no setting value, **no merchant content at all** — only
identifiers, a verb and a digest. The content lives in `theme_versions.payload`, which does cascade
with the store, and that is where it belongs.

And the precedent says so plainly. `SHOW CREATE TABLE audit_logs`: four plain `KEY`s and **not one
`CONSTRAINT`**.

Both foreign keys are dropped. `actor_id` went for the same reason — `nullOnDelete` would blank *who*
published when that user is deleted, and `users` rows do get deleted here. "Someone published version
7" is not an audit trail.

The harness check was **inverted**, because it had been asserting the defect:

```
  ok  deleting the store removed its versions   cascaded away (2 -> 0)
  ok  deleting the store removed its pointers   cascaded away (2 -> 0)
  ok  the append-only audit                     SURVIVED the store deletion (2 rows)
```

Falsified: restoring the cascade makes it report *"2 of 2 rows DESTROYED — an audit log the audited
thing can delete"* and fail; removing it returns to 21 of 21.

This also settles the cascade/`RESTRICT` ordering question a review raised. With no foreign key on
the events table, a store deletion cascades only into `theme_versions` and `theme_published_versions`
— and it is **measured** to succeed with a live pointer in place, which is the risky configuration.

---

## 7. Findings that correct or constrain §1–§4

Five measurements made after the design was first written change it. They are recorded here rather
than silently folded in, because two of them refute something stated above.

### 7.1 A published configuration must stay KEY-COMPLETE — a constraint on publishing, not on storage

§3.1 concluded a version stores the **override set**. That stands, but it comes with a condition that
was not stated. Storefront views index ~520 keys per theme **with no guard**, and an absent key
raises `ErrorException: Undefined array key`, which Laravel turns into a 500 — which is precisely
the M0 defect, where a themeless store's empty defaults array produced 500s on eleven routes.

`getThemeSetting()` itself never fails: a nonexistent store, an unknown theme and a null store id
each return the full merged default array. Key-completeness comes from the **defaults merge**, not
from the stored rows. So the override-set design is safe **only while publishing writes overrides
into `theme_customizes` and leaves the merge intact**.

> **M3-C/M3-D must therefore never publish a snapshot as the whole settings array.** A publish that
> replaced the merged result with a sparse version payload would be a 500 generator. This is a test
> to write, not a comment to trust.

### 7.2 "Byte-exact round-trip" is already impossible on the save path — and the cause is tracked

§3 said repeater JSON "has to round-trip byte-exactly". That is true of the **version store** and
false of the **save path**, and the difference matters.

`app/Http/Middleware/XSS.php:35` deletes four substrings from every string in every request:

```php
public const STRIPPED_SUBSTRINGS = ['&lt;', '&gt;', 'javascript', 'alert'];
```

Measured through the real middleware on theme-setting field names:

| posted | stored |
|---|---|
| `Smart alerts for shoppers` | `Smart s for shoppers` |
| `Get javascript tips weekly` | `Get  tips weekly` |
| `Alert & Co <b>Bold</b>` | **unchanged** |
| `Alerta Roja` | **unchanged** |

The sweep is **case-sensitive**, so it corrupts ordinary lowercase merchant copy while leaving
`Alert`, `JavaScript:` and `<b>Bold</b>` untouched. Only password fields are exempt
(`XSS::CREDENTIAL_KEYS`), and the docblock at `:85` records that exemption being added after the
same sweep mangled passwords.

**This is already tracked**, and is cited rather than claimed: `docs/discovery/11-RELEASE-READINESS.md`
**S-09** — *"The 'XSS middleware' provides no XSS protection and silently corrupts passwords."* The
theme-settings consequence is the part M3 adds to that record.

Two consequences for M3:

1. **M3's write validation cannot delegate to the `xss` middleware** for the directive's "no
   arbitrary executable HTML or JavaScript" requirement. That middleware demonstrably provides none.
   The type-aware allowlist in `ThemeFieldCatalog` is the real control.
2. A version payload must be byte-identical to **what was stored**, not to what the merchant typed.
   `payload_hash` therefore certifies the stored state and nothing upstream of it.

### 7.3 The themes' own shipped defaults are not all valid

`terms_condition_policy_repeater` is invalid JSON in all three themes, `team_slider` ships a PHP
array where a JSON string is declared, and `privacy_policy_create_date` ships a human-readable string
against a declared `date` type. **A strictly typed, validating version store would reject the themes'
own defaults.** M3-C validates what a merchant *writes*; it does not retro-validate shipped defaults,
and must not fail a publish because a default it never touched is malformed.

### 7.4 The authorization picture is INVERTED, and worse than §4.6 recorded

Measured across the seven theme routes:

| | actions | guard |
|---|---|---|
| **reads** | `index`, `themePages`, `themeCustomize`, `changeOrder` | `isAbleTo('Manage Themes')` — **held by no role and no user in the fixture**, so the editor refuses every principal including the operator |
| **writes** | `themeCustomizeUpdate`, `updateOrder`, `makeActiveTheme` | **no permission check at all** — only `auth` + `verified` |

So the four actions that only *read* are locked and the three that *write* are open. "Uncomment line
74" is not the fix: `Edit Theme` does not exist as a permission, and `Manage Themes` is held by
nobody — so both the naive uncomment and the naive copy produce a surface that refuses everyone.

Three further measured traps for M3-C/D's write paths:

- **`getCurrentStore()` returns the literal `1` for an operator** with an empty `current_store`, and
  store 1 belongs to a *merchant*. A publish or rollback performed by the operator would overwrite a
  tenant's live theme while appearing to act on their own.
- **`where('store_id', null)` compiles to `store_id IS NULL`**, so drafts from different unresolved
  principals would silently share one bucket. A new write path must refuse a null store rather than
  pass it to the predicate.
- **A bare `isAbleTo('Manage Themes')` refuses the platform operator** — `users.type = 'super admin'`
  measured false. Every check needs the operator-first ordering `CLAUDE.md` §1 prescribes.

### 7.5 Why drafts must stay out of `theme_customizes` — now with a number

`getThemeSetting()` plucks every row for a `(store_id, theme_id)` pair **with no published filter**,
and they are reached through `ViewServiceProvider:65`.

**The number needed correcting.** A measurement agent reported "111 anonymous storefront routes" and
that figure was repeated here before being checked. Re-measured from `route:list --json`: **111
routes carry `storeSlug`** — the parameter is `{storeSlug?}`, optional, which a first regex missed
entirely and reported as zero — and of those **75 carry no auth middleware**, 47 of them answering
GET, while 36 are auth-gated. All 75 run `ActiveTheme` + `ThemeLanguage`, so they are theme-rendering
routes. 111 is the storefront surface; 75 is the anonymous one.

A draft row written into `theme_customizes` under an existing key — however it were flagged — would
be public the instant it landed. The design in §4.1 keeps drafts in `theme_versions`, which no storefront path
reads, so this is structural rather than a filter that could be forgotten.

---

## 8. Packages

| | | state | commit |
|---|---|---|---|
| M3-A | verified schema inventory, the derived allowlist, `ThemeFieldCatalog` + 13 tests | **complete** | `2abc369b` |
| M3-B | four additive migrations, engine-enforced integrity, the rollback trap and its fix, the duplicate reporter | **complete** | this change |
| M3-C | versioned storage model, backward-compatible reads, type-aware write validation (§7.2, §7.3) | next | |
| M3-D | draft/published state, atomic publish, rollback semantics, **post-commit** cache flush (§4.5) | | |
| M3-E | tenancy and concurrency regression tests, legacy-upgrade validation, closure | | |

### What M3-C inherits as hard requirements

1. **Never publish a snapshot as the whole settings array** (§7.1) — key-completeness comes from the
   defaults merge, and a sparse replacement is a 500 generator.
2. **Do not delegate value safety to the `xss` middleware** (§7.2) — it provides none, and corrupts
   lowercase `alert` and `javascript` in ordinary merchant copy.
3. **Operator-first authorization ordering**, a refusal on a null store, and no reliance on the
   tenancy scope (§7.4).
4. **Flush the model cache after commit, not inside the transaction** (§4.5).
5. **Route the Flutter `updateStoreOwnerInfo` write through the same service** (§1.3), keeping the
   HTTP contract byte-identical, which also fixes the stale-storefront defect.
6. **Define a CANONICAL form for `payload_hash` before anything relies on it.** Three claims rest on
   it — immutability evidence, an independent record of what was published, and an O(1)
   "is this draft identical to what is live" check — and key order, unicode/slash escaping and
   numeric-string normalisation are all currently unspecified, so logically identical payloads can
   hash differently.
7. **Publish must write the stored values directly, never re-POST a payload through the web route.**
   `htmlspecialchars_decode` is not idempotent, so entities progressively unescape across cycles and
   the hash drifts for content nobody changed — on top of the substring sweep of §7.2.
8. **Reject on `ThemeFieldCatalog::typeOf() === null` BEFORE dispatching on the type.** A
   `match (…) { 'text','textarea' => sanitise($v), default => $v }` stores the raw value for every
   unknown field, reinstating the audited hole through the validator meant to close it. The contract
   is now in the class docblock.

### Residual risk the schema cannot reach — for M3-D, in priority order

The three new tables are the best-constrained in the codebase. **The table they project into is the
least.** `theme_customizes` is `store_id` NULLABLE, `theme_id` NULLABLE, no unique key, `Cachable`,
unscoped, absent from `scoped_models`, and read by 75 anonymous routes. Everything the composite key
wins is handed off at that boundary to a hand-written predicate.

| | what the engine cannot stop | what M3-D must do |
|---|---|---|
| 1 | `getCurrentStore()` returns the literal **`1`** for an operator with an empty `current_store`, and store 1 belongs to a *merchant*. Every constraint is satisfied while the operator writes into a merchant's history and re-points their live theme. See the quoted source below. | derive `store_id` from the principal, refuse an operator without an explicit store, and use the operator-first ordering `CLAUDE.md` §1 prescribes |
| 2 | **Cross-tenant READ by bare id.** `ThemeVersion::find($request->version_id)` returns another tenant's 31–35 KB payload. The composite key is a *write* constraint and does nothing here. | never a bare `find()` — always the triple `where('store_id')->where('theme_id')->where('id')`, which `tv_identity_unique` indexes exactly, so the safe lookup is also the fast one |
| 3 | **Cross-tenant DELETE of a non-live version.** `RESTRICT` protects only the version a pointer references; another tenant's *draft* is deletable by bare id. | the same triple predicate on every delete |
| 4 | **Publish is delete-then-insert, so any key absent from the payload is destroyed** — including `footer_repeater`, which the Flutter app writes outside the version system. | requirement 5 above is what prevents a publish from wiping a merchant's social links |
| 5 | **"What is live" is a join with `stores.theme_id`, and nothing constrains it.** `makeActiveTheme` writes it from raw request input with no permission check, no allowlist and no foreign key; switch to a theme with no published version and the storefront silently renders defaults while a pointer row still exists for the old one. | validate `theme_id` against `ThemeFieldCatalog::themes()` on every write path *and* in `makeActiveTheme` |
| 6 | **Dereferencing an event's `version_id`** crosses tenants with no engine backstop at all, by design (§6.6). | the event row carries its own `store_id` and `theme_id` — dereference with the same triple |

Row 1 verbatim, `app/Helper/helper.php`:

```php
$storeId = $user->current_store;
if (! empty($storeId)) {
    return $storeId;
} else {
    if ($user->type == 'super admin') {
        return 1; // Default store for super admin
    } else {
        $store = Store::where('created_by', $user->id)->first();
        return $store ? $store->id : null;
    }
}
// and if $user is falsy the function returns NOTHING -- an implicit null
```

Three things, all measured from that: the operator gets a **merchant's** store; a non-operator with
no owned store gets **null**, and `where('store_id', null)` compiles to `store_id IS NULL`, i.e. one
shared bucket; and an unauthenticated caller gets an implicit null by falling off the end. Note too
that `$user->type == 'super admin'` is a **loose, un-normalised** comparison, where `CLAUDE.md` §1
prescribes `strtolower(trim(…)) === 'super admin'` — so an operator recorded as `Super Admin` takes
the merchant branch instead.

**When the models land, they are the cleanest `scoped_models` candidates in the codebase** — §7.5
keeps drafts out of every storefront read path, which is the condition that disqualifies most models.
Worth noting in the config comment if it is done: the scope is inert for a customer or driver Sanctum
token and for staff with an empty `current_store`, and `StoreScope::resolveTenant()` returns
`MODE_SUPER_ADMIN` with no predicate — so it is defence in depth against a merchant, **not** the
control of record, and does nothing about row 1.

### The census tests M3-C must update deliberately

Three tenancy census tests assert **exact sets and totals**, so M3-C's new models and queries break
them *before they do anything wrong*. Each is designed to require a conscious re-classification, not
a number bump — verified verbatim on this tree:

| test | pin | what trips it |
|---|---|---|
| `tests/Feature/Tenancy/TenantModelClassificationTest.php:207` | `assertSame(95, $total)` over `glob(app/Models/*.php)` | **any** new model file — `ThemeVersion`, `ThemePublishedVersion`, `ThemePublicationEvent` |
| `tests/Feature/Tenancy/RawQueryTenancyInventoryTest.php:657` | `'theme_customizes' => [2, 0]` | the two raw Flutter statements; routing them through a service changes this row |
| same, `:475`, `:747`, `:799` | `assertSame(222, …)`, `assertSame(172, …)`, `assertSame(81, …)` | any new or removed raw `DB::table()` statement anywhere |

**There is no deliberate-defect pin anywhere in the theme surface** — all nine test files that touch
theme configuration pin *correct* behaviour — so M3 cannot break a pinned defect by fixing something.
That was checked before anything was changed, as this project requires.

### One architectural question M3 does NOT settle

Two pins disagree about whether `ThemeCustomize` should be in `scoped_models`:

- `tests/Feature/Storefront/ThemeCustomizeIsStoreScopedTest` asserts it is **not** in `scoped_models`
  and says *"That is not wrong, but this file asserts the EXPLICIT store predicate is what isolates
  the read — re-read it before relying on the scope instead."*
- `TenantModelClassificationTest::test_no_public_storefront_model_is_enforced` holds that scoping it
  would be **wrong**, with a measured storefront-blanking rationale.

M3's design needs neither answer: it adds no model to `scoped_models` and isolates every new query
with an explicit predicate plus the engine-level composite key of §6.1.1. **The question is recorded,
not resolved** — it is an owner/architecture decision, not something a milestone should decide in
passing.

### Validation environment, already prepared

| | |
|---|---|
| engine | MariaDB 10.11.14 on `/var/run/mysqld/mysqld.sock`, started locally; **disposable** |
| fresh-install check | `p3m3_probe_*` — full `migrate` from empty, clean |
| legacy-upgrade corpus | `p3m3_legacy` — 3 stores (one with `theme_id = ''`), 8 `theme_customizes` rows including stored HTML, a JSON repeater value, and an orphan key present in no `theme_json` |
| restore between runs | a `mysqldump` baseline, so each upgrade simulation starts from the same legacy state |

No database dump, credential or customer data is committed; the corpus is synthetic and lives only
in the throwaway server.

---

## 9. M3-C — the one controlled write path, and the four false greens it took to prove it

M3-B put the tables in place. M3-C puts a single authorised, validated, cache-correct writer in front
of them, and routes the two existing writers through it. **No storefront read changed**, and the
merchant form and the Flutter endpoint keep their exact HTTP contracts.

### 9.0 The outstanding M3-B evidence — resolved, and the answer is not the expected one

The directive asked to verify *"the second Feature suite and known-outcome gate covering commit
`6f22f667`"*, noting that the gate on `f2e033a5` was not sufficient evidence for the later schema
changes. **Both runs are absent, and not because one was missed.**

| | |
|---|---|
| `.github/workflows/ci.yml` triggers | `pull_request:` and `push: branches: [main, master]` — **and nothing else** |
| CI runs on `feature/phase-03-storefront` | **0**, for every commit on the branch |
| what *does* run on each push | `Security attestation (push)` only |
| `f2e033a5` attestation | `cancelled` |
| `6f22f667` attestation | `success` |

The CI `tests` job is the only thing that runs the Unit, Contract and Feature suites and the
known-outcome gate, and it cannot fire on this branch while no pull request is open. So the gate has
never covered **any** Phase 03 commit, `f2e033a5` and `6f22f667` included. This is a structural gap
in the evidence trail, not a flaky run, and no amount of re-reading GitHub will produce the missing
artefact.

What replaces it, for this package, is the same three suites plus the same gate script executed
locally on the final tree — recorded in §9.6. Opening a pull request would make CI cover the branch,
and that is an owner decision (the branch is not authorised to merge), so it is **raised, not taken**.

### 9.1 What was there before

Two writers that agreed about nothing:

| | `ThemeCustomizeController::themeCustomizeUpdate()` | `Api\DashboardController` (`updateStoreOwnerInfo`) |
|---|---|---|
| mechanism | Eloquent `updateOrCreate`, one row per posted key | **raw** `DB::table()->updateOrInsert()` |
| authorization | `isAbleTo('Edit Theme')` **commented out** | store id from the request |
| allowlist | none — `name` from arbitrary request keys | none |
| theme id | straight from the route | `stores.theme_id`, unchecked |
| cache | flushed by the Eloquent lifecycle | **never flushed** |

The raw write is the one that shipped a user-visible defect: `ThemeCustomize` is `Cachable`, so the
merchant's social links were saved, read back correctly by the app (its own read is also raw), and
**never appeared on the storefront**.

### 9.2 The pieces

| file | what it is | notes |
|---|---|---|
| `app/Support/Theme/ThemeEditorGate.php` | the authorization boundary | operator first, then ownership, then permissions |
| `app/Support/Theme/ThemeSettingValidator.php` | type-aware validation over the M3-A taxonomy | refuses an unknown type **before** dispatch |
| `app/Support/Theme/ThemePayload.php` | canonical serialisation + `sha256` | key-order independent; values never re-encoded |
| `app/Services/Theme/ThemeConfigurationService.php` | the single write path | upsert only; post-commit flush |
| `app/Models/ThemeVersion.php` | drafts | `ownedBy()` is the only safe lookup |
| `app/Models/ThemePublishedVersion.php` | the publish pointer | `liveFor()` |
| `app/Models/ThemePublicationEvent.php` | the audit row | no `belongsTo`; resolved by the store/theme/version triple |

None of the three new models is `Cachable` and none is in `config/tenancy.php`'s `scoped_models`, so
**every** predicate is explicit. The census tests were updated with a written classification for each.

### 9.3 The eight requirements, and where each is proved

| # | requirement | where |
|---|---|---|
| 1 | published configuration stays key-complete | `ThemeConfigurationServiceTest::test_a_write_upserts_named_keys_and_destroys_nothing_else` — the service **upserts named keys only** and is structurally incapable of a wholesale replacement |
| 2 | do not rely on the XSS middleware | `ThemeSettingValidatorTest` — 47 cases; refusals are structural patterns (tags, inline handlers, URL schemes, numeric character references, NUL), not a word list |
| 3 | operator-first, explicit ownership, fail-closed | `ThemeEditorGateTest` — 13 cases incl. the literal-store-1 trap and a demoted merchant |
| 4 | flush after commit | **`verify-theme-cache-flush-after-commit-on-mysql.php`** — see §9.5 |
| 5 | Flutter write through the service, contract preserved | `StoreOwnerInfoThemeWriteContractTest` — 6 cases |
| 6 | canonical serialisation and hashing | `ThemePayloadTest` — 18 cases |
| 7 | publish stored values directly | the service writes values it holds; nothing is re-POSTed through a web route, so `XSS`'s non-idempotent `htmlspecialchars_decode` is never applied twice |
| 8 | reject unknown field types before dispatch | `ThemeSettingValidator::validate()` refuses a `null` type with a reason, and `assertTypeIsKnown()` throws; the `match` has **no default branch** |

### 9.4 The six residual risks

| risk | disposition |
|---|---|
| Super Admin inheriting store 1 via `getCurrentStore()` | the gate never calls it; `storeIdForSelfService()` reproduces only the merchant branch. Pinned by two tests. |
| retrieving another tenant's version by bare id | `ThemeVersion::ownedBy($storeId, $themeId, $versionId)` is the only lookup; the composite foreign key refuses it at the engine too |
| deletion using weaker predicates | `deleteVersion()` resolves through the same gate and the same `ownedBy()`, and additionally refuses a published version |
| Flutter-written `footer_repeater` destroyed by a web write | `test_a_web_write_preserves_the_flutter_written_footer_repeater` |
| unknown active theme | `resolveThemeId()` validates against `ThemeFieldCatalog::themes()`; `makeActiveTheme()` writes `stores.theme_id` behind only `isset()`, so the stored column is not trusted |
| publication events unscoped | resolved by the (store, theme, version) triple, never by version id alone |

### 9.5 Requirement 4 — four measurements, three of them wrong

This is the finding worth keeping, because the control nearly shipped unproven.

**The claim.** The `Cachable` lifecycle flush fires on the model's `saved` event, *inside* the
transaction. A concurrent reader on another connection can re-cache the pre-commit state in the
window before the commit, and then serve it indefinitely. `DB::afterCommit()` closes that window.

**Attempt 1 — single process.** Deleting the post-commit flush left every assertion green. A reader
on the *same* connection sees the uncommitted row, so it caches the NEW value; the stale state is
unreachable.

**Attempt 2 — two processes, no caching premise.** 4 checks, 0 failed **with the flush deleted**.
Reading `OLD` proves the write is invisible; it does *not* prove anything was *cached*. With nothing
cached there is no stale entry to clear, so the checks pass either way.

**Attempt 3 — proving the cache by mutating a second key.** Wrong, and it produced two failures
describing a cache problem that never happened: `updateOrCreate` takes next-key locks over the index
range it scans, so the parent's `UPDATE` on a *different* row blocked on the child's transaction, the
child hit its own timeout and **rolled back**. A harness that touches the table the other process has
open is measuring the lock manager.

**Attempt 4 — and the real finding.** Proving the cache by *query count* (a repeat read must issue no
`theme_customizes` SELECT) made the premise sound, and the checks **still** passed with the flush
deleted. Instrumenting the parent showed why: **5 cache rows before the child's commit, 0 after.**

> `flushCache()` on a non-taggable store is `Cache::flush()`. On the **database** store that is
> `DELETE FROM cache` — a statement which **joins the open transaction**. It is invisible to other
> connections until the commit, and when it lands it also deletes whatever a concurrent reader cached
> in the meantime. With `CACHE_STORE=database` and `MODEL_CACHE_STORE` unset — the shipped production
> combination — the in-transaction flush already behaves like a post-commit flush, and the race
> cannot occur.

The race is real on **every other store**: file, redis, memcached, array. Those flushes are immediate
and outside the transaction. Re-running with `MODEL_CACHE_STORE=file` gives the discrimination the
requirement needs:

| build | result |
|---|---|
| `DB::afterCommit(...)` present | **4 checks, 0 failed, 0 inconclusive** |
| `DB::afterCommit(...)` removed, flush inside the transaction only | **check 4 FAILS** — the storefront read serves `OLD` after the commit |

So the control is **not** load-bearing on today's production configuration, and **is** load-bearing
the moment `MODEL_CACHE_STORE` is set — which `config/laravel-model-caching.php` exists to allow.
It keeps that a configuration choice rather than a correctness one. Both the service docblock and the
harness record this; neither claims more.

A matching correction was made to `ThemeConfigurationServiceTest`: its cache test proves the
raw-vs-service contrast, which is the regression that actually shipped, and explicitly does **not**
claim the post-commit guarantee, which is proved only by the two-process harness.

### 9.6 Evidence

| gate | result |
|---|---|
| `php artisan test --testsuite=Unit` | 1788 passed, 1 skipped |
| `php artisan test --testsuite=Contract` | 44 passed |
| `tests/Unit/Theme/ThemePayloadTest` | 18 passed |
| `tests/Unit/Theme/ThemeSettingValidatorTest` | 47 passed |
| `tests/Feature/Theme/ThemeEditorGateTest` | 13 passed |
| `tests/Feature/Theme/ThemeConfigurationServiceTest` | 19 passed |
| `tests/Contract/StoreOwnerInfoThemeWriteContractTest` | 6 passed |
| `verify-theme-version-concurrency-on-mysql.php` | 7 passed, 0 failed, 0 inconclusive |
| `verify-theme-cache-flush-after-commit-on-mysql.php` | 4 checks, 0 failed, 0 inconclusive; **check 4 fails when the control is removed** |

The Feature suite and known-outcome gate result for this tree is recorded in §9.7.

### 9.7 Falsification log for this package

Every control below was removed and the suite re-run, because a green test that cannot fail is not
evidence. This is the project's standing rule and it caught three defects in this package alone.

| control removed | what happened |
|---|---|
| the service in `Api\DashboardController` (back to the raw write) | `the links land in the projection and reach the storefront read` **fails**, and `a service refusal does not change the status code` **fails** |
| the cache priming in that contract test | the raw write **passes** — the test was measuring the absence of a cache |
| `DB::afterCommit` in the service, `MODEL_CACHE_STORE=file` | harness check 4 **fails** |
| `DB::afterCommit` in the service, model cache on the database store | harness check 4 **passes** — see §9.5 |

---

## 10. M3-C review response — a real tenant hole, two writers the package missed, and a test that could not fail

An independent tenant-authorization review of §9 found six things. Five were right. Each was
re-measured here before acting, because a reviewer's claim is not evidence either.

### 10.1 The finding that mattered: `creatorId() == 1` was treated as a tenant root

**Confirmed, and it was mine.** The gate's predicate compares `stores.created_by` to the caller's
resolved root. The docblock argued a demoted merchant was harmless because *"store 1 is one store"* —
which **confused `created_by` with `id`**, and the store that satisfies it is the platform
operator's own:

```php
// database/seeders/PermissionTableSeeder.php:41-47  (the shipped installer)
$superAdminStore = Store::create([ ... 'created_by' => $superadmin->id ]);
```

The super admin is the first user created, so in a fresh install that store has `created_by = 1`.
A merchant who demotes their own `type` through the product — `UserPolicy`'s docblock records this
as supported, since `RolePolicy::RESERVED_NAMES` blocks only the `admin`/`super admin` spellings —
drops to `creatorId() = 1` and matches it.

**The test that was supposed to catch this could not distinguish the defect from the fix.** It
asserted only that the demoted merchant could not reach `storeB`, whose `created_by` is merchant B's
id — a store no root-of-1 predicate could ever match. It never created a store with
`created_by = 1`, which is the only case the claim was about. (Precisely: it is not *incapable* of
failing — it would fail against a gate with no ownership predicate at all, so it is a real if weaker
pin. Measured: it passes against both the defect and the fix, which is the property that matters.)

The fix reuses the predicate the house already built for exactly this collapse (it was an account
takeover once: `docs/security/P0-USER-DELETION-CASCADE.md` §7) rather than writing a second one:
`UserPolicy::tenantRootIsAMerchant()` requires a tenant root to be a **type-`admin`** row, which
excludes both the operator and the demoted user. It was `protected`; it is now `public`, for the
same reason `isOperator()` already is. Applied at **both** sites — `resolveStore()` and
`ownsStore()` — so a caller reaching `allows()` by another route cannot get a weaker rule.

Measured: the new test fails before the fix and passes after, and `the operator still reaches their
own store` guards against over-correcting into a lockout.

**Not verified against production.** `SELECT id FROM stores WHERE created_by = 1` would settle
whether the operator's seeded store still exists there. Live in any freshly seeded environment.

### 10.2 Two more unguarded writers, in the file the package had already edited

§9 said the two writers were closed. Two *others* sat in the same controller, below the fix, and the
M3-B migration had already named one: *"`makeActiveTheme` needs the same predicate. One check closes
both."*

| route | method | what it had |
|---|---|---|
| `POST theme-preview/make-active` (`web.php:1448`) | `makeActiveTheme()` | **no check of any kind**; `Store::where('id', getCurrentStore())->update(['theme_id' => $request->theme_id])`, `theme_id` behind only `isset()` |
| `POST theme-customize/{theme}/order/update` (`web.php:1447`) | `updateOrder()` | **no check of any kind**; `store_id => getCurrentStore()` |

Both sit in the `['auth','xss','setlocate','verified']` group with no permission middleware, so a
direct POST from any authenticated verified user is the live path. The GET sibling `changeOrder()`
*does* check `isAbleTo('Manage Themes')` — the same inverted guard M3-A measured on this controller,
the read locked and the write open.

**Two severity corrections, from an independent re-verification that measured both handlers
end to end.**

*It narrows one way.* `getCurrentStore()` is not caller-supplied: `users.current_store` is already
constrained to owned stores (`StoreController:873-883`, `Api\DashboardController:4895-4899`, both
R4-03 fixes). So this is **not** a merchant-to-merchant crossing. For staff it is a *permission*
failure — a sub-user with no role and no permission rewriting their employer's storefront — not a
tenancy one.

*And it confirms the one limb that is cross-tenant.* Driven on a fully migrated install, as the
platform operator with `current_store` NULL: `POST theme-preview/make-active` with
`theme_id=operator-wrote-this` set **store 1's `theme_id`** to that value, with store 1's
`created_by` asserted to be a merchant in the same test. The operator named no store. The same
principal also wrote a `theme_customize_orders` row for that merchant's store.

Both now resolve through `ThemeEditorGate` and validate `theme_id` against
`ThemeFieldCatalog::themes()`. The unvalidated `theme_id` was not cosmetic: `stores.theme_id` is
`varchar(191)`, a longer value **truncates silently** on MySQL, and any unknown value makes
`getThemeSetting()` fall back to `config('theme.active')` — the storefront renders a theme nobody
chose.

### 10.3 No test drove the web routes at all

Also confirmed: nothing in the suite drove `POST theme-customize/{theme}/update`. Reverting the
controller left the **entire M3-C suite green**. `tests/Feature/Theme/ThemeCustomizeWebRoutesTest`
now drives all three write routes over HTTP. Falsified against the pre-M3-C controller: **6 of 11
fail**, including all three operator cases, while the merchant and staff positives pass either way —
which is the correct shape.

### 10.4 And the environment artefact that made the first version of that test worthless

Writing it surfaced a fourth false green, of a kind worth recording beyond this package.

**`App\Http\Middleware\XSS::handle():56-78` redirects ANY `super admin` to the installer's updater
(`/update`) when it finds a single unrecorded migration — before the controller runs.** The first
version of the file asserted *"an operator cannot repoint a merchant's active theme"* and **passed
against the completely unguarded controller**. Measured side by side:

| principal | status | redirect | row after |
|---|---|---|---|
| operator | 302 | `http://localhost/update` | **unchanged** |
| merchant | 302 | `http://localhost` | changed |

The operator never reached the code. And the pending migration is not one of this repository's:
`database/migrations/*.php` is fully recorded in a test run (**measured: 0 pending**). The
middleware also walks `Module::allModules()` and merges
`"packages/workdo/{$module->name}/src/Database/Migrations"` — a **relative** path, which
`File::exists()` resolves against the working directory, so the files are found — and no WorkDo
module migration ever runs in a test, so every one of them is pending forever.

**Any test that asserts a platform operator is refused on a `web` + `xss` route may be measuring
this redirect rather than the control it names.** The test now replicates the middleware's own
computation to record those migrations, which is narrower than disabling `xss`, and carries a
premise test — `test_the_operator_actually_reaches_the_controller` — that fails loudly if the
redirect ever returns.

### 10.4b `theme_customize_orders` has no `theme_id` column, and both paths answered 500 on MySQL

The sharpest thing the re-verification found, and **M3-C's first fix carried the defect forward.**

The table is `id, page_slug, orders, store_id, created_at, updated_at` and nothing else
(`2025_05_23_043026_create_theme_customize_orders_table.php`). Both the read in `changeOrder()` and
the write in `updateOrder()` filtered on `theme_id`. Measured on MariaDB 10.11.14, against the table
built from that migration:

```
SELECT * FROM theme_customize_orders WHERE theme_id = 'stylique' AND page_slug = 'home' ...
ERROR 1054 (42S22): Unknown column 'theme_id' in 'WHERE'
```

So section ordering — read **and** write — has been answering **500 in production**, and the suite
could never have seen it. **SQLite does not error:** it reinterprets the unresolvable identifier as a
string literal, compares `'theme_id' = 'stylique'`, matches nothing, and the handler carries on. A
Feature test driving the route passes with the defect in place — one was written here and did
exactly that. This is the SQLite-hides-MySQL hazard CLAUDE.md records, caught by an engine rather
than by a test.

Both predicates are removed. **The consequence is stated rather than hidden: section order is keyed
on (store, page) and is therefore SHARED ACROSS THEMES.** Keying it per theme needs a new column,
which needs a migration against live data — an owner decision, so it is recorded here and not taken.

The guard is `tests/Unit/Theme/ThemeCustomizeOrderColumnsExistTest`, which compares the **source**
to the **migration** rather than to a database, because a database assertion inherits the same
engine blindness. Falsified: restoring `theme_id` fails it with the file and line.

### 10.4c The observability inventory is LINE-ABSOLUTE, and I tripped it three times

Worth recording because the cause looked mysterious twice and is not mysterious at all.

`docs/security/P01-OBSERVABILITY-INVENTORY.md` keys each security log line by `file:line`, and
`SecurityLogInventoryTest` compares that to a census of the source. **Every edit above a logged line
moves it and invalidates the row.** The `\Log::warning('theme settings write refused', ...)` added
in §9 moved three times as this package grew:

| tree | line |
|---|---|
| `0c9fec6b` | 194 |
| `54bd178e` | 207 |
| `5ded186a` | **212** |

Two Feature runs were discarded as having read a mid-edit working tree, and one of them was
described here as unreproducible. **That was wrong, and the correction belongs in the record:** the
runs were reading real trees whose line numbers had already drifted past the row I had written by
hand. Nothing was racing; I was editing above a logged line and not re-syncing.

The repository already ships the right mechanism, and it is the one to use rather than editing the
row: `python3 docs/development/phase-1/tools/resync-observability-inventory.py --write` re-points
rows by `(file, message)` and **refuses** to write when a line was added or deleted rather than
moved — so it cannot be used to paper over a genuinely new log line. Here it reported `rows
re-pointed: 1, rows unchanged: 223`.

### 10.5 The image-filename regression M3-C introduced

Confirmed. The stored name is built from the **raw client filename**
(`pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)`), while the new validator's
`IMAGE_PATTERN` allows only `[A-Za-z0-9._/-]`. So `my logo.png` — **or any Arabic filename, in a
bilingual product** — was written to disk and then reported as "not saved".

Two corrections to that description, from an adversarial re-verification of the finding itself:

- **"silently" is wrong.** The refusal *is* named — "The following settings were not saved:
  header_logo" — but it is shown **beside** "Setting Saved Successfully." and **after** the file is
  already on disk. The defect is the contradictory pair, not silence.
- **"a space or non-ASCII" understates it.** `IMAGE_PATTERN` is `~^[A-Za-z0-9._/-]*$~`, so a
  parenthesis, an ampersand, a comma or a plus sign is refused too — `Logo (final).png` fails.

And **there were three construction sites, not two.** The first pass slugged `image` in the repeater
branch and the direct `_image`/`_logo`/`_favicon` branch, and missed `background_image` inside the
repeater — its sibling two lines above was fixed while it was not. Found only by re-verification.
Fixed by slugging at all three rather than widening the path rule. Measured:

| input | `Str::slug()` |
|---|---|
| `my logo` | `my-logo` |
| `شعار المتجر` | `shaaar-almtgr` |
| `صابون حلب` | `sabon-hlb` |
| `../../etc/passwd` | `etcpasswd` |
| `🎉` | `''` → falls back to `image` |

### 10.6 The scheme rule refused ordinary copy

Confirmed. `~(?:javascript|vbscript|data)\s*:~i` had no left word boundary and no context, so it
refused `metadata:` and `Big data: our approach` — the S-09 failure mode this class exists to avoid.
Split into three precise rules: `javascript:`/`vbscript:` anywhere (never legitimate prose), `data:`
only in an attribute or `url()` position, and `data:` when the value *is* a data URL. Ten cases
asserted in both directions.

### 10.7 Findings recorded without a code change

| finding | disposition |
|---|---|
| `allows()`'s permission limb is unreachable, and the docblock's "the named permissions still gate STAFF" is false | **Accurate, and the limb is now DELETED along with the sentence.** Re-verification showed the gap I had claimed for it — a store with `created_by IS NULL` — is closed too, because `tenantRootIsAMerchant()` makes `ownsStore()`'s extra conjuncts automatically true for anything that gets past `resolveStore()`. Dead code shaped like an authorization check is worse than no code: the next reader believes `Manage Themes` restricts something, and it restricts nothing. What actually entitles is stated plainly in its place — **tenant-root resolution, so a merchant and every user they created, staff included, with no permission of any kind.** `PERMISSIONS` is kept as a record that nothing reads. |
| `ThemePublishedVersion::version()` is a bare-id dereference | **Accurate, and now replaced** by `liveVersion()`, which resolves through `ThemeVersion::ownedBy()` on the full triple. Not deferred to M3-D: a relation in a model is a precedent that gets copied into a handler where no composite key exists. Pinned and falsified — reverting it to `ThemeVersion::find($this->version_id)` fails the test. Measured along the way: this suite **does** enforce SQLite foreign keys, so the bad row cannot be inserted at all, and `PRAGMA foreign_keys = OFF` is a documented **no-op inside a transaction**, which `RefreshDatabase` always has open. The test therefore exercises an unsaved pointer carrying the mismatched triple — what a restored or imported row looks like when read back. |
| `isOperator()` used `trim` where `UserPolicy` deliberately uses `rtrim` | **Accurate and acted on.** `trim` is WIDER than MySQL's PAD SPACE collation: `' super admin'` is the operator to `trim` and to no SQL query. Latent, but a cross-tenant gate is the worst place to be wider than the engine. Now delegates to `UserPolicy::isOperator()`; the divergence from CLAUDE.md's wording is deliberate and recorded. Both directions pinned. |
| the raw-query ratchet might be matching its own comment | **Refuted by the reviewer itself** — `codeLines()` skips `*` continuation lines, so the counts are right. |
