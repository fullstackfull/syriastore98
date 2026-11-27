# Phase 06 — `SUPPORT-002`: contextual help and announcements, web and Flutter

**Status: engineering complete on `ws/support2`. Not merged, not released, not accepted.**
**Two owner decisions are open and named in §6; one is content, one is a migration.**

---

## 1. The requirement, verbatim

`docs/development/master-plan/DECISION-ID-REGISTER.md` §2, the SUPPORT table, row 2:

> | `SUPPORT-002` | مساعدة سياقية وإعلانات للويب وFlutter. | Contextual help and announcements, for web and Flutter |

`MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` recorded it **NOT IMPLEMENTED**, on both clauses and both
clients, with the next action: *"Scope the announcement and contextual-help surfaces with the owner,
web and Flutter together, and note that Flutter parity needs API endpoints that do not exist yet."*

The register's own caveat applies and is not waived here: the id-to-bullet pairing inside the SUPPORT
family is the register's **ordered reading** of an unnumbered bullet list, not a stated mapping.

---

## 2. What was re-measured, and what one measurement had gone stale

The traceability row's evidence was re-checked rather than taken on report.

| Claim in the row | Re-measured | Verdict |
|---|---|---|
| `routes/api.php` has zero routes matching `ticket\|help\|announce\|faq` | `grep -icE` over the file | **was true** — 0 before this change |
| `flutter-endpoints.json` holds 92 entries, none support-related | the committed manifest | **was true** — 92 |
| the Flutter repo has no ticket/support/help screen | `lib/view/screen/` listed | **true** — none existed |
| "no announcement migration, model or route" | **refuted** | **STALE** — see below |

**The stale one is the load-bearing one.** `CTRL-003` shipped platform maintenance announcements on
branch `ws/p6-ctrl`, which is **not yet merged into `int/checkpoint`**:

- `database/migrations/2026_11_26_000300_create_platform_maintenance_announcements_table.php`
- `app/Models/PlatformMaintenanceAnnouncement.php`
- `app/Support/Control/MaintenanceAnnouncements.php`
- `resources/views/platform/maintenance.blade.php` (the operator's screen, `platform/maintenance`)
- `resources/views/components/ss/maintenance-notice.blade.php`, included from
  `resources/views/layouts/app.blade.php`

So an announcement **store**, a **writer** and a **web banner** already exist. `SUPPORT-002` is
therefore not "build an announcement system"; it is **contextual help** plus **delivery of the
existing announcements to the merchant on both clients**.

---

## 3. How this composes with `CTRL-003` rather than colliding with it

`ARCH-002` forbids a parallel engine. The division of ownership, stated once and repeated in every
file's docblock:

| | `CTRL-003` | `SUPPORT-002` (this change) |
|---|---|---|
| the table | **owns** `platform_maintenance_announcements` | reads it; adds no table |
| the operator's writes | **owns** `platform/maintenance` | none; no write path at all |
| the transient banner | **owns** `<x-ss.maintenance-notice />` | untouched |
| the merchant's durable list | — | **owns** `merchant-announcements` |
| the Flutter client | — | **owns** `api/admin/announcements` |
| contextual help | — | **owns** `merchant-help`, `api/admin/help-topics` |

**No audience was invented, and that is a reading of the decision rather than a shortcut.**
`CTRL-003`'s migration docblock declined to add plan/store/pilot targeting because *"إعلانات صيانة"*
names no audience, and records "announce to the pilot group first" as an open question instead.
**`SUPPORT-002`'s text names no audience either** — it names the two **clients** («للويب وFlutter»),
not a segment. Building targeting here would be inventing the same requirement twice. It stays an
owner question (§6).

### 3.1 The one file taken from `CTRL-003`, and why it merges cleanly

`database/migrations/2026_11_26_000300_create_platform_maintenance_announcements_table.php` is on
this branch **byte-for-byte identical** to `ws/p6-ctrl`'s copy (`sha256`
`960b5a8b14d6f970e15ac034fe249f8e7f112c88559ed1a0c6578cc0053d239d` on both). It is **`CTRL-003`'s
file; `SUPPORT-002` does not own that table.**

It is here because a reader needs a table to be a reader of, and a test needs one to be a test of.
Taking it verbatim rather than writing a second `create` migration means the integration is an
**add/add of identical blobs**, which git resolves with no conflict and which leaves **exactly one**
migration for that table. Its only dependency, `App\Support\Database\ThemeSchemaGuard`, is already on
`int/checkpoint`.

*If `ws/p6-ctrl` edits that file before integration, the add/add becomes a conflict whose resolution
is "take theirs".*

### 3.2 What was deliberately NOT taken, and the test that forbids it

`CTRL-003`'s **model** (`app/Models/PlatformMaintenanceAnnouncement.php`) and **service**
(`MaintenanceAnnouncements`) are **not** on this branch.

`tests/Unit/Tenancy/ClaudeMdCachableModelClaimTest` re-measures the file count and the `Cachable`
exception list of `app/Models/` against CLAUDE.md's prose on **every run**. A workstream that may not
edit `CLAUDE.md` therefore may not add a file to `app/Models/` either — in either direction, cached
or not. The service cannot come without the model, because its reads call
`PlatformMaintenanceAnnouncement::query()`.

So `App\Support\Help\MerchantAnnouncements` reads the table through the **query builder**, in exactly
**one** `DB::table()` statement, because `RawQueryTenancyInventoryTest` counts them.

**The cost of that, named rather than hidden:** after integration the live-window predicate will exist
in **two** places — `MaintenanceAnnouncements::live()` (the banner) and `MerchantAnnouncements::rows()`
(the list and the API). They are written to agree and both are tested, but two copies of one predicate
is a drift risk. **The integration action is to point `CTRL-003`'s banner at
`MerchantAnnouncements::live()` and delete the duplicate**, or the reverse once the model census
question is settled with the owner. It is recorded here rather than left for somebody to notice.

---

## 4. What was built

### 4.1 Contextual help — over the existing documentation centre, not a second corpus

`App\Support\Help\ContextualHelp` is a **map** from a surface key to documentation article ids, and
nothing else:

```
surface key  ->  ['category/slug', ...]   resolved through App\Support\Docs\DocumentationLibrary
```

**Reusing `DocumentationLibrary` is the load-bearing decision.** It is already the owner's bilingual
documentation centre: 16 articles per locale under `resources/docs/{en,ar}/`, a published/draft
state, a locale fallback that is deliberately visible rather than silent, and a cached index. Its own
docblock records why the prose is in the repository — reviewable in a diff, searchable in an editor,
assertable by a test, and a bilingual pair visible as two files side by side. Every one of those
reasons applies at least as strongly to contextual help, which is the *same prose read from a
different entry point*. A second store of help text would be a second thing to keep bilingual, a
second thing to review, a second thing to migrate, and the `ARCH-002` shape.

**So the map is the only new content, and no help prose was written.** Seven surfaces, all of which
the shipped corpus can already answer: `overview`, `products`, `orders`, `storefront`, `staff`,
`plan`, `account`. Eight route segments reach them — `users` and `roles` both map to `staff`, because
`users.*` is who the staff are and `roles.*` is what they may do, and both reach the same two guides. Surfaces the corpus cannot answer — customers, coupons, reports, the wallet — are
**absent rather than present-and-empty**: a help affordance that opens on nothing is worse than one
not offered, and adding them means writing articles, which is owner copy.

Three design points each have a test that failed first:

- **The surface vocabulary is client-neutral.** The web resolves a surface from the Laravel route
  name; the Flutter app has no route names and sends the key itself. Keys that *were* route names
  would couple the shipped app's contract to this repository's routing table.
  `surface_keys_are_not_laravel_route_names` **caught `dashboard`** — a real registered route name —
  and it is now `overview`.
- **The route match is on the whole first dot-segment, never `str_starts_with`.**
  `plan-coupon.index` is the platform **operator's** screen and a prefix match claims it.
- **`is_fallback` compares against the language the reader ASKED for**, not the one the centre could
  be asked for. The first draft compared against the normalised locale, so a French reader was served
  English with the flag **false**; `an_unshipped_locale_falls_back_and_says_so` caught it.

### 4.2 The merchant's announcement list — what a banner cannot be

`CTRL-003`'s banner shows what is live, on the page the merchant happens to be on, while the window
is open. **A merchant who was not signed in on Thursday night has no other way to learn what the
platform said about Thursday night.** That is the whole reason this screen exists beside it.

`CTRL-003` keeps the operator's intent (`is_published`) apart from the window, which yields four
states. The list decides about all four, and every one is driven in both directions:

| state | banner | the list and the API |
|---|---|---|
| `draft` | no | **never** — a half-typed notice must not reach anybody |
| `scheduled` | no | **never** — the state exists so a notice can be written Monday and appear Thursday |
| `live` | yes | yes, flagged `is_live: true` |
| `ended` | no | **yes**, flagged `is_live: false` — the only reason the list exists |

### 4.3 The API — the Flutter half

```
GET api/admin/announcements   ?lang=&limit=
GET api/admin/help-topics     ?lang=&surface=
```

Both inside `Route::prefix('admin')`, both carrying `['custom.auth','AdminApiLog']` like every other
route in that group, both read-only.

- **`custom.auth` is not an audience check.** It logs a token's owner into the guard matching the
  **tokenable class**, so a shopper's and a driver's token both pass it. Each handler derives its
  principal with `auth('web')->user()` and an `instanceof User` test. Nothing reads an identifier from
  the request — there is none to read.
- **The refusal is HTTP 200 with `status: 0`, never a 401.** The shipped app force-logs-out on any
  401. This is pinned as a **contract** property, not an implementation detail.
- **`surface` and `lang` are caller-supplied and are not predicates over data.** `surface` is a key
  lookup in a class constant; `lang` selects between two repository locales. Hostile shapes —
  `../../etc/passwd`, `products/../orders`, `?surface[]=x` — are driven through the real routes.
- **Isolation is asserted as an equality, not a filter.** The table has no `store_id`, so the honest
  check is that two merchants of two different stores receive **byte-identical** payloads: where every
  principal gets the same answer there is no per-tenant channel to confuse, and `null_tenant => 'open'`
  cannot apply to a query with no tenant predicate to lose.

### 4.4 Files

| | |
|---|---|
| `app/Support/Help/ContextualHelp.php` | the surface map, the route map, locale resolution |
| `app/Support/Help/MerchantAnnouncements.php` | the merchant-facing reader both clients go through |
| `app/Http/Controllers/Api/MerchantHelpController.php` | `announcements()`, `helpTopics()` |
| `app/Http/Controllers/MerchantAnnouncementController.php` | `merchant-announcements` |
| `app/Http/Controllers/ContextualHelpController.php` | `merchant-help` |
| `resources/views/announcements/index.blade.php` | the list |
| `resources/views/help/index.blade.php` | the help screen |
| `resources/views/components/ss/contextual-help-link.blade.php` | the header control |
| `resources/views/partision/header.blade.php` | one line, and the component carries the rest |
| `routes/api.php`, `routes/web.php` | four declarations |
| `resources/lang/{en,ar}.json` | 21 keys each |

**The header control is a LINK, not a dropdown**, which is this partial's own precedent: the
notification bell beside it records in full that a dropdown is a second surface with its own focus
trap, its own RTL placement and its own dark-mode contrast to verify. It is also why the component
went into `partision/header.blade.php` rather than `layouts/app.blade.php` — `CTRL-003` inserts its
banner immediately before `@yield('content')`, and a second insertion at the same anchor is a merge
conflict.

---

## 5. Verification

| suite | result |
|---|---|
| `--testsuite=Unit` | **7,210 passed, 1 skipped, 0 failed** (the 1 accepted skip) |
| `--testsuite=Contract` | **64 passed** (58 before; 6 added) |
| `tests/Feature/{Security,Tenancy,Ui,Documentation}` | **7,910 passed, 1 skipped, 0 failed** on Security + Tenancy + Support. Ui and Documentation carry **one pre-existing failure**, `HostUtilityCensusPopulationTest::test_the_old_population_still_reproduces_the_registers_own_figure` (26 vs 19), **measured on a clean `int/checkpoint` worktree as well** and therefore not this branch's |
| `tests/Feature/Support` + `tests/Unit/Support` | 3,999 passed |
| `tests/Support/ApiContract/bin/check-contract.php` | OK, 94 app endpoints, **0 unresolved** |
| `tests/Support/bin/mutate-support002.py` | **21 mutations, 21 CAUGHT, 0 survivors** |

**The neighbour run is in the table for the reason the tenancy skill gives as trap 8:** a fix can be
pinned by a *neighbouring* test that nobody thought to run, and running only the new file is what
hides it.

**The Flutter suite was NOT run.** There is no Flutter or Dart SDK in this environment, so
`flutter analyze` and `flutter test` could not be executed and are **not claimed**. Repo B's half was
verified by reading: every colour, text style and widget signature checked against its declaration,
brace balance on every changed file, and each `lib/`-wide census in `test/` read and checked by hand.
Two defects were found that way — `AppColor.cWarning`/`cSuccess`/`cError` do **not exist** and were
replaced with palette entries that do, and a `growable: false` list assigned into an `RxList` the
controller later `.clear()`s would have thrown `UnsupportedError` on the second open of the help
sheet. **The suite must still be run before the app half is relied on.**

New test files: `tests/Unit/Support/ContextualHelpTest.php` (11), 
`tests/Unit/Support/MerchantAnnouncementFeedTest.php` (17), 
`tests/Feature/Support/MerchantHelpApiTest.php` (16), 
`tests/Feature/Support/MerchantAnnouncementScreenTest.php` (10), 
`tests/Feature/Support/ContextualHelpScreenTest.php` (12), 
`tests/Contract/SupportHelpEndpointContractTest.php` (6).

**Ratchets moved, with the delta stated in each — and the three the Feature run NAMED were found by
running it, not by remembering them:**

| ratchet | moved | why |
|---|---|---|
| `FlutterEndpointManifestTest` | 92 → 94 endpoints, new pinned hash | the two new app endpoints |
| `flutter-endpoints.json`, `server-api-routes.json` | 208 → 210 routes | **regenerated, not edited** |
| `RawQueryTenancyInventoryTest` | `app` 253 → 254, total 298 → 299 | the one `DB::table()` read |
| `BuilderRawQueryVisibilityTest` | its premise figure, in step | it reproduces the census's totals |
| `SecurityLogInventoryTest` | one inventory row, `warning` 284 → 285 | the feed's one `Log::warning` |

The per-table exposure map did **not** move, and that is a fact rather than an omission: the read
names its table through `self::TABLE`, so like `ModuleTrust`'s two it joins the census's
unattributable remainder. The table has no `store_id` and could not — the announcement is the
platform's — which is the argument the inventory's note now carries beside the count.

**Three things the tests caught in themselves**, recorded because each is a way a green run lies:

1. **A page-wide string assertion.** `assertDontSee('Help for this screen')` was true on every
   merchant page — the header control carries that phrase as its `aria-label`. Two cases went red and
   now key on a scoped marker. This is CLAUDE.md's census trap met rather than read about.
2. **A screen that does not render.** `product.index` answers **302** for a freshly made merchant in
   the suite (as do `order.index`, `plan.index` and `users.index`) — the module-gate redirect
   CLAUDE.md records. Of the mapped segments, `dashboard`, `theme.index` and `profile` answer 200, and
   the header-control case drives two of them with **different** surfaces.
3. **A cap that nothing measured.** Mutation 7 survived the first run: both the Unit and the Feature
   case asked for a limit above the row count, so they returned the same number with and without
   `min()`. `a_caller_asking_for_everything_is_capped_at_a_hundred` writes 105 rows and asserts 100.

**And one failure that was the WORKTREE, not the change, recorded so the figures can be read.** The
first Unit run reported 2 failed, both in `Phase05BilingualAppearanceGateTest`, which reads 40
evidence screenshots under `docs/development/phase-5/evidence/`. This branch's worktree was created
with a sparse checkout excluding `docs/development/**/*.png`, because the disk was at 98% and a full
checkout had already failed with "no space left on device". The PNGs are in git at HEAD and were
simply not materialised — the gate was refusing an absence it was right to refuse. The checkout was
widened (only `packages/workdo/Marketing/src/resources/assets`, 530 MB of pure theme artwork that no
PHP reads, stays out), the gate file re-run green, and every figure in the table above is from a run
against the complete tree. **No test was changed for it**, which is the only acceptable resolution:
the alternative — declaring it in `.github/known-test-outcomes.json` — would have parked a green
test as a known failure.

---

## 6. Owner decisions — open, and not deferred by engineering

1. **The announcement COPY is the owner's.** This change ships the mechanism and **no announcement
   text**. Every word a merchant reads in an announcement comes out of the table, written by the
   operator on `CTRL-003`'s screen.
2. **The migration is not applied to production.** It is `CTRL-003`'s table and carries `CTRL-003`'s
   authorisation: approved for development and isolated database testing only. CLAUDE.md §2 reserves
   a persistent migration to the owner.
3. **Audience targeting.** Neither `CTRL-003`'s text nor `SUPPORT-002`'s names an audience, so none
   was built. "Announce to one plan, or to the pilot group, first" is a real thing an operator might
   want and is **not** what either decision asks for.
4. **Which surfaces get help next.** Customers, coupons, reports and the wallet have no article in the
   corpus. Adding a surface is one line; writing the article is owner copy, and
   `every_declared_article_resolves_in_both_languages` refuses a surface whose article does not exist
   in **both** languages.
5. **The knowledge-base boundary stays open.** `SUPPORT-001`'s gap (c) records that a
   platform→merchant knowledge base and videos **overlap Phase 03's separately scoped Documentation
   Center**, and that the owner should say which phase owns them. This change does **not** settle
   that: it adds no article and no second corpus. It is evidence for the question — contextual help
   reads the Phase 03 centre and works — not an answer to it.

### 5.1 Two refinements the self-review against the tenancy skill produced

- **The page size is parsed with `FILTER_VALIDATE_INT`**, not `is_numeric`. The skill's rule is that
  `is_numeric` accepts `'5.9'` and rejects `'5abc'` while MySQL coerces `'5abc'` to 5. There is no
  identifier anywhere in that controller and nothing tenant-scoped to reach, so a loose parse had no
  consequence here — but it is the habit the rule exists to break, and `?limit[]=1` is now rejected
  before the cast rather than at it. `test_a_nonsense_limit_answers_cleanly` drives six shapes.
- **The refusal reuses a message the application already ships.** The first draft added
  `This action is not allowed.` — a 5,201st key one letter of meaning away from four already there.
  It now sends `You do not have permission to perform this action.`, which is the string repo B's
  `network/result.dart` NAMES as what the backend sends when it refuses on permission, and whose
  Arabic was reviewed by somebody other than this workstream. **21** new keys per language, not 22.

## 7. Engineering follow-ups, named rather than silently left

1. **Collapse the duplicated live-window predicate at integration** (§3.2).
2. **`MerchantAnnouncements::SEVERITIES` restates `CTRL-003`'s vocabulary** because the model is not
   on this branch. After integration it should reference
   `PlatformMaintenanceAnnouncement::SEVERITIES`, and a test should assert the two agree.
3. **The Flutter settings entry reuses `envelope.svg`.** A purpose-drawn megaphone is cosmetic.
4. **This branch's citations of `CTRL-003`'s classes are currently UNCHECKED, and become checked at
   integration.** `CitedMethodsExistTest` treats a class as in scope only if `class_exists()`, so
   the docblock citations of `MaintenanceAnnouncements::live()` and
   `PlatformMaintenanceAnnouncement::SEVERITIES` in `app/Support/Help/MerchantAnnouncements.php` are
   skipped here — the classes are on `ws/p6-ctrl`. Both were verified by hand to exist there
   (`git show ws/p6-ctrl:...`), so the merge turns them into asserted citations rather than red ones.
   Worth knowing because it is the one place this branch names code it cannot compile against.
5. **Translation keys will collide at integration, loudly.** Six of this change's keys —
   `Announcements from Store Syria`, `Notice`, `Urgent`, `From :from until :until.`, `Until :until.`,
   `From :from.` — are also added by `CTRL-003`, and both branches append to the end of
   `resources/lang/{en,ar}.json`. The resolution is to keep one copy;
   `TranslationFileIntegrityTest::test_the_file_has_no_duplicate_keys` names any that are missed.
   The Arabic wording is identical on both sides, so the resolution is a deletion, not a judgement.
