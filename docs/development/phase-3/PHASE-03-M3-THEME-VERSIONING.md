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

## 6. Packages

| | | state |
|---|---|---|
| M3-A | verified schema inventory and migration design — this document | **complete** |
| M3-B | additive migrations, integrity constraints, GATE-F-style guards, the reconciliation reporter | next |
| M3-C | versioned storage model and backward-compatible configuration reads | |
| M3-D | draft/published state, publication integrity, rollback semantics, post-commit cache flush | |
| M3-E | tenancy and concurrency regression tests, legacy-upgrade validation on MySQL, closure | |

### Validation environment, already prepared

| | |
|---|---|
| engine | MariaDB 10.11.14 on `/var/run/mysqld/mysqld.sock`, started locally; **disposable** |
| fresh-install check | `p3m3_probe_*` — full `migrate` from empty, clean |
| legacy-upgrade corpus | `p3m3_legacy` — 3 stores (one with `theme_id = ''`), 8 `theme_customizes` rows including stored HTML, a JSON repeater value, and an orphan key present in no `theme_json` |
| restore between runs | a `mysqldump` baseline, so each upgrade simulation starts from the same legacy state |

No database dump, credential or customer data is committed; the corpus is synthetic and lives only
in the throwaway server.
