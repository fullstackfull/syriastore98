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
| M3-C | versioned storage model, backward-compatible reads, type-aware write validation (§7.2, §7.3) | **complete** | `6bd3e4d2`, §9–§10 |
| M3-D | draft/published state, atomic publish, rollback semantics, **post-commit** cache flush (§4.5) | **complete** | §11 |
| M3-E | tenancy and concurrency regression tests, legacy-upgrade validation, closure | **complete** | §12 |

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

### 9.6b The closing gate run — `6bd3e4d2`

```
Tests:  2 failed, 1 skipped, 8628 passed (44101 assertions)

KNOWN-OUTCOME GATE
testcases observed   : 8631  (manifest floor 1000)
failures observed    : 2  (manifest declares 2)
errors observed      : 0  (always expected: 0)
skips observed       : 1  (manifest declares 1)

OK: the observed outcome set matches the manifest exactly -- 2 declared failures,
    1 declared skips, 0 errors, and nothing undeclared.
gate exit=0
```

Quoting the gate's own closing words, because they are the right caveat and not a formality:
*"This is NOT a statement that the suite is green. It is a statement that the suite is failing and
skipping exactly the tests the project has recorded, for the reasons it has recorded."* The two
declared failures are `RegistrationTest::test_new_users_can_register` and
`ProfileTest::test_profile_page_is_displayed`. **No entry was added to the manifest at any point in
this package** — the one undeclared failure that appeared, the observability inventory row, was
fixed rather than declared.

Five Feature runs were made. Three were discarded and the reason is recorded in §10.4c: the
inventory is line-absolute and the log line moved under me. One was lost to a container restart.
This is the fifth, on the committed tree, with nothing edited while it ran.

Everything above was re-measured after that restart rather than carried over.

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

---

## 11. M3-D — atomic publishing, rollback, and theme-isolated section ordering

### 11.1 `theme_customize_orders.theme_id` — the audit, the migration, and what production would need

**Owner decision implemented:** developing this migration in isolated development/test databases is
approved; **applying it to production is not.** Nothing below ran against a live host. The
verification script creates and drops its own throwaway database.

#### The audit came first, because the owner's conditions are about live data

`report-theme-customize-order-duplicates.php` is read-only and issues no DDL. Run against both
databases available here:

| | fresh MariaDB 10.11.14 | `database/p2dev.sqlite` |
|---|---|---|
| rows | **0** | **0** |
| `store_id IS NULL` | 0 | 0 |
| duplicate `(store_id, page_slug)` | none | none |
| indexes reported | `PRIMARY(id)`, `theme_customize_orders_store_id_foreign(store_id)` | **none** |

The index row is the point. SQLite reports **no index** while InnoDB has created one for the foreign
key — the same disagreement M3-B measured on `theme_customizes`. **And both databases hold zero
rows, so neither proves anything about production.** That is precisely why the migration adds no
unique key: a `UNIQUE(store_id, theme_id, page_slug)` meets the first duplicate pair and fails the
migration mid-run, on a table every theme page reads.

#### What the migration does, and the one thing it refuses to do

`2026_11_15_000100` adds `theme_id VARCHAR(191) NULL` and a **non-unique** index
`(store_id, theme_id, page_slug)`. It backfills **nothing**.

The obvious backfill — `SET theme_id = (SELECT theme_id FROM stores ...)` — is wrong, and not
marginally. `stores.theme_id` is the theme active **today**; the order row was written while some
theme was active **then**, and this table never recorded which. For any merchant who has switched
theme since, that statement does not recover a fact, it **invents** one — and a false record is
worse than an absent one because nothing downstream can tell it is false. This is the "do not blindly
assign legacy rows to arbitrary themes" condition, and it is the reason the column is nullable.

So `NULL` reads as *"applies to whichever theme has no row of its own"*, in exactly one place —
`ThemeCustomizeOrder::resolveFor()`: theme-specific first, legacy second. Measured consequence: the
first time a merchant reorders a page under a theme, that theme gets a row and every other theme
keeps falling back. **Nothing a merchant can see changes until they next reorder something.**

#### The rollback trap reproduced — and the mechanism measured exactly

M3-B recorded that adding a composite index beginning with a foreign-key column lets MariaDB drop
the FK's own index, after which dropping the composite fails with errno 1553. A first reproduction
here appeared to **contradict** that, and chasing the contradiction produced the real rule:

| how the single-column index exists | adding a covering composite | then dropping the composite |
|---|---|---|
| declared **explicitly** (`KEY ... (store_id)` in the CREATE) | **kept** | succeeds |
| created **only by the foreign key** — what Laravel's `$table->foreign()` does | **discarded** | `ERROR 1553 (HY000): Cannot drop index … needed in a foreign key constraint` |

`theme_customize_orders` is the second case. After a full `migrate`, `SHOW CREATE TABLE` holds the
constraint and exactly one index serving it:

```sql
PRIMARY KEY (`id`),
KEY `tco_store_theme_page_index` (`store_id`,`theme_id`,`page_slug`),
CONSTRAINT `theme_customize_orders_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`)
```

`theme_customize_orders_store_id_foreign` is **gone as an index**. So `down()` restores a
single-column index *before* dropping the composite. **Falsified:** delete that restore and the
rollback fails at the `dropIndex` call.

#### Evidence

| | |
|---|---|
| `verify-theme-order-theme-id-migration-on-mysql.php` | **20 checks, 0 failed, 0 inconclusive** — fresh install, legacy upgrade, rollback, re-apply, under `sql_mode = NO_ENGINE_SUBSTITUTION` |
| `tests/Feature/Theme/ThemeOrderResolutionTest` | 6 passed — Stylique, Techzonix, Greentic and the empty-theme fallback, cross-theme isolation, the tenant boundary, and that resolving never writes |
| falsification: legacy fallback removed | 2 tests fail |
| falsification: theme-specific lookup skipped | 2 tests fail |
| falsification: `down()` restore removed | rollback fails |
| rollback cost, measured | **every `orders` value survives**; only the theme attribution is discarded, leaving two rows per `(store, page)` that pre-M3-D code resolves with `->first()` |

#### THE FUTURE PRODUCTION MIGRATION AND BACKFILL PROCEDURE — not authorised, written down

This is the procedure the owner would authorise separately. It is recorded here so the decision has
a concrete plan attached rather than being re-derived under pressure.

**Step 0 — audit, before anything.** Run the read-only reporter against production:

```bash
php tests/Support/Security/bin/report-theme-customize-order-duplicates.php --database=<prod> --user=<user> --pass=<pass>
```

Exit `0` means no duplicate `(store_id, page_slug)` pairs and no NULL-owner rows. **Exit `1` means a
later unique constraint is unsafe and must not be attempted**; the output lists every offending
group. Keep the output as evidence — this is the only step that answers a question this repository
cannot.

**Step 1 — apply the additive migration.** `2026_11_15_000100` adds a nullable column and a
non-unique index. It takes a brief metadata lock; on InnoDB with `ALGORITHM=INPLACE` the column add
does not rewrite the table, but the index build scans it, so run it in a maintenance window sized
from the real row count (step 0 reports it). **It backfills nothing and cannot lose data.**

**Step 2 — do NOT backfill.** State the default plainly: leaving every legacy row at `NULL` is
correct and complete. Ordering keeps working for every theme, and merchants acquire theme-specific
rows naturally as they reorder. There is no deadline and no degraded state to escape.

**Step 3 — if a backfill is nevertheless wanted**, it must be a *decision about the product*, not a
data repair, because the information needed does not exist. The only defensible form is: for stores
whose `stores.theme_id` has demonstrably never changed, assign that theme. There is no column
recording theme changes, so "demonstrably" requires an external source — an audit log export, a
backup comparison — and if none exists, **the honest answer is that the backfill cannot be
justified** and step 2 stands.

**Step 4 — a unique constraint is a separate, later change** and needs step 0 to have exited `0`
*and* a decision about what a duplicate group means. It is not part of this migration.

**Rollback plan.** `migrate:rollback --step=1`. Measured above: no `orders` value is lost; theme
attribution is. A store that had per-theme ordering comes back with several rows per
`(store_id, page_slug)` and pre-M3-D code resolves that with `->first()`. Recoverable and visible.

### 11.2 The publishing races — and the two production defects they found in my own service

`verify-theme-publish-concurrency-on-mysql.php`: two real processes, file semaphores rather than
sleeps, production's `sql_mode = NO_ENGINE_SUBSTITUTION`, and `MODEL_CACHE_STORE=file` (the database
store's flush is transactional, so the race it closes cannot occur there — M3-C measured that).

**Why none of it can be a Feature test.** Every claim is about what a *second* principal sees while a
*first* holds a transaction open. `RefreshDatabase` gives one connection, and worse, a
single-connection reader sees its own uncommitted writes — so a "the reader never sees half a
publish" assertion passes whether or not the control exists.

| scenario | what it proves |
|---|---|
| 1. publisher vs publisher | exactly one of two concurrent first-publishes is accepted, the loser is **told**, one pointer row, one history row |
| 2. publisher vs storefront reader | a second connection sees neither the uncommitted projection write nor the pointer move — **the storefront never sees a partially published configuration** |
| 3. draft save vs an open publish | a draft save is not blocked, and does not wait on a lock |
| 4. a failed publish | projection, pointer and history byte-identical; the abandoned version still a draft |
| 5. cache repopulation before commit | the stale entry a concurrent reader cached pre-commit is cleared after it |
| 6. a contended rollback | exactly one of a rollback and a concurrent publish is accepted; pointer and projection still agree |

**20 checks, 0 failed, 0 inconclusive — over eight consecutive runs.** Falsified: removing the
conflict check fails 4, restoring the pessimistic lock aborts the run, removing the post-commit
flush fails 3.

#### Defect 1 — a draft save blocked behind an open publish and died after 50 seconds

Scenario 3 exists because the directive listed "draft save vs publish". It failed immediately:

```
SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded
select max(`version_no`) ... where store_id = ? and theme_id = ? for update
```

`saveDraft()` allocated `version_no` under `lockForUpdate()` over the `theme_versions` range —
**the same rows a publish updates when it marks a version published, and holds until commit.** The
reasoning was right about two draft saves and wrong about everything else. In production a merchant
pressing *Save draft* during a publish waited out `innodb_lock_wait_timeout` and got a 500.

`tv_store_theme_version_unique` was always the real arbiter; the lock merely avoided ever meeting
it. Allocation is now optimistic — read the max, insert, retry on the unique violation, bounded at
five attempts. No lock on the range, so no contention with an unrelated operation.

#### Defect 2 — a deadlock victim got a 500 instead of the refusal the service promises

After that fix the harness still failed **intermittently**: two runs in four. Three wrong
explanations were tried and discarded before the right one, and the discarded ones matter because
each looked plausible:

1. *Model-cache contamination across runs.* Real — `MODEL_CACHE_STORE=file` persists between runs
   and laravel-model-caching's keys do not include the database name, so a fresh run's first read hit
   a previous run's rows from a database that no longer existed. The harness now clears the store
   first and asserts it started empty. **It was not the cause of the intermittency**, but a harness
   that inherits another run's cache could as easily make a broken control look fine.
2. *The `.gitkeep` placeholder* counted as a surviving cache file, so the new premise failed against
   a cache that was genuinely empty — the harness contaminating its own measurement.
3. Only then, capturing a failing run rather than re-running until green:

```
THREW: Illuminate\Database\DeadlockException:
SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock
```

**Both transactions take `lockForUpdate()` on a pointer row that does not exist yet**, which under
REPEATABLE READ is a gap lock on the unique index range. Two gap locks on the same range coexist;
the insert-intention locks that follow do not. Each waits for the other's gap, InnoDB kills one —
*before* anyone reaches the duplicate key. Which path a race takes is timing, which is why it showed
up in half the runs and would have shown up in production as an occasional 500 on *Publish*.

And the fix took two attempts, because adding the deadlock codes to the existing handler **changed
nothing**:

```
Illuminate\Database\DeadlockException extends PDOException
class_parents() -> PDOException -> RuntimeException -> Exception
is_a(..., QueryException::class) -> NO
```

**Laravel's deadlock exception sits outside the `QueryException` hierarchy**, so
`catch (QueryException $e)` cannot see it. Any handler anywhere that catches `QueryException` to deal
with concurrency has the same hole. The catch is now `Throwable` with explicit classification —
deadlock, lock-wait timeout, or duplicate key refuse; everything else is rethrown unchanged.

A deadlock is **not** retried automatically: the caller holds an expectation about which version is
live, and a silent retry would re-run the publish against a world that just changed underneath it.
The optimistic check would usually catch that, and "usually" is not a property to build a publish on.

### 11.3 A duplicate published projection made a publish INVISIBLE — measured, not predicted

The directive's negative-test list names "duplicate or partial published projections". The partial
half was covered by §11.2's all-or-nothing rule. **The duplicate half was not covered, and writing
the test found a real defect.**

`theme_customizes` has **no unique index** on `(store_id, theme_id, name)` — an M3-B decision, taken
because a unique index meets the first duplicate pair and fails the migration mid-run on the table
every storefront page reads, and whether production holds such a pair cannot be measured from here.
So uniqueness is an *assumption*, and the two sides of the application disagree about which row of a
duplicate group wins:

| | which row wins |
|---|---|
| READ — `getThemeSetting()` → `pluck('value', 'name')` | the **last** |
| WRITE — `updateOrCreate(...)` | the **first** |

Measured, with two rows for one name:

```
publish reported: ACCEPTED, written = ['footer_description' => 'PUBLISHED VALUE']
storefront shows: 'LAST ROW'
```

**The publish was invisible** — indistinguishable, to the merchant, from the publish having failed.
That is the Flutter defect of M3-C arriving by a different route, and it would have shipped.

**Fixed in both writers** — the publish path and `writeSettings()`, since both write that table
through the same assumption — by updating **every** row of the name, so whichever one the reader
takes carries the new value.

**Nothing is deleted.** Collapsing the duplicate group would be a destructive write against merchant
content to work around a data problem these services did not create, and it would hide the duplicate
from `report-theme-customize-duplicates.php` instead of fixing it. A test asserts the group is still
two rows after the write.

One consequence worth stating: a builder `update()` fires no model events, so the `Cachable`
lifecycle flush no longer happens on either path. That is not a regression — it makes the
**post-commit flush the only invalidation**, which is the one that is actually correct under
concurrency. Re-falsified after the change: removing `DB::afterCommit` still fails the cache
harness's check 4, and the publish concurrency harness is 20/0/0 over four further runs.

### 11.4 M3-D independent review — and the claim of mine that was backwards

Verdict: **the publish surface is clean.** The reviewer could not construct a principal reaching a
store, version, draft or history it does not own, and traced each of the six questions put to it
rather than agreeing. Three findings, plus two corrections to facts I had asserted.

#### F1 — `ThemeCustomizeOrder` **is** in `scoped_models`. I said it was not, in three places.

My docblock, my test comment and my brief to the reviewer all stated that
`theme_customize_orders` is **not** in `config/tenancy.php`'s `scoped_models`, and that the caller's
predicate is therefore the only tenant boundary. **Verified myself after the review raised it:**

```
config/tenancy.php:176          App\Models\ThemeCustomizeOrder::class,   (with a justification at :170-175)
ThemeCustomizeOrder             use Cachable, BelongsToStore;
getGlobalScopes()               App\Scopes\StoreScope
```

It is scoped. This is exactly the inverted-absence claim CLAUDE.md's *"check before asserting
absence"* rule exists for, and I made it while writing a milestone whose whole subject is tenancy.

**Nothing is broken by it**, and the reviewer traced that rather than assuming: the scope's
admissible set is `{current_store} ∪ {stores created_by = creatorId()}`, both call sites pass an id
that is always a member, and for an operator the scope adds nothing. Predicate and scope agree. In
`ThemeOrderResolutionTest` no principal is authenticated, so the scope is unresolved and
`null_tenant => 'open'` adds no predicate — which means those assertions really are proving the
explicit filter. **The tests were honest; the reason printed inside them was not.** All three
statements corrected.

#### F2 — the one new store-id derivation in M3-D used `getCurrentStore()`

`changeOrder()` resolved its tenant with `(int) getCurrentStore()` while its write sibling fifty
lines down proved ownership through the gate. My asymmetry.

**Latent, not live — and the reason is not in the handler.** `isAbleTo('Manage Themes')` above it
keeps the operator out, because `Utility::addNewData()`'s `$superadminNewPermission` does not contain
that permission. **So the only thing between a support engineer and a merchant's section ordering was
an entry missing from a seed list.** A tenant boundary that depends on a seed list is not a tenant
boundary. Now resolved through `ThemeEditorGate` with the theme catalog-validated, as its sibling
already was.

#### Correction — `Manage Themes` IS held in production. My gate docblock said no role holds it.

That measurement was from the **test fixture**. In production `Utility::addNewData()` grants it to
the shared global role named `admin` — the name is in `$companyNewPermission` (`Utility.php:3259`),
granted at `:3287-3292` to `Role::where('name','LIKE','admin')->first()`, and `addNewData()` runs on
login and registration. **Verified.** So every merchant on that role holds it and the four GET theme
actions are a live surface.

Wrong in the direction that matters: a reader who believed the check was dead code could delete it,
and it is the only thing keeping an operator off `changeOrder()`. Deleting the permission *limb* from
`allows()` remains correct — its unreachability argument stands — but the sentence has been narrowed
to what was actually measured. `Edit Theme` genuinely is not a permission row.

#### F3 — `isUniqueViolation()` cannot distinguish constraint classes on SQLite

The docblock claimed `19 on SQLite` identified a duplicate key specifically. It does not: pdo_sqlite
reports `SQLITE_CONSTRAINT = 19` with SQLSTATE 23000 for **every** constraint class, and the extended
codes never reach `errorInfo[1]`. Exact on MySQL/MariaDB (1062 / 1452 / 1048), coarse on the engine
the suite runs on.

Bounded, and the reviewer checked the bound rather than asserting safety: the attempt cap in
`saveDraft()` is what prevents a loop, not the classification; the only unique index reachable inside
`apply()`'s transaction is `tpv_store_theme_unique`, where a duplicate genuinely *is* a concurrent
publisher; and authorization is decided **before** the `try`, so a misclassification can only turn
one refusal into a differently-worded refusal, never into an allow. Both docblocks narrowed.

#### A ratchet of mine had weakened, and the review caught it

`ThemeCustomizeOrderColumnsExistTest` scanned only the controller — and M3-D moved the
`where('theme_id', …)` predicates **out** of it into `ThemeCustomizeOrder::resolveFor()`. Not
vacuous (the controller's `updateOrCreate` still named the column) but the queries that now carry it
were unchecked. The census reads both files, handles `static::query()` and `whereNull`, and carries a
non-vacuity premise. Falsified: a bogus column in the **model** now fails it with file and line.

#### Recorded, deliberately not changed

| item | why |
|---|---|
| `themeCustomize():61` still uses `getCurrentStore()` | **Pre-existing, not M3-D's.** Same latent shape, same permission guard, but it is a GET display path whose callers I have not measured, and the directive warns against rewriting legacy tenancy behaviour without doing so. **For M4**, which owns the visual editor. |
| a refused publish leaves no trace | `apply()` returns null with a reason and logs nothing, so a cross-tenant attempt is invisible afterwards. The sibling path already logs its refusal with `auth()->id()`. This belongs in the controller that wires publishing up — **which does not exist yet**. For M4. |

#### The framing to carry into M4, stated plainly

**Nothing routes to `ThemePublishingService`.** Its only callers are its own test file and the
concurrency harness — verified. So the whole publish surface is **correct and latent**, and the
controller that exposes it is where the request-input question is actually decided. Two shapes to
watch there: the `?Authenticatable $user` parameter decides both authorization *and* the `actor_id`
written into the audit event, so it must be `$request->user()` and never a user loaded from request
input; and on the admin API, `ApiResponser::error()`'s HTTP 200 convention applies, where a new 401
would log the shipped Flutter app out.

---

## 12. M3-E — an interrupted migration was a permanent, silent loss of the cross-tenant backstop

The first thing M3-E's discovery looked at was the forward-migration path against live data. It found
a defect in my own M3-B migrations, and it is the worst of this phase because **nothing would have
reported it.**

### What was there

All three M3-B migrations opened with a blind early return:

```php
if (Schema::hasTable('theme_published_versions')) {
    return;
}
```

**MySQL DDL is not transactional.** A migration that fails part way leaves its `CREATE TABLE`
committed and its constraints missing — and is *not* recorded as applied, so the next deployment
attempts it again. Reproduced on MariaDB 10.11.14 with the partial table in place:

```
2026_11_13_000200_create_theme_published_versions_table ........ 0.54ms DONE
exit 0

SELECT constraint_name FROM information_schema.referential_constraints ...
-> tpv_store_id_foreign            (and nothing else)
```

**`tpv_version_identity_foreign` was permanently absent while `migrate:status` showed green.** That
is the constraint `2026_11_13_000100:167` calls *"THE KEY THAT MAKES CROSS-TENANT PUBLICATION
IMPOSSIBLE, not merely unlikely"* — the engine-level backstop for the moment a hand-written
`where('store_id', …)` is forgotten. Half a millisecond of "DONE" removed it for good, and recorded
the migration so it could never repair itself.

### Why I got it wrong, in one sentence

`Schema::hasTable()` answers *"does a table of this name exist"*. The migration needed *"is this the
table I would have created"*. **Those are different questions**, and conflating them is the same
inverted-absence mistake this phase keeps recording: `PRAGMA index_list` reporting no index where
InnoDB had one; `getGlobalScopes()` not answering "is it scoped"; and my own claim that
`ThemeCustomizeOrder` was not in `scoped_models`.

### The fix

`App\Support\Database\ThemeSchemaGuard::assertCompatible()` — the same posture as
`GateFSchemaGuard` and M3-D's `assertExistingColumnIsCompatible()`: it reads
`information_schema`, names every disagreement, and **throws**. All three migrations call it before
their early return. SQLite is exempt, narrowly and for a measured reason: `PRAGMA index_list` does
not report the index InnoDB creates for a foreign key, so asserting constraint names there would
fail against a database that is fine.

Same database, after:

```
2026_11_13_000200_create_theme_published_versions_table ........ 4.17ms FAIL
RuntimeException: `theme_published_versions` ALREADY EXISTS BUT IS NOT THE TABLE THIS
  MIGRATION WOULD HAVE CREATED:
  - foreign key `tpv_version_identity_foreign` is missing
exit 1
migrations row recorded: 0
```

It stops the deployment, names the constraint, and **leaves the migration unrecorded so it can be
repaired**.

### Evidence

| | |
|---|---|
| `verify-interrupted-theme-migration-on-mysql.php` | **6 checks, 0 failed, 0 inconclusive** — fresh install carries the FK; a COMPLETE table is skipped not refused (idempotence); a PARTIAL table is refused, by name, non-zero exit, unrecorded |
| falsification | guard call removed → **3 of 6 fail** |
| census | `app` 176 → 178 and the total 221 → 223, with the two `information_schema` reads **classified** rather than counted: tables that have no `store_id`, parameterised on a literal table name, console-only, and able to produce exactly one outcome — a `RuntimeException` that stops the migration |

The stale number in `RawQueryTenancyInventoryTest`'s method *name* is left alone deliberately and
now says why: the name is an identity the known-outcome tooling matches on, so renaming it to chase
the figure would change that identity for no behavioural gain.

---

### 12.2 The service was verified alone and then used nested — so "after commit" had to be re-measured

M3-C's fourth hard requirement is *"flush the model cache after commit, not inside the
transaction"*, and §9 proved it with a two-process MariaDB harness. That proof was taken with
`writeSettings()` **owning its transaction**.

**Its one production caller does not call it that way.** `Api\DashboardController` opens
`\DB::beginTransaction()` at `:8922`, calls `writeSettings()` at `:9054` and commits at `:9067`.
So the service's own `DB::transaction(...)` is a **savepoint**, not a transaction, and every word of
the M3-C guarantee had to be re-asked under that shape. A guarantee measured in isolation and then
used nested is not the same guarantee.

Measured 2026-11-15 (framework semantics, SQLite — this is `DB::afterCommit` behaviour, not an
engine behaviour):

| | |
|---|---|
| callback registered inside an open transaction | does **not** run |
| a nested `DB::transaction()` releasing its savepoint | does **not** run |
| the **outermost** commit | runs, at transaction level `0` |
| the outermost `rollBack()` | **never** runs |

**The nesting is safe.** The flush defers to the controller's commit, which is what was wanted.
`tests/Feature/Theme/ThemeWriteUnderCallerTransactionTest` keeps it that way, because the two ways
to break it — a direct `flushCache()` in place of `DB::afterCommit()`, or a stray `DB::commit()` in
the service — would break the *caller* and nothing else in the suite would notice.
**Falsified:** the service made to commit work it does not own fails both tests; restored, both
pass.

#### The first draft of that test failed, and the probe was wrong, not the service

Written against Eloquent, `test_a_rolled_back_caller_transaction_discards_the_theme_write` failed.
Both values read at the same instant after the rollback:

```
RAW TABLE  NULL                          <- the database rolled back correctly
ELOQUENT   'THIS SAVE MUST NOT SURVIVE'  <- Cachable serving a row that is now gone
```

`ThemeCustomize` is `Cachable`; the in-transaction read re-populated the model cache with
uncommitted data, and the rollback did not take the cache with it. **An Eloquent assertion here
answers "what does the cache hold", never "did the database roll back".** This is the fourth time
this phase that a theme test read the cache and believed it was reading the table. The assertion
reads the raw table, and says so in the file.

#### A consequence for a decision that has not been taken yet

That staleness **cannot reach production today**: `MODEL_CACHE_STORE` is unset, so the model cache
is on `CACHE_STORE=database` — the same connection — and its rows roll back with the transaction.

**Both opt-in stores offered by D-28a break that.** `model_cache` can take a separate connection
(`MODEL_CACHE_DB_CONNECTION`) and `model_cache_file` is a file store; neither rolls back. And since
`afterCommit` never fires on a rollback, no flush repairs it — the phantom value is served until
something else evicts the key. Recorded in
`docs/development/phase-2/F194-MODEL-CACHE-ISOLATION-RUNBOOK.md` §10 as a property of **that
decision**, with an added post-activation check, rather than patched here.

#### And two cautions for anyone re-testing this

- **`flushCache()` is tag-scoped.** An untagged `Cache::put()` sentinel survives it, so the obvious
  "did the flush fire" probe passes whether or not it did. Measured: `plain sentinel after
  flushCache: 'ALIVE'`.
- **No `afterCommit` callback runs anywhere in this suite.** `RefreshDatabase` wraps every test in a
  transaction that is never committed, so the level never returns to `0`. Cache-invalidation
  evidence has to be taken across two processes against a real store —
  `verify-theme-cache-flush-after-commit-on-mysql.php`.

### 12.3 The integration sweep — who else writes the configuration

M3's premise is **one controlled write path**. That is a claim about the whole repository, so it was
measured across `app/` and `packages/` rather than asserted.

| | |
|---|---|
| writers of `theme_customizes` | **only** `ThemeConfigurationService` and `ThemePublishingService` |
| readers | **one** — `helper.php:3576`, inside `getThemeSetting()` |
| `ViewServiceProvider` | imports `ThemeCustomize` and **never uses it** — a dead import, not a path |
| `Api\DashboardController:9033` | the former raw `DB::table()->updateOrInsert()`, now routed through the service; the comment is the record of the defect |
| vendored `store_theme_customize` | a **different table**, in `packages/workdo/ThemeCustomizer`, read by nothing outside that module. Not part of the published-config path and not bypassing anything |

**Publish does not drop what it does not know about.** `ThemePublishingService::apply()` is an
UPSERT: keys live in the projection but absent from the snapshot are collected into
`keysNotInSnapshot` and **returned, never deleted** — pinned by a test on the directive's own named
example, `footer_repeater`. This is what makes requirement 5 ("retain independently managed
settings such as Flutter-written social links") true by construction rather than by care.

#### One latent storefront 500, recorded and deliberately not fixed here

`packages/workdo/ProductCompare/src/resources/views/pages/compare.blade.php:9` indexes
`$themeSettings['compare_banner_status']` **with no `??` guard** — while the very next line guards
`compare_banner_image` with one. Measured: `compare_banner_status` is declared in **no**
`theme_json`, written by **no** handler, and read in exactly that one place. So the
key-completeness merge, which draws on the *theme's own* declared keys, can never supply it.

This is **pre-existing and not caused by M3** — the legacy path would 500 on that page identically.
It is in vendored `packages/workdo/`, which CLAUDE.md puts out of bounds, and the compare page is
not an M3 surface. Recorded for M4, where the editor's own key handling is in scope.

### 12.4 The directive's ten negative tests — the requirement-to-test trace

The M3-D directive named ten negative cases by hand. A claim that they are "covered" is worth
nothing unless it can be checked, so here is each one against the method that drives it. All ten
resolve; none is covered only by a positive test asserting the happy path.

| # | the directive's words | the test(s) |
|---|---|---|
| 1 | cross-tenant version reads | `test_owned_by_refuses_a_version_belonging_to_another_store_or_theme`, `test_the_live_version_resolver_refuses_a_cross_tenant_pointer` |
| 2 | cross-tenant publication | `test_a_merchant_cannot_publish_another_tenants_version`, `test_a_version_of_another_store_cannot_be_published_into_this_one` |
| 3 | cross-tenant rollback | `test_a_merchant_cannot_roll_back_another_tenants_theme` |
| 4 | unauthorized draft deletion | `test_a_merchant_cannot_delete_another_tenants_draft`, `test_a_published_version_is_refused_for_deletion` |
| 5 | invalid active-theme selection | `test_a_theme_the_installation_does_not_ship_is_refused`, `test_an_unknown_theme_is_refused`, `test_an_operator_cannot_repoint_a_merchants_active_theme` |
| 6 | null-store access | `test_a_zero_or_empty_store_id_is_refused`, `test_a_non_positive_store_id_resolves_nothing_rather_than_the_null_bucket`, `test_a_store_that_does_not_exist_is_refused` |
| 7 | demoted merchant access | `test_a_demoted_merchant_cannot_publish`, `test_a_merchant_demoted_to_a_root_of_one_does_not_gain_every_store`, `…_does_not_gain_the_operators_store` |
| 8 | operator acting without explicit store context | `test_an_operator_cannot_write_without_naming_a_store`, `test_an_operator_without_an_explicit_store_is_refused`, `test_an_operator_with_no_store_named_is_refused_and_does_not_get_store_one`, `test_the_self_service_resolver_refuses_an_operator_without_a_current_store` |
| 9 | audit-event dereferencing | `test_a_publication_event_resolves_its_version_with_its_own_store_and_theme`, `test_a_publication_event_resolves_its_versions_only_within_its_own_tenant` |
| 10 | duplicate or partial published projections | `test_a_write_into_a_duplicated_projection_reaches_the_storefront`, `test_publishing_into_a_duplicated_projection_leaves_the_storefront_consistent`, `test_a_failure_mid_publish_leaves_projection_pointer_and_history_untouched` |

Row 8 is the one worth reading twice. `getCurrentStore()` returns a literal `1` for an operator
whose `current_store` is empty, **and store 1 belongs to a merchant** — so "the operator is refused"
and "the operator does not silently get store 1" are different assertions, and only the second one
catches the bug. Both are present, which is why the method name says so.

### 12.5 M3 closure evidence

M3-E changed **no production code** — `git show --name-only 2ff0731c` is one test file and one
runbook — so M3-D's measurements still describe the shipped behaviour rather than needing to be
taken again. They were re-run anyway, because "still passes" is cheap to establish and "should still
pass" is not evidence.

**The four MariaDB harnesses, re-run at closure (2026-11-15):**

| harness | result |
|---|---|
| `verify-theme-publish-concurrency-on-mysql.php` | **20 checks, 0 failed, 0 inconclusive** |
| `verify-theme-order-theme-id-migration-on-mysql.php` | **20 checks, 0 failed, 0 inconclusive** |
| `verify-interrupted-theme-migration-on-mysql.php` | **6 checks, 0 failed, 0 inconclusive** |
| `verify-theme-cache-flush-after-commit-on-mysql.php` | **4 checks, 0 failed, 0 inconclusive** |
| | **50 checks, 0 failed** |

**The suites, locally:** Unit **1797 passed, 1 skipped** (15609 assertions); Contract **44 passed**
(179 assertions); the theme suites specifically **338 passed** (4251 assertions).

**And the gate in CI, which is the one that counts.** Local runs are not evidence that CI passes —
that was the whole reason PR #138 was opened. On `ad996a46` the four blocking steps of the required
`tests` job each reported `success`:

```
success    Unit suite -- BLOCKING
success    Contract suite -- BLOCKING
success    Feature suite -- BLOCKING on the known-outcome SET
success    The Feature outcome set must match the record -- BLOCKING
```

The fourth is the one worth naming: it asserts the declared outcome **set in both directions**, so
it would have failed on an undeclared failure *and* on a declared failure that had started passing.
**No entry was added to the manifest on this branch** — `git diff main...HEAD` on
`.github/known-test-outcomes.json` is empty — so the 2 accepted failures and 1 accepted skip are
exactly the ones `main` already declared.

**What closure does NOT mean.** M3 engineering is closed; nothing here is released, accepted, or
applied to production. `Security-sensitive diff: human checklist` fails by design and only a person
can clear it. The `theme_customize_orders.theme_id` migration remains development/test only. And the
`created_by = 1` production question stays **UNVERIFIED** — the authorization logic refuses that case
either way, which is why it never blocked the work.
