# GAP-104 / P03-C-05 — content management for the official website

**Status:** engineering complete. Not released, not merged, **and not usable in Arabic until the
owner applies 16 translation pairs** (§9) — two of which currently make `ArabicUiCoverageTest` red.

**Row:** `GAP-104` / `P03-C-05`, previously NOT IMPLEMENTED.
**Date:** 2026-11-26. **Branch:** Phase 03 storefront work.

---

## 1. What the official website is, and what was missing

The official Store Syria website is the **platform's own public site** — distinct from a merchant's
storefront (`{storeSlug}/…`, themed, tenant-scoped) and from the merchant admin. It is seven
first-party Blade pages under `resources/views/platform/`, served by
`app/Http/Controllers/PlatformSiteController.php` from a route group at `routes/web.php` ~430:

| URL | view | what it says |
|---|---|---|
| `/platform` | `platform.home` | what Store Syria is |
| `/platform/features` | `platform.features` | what the product does (from `PlatformCapabilities`) |
| `/platform/pricing` | `platform.pricing` | plans, read from the `plans` table |
| `/platform/getting-started` | `platform.onboarding` | six steps |
| `/platform/faq` | `platform.faq` | ten questions |
| `/platform/contact` | `platform.contact` | how to reach the company |
| `/platform/legal` | `platform.legal` | five documents, none published |

(`/platform/docs/…`, the Documentation Centre, is M6 and a separate corpus.)

**The gap, in P03-C-05's own words:** "all seven pages are Blade templates … with literal copy
wrapped in `__()`… Changing a headline, an FAQ answer or the legal text requires a code change,
review, merge and deploy. There is no admin screen for platform content." And its knock-on: "the
five legal documents and the contact details, which the owner must supply, **can only be published
by a developer**."

---

## 2. The split this change keeps

| | whose | state after this change |
|---|---|---|
| the **capability** — an operator can change the site's text without a deploy | engineering | **delivered** |
| the **content** — five legal documents, four contact details | the **owner** | **still owed, and the product now shows that it is owed** |

Nothing in this change drafts a privacy notice, invents a postal address, a telephone number, an
email address or opening hours. Nine fields ship with **no wording at all**; the pages keep saying
so; the editor marks them `Owner content` and counts them as outstanding per language.
`PlatformContentRegistryTest::test_an_owed_field_resolves_to_the_empty_string` fails if any of them
is ever given a default.

---

## 3. What is editable, and what deliberately is not

**51 fields across the seven pages**, declared in `App\Support\Platform\PlatformContent`:

| page | fields |
|---|---|
| each of the seven | hero eyebrow, heading, intro (21) |
| pricing | + the note explaining what the figures mean (1) |
| faq | + ten question/answer pairs (20) |
| contact | + address, telephone, email, opening hours — **owed** (4) |
| legal | + the five document bodies, Markdown — **owed** (5) |

**Not editable, by design:**

- **Prices and plan limits.** They come from the `plans` table the subscription flow charges
  against. M5's standing rule is that the site cannot state a price the product does not charge;
  making the figures editable would reintroduce exactly that drift. The pricing *narrative* is
  editable, the figures are not.
- **Capability claims.** `PlatformCapabilities` anchors 34 claims to a module on disk or a theme in
  `themes/`, which is what makes `P03-C-04` verifiable. A free-text override would unanchor it.
- **Page structure, sections and images.** This is a content capability, not a page builder. M5's
  constraint — do not create a second landing-page engine — still holds.
- **Page titles and `meta_description`.** Left in code; no SEO surface is in scope here (`P03-MP-01/02`).
- **Legal document titles and summaries.** Structural; the binding text is the editable field.

---

## 4. Files, not database — and why this is the opposite call to the Documentation Centre

The Documentation Centre keeps its corpus as repository files because **a web write lands on a
container filesystem that the next deploy replaces**, so an approval made through a button would
vanish with no trace. That reasoning is sound, and **it is the reasoning against files here**: the
whole requirement of this row is editing *without* a deploy. Writing to
`resources/views/platform/*.blade.php` from a browser would satisfy the screen and lose the content
at the next release — the worst of both designs. The documentation centre can keep files because its
state transitions are reviewed commits; marketing copy, contact details and approved legal text are
not, and must not be.

**So the content is in the database — and there is NO MIGRATION.**

`settings` already exists in production (base install,
`2023_12_11_100148_create_settings_table.php`: `name`, `value` `longText` nullable, `created_by`,
`store_id` nullable) and is already the platform operator's own key/value store —
`getSuperAdminAllSetting()` (`app/Helper/helper.php:743`) reads rows keyed on exactly that operator.
So the capability works the day the branch deploys, with no schema change to approve.

**Row shape — one row per locale, three predicates:**

| | |
|---|---|
| `name` | `platform_site_content_en` / `platform_site_content_ar`, each a JSON map of key → text |
| `store_id` | `NULL` — the official site is not a store's; this also keeps it out of `getSuperAdminAllSetting()`'s bag, which filters on the operator's `current_store` and is plucked on public pages |
| `created_by` | the platform operator's id |

One row per locale rather than one per field: a save is atomic; `settings` has **no unique index**,
so the duplicate-row hazard P1-PLT-07 documents applies to two names instead of 51; and the
readers' "newest row wins" rule has one row to pick from per locale.

**Can a merchant write this row?** A real question, because
`PaymentSettingController::saveSettings()` writes one `settings` row per key it is POSTed. Two
independent reasons it cannot, both measured and both pinned by
`test_a_merchants_own_settings_row_cannot_become_the_platforms_content` (which seeds three rows of
the same name — the platform's, a merchant's store-scoped one, and a merchant's storeless one — and
asserts only the first is published):

1. that writer pins `store_id` to `getCurrentStore() ?: '1'` (`PaymentSettingController:35`), never
   `NULL`, so its row cannot collide with this one's `(name, NULL)` identity; and
2. `SettingOwnership::keep()` re-stamps a merchant's CREATE to their own merchant tenant root and
   forbids an UPDATE changing `created_by` at all.

If either ever changed, the failure is **safe**: a row the reader rejects means the built-in copy
renders, not that a merchant's text is published.

**Not `Setting::putForStore()`.** That helper resolves by `(name, store_id)` and deliberately omits
`created_by`; this reader *requires* `created_by`, so a row of that name owned by somebody else
would absorb the operator's save through the helper and then be ignored on read — the operator's
edit silently lost. Read and write therefore share exactly one predicate, written once.

**What it costs, stated rather than discovered later:** the content is not in a git diff and no
review sees it change. There is **no audit trail** of who changed what — named as outstanding in §10.

---

## 5. The gate

`PlatformOperatorOnly` on all three routes **plus** the same check inside all three handlers, the
house pattern established by `platform/analytics`, `platform/resources`, `platform/revenue` and
`platform/documentation`.

- **Not `@can()` / `$user->can()`** — `permissions_as_gates => false`, so both are always false.
- **Not `isAbleTo()` alone** — there is no super-admin bypass; it refuses the one person who may use
  this screen.
- **Not a permission name at all.** A merchant mints their own roles, so any permission is
  substantially self-assignable, and the subject here is the company's own published words,
  including — once supplied — the five documents that bind the business.
  `test_a_merchant_who_has_granted_themselves_every_permission_is_still_refused` is the
  differentiating case.
- **`instanceof User` first**, because `UserPolicy::isOperator()` reads `->type` off an untyped
  actor and `customers` has a `type` column.
- **`UserPolicy::isOperator()`** and not a second spelling: `rtrim($type, ' ')` matches MySQL's PAD
  SPACE, so `' super admin'` is a non-operator to the engine and must be one here.
- **403, never 401** for a signed-in non-operator; `auth` owns the anonymous case and redirects,
  because repo B maps any 401 to a forced logout.

A consequence worth stating: **the operator cannot delegate official-site editing to a non-operator
today.** Doing so needs a permission seeded in `Utility.php::$arrPermissions`, and seeding one is a
product decision about who may speak for the company. Recorded as an owner question (§10), not
guessed at.

---

## 6. The two defects this work found in itself

### F1 — an emptied field could never be cleared

`ConvertEmptyStringsToNull` is **global** middleware (measured from the resolved kernel, after
`TrimStrings`), so an operator who clears a textarea and saves POSTs `''`, which arrives as `null`.
Treating that as "not submitted" made every override **permanent**: the content could be changed
without a deploy and never changed back without one — the defect this row exists to remove, in
reverse. Fixed in `PlatformContentController::update()`; driven by
`test_clearing_a_field_restores_the_built_in_copy`, which failed on exactly this before the fix.

### F2 — the `xss` middleware deleted words from the operator's prose

Found by the authorization review of this change. `App\Http\Middleware\XSS` deletes four substrings
from **every** string in **every** request it handles — `['&lt;', '&gt;', 'javascript', 'alert']`
(`XSS.php:35`, applied at `:116`), exempting credential-shaped keys only. This is the one route in
the application whose entire purpose is to carry prose, so

> "you may not inject javascript into your storefront" — an acceptable-use document

saved, reported success, and published **"you may not inject  into your storefront"** on the
company's own website. `str_replace` is case-sensitive, so "JavaScript" survived and "javascript"
did not, which makes the corruption look random. For the five legal documents that is binding text
altered without anybody seeing it.

Fixed with `->withoutMiddleware('xss')` on **the write route only**. What is given up: nothing this
path relies on. `XSS` is an input sanitiser, not an output encoder; the controls that stop markup
reaching a reader are downstream and tested — Blade escaping for a `line`/`block` field and
`DocumentationRenderer::html()` (`html_input => 'strip'`, `allow_unsafe_links => false`) for a
`markdown` one. The mutation that replaces that renderer with a raw echo reddens
`test_html_in_a_published_legal_document_is_stripped`, which is what proves the renderer and not the
middleware was doing the work. `ValidateCsrfToken` is in the `web` **group**, not in `xss`, and is
unaffected — confirmed from the resolved stack. The installer redirect `XSS` also carries
(`XSS.php:56-78`) still runs on the two GET routes.

---

## 7. Tests

| file | tests |
|---|---|
| `tests/Unit/Platform/PlatformContentRegistryTest` | 9 |
| `tests/Feature/Platform/PlatformContentEditorGateTest` | 13 |
| `tests/Feature/Platform/PlatformContentPublishingTest` | 17 |

**39 tests, 359 assertions**, all passing.

**Failing first:** both feature files were written before any implementation existed and failed
21/21 with `Class "App\Support\Platform\PlatformContentStore" not found`; the registry test failed
8/8 the same way.

**Then reverted — 22 mutations, all caught** (`tests/Support/bin/mutate.py`):

| mutation | caught by |
|---|---|
| reader drops the `created_by` ownership predicate | `…merchants_own_settings_row_cannot_become_the_platforms_content` |
| an emptied field treated as "not submitted" | `…clearing_a_field_restores_the_built_in_copy` |
| the write trusts a stale owner memo | `…contact_details_are_absent_until_supplied_and_then_published` |
| the update accepts any page's fields | `…a_key_the_registry_does_not_declare_is_not_stored` |
| the store stops filtering keys against the registry | `…the_store_refuses_a_key_the_registry_does_not_declare` |
| the store accepts any locale | `…the_store_refuses_a_locale_the_site_is_not_published_in` |
| an owed legal document is given invented wording | `…an_owed_field_resolves_to_the_empty_string` |
| the legal page ignores the supplied document | `…a_supplied_legal_document_is_published…` |
| the legal page echoes operator text unrendered | `…html_in_a_published_legal_document_is_stripped` |
| the contact page drops the supplied details block | `…contact_details_are_absent_until_supplied…` |
| the home hero stops reading the resolved content | `…the_operator_changes_a_headline_and_a_visitor_sees_it…` |
| the faq page stops reading the resolved pairs | `…an_faq_answer_is_editable` |
| the in-handler operator check is removed | `…the_handlers_refuse_a_merchant_on_their_own…` |
| `PlatformOperatorOnly` removed from the update route | `…every_content_route_carries_the_operator_middleware…` |
| the principal class check is removed from the handler | `…the_handlers_refuse_a_customer_carrying_the_operators_type…` |
| the gate becomes a permission name | `…the_platform_operator_can_open_the_index_and_an_editor` |
| the write route keeps `xss` | `…the_words_alert_and_javascript_survive_a_save` |
| the operator gate **excluded** with `withoutMiddleware` | `…every_content_route_carries_the_operator_middleware…` |
| the reader falls back across locales | `…a_document_approved_in_one_language_is_not_published_in_the_other` |
| an unknown page is accepted by the editor | `…an_unknown_page_is_a_404_for_the_operator` |
| a registry key a view reads is renamed | `…every_content_key_a_public_view_reads_is_declared` |
| the contact detail is rendered unescaped (`nl2br($v)` for `nl2br(e($v))`) | `…a_multi_line_contact_detail_is_escaped_before_its_newlines_are_rendered` |

**Four of those mutations were added because an earlier run showed a layer was unpinned**, which is
the point of running them:

- removing the store's key filter and its locale filter left every HTTP test green (the controller
  filters first) → two direct tests on `PlatformContentStore::put()`;
- removing the handler's own operator check and its `instanceof User` clause left every HTTP test
  green (the route middleware refuses first) → four tests that invoke the controller directly, with
  no router and no middleware, which is exactly the situation a mis-moved route would produce;
- the store's registry filter was an **equivalent mutant** until the ordering loop stopped doubling
  as a filter: iterating `fields()` and keeping the keys present in `$clean` ordered *and* filtered
  in one pass, so the explicit `has()` guard could be deleted with nothing going red. `put()` now
  sorts the clean keys instead, so `has()` is the only filter and is pinned.

**Suites run** (not the full Unit/Feature suites — out of scope for this change):
`tests/Feature/Platform`, `tests/Unit/Platform`, `tests/Feature/Documentation`, `tests/Unit/Ui`,
`tests/Unit/Localisation`, `tests/Feature/Security/RouteShadowingTest` — **1867 passed, 0 failed**.
`tests/Unit/Merchant/ArabicUiCoverageTest` is **red on two strings** — see §9.

**Compiled-Blade lint:** all nine views compiled through the application's own compiler and
`php -l`'d — 0 syntax errors. (Relevant: two of the three traps CLAUDE.md records bit during this
work. A C-style comment's closing delimiter written inside a PHPDoc block ended it early and made
PHPUnit refuse to load the test file; and the view-key census was broken by its own explanatory
comment containing `$c['literal']` — the seventh recorded instance of a census satisfied or broken
by prose about the thing it measures.)

---

## 8. Rendered evidence — both directions, both appearances

`docs/development/phase-3/evidence/gap104-site-content-2026-11-26/` — **24 captures**, driven
through a real HTTP server against a throwaway SQLite fixture (never `database/p2dev.sqlite`, which
is empty and shared), with `measurements-*.json` beside them.

Every row's premise is checked, because the ways a capture lies all answer HTTP 200: status 200,
not Laravel's error page, more than 200 characters of body text, `dir` agreeing with the locale
asked for, and the surface's own content marker present.

| | result |
|---|---|
| rows | 24 — 3 editor screens × (en, ar) × (light, dark) + 3 public pages × (en, ar) × (light, dark) |
| premises | **24/24 hold** |
| contrast failures (WCAG AA, computed) | **0** |
| console errors | **0** |
| off-origin requests, public pages | **0** |
| off-origin requests, editor screens | 3 — `unpkg.com/leaflet`, two `cdnjs` pickadate files, loaded by the vendored WorkDo admin layout on every merchant screen. Pre-existing, not introduced here. |

**Three measurement traps hit and corrected while producing this, each of which would have made the
evidence a lie:**

1. **The admin theme is a setting, not `prefers-color-scheme`.** The WorkDo layout reads
   `cust_darklayout` and sets `data-ss-theme` itself, so Playwright's `colorScheme` emulation left
   the editor's background identical in both columns — `rgb(249,250,251)` twice, i.e. the dark
   column was a second copy of the light one. This is the failure PHASE-02 §4 records. The setting
   is now written between passes and the dark captures really are dark (`rgb(34,36,44)`).
2. **Off-origin requests were counted per browser context, not per page**, so all four requests made
   by `/login` were attributed to every platform page. Reset per surface.
3. **Two false contrast findings.** `.ss-ps-brand__mark` is a letter on a token gradient, which a
   background-*colour* walk cannot see, so it scored 1.00:1 — now counted as not-measurable rather
   than failing. And a `<caption class="ss-visually-hidden">` clipped to about one pixel scored
   3.03:1 — WCAG contrast applies to text a sighted user can read, so clipped nodes are skipped.

**One real contrast finding, fixed:** the host's `.btn btn-primary` paints `rgb(4,34,42)` on
`rgb(51,93,168)` in the dark admin theme — **2.58:1** against a 4.5:1 requirement, measured on this
screen. It is a known pre-existing host defect (`store-syria-ds.css:5640` records the same two
colours at 2.63:1 on the order grid and solved it there by removing the fill) and it affects 69
first-party and 100 vendored views. The editor's Save/Cancel now use the design system's own
`.ss-btn` / `.ss-btn--primary`, which take `--ss-brand` / `--ss-on-brand` and are correct in both
themes by construction. **The host defect itself is untouched and remains open elsewhere.**

---

## 9. Translations — 16 pairs the owner must apply, 2 of them blocking

Per the working rules for this change, `resources/lang/en.json` and `ar.json` were **not edited**
(concurrent appends lose each other's keys). Until the pairs below are applied:

- **`tests/Unit/Merchant/ArabicUiCoverageTest` is RED on two strings** — `Opening hours` and the
  legal mixed-state sentence, both on **public** pages, which that census scans. This is a CI-
  blocking Unit failure and it is resolved entirely by applying the pairs.
- the other 14 are on the editor screens, which that census does **not** scan: its glob is
  `resources/views/platform/*.blade.php`, non-recursive, so `platform/content/*.blade.php` is
  invisible to it — the same "a coverage test is only as wide as its list" failure its own comments
  record twice. **Adding `resources/views/platform/content/*.blade.php` to `SURFACES` is
  recommended** and is an edit to a file this change does not own.

The Arabic below follows the conventions already in `ar.json` and **still needs native review**, as
M4's 151 and M5's 215 strings do.

Where an existing key would do, it was reused rather than adding one: `Email`, `Details`, `Address`,
`Telephone`, `Heading`, `Question`, `Answer`, `Note`, `Save`, `Cancel`, `Edit`, `Page`, `Action`,
`None`, `Not published yet`, the five legal document titles and the seven page names are all
already in both files.

| key (identical in `en.json`) | proposed `ar.json` |
|---|---|
| `Official website content` | `محتوى الموقع الرسمي` |
| `Texts` | `النصوص` |
| `Owner content` | `محتوى يقدّمه المالك` |
| `Owner content outstanding` | `محتوى المالك المتبقّي` |
| `Open the public page` | `افتح الصفحة العامّة` |
| `Shipped wording` | `الصياغة الأصلية` |
| `Eyebrow` | `سطر تمهيدي` |
| `Intro` | `مقدّمة` |
| `Opening hours` | `ساعات العمل` |
| `The official website has been updated.` | `تم تحديث الموقع الرسمي.` |
| `The text on the official Store Syria website, changed here rather than in a release. Plan prices and limits are not editable: they are read from the plans the subscription flow charges against.` | `نصوص موقع ستور سوريا الرسمي، تُغيَّر من هنا بدلًا من إصدار جديد. أسعار الباقات وحدودها غير قابلة للتعديل: تُقرأ من الباقات التي تُحصَّل عليها الاشتراكات.` |
| `Each page of the official website, how many of its texts have been supplied in each language, and how much content is still owed.` | `كل صفحة من صفحات الموقع الرسمي، وكم من نصوصها قُدِّم بكل لغة، وكم بقي من المحتوى المطلوب.` |
| `A text left empty uses the wording the site ships with. The texts counted as outstanding carry no built-in wording at all, because the owner supplies them: until they do, those pages say so rather than showing anything.` | `النص المتروك فارغًا يستخدم الصياغة الأصلية للموقع. أمّا النصوص المحسوبة كمتبقّية فليست لها صياغة أصلية إطلاقًا لأن المالك هو من يقدّمها: وإلى أن يفعل، تذكر تلك الصفحات ذلك بدل عرض أي شيء.` |
| `Leave a text empty to use the wording the site ships with. A change is published as soon as it is saved.` | `اترك النص فارغًا لاستخدام الصياغة الأصلية للموقع. يُنشر التغيير فور حفظه.` |
| `The site ships with no wording for this. Nothing is published until the approved text is supplied here.` | `لا توجد صياغة أصلية لهذا في الموقع. لا يُنشر شيء إلى أن يُقدَّم النص المعتمد هنا.` |
| `Some of these documents have been published and some have not. A document marked as not published yet is a description of what it will cover, not terms, and nothing in it is binding on you or on us.` | `بعض هذه الوثائق نُشر وبعضها لم يُنشر. الوثيقة الموسومة بأنها لم تُنشر بعد هي وصف لما ستغطّيه، وليست شروطًا، ولا شيء فيها مُلزِم لك ولا لنا.` |

---

## 10. Outstanding

### Owed by the owner — content, named

| | |
|---|---|
| **Terms of service** | the agreement between a merchant and Store Syria |
| **Privacy notice** | what personal information the platform holds, why, who can reach it, how long |
| **Acceptable use** | what may and may not be sold or published through a store |
| **Subscription, billing and refunds** | how subscriptions are charged and when a charge is refunded |
| **Handling your customers' data** | the terms on which the platform holds a merchant's customers' data |
| **Contact: postal address, telephone, email address, opening hours** | there is no shipped setting for any of these; `contactus_url` is the only contact key the application reads |

Each is owed **in both languages** — an approved English document is not published to an Arabic
reader, deliberately (§3 of `PlatformContent`), so supplying only one language leaves the other
marked as not published.

### Owed by the owner — decisions

1. **The deviation this row records is now moot but should still be ruled on.** P03-C-05's next
   action offered two outcomes: accept code-owned marketing copy, or schedule a managed-content
   path. A managed-content path is now built. The owner should record which it is, and whether the
   content living in `settings` rather than in git is acceptable given that **no review sees it
   change**.
2. **Delegation.** Only the platform operator can edit the site. Letting a non-operator do it needs
   a permission seeded in `Utility.php::$arrPermissions` — a product decision about who may speak
   for the company.
3. **An audit trail.** Nothing records who changed which text, or when. The `settings` row carries
   `updated_at` and one owner, nothing more. Worth having before the legal documents land.

### Engineering, named and not done

| | |
|---|---|
| **No link to the screen.** `resources/views/superadmin/dashboard.blade.php` links `platform.analytics` and `platform.resources`; `platform.site_content.index` should join them. That file is not owned by this change. | one line |
| **`ArabicUiCoverageTest::SURFACES` does not scan `resources/views/platform/content/`** (non-recursive glob), so the editor's 14 untranslated strings are invisible to CI. | one line in a file this change does not own |
| **CSRF is not exercised by the tests.** Laravel disables `ValidateCsrfToken` under `runningUnitTests()`, so the POST route's CSRF protection is present in the resolved stack (confirmed) but unproven by a test. | — |
| **No preview before publish.** A save is live immediately. The editor links to the public page; it does not stage. | — |
| **No per-field history or undo** beyond clearing a field to restore the shipped wording. | — |
| **The host's `.btn btn-primary` remains 2.58:1 in the dark admin theme** on 69 first-party and 100 vendored views. Avoided on this screen; not fixed. | pre-existing |
| **Markdown is only accepted for the five legal bodies.** Other fields are plain text. | by design |

---

## 11. Files

**Added**

```
app/Support/Platform/PlatformContent.php            the registry: 51 fields, 9 owed, and the exclusions
app/Support/Platform/PlatformContentStore.php       the settings-backed store, owner-pinned, no migration
app/Http/Controllers/PlatformContentController.php  index / edit / update, operator-only
resources/views/platform/content/index.blade.php    where every page stands
resources/views/platform/content/edit.blade.php     one page, both languages, side by side
tests/Unit/Platform/PlatformContentRegistryTest.php
tests/Feature/Platform/PlatformContentEditorGateTest.php
tests/Feature/Platform/PlatformContentPublishingTest.php
docs/development/phase-3/evidence/gap104-site-content-2026-11-26/   24 captures + measurements
```

**Changed**

```
routes/web.php                               one block of three routes, beside platform/documentation
app/Http/Controllers/PlatformSiteController.php   each action passes the page's resolved content
resources/views/platform/home.blade.php      hero reads the registry
resources/views/platform/features.blade.php  hero reads the registry
resources/views/platform/pricing.blade.php   hero + the note read the registry
resources/views/platform/onboarding.blade.php hero reads the registry
resources/views/platform/faq.blade.php       hero + the ten pairs read the registry (the array moved into it)
resources/views/platform/contact.blade.php   hero + the owner's four details, published only when supplied
resources/views/platform/legal.blade.php     hero + the five bodies, published only when supplied
```

**No migration. No change to any language file, to `packages/workdo/`, to `.github/`, to
`bootstrap/app.php`, to `composer.json`, or to any existing test.**
